<?php
/**
 * stats.php - Festival Management Statistics Dashboard
 *
 * Handles server-side calculation of festival metrics from raw logs.
 * Features: 
 * - JSON API mode for seamless updates via AJAX.
 * - Deduplication based on session_id (only newest entries per user/beer count).
 * - Real-time aggregation of beers and breweries.
 * - Recent activity feed showing the 5 latest ratings.
 */

session_start();

// --- Configuration ---
$ratingsLogPath = '/var/log/mybeerfest/ratings.log';
$consentLogPath = '/var/log/mybeerfest/cookie_consent.log';
$appLanguage = getenv('APP_LANGUAGE') ?: 'da';
$festivalTitle = getenv('FESTIVAL_TITLE') ?: $translations['default_festival_title'];

// Load language configuration for consistent terminology
$langFile = __DIR__ . "/lang/{$appLanguage}.conf";
$translations = array();
if (file_exists($langFile)) {
    $translations = parse_ini_file($langFile);
}

/**
 * Safely retrieves and escapes translation strings.
 *
 * @param string $key The translation key.
 * @param string $default The fallback text.
 * @return string The escaped translation.
 */
function t($key, $default = '') {
    global $translations;
    return htmlspecialchars($translations[$key] ?? $default);
}

// --- Data Processing Logic ---

/**
 * Aggregates statistics from log files with strict deduplication and grouping.
 *
 * @param string $ratingsPath Path to the ratings log.
 * @param string $consentPath Path to the consent log.
 * @param string $targetSession Optional session filter (e.g., 'Fredag').
 * @return array The calculated statistics object.
 */
