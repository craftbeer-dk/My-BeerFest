<?php
// health.php
// Liveness/readiness probe for container health checks and monitoring.
// Responds 200 when the app can serve, 503 otherwise. The body reports only
// booleans — no paths or error strings — because this endpoint is public.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$beersFile = __DIR__ . '/data/beers.json';
$logDir = '/var/log/mybeerfest';
$deep = isset($_GET['deep']);

$checks = [
    'php' => true,
    'catalog_present' => is_readable($beersFile) && filesize($beersFile) > 0,
];

// Parsing the catalog on every probe is wasteful, so it is opt-in.
if ($deep) {
    $raw = $checks['catalog_present'] ? file_get_contents($beersFile) : false;
    $checks['catalog_valid'] = $raw !== false && json_decode($raw) !== null;
}

if (getenv('ENABLE_STATISTICS_LOGGING') === 'true') {
    $checks['logs_writable'] = is_dir($logDir) && is_writable($logDir);
}

$healthy = !in_array(false, $checks, true);
http_response_code($healthy ? 200 : 503);

echo json_encode([
    'status' => $healthy ? 'ok' : 'error',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
