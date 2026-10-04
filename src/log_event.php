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

// Field types: 'string', 'int' (>= 0), 'bool', 'beer_id' (must exist in beers.json) or a list of
// allowed values. All fields are required; events with a missing or invalid field are dropped.
$eventSchemas = [
    'filter_change'   => ['filter' => ['session', 'route', 'style', 'brewery', 'country', 'my_rated', 'unrated', 'favorites'], 'value' => 'string', 'results' => 'int'],
    'sort_change'     => ['value' => ['brewery-asc', 'name-asc', 'alc-asc', 'alc-desc', 'rating-desc', 'my-rating-desc', 'route-order']],
    'filter_clear'    => ['cleared' => ['filters', 'session']],
    'panel_toggle'    => ['open' => 'bool', 'auto' => 'bool'],
    'search'          => ['term' => 'string', 'results' => 'int'],
    'app_open'        => ['mode' => ['standalone', 'browser'], 'source' => ['load', 'resume'], 'platform' => ['ios', 'android', 'other']],
    'install'         => ['step' => ['banner_shown', 'banner_dismissed', 'prompt_accepted', 'prompt_declined', 'installed', 'first_launch'], 'platform' => ['ios', 'ios_other_browser', 'android', 'other']],
    'favorite_toggle' => ['beer_id' => 'beer_id', 'on' => 'bool'],
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

require_once __DIR__ . '/beer_catalog.php';

$sessionId = $data['session_id'] ?? '';
$events = $data['events'] ?? null;
if (!isValidSessionId($sessionId) || !is_array($events) || !array_is_list($events)
    || count($events) === 0 || count($events) > $maxEventsPerBatch) {
    http_response_code(400);
    exit();
}
$beerCatalog = null;

$validEvents = [];
foreach ($events as $event) {
    if (!is_array($event) || !is_string($event['type'] ?? null) || !isset($eventSchemas[$event['type']])
        || !is_array($event['data'] ?? null)) {
        continue;
    }
    $input = $event['data'];
    $clean = [];
    foreach ($eventSchemas[$event['type']] as $field => $kind) {
        $value = $input[$field] ?? null;
        if (is_array($kind)) {
            $valid = is_string($value) && in_array($value, $kind, true);
        } elseif ($kind === 'string') {
            $valid = is_string($value);
            if ($valid) $value = mb_substr(strip_tags(trim($value)), 0, $maxStringLength);
        } elseif ($kind === 'int') {
            $valid = is_int($value) && $value >= 0;
        } elseif ($kind === 'bool') {
            $valid = is_bool($value);
        } else {
            $beerCatalog ??= loadBeerCatalog();
            $valid = isValidBeerId($value, $beerCatalog);
        }
        if (!$valid) {
            continue 2;
        }
        $clean[$field] = $value;
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