function calculateStats($ratingsPath, $consentPath, $targetSession = '', $excludedSessionIds = [], $deviceFilter = '') {
    $stats = array(
        'visitors' => array(
            'total' => 0,
            'yes' => 0,
            'no' => 0,
            'devices' => array('mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 0),
            'daily' => array(),
            'device_filter' => $deviceFilter
        ),
        'engagement' => array('total_ratings' => 0, 'unique_users' => 0, 'beers_with_ratings' => 0),
        'highlights' => array(
            'highest_beer' => null, 
            'lowest_beer' => null, 
            'most_rated_beer' => null, 
            'highest_brewery' => null, 
            'lowest_brewery' => null, 
            'most_rated_brewery' => null
        ),
        'recent_activity' => array(),
        'top_beers' => array(),
        'available_sessions' => array()
    );

    // 1. Process Visitor Logs (Deduplicate by session_id)
    $visitorConsents = array();
    $visitorDevices = array();
    $dailyVisitors = array();
    if (file_exists($consentPath)) {
        $handle = fopen($consentPath, "r");
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['session_id'])) {
                // Keep only the newest consent state for each visitor
                $visitorConsents[$entry['session_id']] = (isset($entry['consent']) && $entry['consent'] === true);
                $device = isset($entry['device_type']) ? $entry['device_type'] : 'unknown';
                if (!isset($stats['visitors']['devices'][$device])) $device = 'unknown';
                $visitorDevices[$entry['session_id']] = $device;
                if (isset($entry['timestamp']) && is_string($entry['timestamp'])) {
                    $day = substr($entry['timestamp'], 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                        $dailyVisitors[$day][$entry['session_id']] = true;
                    }
                }
            }
        }
        fclose($handle);
    }

    foreach ($visitorDevices as $device) {
        $stats['visitors']['devices'][$device]++;
    }

    $matchesDevice = function($sid) use ($visitorDevices, $deviceFilter) {
        return $deviceFilter === '' || (isset($visitorDevices[$sid]) && $visitorDevices[$sid] === $deviceFilter);
    };

    foreach ($visitorConsents as $sid => $c) {
        if (!$matchesDevice($sid)) continue;
        $stats['visitors']['total']++;
        if ($c) $stats['visitors']['yes']++;
        else $stats['visitors']['no']++;
    }

    try {
        $cursor = new DateTime('now', new DateTimeZone('UTC'));
    } catch (Exception $e) {
        $cursor = new DateTime('@' . time());
    }
    $cursor->modify('-13 days');
    for ($i = 0; $i < 14; $i++) {
        $day = $cursor->format('Y-m-d');
        $count = 0;
        if (isset($dailyVisitors[$day])) {
            foreach ($dailyVisitors[$day] as $sid => $_) {
                if ($matchesDevice($sid)) $count++;
            }
        }
        $stats['visitors']['daily'][] = array('date' => $day, 'count' => $count);
        $cursor->modify('+1 day');
    }

    // 2. Process Rating Logs (Deduplicate per user per beer)
    $deduplicatedRatings = array(); // [beer_id][session_id] = rating_entry
    $userSessions = array(); 
    $rawChronologicalRatings = array();

    if (file_exists($ratingsPath)) {
        $handle = fopen($ratingsPath, "r");
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!$entry) continue;

            $sess = isset($entry['session']) ? $entry['session'] : 'N/A';
            $stats['available_sessions'][$sess] = true;

            // Session Filtering
            if ($targetSession !== '' && $sess !== $targetSession) continue;

            $bid = isset($entry['beer_id']) ? $entry['beer_id'] : 'unknown';
            $sid = isset($entry['session_id']) ? $entry['session_id'] : 'anon';

            // Exclude flagged raters
            if (!empty($excludedSessionIds) && in_array($sid, $excludedSessionIds, true)) continue;
            
            // Deduplicate: User's latest rating for a specific beer overwrites previous ones
            $deduplicatedRatings[$bid][$sid] = $entry;
            $userSessions[$sid] = true;
            
            // Collect for "Last Rated" feed
            $rawChronologicalRatings[] = $entry;
        }
        fclose($handle);
    }

    $stats['recent_activity'] = array_slice(array_reverse($rawChronologicalRatings), 0, 5);
    $stats['engagement']['unique_users'] = count($userSessions);

    // 3. Aggregate Metrics for Beers and Breweries
    $beerAgg = array();
    $brewAgg = array();

    foreach ($deduplicatedRatings as $bid => $users) {
        foreach ($users as $sid => $data) {
            $rating = $data['rating'];
            $brewery = $data['brewery'];

            if (!isset($beerAgg[$bid])) {
                $beerAgg[$bid] = array('name' => $data['beer_name'], 'brewery' => $brewery, 'ratings' => array(), 'count' => 0);
            }
            if ($rating > 0) {
                $beerAgg[$bid]['ratings'][] = $rating;
            }
            $beerAgg[$bid]['count']++;

            if (!isset($brewAgg[$brewery])) {
                $brewAgg[$brewery] = array('name' => $brewery, 'ratings' => array(), 'count' => 0);
            }
            if ($rating > 0) {
                $brewAgg[$brewery]['ratings'][] = $rating;
            }
            $brewAgg[$brewery]['count']++;

            $stats['engagement']['total_ratings']++;
        }
    }

    $stats['engagement']['beers_with_ratings'] = count($beerAgg);

    // 4. Mean Calculation and Sorting
    $processList = function($list) {
        foreach ($list as $key => &$val) {
            $val['avg'] = count($val['ratings']) > 0
                ? array_sum($val['ratings']) / count($val['ratings'])
                : 0;
        }
        
        $byAvg = $list;
        uasort($byAvg, function($a, $b) { 
            return ($b['avg'] <=> $a['avg']) ?: ($b['count'] <=> $a['count']); 
        });
        
        $byCount = $list;
        uasort($byCount, function($a, $b) { 
            return ($b['count'] <=> $a['count']) ?: ($b['avg'] <=> $a['avg']); 
        });
        
        return array('avg' => $byAvg, 'count' => $byCount);
    };

    $beerResults = $processList($beerAgg);
    $brewResults = $processList($brewAgg);

    $stats['highlights']['highest_beer'] = !empty($beerResults['avg']) ? reset($beerResults['avg']) : null;
    $stats['highlights']['lowest_beer'] = !empty($beerResults['avg']) ? end($beerResults['avg']) : null;
    $stats['highlights']['most_rated_beer'] = !empty($beerResults['count']) ? reset($beerResults['count']) : null;

    $stats['highlights']['highest_brewery'] = !empty($brewResults['avg']) ? reset($brewResults['avg']) : null;
    $stats['highlights']['lowest_brewery'] = !empty($brewResults['avg']) ? end($brewResults['avg']) : null;
    $stats['highlights']['most_rated_brewery'] = !empty($brewResults['count']) ? reset($brewResults['count']) : null;

    $stats['top_beers'] = array_slice($beerResults['avg'], 0, 10);
    $stats['available_sessions'] = array_keys($stats['available_sessions']);

    return $stats;
}

