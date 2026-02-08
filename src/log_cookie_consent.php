<?php
// log_cookie_consent.php
// Handles logging of the user's cookie consent choice.

// Set the allowed origin based on an environment variable for better security.
$allowedOrigin = getenv('DOMAIN');
if ($allowedOrigin && preg_match('#^https?://#', $allowedOrigin)) {
    // Only allow requests from the specified domain.
    header("Access-Control-Allow-Origin: " . $allowedOrigin);
} elseif ($allowedOrigin) {
    // DOMAIN is set but lacks a scheme — assume https.
    header("Access-Control-Allow-Origin: https://" . $allowedOrigin);
}
// When DOMAIN is not set, no Access-Control-Allow-Origin header is emitted,
// which restricts requests to same-origin only.
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request (for CORS).
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No Content
    exit();
}

// Get environment variable to check if logging is enabled.
// This should be consistent with the main application.
$enableStatisticsLogging = getenv('ENABLE_STATISTICS_LOGGING') === 'true';

// Define the log file path.
$logFilePath = '/var/log/mybeerfest/cookie_consent.log';

// If logging is disabled, do nothing and return a success response.
// The client does not need to know that logging is off on the server.
if (!$enableStatisticsLogging) {
    http_response_code(204); // No Content
    exit;
}

// Start session to access the session ID.
session_start();
$sessionId = $_SESSION['session_id'] ?? 'unknown_session'; // Use a fallback value.

// Get festival title from environment variable.
$festivalTitle = getenv('FESTIVAL_TITLE') ?: 'My BeerFest'; // Default value.

// Ensure the request method is POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    exit;
}

// Get the raw POST data from the request.
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

// Validate the incoming JSON data.
if (json_last_error() !== JSON_ERROR_NONE || !isset($data['consent']) || !is_bool($data['consent'])) {
    http_response_code(400); // Bad Request
    error_log("Invalid JSON or missing/invalid consent data in log_cookie_consent.php. Length: " . strlen($jsonInput));
    exit;
}

// Generate timestamps using a robust method.
try {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
    // RFC3339 format with milliseconds and 'Z' for UTC.
    $timestamp = $dt->format('Y-m-d\TH:i:s.v\Z');
    // Unix timestamp in milliseconds.
    $timestamp_unix_ms = floor(microtime(true) * 1000);
} catch (Exception $e) {
    error_log("Error generating timestamp in log_cookie_consent.php: " . $e->getMessage());
    $timestamp = date('c'); // Fallback for RFC3339.
    $timestamp_unix_ms = round(microtime(true) * 1000); // Fallback for Unix ms.
}

// Prepare the log entry.
$logEntry = [
    'timestamp'        => $timestamp,
    'timestamp_unix_ms'=> $timestamp_unix_ms,
    'session_id'       => $sessionId,
    'festival_name'    => $festivalTitle,
    'action'           => 'cookie consent',
    'consent'          => (bool) $data['consent'], // Ensure it's a boolean.
];

// Convert the log entry to a JSON string.
$logString = json_encode($logEntry) . PHP_EOL;

// Ensure the log directory exists.
$logDir = dirname($logFilePath);
if (!is_dir($logDir)) {
    // Attempt to create the directory recursively with appropriate permissions.
    if (!mkdir($logDir, 0750, true)) {
        error_log("Error: Could not create log directory: $logDir");
        // Do not send an error to the client, as this is a server-side issue.
    }
}

// Append the log entry to the log file.
// FILE_APPEND prevents overwriting the file, and LOCK_EX prevents concurrent writes.
if (file_put_contents($logFilePath, $logString, FILE_APPEND | LOCK_EX) === false) {
    // If logging fails, log the error on the server but don't fail the client request.
    error_log("Error: Could not write to cookie consent log file: {$logFilePath}");
}

// Always send a success response to the client.
// A 204 No Content response is appropriate here as we don't need to send anything back.
http_response_code(204);

