<?php
// admin_api.php - Backend API for admin beer data management

header('Content-Type: application/json');

$allowedOrigin = getenv('DOMAIN');
if ($allowedOrigin) {
    header("Access-Control-Allow-Origin: " . $allowedOrigin);
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$dataDir = '/var/www/html/data';
$beersFile = $dataDir . '/beers.json';
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'save':
        handleSave($beersFile, $dataDir);
        break;
    case 'versions':
        handleListVersions($dataDir);
        break;
    case 'version_data':
        handleGetVersionData($dataDir);
        break;
    case 'restore':
        handleRestore($beersFile, $dataDir);
        break;
    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}

// Allowed beer fields with their validation rules
function getBeerFieldRules() {
    return [
        'id'       => ['type' => 'string', 'required' => true,  'max_length' => 100, 'pattern' => '/^[a-zA-Z0-9_-]+$/'],
        'name'     => ['type' => 'string', 'required' => true,  'max_length' => 200],
        'brewery'  => ['type' => 'string', 'required' => false, 'max_length' => 200],
        'style'    => ['type' => 'string', 'required' => false, 'max_length' => 200],
        'country'  => ['type' => 'string', 'required' => false, 'max_length' => 100],
        'session'  => ['type' => 'string', 'required' => false, 'max_length' => 100],
        'alc'      => ['type' => 'number', 'required' => false, 'min' => 0, 'max' => 100],
        'rating'   => ['type' => 'number', 'required' => false, 'min' => 0, 'max' => 5],
        'untappd'  => ['type' => 'url',    'required' => false, 'max_length' => 500],
    ];
}

// Validate and sanitize a single beer object. Returns [sanitized_beer, error_string|null].
function validateBeer($beer, $index) {
    if (!is_array($beer)) {
        return [null, "Item at index $index is not an object"];
    }

    $rules = getBeerFieldRules();
    $sanitized = [];

    // Check for unknown fields - strip them
    foreach ($beer as $key => $value) {
        if (!isset($rules[$key])) {
            // Unknown field - silently drop it
            continue;
        }
    }

    // Validate each known field
    foreach ($rules as $field => $rule) {
        $value = $beer[$field] ?? null;

        // Required check
        if ($rule['required']) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                return [null, "Beer at index $index: '$field' is required"];
            }
        }

        // Skip null/missing optional fields
        if ($value === null || ($rule['type'] === 'string' && is_string($value) && trim($value) === '')) {
            continue;
        }

        switch ($rule['type']) {
            case 'string':
                if (!is_string($value)) {
                    return [null, "Beer at index $index: '$field' must be a string"];
                }
                $value = trim(strip_tags($value));
                if (isset($rule['max_length']) && mb_strlen($value) > $rule['max_length']) {
                    return [null, "Beer at index $index: '$field' exceeds max length of {$rule['max_length']}"];
                }
                if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                    return [null, "Beer at index $index: '$field' contains invalid characters (only alphanumeric, hyphens, underscores)"];
                }
                $sanitized[$field] = $value;
                break;

            case 'number':
                if (is_string($value) && is_numeric($value)) {
                    $value = (float) $value;
                }
                if (!is_numeric($value)) {
                    return [null, "Beer at index $index: '$field' must be a number"];
                }
                $value = (float) $value;
                if (isset($rule['min']) && $value < $rule['min']) {
                    return [null, "Beer at index $index: '$field' must be >= {$rule['min']}"];
                }
                if (isset($rule['max']) && $value > $rule['max']) {
                    return [null, "Beer at index $index: '$field' must be <= {$rule['max']}"];
                }
                $sanitized[$field] = $value;
                break;

            case 'url':
                if (!is_string($value)) {
                    return [null, "Beer at index $index: '$field' must be a string"];
                }
                $value = trim($value);
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    return [null, "Beer at index $index: '$field' must be a valid URL"];
                }
                // Only allow http/https schemes
                if ($value !== '') {
                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    if (!in_array($scheme, ['http', 'https'], true)) {
                        return [null, "Beer at index $index: '$field' must use http or https"];
                    }
                }
                if (isset($rule['max_length']) && mb_strlen($value) > $rule['max_length']) {
                    return [null, "Beer at index $index: '$field' exceeds max length of {$rule['max_length']}"];
                }
                $sanitized[$field] = $value;
                break;
        }
    }

    return [$sanitized, null];
}