// --- Controller logic ---
$filterSession = isset($_GET['session']) ? $_GET['session'] : '';
$excludeRaters = isset($_GET['exclude_raters']) && $_GET['exclude_raters'] === '1';

$deviceFilter = isset($_GET['device']) ? $_GET['device'] : '';
if (!in_array($deviceFilter, array('mobile', 'tablet', 'desktop', 'unknown'), true)) {
    $deviceFilter = '';
}

$excludedSessionIds = [];
if ($excludeRaters) {
    $excludedFile = '/var/www/html/data/excluded_raters.json';
    if (file_exists($excludedFile)) {
        $excludedData = json_decode(file_get_contents($excludedFile), true);
        if (is_array($excludedData)) {
            $excludedSessionIds = array_column($excludedData, 'session_id');
        }
    }
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    $stats = calculateStats($ratingsLogPath, $consentLogPath, $filterSession, $excludedSessionIds, $deviceFilter);
    $stats['exclude_raters_active'] = $excludeRaters;
    $stats['excluded_count'] = count($excludedSessionIds);
    echo json_encode($stats);
    exit;
}

$initialData = calculateStats($ratingsLogPath, $consentLogPath, $filterSession, $excludedSessionIds, $deviceFilter);
$festivalTitle = getenv('FESTIVAL_TITLE') ?: t('default_festival_title', 'My Beerfest');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Stats - <?php echo htmlspecialchars($festivalTitle); ?></title>
    <link rel="stylesheet" href="dist/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo file_exists(__DIR__ . '/custom/theme.css') ? 'custom/theme.css' : 'config/theme.css'; ?>">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: var(--background-color); color: var(--text-color); }
        .container { max-width: 1200px; margin: 0 auto; padding: 1rem; }
        
        /* Message Box Sync with index.php */
        .message-box {
            position: fixed;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            background-color: #10b981;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }
        .message-box.active {
            display: block;
            opacity: 1;
        }

        .stat-card { background-color: var(--card-background-color); border: 1px solid var(--card-border-color); border-radius: 0.5rem; padding: 1.5rem; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2.25rem; font-weight: 700; color: var(--palette-text-primary); display: block; }
        .stat-label { font-size: 0.75rem; color: var(--card-paragraph-color); text-transform: uppercase; font-weight: 600; }
        
        .section-heading { font-size: 1.5rem; font-weight: 600; color: var(--card-heading-color); margin: 0 0 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--divider-color); }
        .stats-group { margin-bottom: 2.5rem; }

        .highlight-section { background-color: var(--section-background-color); border: 1px solid var(--section-border-color); border-radius: 0.5rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .highlight-title { font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem; color: var(--card-heading-color); border-bottom: 1px solid var(--divider-color); padding-bottom: 0.5rem; }
        
        .data-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--divider-color); }
        .data-row:last-child { border-bottom: none; }

        /* Standard Controls — matching index.php filter-sort-section */
        label {
            font-weight: 600;
            display: block;
            margin-bottom: 0.5rem;
            color: var(--label-color);
        }

        select {
            padding: 0.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            transition: border-color 0.2s, box-shadow 0.2s;
            width: 100%;
            height: 36px;
        }

        select:focus {
            outline: none;
            border-color: var(--palette-text-primary);
            box-shadow: 0 0 3px 1px var(--palette-text-primary);
        }

        .btn { background: var(--button-primary-background-color); color: white; padding: 0 1.5rem; border-radius: 0.375rem; font-weight: 600; transition: background-color 0.2s; border: none; height: 36px; }
        .btn:hover { background-color: var(--button-primary-hover-bg); cursor: pointer; }

        .device-row { cursor: pointer; transition: background-color 0.15s; }
        .device-row:hover { background-color: rgba(255,255,255,0.06); }
        .device-row.active { background-color: rgba(229,237,144,0.14); }
        .device-row.active td:first-child { font-weight: 600; color: var(--palette-text-primary); }
        .filter-note { font-size: 0.875rem; font-weight: 400; color: var(--card-paragraph-color); }
        .filter-note button { color: var(--palette-link); cursor: pointer; margin-left: 0.25rem; }
    </style>
