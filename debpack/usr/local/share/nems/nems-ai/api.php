<?php
// Executed via require_once from /var/www/html/nems-api/index.php
set_time_limit(60);

$nems_ai_dir = '/usr/local/share/nems/nems-ai';
$db_path = $nems_ai_dir . '/noc_history.db';
$ollama_url = 'http://127.0.0.1:11434/api/generate';

// 1. Initialize SQLite 24-Hour History Database
try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS event_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp INTEGER,
        host_name TEXT,
        service_description TEXT,
        state INTEGER,
        plugin_output TEXT
    )");
} catch (Exception $e) {
    $db = null;
}

$payload = $json_body ?? [];
$event_type = $payload['event_type'] ?? 'incident';
$baseline_text = $payload['baseline_text'] ?? '';
$now = time();
$model_name = 'nems-ai';

// PHP Post-Processing Unit & Slash Expander
function expandSpokenUnits(string $text): string {
    $text = preg_replace('/\bMB\/s\b/i', 'megabytes per second', $text);
    $text = preg_replace('/\bKB\/s\b/i', 'kilobytes per second', $text);
    $text = preg_replace('/\bGB\/s\b/i', 'gigabytes per second', $text);

    $text = preg_replace('/([a-zA-Z0-9]+)\s*\/\s*([a-zA-Z0-9]+)/', '$1 and $2', $text);

    $replacements = [
        '/\bMbps\b/i'  => 'megabits per second',
        '/\bGbps\b/i'  => 'gigabits per second',
        '/\bms\b/i'    => 'milliseconds',
        '/\bGB\b/i'    => 'gigabytes',
        '/\bMB\b/i'    => 'megabytes',
        '/\bCPU\b/i'   => 'C P U',
        '/\bRAM\b/i'   => 'RAM'
    ];
    return preg_replace(array_keys($replacements), array_values($replacements), $text);
}

