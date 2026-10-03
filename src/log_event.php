<?php
// log_event.php - Generic endpoint for logging batched UI interaction events.
// To log a new kind of interaction, add its type and allowed fields to $eventSchemas.

$allowedOrigin = getenv('DOMAIN');
if ($allowedOrigin && preg_match('#^https?://#', $allowedOrigin)) {
    header("Access-Control-Allow-Origin: " . $allowedOrigin);
} elseif ($allowedOrigin) {
    header("Access-Control-Allow-Origin: https://" . $allowedOrigin);
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

$eventSchemas = [
    'filter_change' => ['filter' => 'string', 'value' => 'string', 'results' => 'int'],
    'sort_change'   => ['value' => 'string'],
    'filter_clear'  => ['cleared' => 'string'],
    'panel_toggle'  => ['open' => 'bool', 'auto' => 'bool'],
    'search'        => ['term' => 'string', 'results' => 'int'],
    'app_open'      => ['mode' => 'string', 'source' => 'string', 'platform' => 'string'],
    'install'       => ['step' => 'string', 'platform' => 'string'],
];
$maxEventsPerBatch = 25;
$maxStringLength = 100;

$logFilePath = '/var/log/mybeerfest/events.log';
$enableLogging = getenv('ENABLE_STATISTICS_LOGGING') === 'true';
$festivalTitle = getenv('FESTIVAL_TITLE') ?: 'Unknown Festival';

$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    exit();
}

$sessionId = $data['session_id'] ?? '';
$events = $data['events'] ?? null;
if (!is_string($sessionId) || trim($sessionId) === '' || !is_array($events) || !array_is_list($events)
    || count($events) === 0 || count($events) > $maxEventsPerBatch) {
    http_response_code(400);
    exit();
}
$sessionId = substr(strip_tags(trim($sessionId)), 0, 64);

$validEvents = [];
foreach ($events as $event) {
    if (!is_array($event) || !is_string($event['type'] ?? null) || !isset($eventSchemas[$event['type']])) {
        continue;
    }
    $input = is_array($event['data'] ?? null) ? $event['data'] : [];
    $clean = [];
    foreach ($eventSchemas[$event['type']] as $field => $kind) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $value = $input[$field];
        if ($kind === 'string' && is_string($value)) {
            $clean[$field] = mb_substr(strip_tags(trim($value)), 0, $maxStringLength);
        } elseif ($kind === 'int' && is_int($value) && $value >= 0) {
            $clean[$field] = $value;
        } elseif ($kind === 'bool' && is_bool($value)) {
            $clean[$field] = $value;
        }
    }
    $validEvents[] = ['type' => $event['type'], 'data' => $clean];
}

if (count($validEvents) === 0) {
    http_response_code(400);
    exit();
}

if (!$enableLogging) {
    http_response_code(204);
    exit();
}

$dt = new DateTime('now', new DateTimeZone('UTC'));
$timestamp = $dt->format('Y-m-d\TH:i:s.v\Z');
$timestampUnixMs = (int) floor(microtime(true) * 1000);

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($userAgent === '') {
    $deviceType = 'unknown';
} elseif (preg_match('/\b(iPad|Tablet)\b|Android(?!.*Mobile)/i', $userAgent)) {
    $deviceType = 'tablet';
} elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $userAgent)) {
    $deviceType = 'mobile';
} else {
    $deviceType = 'desktop';
}

$lines = '';
foreach ($validEvents as $event) {
    $lines .= json_encode([
        'timestamp'         => $timestamp,
        'timestamp_unix_ms' => $timestampUnixMs,
        'festival_name'     => $festivalTitle,
        'session_id'        => $sessionId,
        'device_type'       => $deviceType,
        'type'              => $event['type'],
        'data'              => (object) $event['data'],
    ], JSON_UNESCAPED_UNICODE) . "\n";
}

$logDir = dirname($logFilePath);
if (!is_dir($logDir) && !mkdir($logDir, 0750, true)) {
    error_log("Error: Could not create log directory: $logDir");
}
if (file_put_contents($logFilePath, $lines, FILE_APPEND | LOCK_EX) === false) {
    error_log("Error: Could not write to event log file: $logFilePath");
}

http_response_code(204);