</head>
<body>
    <!-- Notification box for updates -->
    <div id="message-box" class="message-box">
        <?php echo t('beer_list_updated', 'Beer list updated!'); ?>
    </div>
    
    <div class="container">
        <h1 class="text-4xl font-bold text-center mb-6 p-4 rounded-lg shadow-lg" style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            Stats - <?php echo htmlspecialchars($festivalTitle); ?>
        </h1>

        <!-- Administrative Controls -->
        <div class="highlight-section mb-6">
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="w-full md:w-48">
                    <label for="session-select"><?php echo t('session', 'Session'); ?> (Raters)</label>
                    <select id="session-select" onchange="refreshData()">
                        <option value=""><?php echo t('all_sessions', 'All Sessions'); ?></option>
                        <?php foreach ($initialData['available_sessions'] as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>">
                                <?php echo htmlspecialchars($s); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="w-full md:w-48">
                    <label for="device-select">Device (Visitors)</label>
                    <select id="device-select" onchange="refreshData()">
                        <?php foreach (array('' => 'All Devices', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop', 'unknown' => 'Unknown') as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>"<?php echo $deviceFilter === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center gap-4 md:ml-auto flex-wrap">
                    <label for="exclude-raters" class="inline-flex items-center gap-2 cursor-pointer text-sm" style="margin: 0;">
                        <input type="checkbox" id="exclude-raters" class="w-4 h-4 rounded border-gray-300" onchange="refreshData()">
                        <span>Exclude flagged raters</span>
                    </label>
                    <label for="auto-reload" class="inline-flex items-center gap-2 cursor-pointer text-sm" style="margin: 0;">
                        <input type="checkbox" id="auto-reload" checked class="w-4 h-4 rounded border-gray-300">
                        <span>Auto-refresh (30s)</span>
                    </label>
                    <button class="btn whitespace-nowrap" onclick="refreshData()">
                        Manual Sync
                    </button>
                </div>
            </div>
        </div>



        <!-- ============ VISITORS ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Visitors <span id="visitor-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="stat-card">
                    <span id="v-total" class="stat-number">0</span>
                    <span class="stat-label">Total Unique Visitors</span>
                </div>
                <div class="stat-card border-green-500/30">
                    <span id="v-yes" class="stat-number text-green-500">0</span>
                    <span class="stat-label">Consent Given</span>
                </div>
                <div class="stat-card border-red-500/30">
                    <span id="v-no" class="stat-number text-red-500">0</span>
                    <span class="stat-label">Consent Refused</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="highlight-section" style="margin-bottom: 0; display: flex; flex-direction: column;">
                    <h3 class="highlight-title">Daily Visitors (last 14 days)</h3>
                    <div id="visitor-chart" class="overflow-x-auto" style="flex: 1; min-height: 220px;"></div>
                </div>

                <div class="highlight-section" style="margin-bottom: 0;">
                    <h3 class="highlight-title">Devices</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-white/20">
                                    <th class="py-2">Device</th>
                                    <th class="py-2 text-right">Visitors</th>
                                    <th class="py-2 text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="device-row border-b border-white/10" data-device="mobile" onclick="selectDevice('mobile')"><td class="py-2">Mobile</td><td class="py-2 text-right font-bold" id="v-mobile">0</td><td class="py-2 text-right opacity-70" id="v-mobile-pct">–</td></tr>
                                <tr class="device-row border-b border-white/10" data-device="tablet" onclick="selectDevice('tablet')"><td class="py-2">Tablet</td><td class="py-2 text-right font-bold" id="v-tablet">0</td><td class="py-2 text-right opacity-70" id="v-tablet-pct">–</td></tr>
                                <tr class="device-row border-b border-white/10" data-device="desktop" onclick="selectDevice('desktop')"><td class="py-2">Desktop</td><td class="py-2 text-right font-bold" id="v-desktop">0</td><td class="py-2 text-right opacity-70" id="v-desktop-pct">–</td></tr>
                                <tr class="device-row" data-device="unknown" onclick="selectDevice('unknown')"><td class="py-2">Unknown</td><td class="py-2 text-right font-bold" id="v-unknown">0</td><td class="py-2 text-right opacity-70" id="v-unknown-pct">–</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ RATERS ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Raters <span id="raters-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="stat-card">
                    <span id="r-total" class="stat-number">0</span>
                    <span class="stat-label">Total Ratings</span>
                </div>
                <div class="stat-card">
                    <span id="r-users" class="stat-number">0</span>
                    <span class="stat-label">Unique Users</span>
                </div>
                <div class="stat-card">
                    <span id="r-beers" class="stat-number">0</span>
                    <span class="stat-label">Beers Rated</span>
                </div>
            </div>

        <!-- Highlights Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="highlight-section">
                <h3 class="highlight-title">Beer Performance</h3>
                <div class="data-row"><span>Highest Rated Beer:</span><span id="h-beer" class="font-bold text-right">-</span></div>
                <div class="data-row"><span>Lowest Rated Beer:</span><span id="l-beer" class="font-bold text-right">-</span></div>
                <div class="data-row"><span>Most Rated Beer:</span><span id="m-beer" class="font-bold text-right">-</span></div>
            </div>
            <div class="highlight-section">
                <h3 class="highlight-title">Brewery Performance</h3>
                <div class="data-row"><span>Highest Rated Brewery:</span><span id="h-brew" class="font-bold text-right">-</span></div>
                <div class="data-row"><span>Lowest Rated Brewery:</span><span id="l-brew" class="font-bold text-right">-</span></div>
                <div class="data-row"><span>Most Rated Brewery:</span><span id="m-brew" class="font-bold text-right">-</span></div>
            </div>
        </div>

        <!-- Leaderboard Table -->
        <div class="highlight-section">
            <h3 class="highlight-title">Top 10 Performers</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-white/20">
                            <th class="py-2">Beer</th>
                            <th class="py-2">Brewery</th>
                            <th class="py-2 text-center">Ratings</th>
                            <th class="py-2 text-right">Mean Rating</th>
                        </tr>
                    </thead>
                    <tbody id="top-table"></tbody>
                </table>
            </div>
        </div>

        <!-- Recent Activity Feed -->
        <div class="highlight-section">
            <h3 class="highlight-title">5 Last Rated</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-white/20">
                            <th class="py-2">Time</th>
                            <th class="py-2">Beer</th>
                            <th class="py-2">Brewery</th>
                            <th class="py-2 text-right">Score</th>
                        </tr>
                    </thead>
                    <tbody id="recent-table"></tbody>
                </table>
            </div>
        </div>
        </div>
    </div>

    <script>
        const messageBox = document.getElementById('message-box');

        /**
         * Displays the notification box briefly.
         */
        function showNotification() {
            messageBox.classList.add('active');
            setTimeout(() => {
                messageBox.classList.remove('active');
            }, 3000);
        }

        /**
         * Fetches new statistics in JSON format and triggers a UI update.
         */
        async function refreshData() {
            const session = document.getElementById('session-select').value;
            const excludeRaters = document.getElementById('exclude-raters').checked ? '1' : '0';
            const device = document.getElementById('device-select').value;
            const url = `stats.php?format=json&session=${encodeURIComponent(session)}&exclude_raters=${excludeRaters}&device=${encodeURIComponent(device)}`;

            try {
                const response = await fetch(url);
                const data = await response.json();
                updateUI(data);
                showNotification();
            } catch (e) {
                console.error("Refresh failed", e);
            }
        }

        /**
         * Filters the Visitors section to a device type.
         */
        function selectDevice(key) {
            const select = document.getElementById('device-select');
            select.value = (select.value === key) ? '' : key;
            refreshData();
        }

        /**
         * Renders the visitor trend as an inline SVG line chart.
         */
        function renderVisitorChart(series) {
            const host = document.getElementById('visitor-chart');
            if (!host) return;
            const data = Array.isArray(series) ? series : [];
            if (data.length === 0) { host.innerHTML = ''; return; }

            const prevLeft = host.scrollLeft;
            const prevMax = host.scrollWidth - host.clientWidth;
            const pinRight = prevMax <= 0 || prevLeft >= prevMax - 2;

            const W = 760, H = 240;
            const padL = 32, padR = 12, padT = 16, padB = 28;
            const plotW = W - padL - padR;
            const plotH = H - padT - padB;
            const n = data.length;
            const counts = data.map(d => Number(d.count) || 0);
            const maxY = Math.max(...counts, 1);
            const peakVal = Math.max(...counts);
            const peakIdx = counts.indexOf(peakVal);
            const hasPeak = peakVal > 0;

            const px = i => padL + (n === 1 ? plotW / 2 : plotW * i / (n - 1));
            const py = c => padT + plotH - (plotH * c / maxY);
            const fmtDate = ds => {
                const p = String(ds).split('-');
                return p.length === 3 ? p[2] + '/' + p[1] : ds;
            };
            const anchorFor = i => i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle');

            const linePts = counts.map((c, i) => px(i).toFixed(1) + ',' + py(c).toFixed(1)).join(' ');
            const baseY = (padT + plotH).toFixed(1);
            const areaPts = padL + ',' + baseY + ' ' + linePts + ' ' + px(n - 1).toFixed(1) + ',' + baseY;

            let svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" '
                + 'preserveAspectRatio="xMidYMid meet" style="min-width:520px;width:100%;height:100%;display:block;">';

            [0].forEach(v => {
                const y = py(v).toFixed(1);
                svg += '<line x1="' + padL + '" y1="' + y + '" x2="' + (W - padR) + '" y2="' + y
                    + '" stroke="var(--divider-color)" stroke-width="1" opacity="0.4"/>';
                svg += '<text x="' + (padL - 6) + '" y="' + y + '" text-anchor="end" dominant-baseline="middle" '
                    + 'font-size="11" fill="var(--card-paragraph-color)">' + v + '</text>';
            });

            if (hasPeak) {
                const xp = px(peakIdx).toFixed(1);
                svg += '<line x1="' + xp + '" y1="' + py(peakVal).toFixed(1) + '" x2="' + xp + '" y2="'
                    + (padT + plotH) + '" stroke="var(--palette-text-primary)" stroke-width="1" '
                    + 'stroke-dasharray="3 3" opacity="0.5"/>';
            }

            svg += '<polygon points="' + areaPts + '" fill="var(--palette-text-primary)" opacity="0.12"/>';
            svg += '<polyline points="' + linePts + '" fill="none" stroke="var(--palette-text-primary)" '
                + 'stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

            data.forEach((d, i) => {
                const isPeak = hasPeak && i === peakIdx;
                const cx = px(i).toFixed(1);
                const cy = py(counts[i]).toFixed(1);
                svg += '<circle cx="' + cx + '" cy="' + cy + '" '
                    + 'r="' + (isPeak ? 4 : 2.5) + '" fill="var(--palette-text-primary)"'
                    + (isPeak ? ' stroke="var(--card-background-color)" stroke-width="1.5"' : '')
                    + '><title>' + fmtDate(d.date) + ': ' + counts[i] + '</title></circle>';
                const ly = Math.max(py(counts[i]) - 8, 11);
                svg += '<text x="' + cx + '" y="' + ly.toFixed(1) + '" text-anchor="' + anchorFor(i) + '" '
                    + 'font-size="' + (isPeak ? 11 : 10) + '" '
                    + (isPeak ? 'font-weight="600" fill="var(--palette-text-primary)"'
                              : 'fill="var(--card-paragraph-color)"')
                    + '>' + counts[i] + '</text>';
            });

            const step = Math.max(1, Math.round(n / 7));
            const forced = hasPeak ? [0, n - 1, peakIdx] : [0, n - 1];
            const ticks = new Set(forced);
            for (let i = step; i < n - 1; i += step) {
                if (forced.every(f => Math.abs(f - i) > 1)) ticks.add(i);
            }
            const labelIdx = Array.from(ticks).sort((a, b) => a - b);
            labelIdx.forEach(i => {
                const isPeak = hasPeak && i === peakIdx;
                svg += '<text x="' + px(i).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="' + anchorFor(i) + '" font-size="11" '
                    + (isPeak ? 'font-weight="600" ' : '')
                    + 'fill="var(--card-paragraph-color)">' + fmtDate(data[i].date) + '</text>';
            });

            svg += '</svg>';
            host.innerHTML = svg;

            const applyScroll = () => {
                const newMax = host.scrollWidth - host.clientWidth;
                if (newMax > 0) host.scrollLeft = pinRight ? newMax : Math.min(prevLeft, newMax);
            };
            applyScroll();
            if (typeof requestAnimationFrame === 'function') requestAnimationFrame(applyScroll);
        }

        /**
         * Updates the DOM with calculated metrics.
         */
        function updateUI(data) {
            // Visitors
            document.getElementById('v-total').textContent = data.visitors.total;
            document.getElementById('v-yes').textContent = data.visitors.yes;
            document.getElementById('v-no').textContent = data.visitors.no;

            // Devices
            const devices = data.visitors.devices || {};
            const deviceTotal = Object.values(devices).reduce((a, b) => a + (Number(b) || 0), 0);
            ['mobile', 'tablet', 'desktop', 'unknown'].forEach(key => {
                const count = Number(devices[key]) || 0;
                document.getElementById('v-' + key).textContent = count;
                document.getElementById('v-' + key + '-pct').textContent =
                    deviceTotal > 0 ? Math.round(count / deviceTotal * 100) + '%' : '–';
            });

            const activeDevice = data.visitors.device_filter || '';
            document.getElementById('device-select').value = activeDevice;
            const labels = { mobile: 'Mobile', tablet: 'Tablet', desktop: 'Desktop', unknown: 'Unknown' };
            document.querySelectorAll('.device-row').forEach(row => {
                row.classList.toggle('active', row.dataset.device === activeDevice);
            });
            const note = document.getElementById('visitor-filter-note');
            note.textContent = '';
            if (activeDevice) {
                note.append('— ' + (labels[activeDevice] || activeDevice) + ' only ');
                const clear = document.createElement('button');
                clear.type = 'button';
                clear.textContent = '(clear)';
                clear.onclick = () => selectDevice(activeDevice);
                note.append(clear);
            }

            renderVisitorChart(data.visitors.daily || []);

            // Engagement
            document.getElementById('r-total').textContent = data.engagement.total_ratings;
            document.getElementById('r-users').textContent = data.engagement.unique_users;
            document.getElementById('r-beers').textContent = data.engagement.beers_with_ratings;

            // Raters filter note
            const rSession = document.getElementById('session-select').value;
            const rExclude = document.getElementById('exclude-raters').checked;
            const rNote = document.getElementById('raters-filter-note');
            rNote.textContent = '';
            const rParts = [];
            if (rSession) rParts.push(rSession);
            if (rExclude) rParts.push('excl. flagged');
            if (rParts.length) {
                rNote.append('— ' + rParts.join(', ') + ' ');
                const rClear = document.createElement('button');
                rClear.type = 'button';
                rClear.textContent = '(clear)';
                rClear.onclick = () => {
                    document.getElementById('session-select').value = '';
                    document.getElementById('exclude-raters').checked = false;
                    refreshData();
                };
                rNote.append(rClear);
            }

            // Highlights — safe DOM construction (no innerHTML with user data)
            const setHighlight = (elId, item, suffix) => {
                const el = document.getElementById(elId);
                el.textContent = '';
                if (!item) { el.textContent = 'N/A'; return; }
                const score = Number(item[suffix]);
                const scoreText = (suffix === 'avg') ? score.toFixed(2) + ' \u2605' : score + ' ratings';
                const nameSpan = document.createElement('span');
                nameSpan.textContent = (item.name || '') + ' (' + scoreText + ')';
                el.appendChild(nameSpan);
                if (item.brewery) {
                    el.appendChild(document.createElement('br'));
                    const brewSpan = document.createElement('span');
                    brewSpan.className = 'text-xs font-normal opacity-70';
                    brewSpan.textContent = item.brewery;
                    el.appendChild(brewSpan);
                }
            };

            setHighlight('h-beer', data.highlights.highest_beer, 'avg');
            setHighlight('l-beer', data.highlights.lowest_beer, 'avg');
            setHighlight('m-beer', data.highlights.most_rated_beer, 'count');

            setHighlight('h-brew', data.highlights.highest_brewery, 'avg');
            setHighlight('l-brew', data.highlights.lowest_brewery, 'avg');
            setHighlight('m-brew', data.highlights.most_rated_brewery, 'count');

            // Top Performers Table — safe DOM construction
            const topTable = document.getElementById('top-table');
            topTable.innerHTML = '';
            const topFrag = document.createDocumentFragment();
            Object.values(data.top_beers).forEach(b => {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-white/10 hover:bg-white/5';
                const cells = [
                    ['py-3 font-semibold', b.name],
                    ['py-3 opacity-70', b.brewery],
                    ['py-3 text-center', String(Number(b.count))],
                    ['py-3 text-right font-bold text-palette-text-primary', Number(b.avg).toFixed(2) + ' \u2605']
                ];
                cells.forEach(([cls, text]) => {
                    const td = document.createElement('td');
                    td.className = cls;
                    td.textContent = text;
                    tr.appendChild(td);
                });
                topFrag.appendChild(tr);
            });
            topTable.appendChild(topFrag);

            // Recent Activity Feed — safe DOM construction
            const recentTable = document.getElementById('recent-table');
            recentTable.innerHTML = '';
            const recentFrag = document.createDocumentFragment();
            data.recent_activity.forEach(r => {
                const timeStr = new Date(r.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const tr = document.createElement('tr');
                tr.className = 'border-b border-white/10';
                const rating = Number(r.rating);
                const cells = [
                    ['py-2 text-xs opacity-60', timeStr],
                    ['py-2 font-medium', r.beer_name],
                    ['py-2 opacity-70', r.brewery],
                    ['py-2 text-right font-bold', rating > 0 ? rating.toFixed(2) : 'No rating']
                ];
                cells.forEach(([cls, text]) => {
                    const td = document.createElement('td');
                    td.className = cls;
                    td.textContent = text;
                    tr.appendChild(td);
                });
                recentFrag.appendChild(tr);
            });
            recentTable.appendChild(recentFrag);
        }

        // Initialize display and set background refresh interval
        updateUI(<?php echo json_encode($initialData); ?>);
        setInterval(() => {
            if (document.getElementById('auto-reload').checked) refreshData();
        }, 30000);
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
echo preg_replace('/<!--[\s\S]*?-->/', '', $html);
?>