<?php
// log_rating.php - Server-side endpoint for logging beer ratings

// Allow cross-origin requests for development/testing if needed
// In a production environment, you might want to restrict this to specific origins
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

// Handle preflight OPTIONS request (for CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get environment variable to check if logging is enabled
$enableLogging = getenv('ENABLE_STATISTICS_LOGGING') === 'true';
$logFilePath = '/var/log/mybeerfest/ratings.log'; // Path inside the container

// Get festival title from environment variable (reusing FESTIVAL_TITLE)
$festivalTitleForLog = getenv('FESTIVAL_TITLE') ?: 'Unknown Festival'; // Reusing FESTIVAL_TITLE for log

// --- Load beers.json for server-side lookup ---
$beers = [];
$beersDataPath = '/var/www/html/data/beers.json'; // Path to beers.json inside the container
if (file_exists($beersDataPath)) {
    $jsonContent = file_get_contents($beersDataPath);
    $beers = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($beers)) {
        error_log("Error: Could not decode beers.json or it's not an array.");
        $beers = []; // Reset to empty array if invalid
    }
} else {
    error_log("Warning: beers.json not found at $beersDataPath. Beer details will be N/A.");
}

// Create a lookup map for beers by ID
$beerLookup = [];
foreach ($beers as $beer) {
    if (isset($beer['id'])) {
        $beerLookup[$beer['id']] = $beer;
    }
}
// --- End Load beers.json ---


// Ensure the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $json_data = file_get_contents('php://input');

    // Decode the JSON data
    $data = json_decode($json_data, true);

    // Check if JSON decoding was successful and data is valid
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log("Error: Invalid JSON data received in log_rating.php. Length: " . strlen($json_data));
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data received']);
        exit();
    }

    // --- Input Validation and Sanitization for incoming client data ---

    // Validate and sanitize beer_id (from client)
    $beerId = $data['beer_id'] ?? '';
    if (!is_string($beerId) || empty($beerId)) {
        error_log("Validation Error: Missing or invalid beer_id from client.");
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid beer ID']);
        exit();
    }
    $beerId = strip_tags(trim($beerId)); // Sanitize string

    // Validate rating
    $rating = $data['rating'] ?? null;
    if (!is_numeric($rating) || $rating < 0.25 || $rating > 5.0) {
        error_log("Validation Error: Missing or invalid rating.");
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid rating value (must be between 0.25 and 5.0)']);
        exit();
    }
    $rating = (float) $rating; // Ensure it's a float

    // Validate and sanitize session_id
    $sessionId = $data['session_id'] ?? '';
    if (!is_string($sessionId) || empty($sessionId)) {
        error_log("Validation Error: Missing or invalid session_id.");
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid session ID']);
        exit();
    }
    $sessionId = strip_tags(trim($sessionId)); // Sanitize string

    // Generate timestamps
    try {
        $dt = new DateTime('now', new DateTimeZone('UTC'));
        // RFC3339 format with milliseconds and 'Z' for UTC
        $timestamp = $dt->format('Y-m-d\TH:i:s.v\Z');
        // Unix timestamp in milliseconds
        $timestamp_unix_ms = floor(microtime(true) * 1000);
    } catch (Exception $e) {
        error_log("Error generating timestamp: " . $e->getMessage());
        $timestamp = date('c'); // Fallback for RFC3339
        $timestamp_unix_ms = round(microtime(true) * 1000); // Fallback for Unix ms
    }


    // --- Server-side Lookup of Beer Details (Strictly from beers.json) ---
    $lookedUpBeer = $beerLookup[$beerId] ?? null;

    // If beer ID not found in lookup, log a warning and set all details to N/A or null
    if ($lookedUpBeer === null) {
        error_log("Warning: Beer ID '$beerId' not found in beers.json for logging. Details will be N/A.");
        $beerName = 'N/A';
        $brewery = 'N/A';
        $style = 'N/A';
        $country = 'N/A';
        $alc = null;
        $session = 'N/A';
        $untappd = '';
    } else {
        // Overwrite client-provided data with server-side looked-up data
        $beerName = $lookedUpBeer['name'] ?? 'N/A';
        $brewery = $lookedUpBeer['brewery'] ?? 'N/A';
        $style = $lookedUpBeer['style'] ?? 'N/A';
        $country = $lookedUpBeer['country'] ?? 'N/A';
        $alc = $lookedUpBeer['alc'] ?? null;
        // Alc handling: if not null and not numeric, keep its original string value (e.g., "-")
        if ($alc !== null && !is_numeric($alc)) {
            // No change, keep original non-numeric string value
        } elseif (is_numeric($alc)) {
            $alc = (float) $alc; // Ensure it's a float if numeric
        }
        // If it was originally null or empty string, it remains null

        $session = $lookedUpBeer['session'] ?? 'N/A';
        $untappd = $lookedUpBeer['untappd'] ?? '';
    }

    // --- End Input Validation and Sanitization ---

    $logEntry = [
        'timestamp' => $timestamp, // RFC3339 format (with milliseconds and Z)
        'timestamp_unix_ms' => $timestamp_unix_ms, // New field: Unix milliseconds
        'festival_name' => $festivalTitleForLog, // Updated: Use FESTIVAL_TITLE for log
        'session_id' => $sessionId,
        'beer_id' => $beerId,
        'beer_name' => $beerName, // Server-side looked up name
        'rating' => $rating,
        'brewery' => $brewery,
        'style' => $style,
        'country' => $country,
        'alc' => $alc,
        'session' => $session,
        'untappd' => $untappd
    ];

    if ($enableLogging) {
        // Ensure the log directory exists
        $logDir = dirname($logFilePath);
        if (!is_dir($logDir)) {
            // Attempt to create directory with permissions
            if (!mkdir($logDir, 0750, true)) {
                error_log("Error: Could not create log directory: $logDir");
                // Continue execution, but logging will fail
            }
        }

        // Append the JSON entry to the log file in NDJSON format
        // Use FILE_APPEND to add to the end, LOCK_EX to prevent race conditions
        if (file_put_contents($logFilePath, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log("Error: Could not write to log file: $logFilePath");
            // Do not send an error response to the client for logging failures
        }
    } else {
        error_log("Statistics logging is disabled. Data not logged to file.");
    }

    // Send a success response back to the client
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Rating processed.']);
} else {
    // Method Not Allowed
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
?>