// 2. Process Payload Types
if ($event_type === 'batch_incidents' && !empty($payload['incidents'])) {
    $incidents = $payload['incidents'];
    $incident_summaries = [];

    foreach ($incidents as $inc) {
        $host = $inc['host'] ?? '';
        $alias = $inc['alias'] ?? $host;
        $check = $inc['checkName'] ?? '';
        $state = $inc['stateCode'] ?? 0;
        $output = $inc['msg'] ?? '';

        if ($db && !empty($host)) {
            try {
                $stmt = $db->prepare("INSERT INTO event_log (timestamp, host_name, service_description, state, plugin_output) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$now, $host, $check, $state, $output]);
            } catch (Exception $e) {}
        }

        $incident_summaries[] = "- Host: {$alias} | Check: {$check} | Error: {$output}";
    }

    if ($db) {
        try { $db->exec("DELETE FROM event_log WHERE timestamp < " . ($now - 86400)); } catch (Exception $e) {}
    }

    $summary_list_str = implode("\n", $incident_summaries);

    $prompt = "You are NEMS AI, an intelligent NOC voice assistant.\n"
            . "Synthesize these simultaneous network incidents into a single, logical spoken summary (under 35 words):\n"
            . "{$summary_list_str}\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. EVERY item listed is an active failure. NEVER claim any service or backup is online, working, or functioning.\n"
            . "2. Group failures by Host: If a host has an invalid address/hostname or is down, state that the host issue is affecting its checks (e.g. Ping and SSH).\n"
            . "3. Clearly separate issues on different hosts (e.g. 'Host test has an invalid address rejecting ping and SSH, while NEMS Migrator backup is unauthorized').\n"
            . "4. NEVER use slash characters ('/'). Use 'and' or 'or' instead.\n"
            . "5. Do NOT use setup phrases like 'Critical issue with' or quotes. State facts directly.\n"
            . "6. Maintain strict subject-verb agreement for singular vs plural items (e.g., write 'There is 1 active incident' instead of 'There are 1 active incidents').";

} else if ($event_type === 'batch_recoveries' && !empty($payload['recoveries'])) {
    $recoveries = $payload['recoveries'];
    $recovery_summaries = [];

    foreach ($recoveries as $rec) {
        $host = $rec['host'] ?? '';
        $alias = $rec['alias'] ?? $host;
        $check = $rec['checkName'] ?? '';
        $output = $rec['msg'] ?? '';

        $recovery_summaries[] = "- Host: {$alias} | Check: {$check} | Output: {$output}";
    }

    $summary_list_str = implode("\n", $recovery_summaries);

    $prompt = "You are NEMS AI, an intelligent NOC voice assistant.\n"
            . "Synthesize these service recoveries into a concise spoken summary under 25 words:\n"
            . "{$summary_list_str}\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Group recoveries by host and confirm services are back online.\n"
            . "2. State actual numerical speed or latency metrics if present.\n"
            . "3. NEVER use slash characters ('/'). Use 'and' or 'or' instead.\n"
            . "4. Write ONLY the final spoken sentence. No setup words or quotes.\n"
            . "5. Maintain strict subject-verb agreement for singular vs plural items (e.g., write 'There is 1 active incident' instead of 'There are 1 active incidents').";


} else {
    // Single Event
    $check = $payload['check_data'] ?? [];
    $host = $check['host_name'] ?? '';
    $alias = $check['host_alias'] ?? $host;
    $service = $check['service_description'] ?? '';
    $state = $check['state'] ?? 0;
    $output = $check['plugin_output'] ?? '';

    $occurrences_24h = 0;
    if ($db && !empty($host)) {
        try {
            $stmt = $db->prepare("INSERT INTO event_log (timestamp, host_name, service_description, state, plugin_output) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$now, $host, $service, $state, $output]);
            $db->exec("DELETE FROM event_log WHERE timestamp < " . ($now - 86400));

            $count_stmt = $db->prepare("SELECT COUNT(*) FROM event_log WHERE host_name = ? AND service_description = ? AND timestamp > ?");
            $count_stmt->execute([$host, $service, $now - 86400]);
            $occurrences_24h = (int)$count_stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    $context_str = $occurrences_24h > 1 ? "Failed {$occurrences_24h} times in past 24h." : "First occurrence today.";

    if (strtolower($event_type) === 'recovery') {
        $prompt = "You are NEMS AI, an intelligent NOC voice assistant.\n"
                . "Generate a single direct spoken sentence under 25 words.\n"
                . "Target: {$service} on {$alias}\n"
                . "Restored Status Metrics: {$output}\n\n"
                . "Directives:\n"
                . "1. Confirm {$service} has returned to normal status.\n"
                . "2. State the actual numbers/speeds from Restored Status Metrics (e.g. download, upload, and ping).\n"
                . "3. NEVER say 'results available'. State actual speed values directly.\n"
                . "4. NEVER use slash characters ('/'). Use 'and' or 'or' instead.\n"
                . "5. Write ONLY the spoken sentence.\n"
                . "6. Maintain strict subject-verb agreement for singular vs plural items (e.g., write 'There is 1 active incident' instead of 'There are 1 active incidents').";

    } else {
        $prompt = "You are NEMS AI, an intelligent NOC voice assistant.\n"
                . "Generate a single direct spoken sentence under 20 words for a speaker display.\n"
                . "Target: {$service} on {$alias}\n"
                . "Error Output: {$output}\n"
                . "Context: {$context_str}\n"
                . "NEVER use slash characters ('/'). State the exact issue directly without setup fluff.\n"
                . "Maintain strict subject-verb agreement for singular vs plural items (e.g., write 'There is 1 active incident' instead of 'There are 1 active incidents').";

    }
}

// 3. Query Ollama Engine (45-second budget)
$ch_ollama = curl_init($ollama_url);
curl_setopt($ch_ollama, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_ollama, CURLOPT_POST, true);
curl_setopt($ch_ollama, CURLOPT_TIMEOUT, 45);
curl_setopt($ch_ollama, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch_ollama, CURLOPT_POSTFIELDS, json_encode([
    'model' => $model_name,
    'prompt' => $prompt,
    'stream' => false,
    'options' => ['num_predict' => 60, 'temperature' => 0.2]
]));

$ollama_res = curl_exec($ch_ollama);
curl_close($ch_ollama);

if ($ollama_res) {
    $ollama_json = json_decode($ollama_res, true);
    $raw_speech = trim($ollama_json['response'] ?? '');
    
    $speech = preg_replace('/^["\']|["\']$/', '', $raw_speech);
    $speech = preg_replace('/^(Technical Units expanded|Note|Summary|Result):/i', '', $speech);
    $speech = str_replace(['*', '#', '`', "\n", "\r", '"', "'"], ' ', $speech);
    $speech = trim(preg_replace('/\s+/', ' ', $speech));

    $speech = expandSpokenUnits($speech);

    if (!empty($speech)) {
        echo json_encode([
            'success' => true,
            'ai_active' => true,
            'speech_text' => $speech,
            'display_text' => $speech
        ]);
        exit();
    }
}

// Fallback response
$fallback_speech = expandSpokenUnits($baseline_text);
echo json_encode([
    'success' => true,
    'ai_active' => false,
    'speech_text' => $fallback_speech,
    'display_text' => $baseline_text
]);
exit();
