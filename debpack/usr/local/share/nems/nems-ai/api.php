<?php
// Executed via require_once from /var/www/html/nems-api/index.php
set_time_limit(60);

$nems_ai_dir = '/usr/local/share/nems/nems-ai';
$db_path = $nems_ai_dir . '/noc_history.db';
$lock_file = '/tmp/nems-ai.lock';
$ollama_url = 'http://127.0.0.1:11434/api/generate';

// Fast Busy Check: Return busy status immediately if a query is actively generating
if (file_exists($lock_file) && (time() - filemtime($lock_file) < 40)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'status'  => 'busy',
        'message' => 'NEMS AI engine is currently processing a prior request.'
    ]);
    exit();
}

// Acquire Atomic Lock
touch($lock_file);

// Ensure lock is cleared on script exit or unexpected crash
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) {
        @unlink($lock_file);
    }
});

// Initialize SQLite 24-Hour History Database
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

// Construct Prompts
if ($event_type === 'flapping') {
    $host = $payload['host'] ?? 'Unknown Host';
    $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
            . "Generate a single natural sentence under 15 words explaining that server {$host} went down but recovered almost immediately.\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Speak naturally like an engineer in the room.\n"
            . "2. Write ONLY the final spoken sentence.";

} else if ($event_type === 'batch_incidents' && !empty($payload['incidents'])) {
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

    $summary_list_str = implode("\n", $incident_summaries);

    $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
            . "Summarize these NEW network incidents into a single, natural spoken sentence (under 25 words):\n"
            . "{$summary_list_str}\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Base facts strictly on Baseline Text: '{$baseline_text}'\n"
            . "2. NEVER claim any offline host or service is working.\n"
            . "3. Group issues naturally by host.\n"
            . "4. NEVER use slashes ('/'). Use 'and' or 'or'.\n"
            . "5. Write ONLY the final spoken sentence.";

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
            . "1. Do NOT use the word 'All'. Say 'Services have recovered on...' and name the hosts.\n"
            . "2. NEVER mention latency or remaining incidents.\n"
            . "3. Write ONLY the final spoken sentence.";

} else if (strtolower($event_type) === 'celebration') {
    $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
            . "Announce that network health has restored to 100% using natural, human language (under 20 words).\n"
            . "Baseline Context: '{$baseline_text}'\n\n"
            . "STRICT DIRECTIVES:\n"
            . "1. Speak like a real engineer in a control room.\n"
            . "2. Use natural phrasing like: 'All hosts and services are back up and running'.\n"
            . "3. Add a quick, genuine word of encouragement (e.g. 'Great job team').\n"
            . "4. Write ONLY the final spoken sentence.";

} else {
    // Single Event
    $check = $payload['check_data'] ?? [];
    $host = $check['host_name'] ?? '';
    $alias = $check['host_alias'] ?? $host;
    $service = $check['service_description'] ?? '';
    $state = $check['state'] ?? 0;
    $output = $check['plugin_output'] ?? '';

    if (strtolower($event_type) === 'recovery') {
        $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
                . "Generate a direct spoken sentence in conversational human language under 20 words.\n"
                . "Target: {$service} on {$alias}\n"
                . "Restored Status Metrics: {$output}\n\n"
                . "STRICT DIRECTIVES:\n"
                . "1. Confirm {$service} on {$alias} is back online.\n"
                . "2. State actual throughput metrics if present.\n"
                . "3. Write ONLY the final spoken sentence.";
    } else {
        $prompt = "You are NEMS AI, a plain-spoken NOC voice engineer.\n"
                . "Generate a direct alert in natural human language under 20 words.\n"
                . "Target: {$service} on {$alias}\n"
                . "Error Output: {$output}\n\n"
                . "STRICT DIRECTIVES:\n"
                . "1. State the failure naturally (e.g. 'Server {$alias} is offline').\n"
                . "2. Write ONLY the final spoken sentence.";
    }
}

// Query Ollama Engine
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

// Release Lock
if (file_exists($lock_file)) {
    @unlink($lock_file);
}

if ($ollama_res) {
    $ollama_json = json_decode($ollama_res, true);
    $raw_speech = trim($ollama_json['response'] ?? '');

    $speech = preg_replace('/^["\']|["\']$/', '', $raw_speech);
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
