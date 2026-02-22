<?php
// admin_api.php - Backend API for admin beer data management

header('Content-Type: application/json');

// CSRF protection: reject cross-origin requests.
// Admin API is same-origin only — no CORS headers are emitted.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    // An Origin header is present — verify it's same-origin or matches DOMAIN.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $allowed = false;

    // Same-origin: Origin matches the Host header of this request
    if ($host !== '') {
        $allowed = ($origin === 'https://' . $host)
                || ($origin === 'http://' . $host);
    }

    // Also allow the explicitly configured DOMAIN
    if (!$allowed) {
        $allowedOrigin = getenv('DOMAIN');
        if ($allowedOrigin) {
            $allowed = ($origin === 'https://' . $allowedOrigin)
                    || ($origin === 'http://' . $allowedOrigin)
                    || ($origin === $allowedOrigin);
        }
    }

    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Cross-origin request denied']);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // No CORS headers — preflight will fail for cross-origin requests
    http_response_code(403);
    exit();
}

// CSRF: Require X-Requested-With header on mutation requests.
// Browsers block cross-origin custom headers unless CORS preflight allows them,
// and this API emits no CORS headers, so cross-origin POSTs cannot set this header.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if ($xrw !== 'XMLHttpRequest') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: missing required header']);
        exit();
    }
}

$dataDir = '/var/www/html/data';
$beersFile = $dataDir . '/beers.json';
$excludedRatersFile = $dataDir . '/excluded_raters.json';
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
    case 'untappd_lookup':
        handleUntappdLookup();
        break;
    case 'bad_raters':
        handleBadRaters($excludedRatersFile);
        break;
    case 'exclude_rater':
        handleExcludeRater($excludedRatersFile);
        break;
    case 'excluded_raters':
        handleGetExcludedRaters($excludedRatersFile);
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
        'note'     => ['type' => 'string', 'required' => false, 'max_length' => 200],
        'alc'      => ['type' => 'number', 'required' => false, 'min' => 0, 'max' => 100],
        'rating'      => ['type' => 'number', 'required' => false, 'min' => 0, 'max' => 5],
        'untappd'     => ['type' => 'url',    'required' => false, 'max_length' => 500],
        'last_lookup' => ['type' => 'number', 'required' => false, 'min' => 0, 'max' => 9999999999],
        'last_update' => ['type' => 'number', 'required' => false, 'min' => 0, 'max' => 9999999999],
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
// --- Untappd Lookup ---

// SSRF protection: only allow requests to https://untappd.com
function validateUntappdUrl($url) {
    if (!is_string($url) || empty($url)) return false;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $parsed = parse_url($url);
    if ($parsed === false) return false;

    // HTTPS only
    if (($parsed['scheme'] ?? '') !== 'https') return false;

    // Host must be exactly untappd.com (prevents untappd.com.evil.com etc.)
    if (($parsed['host'] ?? '') !== 'untappd.com') return false;

    // No userinfo (user:pass@untappd.com)
    if (isset($parsed['user']) || isset($parsed['pass'])) return false;

    // No port override
    if (isset($parsed['port'])) return false;

    return true;
}

