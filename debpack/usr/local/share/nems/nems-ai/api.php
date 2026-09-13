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

// 2. Process Payload Types & Humanized Prompts
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

    $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
            . "Summarize these NEW network incidents into a single, natural spoken sentence (under 25 words):\n"
            . "{$summary_list_str}\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Speak like a real engineer in the server room, NOT an automated robot.\n"
            . "2. Base your facts strictly on Baseline Text: '{$baseline_text}'\n"
            . "3. NEVER claim any offline host, backup, or service is working or online.\n"
            . "4. Group issues naturally by host (e.g. 'Server Backup and Percy2 are down, taking Ping and SSH offline').\n"
            . "5. NEVER use slashes ('/'). Use 'and' or 'or' instead.\n"
            . "6. PROHIBITED BUZZWORDS: 'operational status', 'experiencing state', 'speaker display', 'milestone'.\n"
            . "7. Write ONLY the final spoken sentence.";

} else if ($event_type === 'batch_recoveries' && !empty($payload['recoveries'])) {
    $recoveries = $payload['recoveries'];
    $recovery_summaries = [];

    foreach ($recoveries as $rec) {
        $alias = $rec['alias'] ?? ($rec['host'] ?? '');
        $check = $rec['checkName'] ?? '';
        $recovery_summaries[] = "- Host: {$alias} | Check: {$check}";
    }

    $summary_list_str = implode("\n", $recovery_summaries);

    $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
            . "Summarize these service recoveries in natural human language (under 20 words):\n"
            . "{$summary_list_str}\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Speak like a real human engineer. E.g., 'Services are back up on Backup and Percy2' or 'HTTP and SSH are running again on QNAP'.\n"
            . "2. NEVER use the blanket word 'All' unless every single service in the network was down.\n"
            . "3. NEVER mention latency, milliseconds, or remaining incidents.\n"
            . "4. NEVER use slashes ('/'). Use 'and' or 'or'.\n"
            . "5. PROHIBITED BUZZWORDS: 'returned to normal operational status', 'speaker display', 'telemetry'.\n"
            . "6. Write ONLY the final spoken sentence.";

} else if (strtolower($event_type) === 'celebration') {
    $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
            . "Announce that network health has restored to 100% using natural, human language (under 20 words).\n"
            . "Baseline Context: '{$baseline_text}'\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Speak like a real engineer in a control room, NOT a corporate press release.\n"
            . "2. PROHIBITED PHRASES: 'seamless operations', 'optimal performance', 'achieved milestone', 'ensuring', 'operational status'.\n"
            . "3. Use natural phrasing like: 'All hosts and services are back up and running' or 'Every server is back online'.\n"
            . "4. Add a quick, genuine word of encouragement at the end (e.g. 'Great job team' or 'Outstanding work team').\n"
            . "5. Write ONLY the final spoken sentence.";

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
        $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
                . "Generate a direct spoken sentence in conversational human language under 20 words.\n"
                . "Target: {$service} on {$alias}\n"
                . "Restored Status Metrics: {$output}\n\n"
                . "STRICT DIRECTIVES:\n"
                . "1. Confirm {$service} on {$alias} is back up and running (or back online).\n"
                . "2. State actual numbers or speed metrics if present (e.g., 'downloading at 150 megabits per second').\n"
                . "3. NEVER say 'returned to normal operational status' or mention remaining issues/speaker displays.\n"
                . "4. NEVER use slashes ('/'). Use 'and' or 'or'.\n"
                . "5. Write ONLY the final spoken sentence.";

    } else {
        $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
                . "Generate a direct, clear alert in natural human language under 20 words.\n"
                . "Target: {$service} on {$alias}\n"
                . "Error Output: {$output}\n"
                . "Context: {$context_str}\n\n"
                . "STRICT DIRECTIVES:\n"
                . "1. State the failure naturally (e.g., 'Server {$alias} is offline' or '{$service} on {$alias} is down').\n"
                . "2. NEVER use slashes ('/'). Use 'and' or 'or'.\n"
                . "3. PROHIBITED BUZZWORDS: 'operational status', 'speaker display', 'critical condition', 'experiencing issues'.\n"
                . "4. Write ONLY the final spoken sentence without setup fluff or quotes.";
    }
}

// 3. Query Ollama Engine
$ch_ollama = curl_init($ollama_url);
curl_setopt($ch_ollama, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_ollama, CURLOPT_POST, true);
curl_setopt($ch_ollama, CURLOPT_TIMEOUT, 45);
curl_setopt($ch_ollama, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch_ollama, CURLOPT_POSTFIELDS, json_encode([
    'model' => $model_name,
    'prompt' => $prompt,
    'stream' => false,
    'options' => [
        'num_predict' => 60,
        'temperature' => 0.2,
        'num_thread'  => 2
    ]
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
echo json_encode([
    'success' => true,
    'ai_active' => false,
    'speech_text' => $baseline_text,
    'display_text' => $baseline_text
]);
exit();
