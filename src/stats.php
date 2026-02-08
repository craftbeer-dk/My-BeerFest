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
function calculateStats($ratingsPath, $consentPath, $targetSession = '') {
    $stats = array(
        'visitors' => array('total' => 0, 'yes' => 0, 'no' => 0),
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
    if (file_exists($consentPath)) {
        $handle = fopen($consentPath, "r");
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['session_id'])) {
                // Keep only the newest consent state for each visitor
                $visitorConsents[$entry['session_id']] = (isset($entry['consent']) && $entry['consent'] === true);
            }
        }
        fclose($handle);
    }
    
    $stats['visitors']['total'] = count($visitorConsents);
    foreach ($visitorConsents as $c) {
        if ($c) $stats['visitors']['yes']++;
        else $stats['visitors']['no']++;
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
            $beerAgg[$bid]['ratings'][] = $rating;
            $beerAgg[$bid]['count']++;

            if (!isset($brewAgg[$brewery])) {
                $brewAgg[$brewery] = array('name' => $brewery, 'ratings' => array(), 'count' => 0);
            }
            $brewAgg[$brewery]['ratings'][] = $rating;
            $brewAgg[$brewery]['count']++;
            
            $stats['engagement']['total_ratings']++;
        }
    }

    $stats['engagement']['beers_with_ratings'] = count($beerAgg);

    // 4. Mean Calculation and Sorting
    $processList = function($list) {
        foreach ($list as $key => &$val) {
            $val['avg'] = array_sum($val['ratings']) / count($val['ratings']);
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

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode(calculateStats($ratingsLogPath, $consentLogPath, $filterSession));
    exit;
}

$initialData = calculateStats($ratingsLogPath, $consentLogPath, $filterSession);
$festivalTitle = getenv('FESTIVAL_TITLE') ?: t('default_festival_title', 'My Beerfest');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Stats - <?php echo htmlspecialchars($festivalTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="config/theme.css">
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
                    <label for="session-select"><?php echo t('session', 'Session'); ?></label>
                    <select id="session-select" onchange="refreshData()">
                        <option value=""><?php echo t('all_sessions', 'All Sessions'); ?></option>
                        <?php foreach ($initialData['available_sessions'] as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>">
                                <?php echo htmlspecialchars($s); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center gap-4 md:ml-auto">
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



        <!-- Metric Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
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
            const url = `stats.php?format=json&session=${encodeURIComponent(session)}`;

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
         * Updates the DOM with calculated metrics.
         */
        function updateUI(data) {
            // Visitors
            document.getElementById('v-total').textContent = data.visitors.total;
            document.getElementById('v-yes').textContent = data.visitors.yes;
            document.getElementById('v-no').textContent = data.visitors.no;

            // Engagement
            document.getElementById('r-total').textContent = data.engagement.total_ratings;
            document.getElementById('r-users').textContent = data.engagement.unique_users;
            document.getElementById('r-beers').textContent = data.engagement.beers_with_ratings;

            // HTML-escape helper to prevent XSS via innerHTML
            const esc = (str) => {
                if (str == null) return '';
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(String(str)));
                return div.innerHTML;
            };

            // Highlights
            const fmt = (item, suffix) => {
                if (!item) return 'N/A';
                const score = item[suffix];
                const scoreText = (suffix === 'avg') ? score.toFixed(2) + ' ★' : score + ' ratings';
                const breweryLabel = item.brewery ? `<br><span class="text-xs font-normal opacity-70">${esc(item.brewery)}</span>` : '';
                return `<span>${esc(item.name)} (${scoreText})${breweryLabel}</span>`;
            };

            document.getElementById('h-beer').innerHTML = fmt(data.highlights.highest_beer, 'avg');
            document.getElementById('l-beer').innerHTML = fmt(data.highlights.lowest_beer, 'avg');
            document.getElementById('m-beer').innerHTML = fmt(data.highlights.most_rated_beer, 'count');
            
            document.getElementById('h-brew').innerHTML = fmt(data.highlights.highest_brewery, 'avg');
            document.getElementById('l-brew').innerHTML = fmt(data.highlights.lowest_brewery, 'avg');
            document.getElementById('m-brew').innerHTML = fmt(data.highlights.most_rated_brewery, 'count');

            // Top Performers Table
            const topTable = document.getElementById('top-table');
            topTable.innerHTML = '';
            Object.values(data.top_beers).forEach(b => {
                topTable.innerHTML += `
                    <tr class="border-b border-white/10 hover:bg-white/5">
                        <td class="py-3 font-semibold">${esc(b.name)}</td>
                        <td class="py-3 opacity-70">${esc(b.brewery)}</td>
                        <td class="py-3 text-center">${b.count}</td>
                        <td class="py-3 text-right font-bold text-palette-text-primary">${b.avg.toFixed(2)} ★</td>
                    </tr>`;
            });

            // Recent Activity Feed
            const recentTable = document.getElementById('recent-table');
            recentTable.innerHTML = '';
            data.recent_activity.forEach(r => {
                const timeStr = new Date(r.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                recentTable.innerHTML += `
                    <tr class="border-b border-white/10">
                        <td class="py-2 text-xs opacity-60">${esc(timeStr)}</td>
                        <td class="py-2 font-medium">${esc(r.beer_name)}</td>
                        <td class="py-2 opacity-70">${esc(r.brewery)}</td>
                        <td class="py-2 text-right font-bold">${r.rating.toFixed(2)}</td>
                    </tr>`;
            });
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