function handleUntappdLookup() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        return;
    }

    $jsonData = file_get_contents('php://input');
    if (strlen($jsonData) > 100 * 1024) {
        http_response_code(413);
        echo json_encode(['status' => 'error', 'message' => 'Payload too large']);
        return;
    }

    $input = json_decode($jsonData, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input) || $input !== array_values($input)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON: expected an array']);
        return;
    }

    // Limit to 50 beers per request to avoid long-running requests
    if (count($input) > 50) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Max 50 beers per lookup request']);
        return;
    }

    // Load beer data from server-side source (never trust client for this)
    $beersFile = '/var/www/html/data/beers.json';
    $beersData = [];
    if (file_exists($beersFile)) {
        $beersData = json_decode(file_get_contents($beersFile), true) ?: [];
    }
    $beersById = [];
    foreach ($beersData as $beer) {
        if (isset($beer['id'])) {
            $beersById[$beer['id']] = $beer;
        }
    }

    $results = [];
    $lookedUpIds = [];
    foreach ($input as $i => $item) {
        if (!is_array($item)) {
            $results[] = ['id' => '', 'found' => false, 'error' => 'Invalid item'];
            continue;
        }

        // Only accept 'id' and optional 'manual_url' from the client
        $id = $item['id'] ?? '';
        if (!is_string($id) || empty($id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
            $results[] = ['id' => $id, 'found' => false, 'error' => 'Invalid beer ID'];
            continue;
        }

        // Look up beer in server-side data
        if (!isset($beersById[$id])) {
            $results[] = ['id' => $id, 'found' => false, 'error' => 'Beer not found'];
            continue;
        }

        $beer = $beersById[$id];
        $name = trim($beer['name'] ?? '');
        $brewery = trim($beer['brewery'] ?? '');
        $currentRating = $beer['rating'] ?? null;

        if (empty($name)) {
            $results[] = ['id' => $id, 'found' => false, 'error' => 'Beer has no name'];
            continue;
        }

        // Determine the Untappd URL: client can supply a manual_url (validated), else use the beer's existing one
        $untappdUrl = '';
        $manualUrl = trim($item['manual_url'] ?? '');
        if (!empty($manualUrl)) {
            if (!validateUntappdUrl($manualUrl) || !preg_match('#^https://untappd\.com/b/#', $manualUrl)) {
                $results[] = ['id' => $id, 'found' => false, 'error' => 'Invalid Untappd URL (must be https://untappd.com/b/...)'];
                continue;
            }
            $untappdUrl = $manualUrl;
        } else {
            $untappdUrl = trim($beer['untappd'] ?? '');
        }

        if (!empty($untappdUrl) && validateUntappdUrl($untappdUrl) && preg_match('#^https://untappd\.com/b/#', $untappdUrl)) {
            // Path A: Has Untappd URL — fetch detail page
            $result = lookupByDetailPage($untappdUrl, $id, $currentRating);
        } else {
            // Path B: No URL — search Untappd
            $result = lookupBySearch($name, $brewery, $id, $currentRating);
        }

        $results[] = $result;
        $lookedUpIds[] = $id;
    }

    // Write last_lookup timestamps directly to beers.json (not a user-facing change)
    if (!empty($lookedUpIds)) {
        $now = time();
        $dirty = false;
        foreach ($beersData as &$b) {
            if (in_array($b['id'] ?? '', $lookedUpIds, true)) {
                $b['last_lookup'] = $now;
                $dirty = true;
            }
        }
        unset($b);
        if ($dirty) {
            file_put_contents(
                $beersFile,
                json_encode($beersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
    }

    echo json_encode(['status' => 'success', 'results' => $results]);
}

// Rate limiter: pauses 10s after every 5 outbound fetches within a 30s window.
// Uses a temp file to track timestamps across requests.
function throttleUntappdRequest() {
    $rateFile = '/tmp/mybeerfest_untappd_rate.json';
    $now = microtime(true);
    $window = (int)(getenv('UNTAPPD_RATE_WINDOW') ?: 30);
    $maxInWindow = (int)(getenv('UNTAPPD_RATE_MAX') ?: 5);
    $cooldown = (int)(getenv('UNTAPPD_RATE_COOLDOWN') ?: 10);

    $timestamps = [];
    if (file_exists($rateFile)) {
        $raw = file_get_contents($rateFile);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            // Keep only timestamps within the window
            $timestamps = array_values(array_filter($data, function ($t) use ($now, $window) {
                return ($now - $t) < $window;
            }));
        }
    }

    if (count($timestamps) >= $maxInWindow) {
        sleep($cooldown);
        $timestamps = []; // Reset after cooldown
    }

    $timestamps[] = $now;
    file_put_contents($rateFile, json_encode($timestamps), LOCK_EX);
}

function fetchUntappdPage($url) {
    // Validate URL before fetching (defense in depth — callers should also validate)
    if (!validateUntappdUrl($url)) {
        return null;
    }

    // Enforce rate limit before every outbound fetch
    throttleUntappdRequest();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,  // Allow redirects (Untappd often 301s on slug changes)
        CURLOPT_MAXREDIRS => 3,          // Limit redirect chain length
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, // Only allow HTTPS protocol
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, // Redirects must also be HTTPS
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($httpCode !== 200 || $html === false) {
        return null;
    }

    // SSRF check: verify the final URL after redirects still points to untappd.com
    if (!validateUntappdUrl($effectiveUrl)) {
        return null;
    }

    return ['html' => $html, 'effective_url' => $effectiveUrl];
}

function parseDetailPage($html) {
    // Extract JSON-LD structured data
    if (!preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $html, $match)) {
        return null;
    }

    $data = json_decode($match[1], true);
    if (!is_array($data)) {
        return null;
    }

    $result = ['name' => null, 'rating' => null];

    if (isset($data['name'])) {
        $result['name'] = $data['name'];
    }

    if (isset($data['aggregateRating']['ratingValue'])) {
        $result['rating'] = round((float) $data['aggregateRating']['ratingValue'], 2);
    }

    return $result;
}

function parseSearchResults($html) {
    $results = [];

    // Match each beer-item block. Use a lookahead to stop at the next beer-item or end markers.
    preg_match_all('/<div class="beer-item\s*">(.*?)(?=<div class="beer-item|<div class="results-list-top|<div class="add-beer|<\/div>\s*<\/div>\s*<\/div>\s*$)/s', $html, $blocks);

    foreach ($blocks[1] as $block) {
        $entry = ['name' => '', 'brewery' => '', 'url' => '', 'rating' => null, 'style' => '', 'abv' => null];

        if (preg_match('/<p class="name"><a href="([^"]+)">([^<]+)<\/a>/', $block, $m)) {
            $entry['url'] = 'https://untappd.com' . $m[1];
            $entry['name'] = html_entity_decode(trim($m[2]), ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<p class="brewery"><a[^>]*>([^<]+)<\/a>/', $block, $m)) {
            $entry['brewery'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/data-rating="([^"]+)"/', $block, $m)) {
            $entry['rating'] = round((float) $m[1], 2);
        }

        if (preg_match('/<p class="style">([^<]+)<\/p>/', $block, $m)) {
            $entry['style'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        if (preg_match('/<p class="abv">\s*([\d.]+)%/', $block, $m)) {
            $entry['abv'] = (float) $m[1];
        }

        if (!empty($entry['name'])) {
            $results[] = $entry;
        }
    }

    return $results;
}

function normalizeString($str) {
    $str = mb_strtolower($str, 'UTF-8');
    // Transliterate common accented chars
    $str = strtr($str, [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'ø' => 'o', 'å' => 'a', 'æ' => 'ae',
        'ñ' => 'n', 'ç' => 'c', 'ð' => 'd', 'þ' => 'th',
    ]);
    // Remove non-alphanumeric
    $str = preg_replace('/[^a-z0-9\s]/', '', $str);
    // Collapse whitespace
    $str = preg_replace('/\s+/', ' ', trim($str));
    return $str;
}

function matchScore($searchName, $searchBrewery, $resultName, $resultBrewery) {
    $sn = normalizeString($searchName);
    $rn = normalizeString($resultName);
    $sb = normalizeString($searchBrewery);
    $rb = normalizeString($resultBrewery);

    // Name similarity (60% weight)
    similar_text($sn, $rn, $namePercent);

    // Brewery similarity (40% weight)
    $breweryPercent = 0;
    if (!empty($sb) && !empty($rb)) {
        similar_text($sb, $rb, $breweryPercent);
    } elseif (empty($sb) && empty($rb)) {
        $breweryPercent = 100;
    }

    return round($namePercent * 0.6 + $breweryPercent * 0.4);
}

function lookupByDetailPage($untappdUrl, $beerId, $currentRating) {
    $fetch = fetchUntappdPage($untappdUrl);
    if ($fetch === null) {
        return [
            'id' => $beerId,
            'lookup_type' => 'detail',
            'found' => false,
            'error' => 'Failed to fetch Untappd page',
            'untappd_url' => $untappdUrl,
        ];
    }

    $parsed = parseDetailPage($fetch['html']);
    if ($parsed === null || $parsed['rating'] === null) {
        return [
            'id' => $beerId,
            'lookup_type' => 'detail',
            'found' => false,
            'error' => 'Could not parse rating from page',
            'untappd_url' => $untappdUrl,
        ];
    }

    $ratingChanged = ($currentRating === null || round((float) $currentRating, 2) !== $parsed['rating']);

    $result = [
        'id' => $beerId,
        'lookup_type' => 'detail',
        'found' => true,
        'untappd_url' => $untappdUrl,
        'untappd_rating' => $parsed['rating'],
        'untappd_name' => $parsed['name'],
        'confidence' => 100,
        'current_rating' => $currentRating,
        'rating_changed' => $ratingChanged,
    ];

    // Flag when Untappd redirected to a different canonical URL
    $resolvedUrl = $fetch['effective_url'];
    if ($resolvedUrl !== $untappdUrl) {
        $result['redirected_url'] = $resolvedUrl;
    }

    return $result;
}

function lookupBySearch($name, $brewery, $beerId, $currentRating) {
    $query = $name;
    if (!empty($brewery)) {
        $query .= ' ' . $brewery;
    }

    $searchUrl = 'https://untappd.com/search?q=' . urlencode($query) . '&type=beer&sort=all';
    $fetch = fetchUntappdPage($searchUrl);

    if ($fetch === null) {
        return [
            'id' => $beerId,
            'lookup_type' => 'search',
            'found' => false,
            'error' => 'Failed to fetch search results',
            'search_url' => $searchUrl,
        ];
    }

    $searchResults = parseSearchResults($fetch['html']);

    if (empty($searchResults)) {
        return [
            'id' => $beerId,
            'lookup_type' => 'search',
            'found' => false,
            'error' => 'No results found',
            'search_url' => $searchUrl,
        ];
    }

    // Score each result and find the best match
    $bestScore = 0;
    $bestMatch = null;
    foreach ($searchResults as $sr) {
        $score = matchScore($name, $brewery, $sr['name'], $sr['brewery']);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $sr;
        }
    }

    if ($bestMatch === null) {
        return [
            'id' => $beerId,
            'lookup_type' => 'search',
            'found' => false,
            'error' => 'No matching results',
            'search_url' => $searchUrl,
        ];
    }

    $ratingChanged = ($currentRating === null || round((float) $currentRating, 2) !== $bestMatch['rating']);

    return [
        'id' => $beerId,
        'lookup_type' => 'search',
        'found' => true,
        'untappd_url' => $bestMatch['url'],
        'untappd_rating' => $bestMatch['rating'],
        'untappd_name' => $bestMatch['name'],
        'untappd_brewery' => $bestMatch['brewery'],
        'untappd_style' => $bestMatch['style'],
        'confidence' => $bestScore,
        'current_rating' => $currentRating,
        'rating_changed' => $ratingChanged,
        'search_url' => $searchUrl,
    ];
}

// ── Bad Rater Detection ─────────────────────────────────────────────

function handleBadRaters($excludedRatersFile) {
    require_once __DIR__ . '/bad_rater_engine.php';

    $ratingsLogPath = '/var/log/mybeerfest/ratings.log';

    if (!file_exists($ratingsLogPath)) {
        echo json_encode([
            'status'  => 'success',
            'flagged' => new \stdClass(),
            'summary' => [
                'total_sessions_analyzed' => 0,
                'total_flagged'           => 0,
                'pattern_counts'          => new \stdClass(),
            ],
        ]);
        return;
    }

    $results = detectBadRaters($ratingsLogPath);

    // Merge exclusion status
    $excluded    = loadExcludedRaters($excludedRatersFile);
    $excludedIds = array_column($excluded, 'session_id');

    foreach ($results['flagged'] as &$flagged) {
        $flagged['excluded'] = in_array($flagged['session_id'], $excludedIds, true);
    }
    unset($flagged);

    // Ensure empty objects serialise as {} not []
    if (empty($results['flagged'])) {
        $results['flagged'] = new \stdClass();
    }
    if (empty($results['summary']['pattern_counts'])) {
        $results['summary']['pattern_counts'] = new \stdClass();
    }

    echo json_encode(['status' => 'success'] + $results);
}

function handleExcludeRater($excludedRatersFile) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        return;
    }

    $input     = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? '';
    $exclude   = $input['exclude']    ?? true;
    $patterns  = $input['patterns']   ?? [];

    if (!is_string($sessionId) || $sessionId === '' || mb_strlen($sessionId) > 200) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid session_id']);
        return;
    }

    $excluded = loadExcludedRaters($excludedRatersFile);

    if ($exclude) {
        $existingIds = array_column($excluded, 'session_id');
        if (!in_array($sessionId, $existingIds, true)) {
            $excluded[] = [
                'session_id'  => $sessionId,
                'excluded_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'patterns'    => array_map('strval', array_slice((array) $patterns, 0, 10)),
            ];
        }
    } else {
        $excluded = array_values(array_filter($excluded, function ($e) use ($sessionId) {
            return $e['session_id'] !== $sessionId;
        }));
    }

    saveExcludedRaters($excludedRatersFile, $excluded);
    echo json_encode(['status' => 'success', 'excluded_count' => count($excluded)]);
}

function handleGetExcludedRaters($excludedRatersFile) {
    $excluded = loadExcludedRaters($excludedRatersFile);
    echo json_encode(['status' => 'success', 'excluded' => $excluded]);
}

function loadExcludedRaters($filePath) {
    if (!file_exists($filePath)) return [];
    $data = json_decode(file_get_contents($filePath), true);
    return is_array($data) ? $data : [];
}

function saveExcludedRaters($filePath, $data) {
    file_put_contents(
        $filePath,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}
?>