function handleSave($beersFile, $dataDir) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        return;
    }

    $jsonData = file_get_contents('php://input');

    // Reject excessively large payloads (5 MB)
    if (strlen($jsonData) > 5 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => 'Payload too large']);
        return;
    }

    $newBeers = json_decode($jsonData, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($newBeers)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
        return;
    }

    // Must be a sequential array, not an associative one
    if ($newBeers !== array_values($newBeers)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Data must be a JSON array']);
        return;
    }

    // Cap the number of beers to a reasonable maximum
    if (count($newBeers) > 2000) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Too many beers (max 2000)']);
        return;
    }

    // Validate and sanitize every beer, check for duplicate IDs
    $sanitizedBeers = [];
    $seenIds = [];
    foreach ($newBeers as $i => $beer) {
        [$sanitized, $error] = validateBeer($beer, $i);
        if ($error !== null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $error]);
            return;
        }

        if (isset($seenIds[$sanitized['id']])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Duplicate beer id: " . $sanitized['id']]);
            return;
        }
        $seenIds[$sanitized['id']] = true;
        $sanitizedBeers[] = $sanitized;
    }

    // Create backup of current file
    if (file_exists($beersFile)) {
        $timestamp = date('Ymd_His');
        $backupFile = $dataDir . '/beers-' . $timestamp . '.json';

        if (!copy($beersFile, $backupFile)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create backup']);
            return;
        }
    }

    // Write the sanitized data (not the raw client input)
    $result = file_put_contents(
        $beersFile,
        json_encode($sanitizedBeers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($result === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to write beers.json']);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Data saved successfully',
        'backup' => isset($backupFile) ? basename($backupFile) : null,
        'beer_count' => count($sanitizedBeers)
    ]);
}

function handleListVersions($dataDir) {
    $versions = [];
    $files = glob($dataDir . '/beers-*.json');

    if ($files === false) {
        $files = [];
    }

    foreach ($files as $file) {
        $basename = basename($file);
        if (!preg_match('/^beers-(\d{8}_\d{6})\.json$/', $basename, $matches)) {
            continue;
        }

        $data = json_decode(file_get_contents($file), true);
        $beerCount = is_array($data) ? count($data) : 0;

        $versions[] = [
            'filename' => $basename,
            'timestamp' => $matches[1],
            'modified' => filemtime($file),
            'size' => filesize($file),
            'beer_count' => $beerCount
        ];
    }

    // Sort newest first
    usort($versions, function ($a, $b) {
        return $b['modified'] - $a['modified'];
    });

    echo json_encode(['status' => 'success', 'versions' => $versions]);
}

function handleGetVersionData($dataDir) {
    $filename = $_GET['filename'] ?? '';

    // Security: only allow expected filename pattern
    if (!preg_match('/^beers-\d{8}_\d{6}\.json$/', $filename)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid filename']);
        return;
    }

    $filePath = $dataDir . '/' . $filename;
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Version not found']);
        return;
    }

    $data = json_decode(file_get_contents($filePath), true);
    if ($data === null) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to parse version data']);
        return;
    }

    echo json_encode(['status' => 'success', 'data' => $data]);
}

function handleRestore($beersFile, $dataDir) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        return;
    }

    $jsonData = file_get_contents('php://input');
    $input = json_decode($jsonData, true);
    $filename = $input['filename'] ?? '';

    // Security: only allow expected filename pattern
    if (!preg_match('/^beers-\d{8}_\d{6}\.json$/', $filename)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid filename']);
        return;
    }

    $restoreFrom = $dataDir . '/' . $filename;
    if (!file_exists($restoreFrom)) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Version not found']);
        return;
    }

    // Validate the restore source is valid JSON array
    $restoreData = json_decode(file_get_contents($restoreFrom), true);
    if (!is_array($restoreData) || $restoreData !== array_values($restoreData)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Version file contains invalid data']);
        return;
    }

    // Validate every beer in the restore data
    $sanitizedRestore = [];
    foreach ($restoreData as $i => $beer) {
        [$sanitized, $error] = validateBeer($beer, $i);
        if ($error !== null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Restore data invalid: $error"]);
            return;
        }
        $sanitizedRestore[] = $sanitized;
    }
    $restoreData = $sanitizedRestore;

    // Backup current before restoring
    if (file_exists($beersFile)) {
        $timestamp = date('Ymd_His');
        $backupFile = $dataDir . '/beers-' . $timestamp . '.json';
        if (!copy($beersFile, $backupFile)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create backup before restore']);
            return;
        }
    }

    // Write the restored data
    $result = file_put_contents(
        $beersFile,
        json_encode($restoreData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($result === false) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to restore data']);
        return;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Version restored successfully',
        'backup' => isset($backupFile) ? basename($backupFile) : null,
        'beer_count' => count($restoreData)
    ]);
}
?>
