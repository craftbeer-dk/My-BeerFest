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
require_once __DIR__ . '/beer_catalog.php';

// --- Configuration ---
$ratingsLogPath = '/var/log/mybeerfest/ratings.log';
$consentLogPath = '/var/log/mybeerfest/cookie_consent.log';
$eventsLogPath = '/var/log/mybeerfest/events.log';
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
 * Groups visits into the viewer's local hours (a single day), days (up to 31 days) or weeks
 * (Monday-based), covering the whole window or, for all time, the first to the last visit.
 * Each bucket has browser and installed-app visits and its unique visitors.
 */
function usageBuckets($opens, $window, $tz) {
    $local = function ($ms) use ($tz) { return (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($tz); };
    $times = array_column($opens, 0);
    if ($window !== null) {
        $first = $local($window[0]);
        $last = $local($window[1] - 1);
    } elseif ($times) {
        $first = $local(min($times));
        $last = $local(max($times));
    } else {
        $first = $last = $local(time() * 1000);
    }
    $firstDay = $first->setTime(0, 0);
    $lastDay = $last->setTime(0, 0);
    $days = $firstDay->diff($lastDay)->days + 1;
    $bucket = $days <= 1 ? 'hour' : ($days <= 31 ? 'day' : 'week');
    $monday = function ($d) { return $d->setISODate((int) $d->format('o'), (int) $d->format('W'))->setTime(0, 0); };
    $keyOf = function ($d) use ($bucket, $monday) {
        if ($bucket === 'hour') return $d->format('Y-m-d\TH');
        return ($bucket === 'week' ? $monday($d) : $d)->format('Y-m-d');
    };

    $buckets = array();
    if ($bucket === 'hour') {
        for ($h = 0; $h < 24; $h++) $buckets[$firstDay->format('Y-m-d') . 'T' . sprintf('%02d', $h)] = array(0, 0, array());
    } else {
        $step = new DateInterval($bucket === 'week' ? 'P7D' : 'P1D');
        $end = $bucket === 'week' ? $monday($lastDay) : $lastDay;
        for ($d = $bucket === 'week' ? $monday($firstDay) : $firstDay; $d <= $end; $d = $d->add($step)) {
            $buckets[$d->format('Y-m-d')] = array(0, 0, array());
        }
    }
    foreach ($opens as $open) {
        $key = $keyOf($local($open[0]));
        if (!isset($buckets[$key])) continue;
        $buckets[$key][$open[1] ? 1 : 0]++;
        $buckets[$key][2][$open[2]] = true;
    }
    $out = array();
    foreach ($buckets as $key => $b) {
        $out[] = array('key' => $key, 'browser' => $b[0], 'standalone' => $b[1], 'unique' => count($b[2]));
    }
    return array($bucket, $out);
}

/**
 * Aggregates statistics from log files with strict deduplication and grouping.
 *
 * @param string $ratingsPath Path to the ratings log.
 * @param string $consentPath Path to the consent log.
 * @param string $targetSession Optional session filter (e.g., 'Fredag').
 * @return array The calculated statistics object.
 */
function calculateStats($ratingsPath, $consentPath, $targetSession = '', $excludedSessionIds = [], $deviceFilter = '', $eventsPath = '', $usageDeviceFilter = '', $filtersDeviceFilter = '', $favSessionFilter = '', $favDeviceFilter = '', $usageWindow = null, $usageTz = null) {
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
        'favorites' => array(
            'total' => 0,
            'sessions' => 0,
            'beers' => 0,
            'median_per_session' => 0,
            'filter_sessions' => 0,
            'top' => array(),
            'available_sessions' => array(),
            'session_filter' => $favSessionFilter,
            'device_filter' => $favDeviceFilter
        ),
        'available_sessions' => array(),
        'usage' => array(
            'visits' => 0,
            'unique_visitors' => 0,
            'browser' => 0,
            'standalone' => 0,
            'installs' => 0,
            'installs_by_platform' => array('ios' => 0, 'android' => 0, 'other' => 0),
            'funnel' => array('banner_shown' => 0, 'banner_dismissed' => 0, 'prompt_accepted' => 0, 'prompt_declined' => 0, 'installed' => 0),
            'bucket' => 'day',
            'buckets' => array(),
            'active_days' => array(),
            'device_filter' => $usageDeviceFilter
        ),
        'filters' => array(
            'changes' => 0,
            'sessions' => 0,
            'clears' => 0,
            'searches' => 0,
            'usage' => array(),
            'selections' => array(),
            'top_searches' => array(),
            'device_filter' => $filtersDeviceFilter
        )
    );
    $filterSessions = array();
    $filterUsage = array();
    $selections = array();
    $searchTerms = array();
    $favoriteStates = array();
    $ratingSessionsPerBeer = array();
    $favoriteFilterSessions = array();

    // 0. Process interaction events (visits, installs, filters and search)
    $usageOpens = array();
    $activeQuarters = array();
    if ($eventsPath !== '' && file_exists($eventsPath)) {
        $usageVisitors = array();
        $handle = fopen($eventsPath, "r");
        while (($line = fgets($handle)) !== false) {
            if (!preg_match('/"type":"(app_open|install|filter_change|filter_clear|search|favorite_toggle)"/', $line)) continue;
            $entry = json_decode($line, true);
            if (!$entry || !isset($entry['type'], $entry['data']) || !is_array($entry['data'])) continue;
            $type = $entry['type'];
            $device = $entry['device_type'] ?? 'unknown';
            $sid = $entry['session_id'] ?? 'anon';
            $data = $entry['data'];

            if ($type === 'filter_change' && ($data['filter'] ?? '') === 'favorites' && ($data['value'] ?? '') === 'on'
                && ($favDeviceFilter === '' || $device === $favDeviceFilter)) {
                $favoriteFilterSessions[$sid] = true;
            }

            if ($type === 'favorite_toggle') {
                if ($favDeviceFilter !== '' && $device !== $favDeviceFilter) continue;
                $bid = $data['beer_id'] ?? '';
                if (!is_string($bid) || $bid === '' || !is_bool($data['on'] ?? null)) continue;
                $favoriteStates[$bid][$sid] = $data['on'];
                continue;
            }

            if ($type === 'filter_change' || $type === 'filter_clear' || $type === 'search') {
                if ($filtersDeviceFilter !== '' && $device !== $filtersDeviceFilter) continue;
                if ($type === 'filter_clear') {
                    $stats['filters']['clears']++;
                } elseif ($type === 'search') {
                    $term = $data['term'] ?? '';
                    if (!is_string($term) || $term === '') continue;
                    $stats['filters']['searches']++;
                    $searchTerms[$term]['sessions'][$sid] = true;
                    $searchTerms[$term]['results'] = $data['results'] ?? null;
                } else {
                    $filter = $data['filter'] ?? '';
                    $value = $data['value'] ?? '';
                    if (!is_string($filter) || $filter === '' || !is_string($value) || $value === '' || $value === 'off') continue;
                    $stats['filters']['changes']++;
                    $filterSessions[$sid] = true;
                    if (!isset($filterUsage[$filter])) $filterUsage[$filter] = array('times' => 0, 'sessions' => array());
                    $filterUsage[$filter]['times']++;
                    $filterUsage[$filter]['sessions'][$sid] = true;
                    if (in_array($filter, array('session', 'style', 'brewery', 'country', 'route'), true)) {
                        $selections[$filter][$value] = ($selections[$filter][$value] ?? 0) + 1;
                    }
                }
                continue;
            }

            if ($usageDeviceFilter !== '' && $device !== $usageDeviceFilter) continue;

            // Usage is limited to the selected time window [from, to) in ms; the days with any
            // visit are listed regardless, so the page can offer them to choose from.
            $ms = $entry['timestamp_unix_ms'] ?? null;
            $hasTime = is_numeric($ms);
            if ($type === 'app_open' && $hasTime) $activeQuarters[intdiv((int) $ms, 900000)] = true;
            if ($usageWindow !== null && (!$hasTime || $ms < $usageWindow[0] || $ms >= $usageWindow[1])) continue;

            if ($type === 'app_open') {
                $mode = ($data['mode'] ?? '') === 'standalone' ? 'standalone' : 'browser';
                $stats['usage']['visits']++;
                $usageVisitors[$sid] = true;
                $stats['usage'][$mode]++;
                if ($hasTime) $usageOpens[] = array((int) $ms, $mode === 'standalone', $sid);
            } else {
                $step = $data['step'] ?? '';
                if ($step === 'first_launch') {
                    $stats['usage']['installs']++;
                    $platform = $data['platform'] ?? 'other';
                    if (!isset($stats['usage']['installs_by_platform'][$platform])) $platform = 'other';
                    $stats['usage']['installs_by_platform'][$platform]++;
                } elseif (isset($stats['usage']['funnel'][$step])) {
                    $stats['usage']['funnel'][$step]++;
                }
            }
        }
        fclose($handle);
        $stats['usage']['unique_visitors'] = count($usageVisitors);
    }
    $usageTz = $usageTz ?: new DateTimeZone(date_default_timezone_get());
    list($stats['usage']['bucket'], $stats['usage']['buckets']) = usageBuckets($usageOpens, $usageWindow, $usageTz);
    foreach (array_keys($activeQuarters) as $quarter) {
        $day = (new DateTimeImmutable('@' . ($quarter * 900)))->setTimezone($usageTz)->format('Y-m-d');
        $stats['usage']['active_days'][$day] = true;
    }
    $stats['usage']['active_days'] = array_keys($stats['usage']['active_days']);

    $stats['filters']['sessions'] = count($filterSessions);
    foreach ($filterUsage as $filter => $use) {
        $stats['filters']['usage'][] = array('filter' => $filter, 'times' => $use['times'], 'sessions' => count($use['sessions']));
    }
    usort($stats['filters']['usage'], function($a, $b) { return $b['times'] <=> $a['times']; });
    foreach ($selections as $filter => $values) {
        arsort($values);
        $stats['filters']['selections'][$filter] = array();
        foreach (array_slice($values, 0, 10, true) as $value => $count) {
            $stats['filters']['selections'][$filter][] = array('value' => (string) $value, 'count' => $count);
        }
    }
    $stats['filters']['selections'] = (object) $stats['filters']['selections'];
    foreach ($searchTerms as $term => $info) {
        $stats['filters']['top_searches'][] = array('term' => (string) $term, 'sessions' => count($info['sessions']), 'results' => $info['results']);
    }
    usort($stats['filters']['top_searches'], function($a, $b) { return $b['sessions'] <=> $a['sessions']; });
    $stats['filters']['top_searches'] = array_slice($stats['filters']['top_searches'], 0, 15);

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
    // All devices, for the "Simulate 100% consent" factor (independent of the Consent tab's filter)
    $stats['consent_all'] = array('total' => count($visitorConsents), 'yes' => count(array_filter($visitorConsents)));

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
            if (isset($entry['beer_id'], $entry['session_id']) && is_scalar($entry['beer_id']) && is_scalar($entry['session_id'])) {
                $ratingSessionsPerBeer[$entry['beer_id']][$entry['session_id']] = true;
            }

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
        
        $byAvg = array_filter($list, function($val) { return count($val['ratings']) > 0; });
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

    // Favorites: each session's latest star state per beer, for beers still in the catalog
    $beerLookup = loadBeerCatalog();
    foreach ($beerLookup as $beer) {
        if (!empty($beer['session']) && is_string($beer['session'])) $stats['favorites']['available_sessions'][$beer['session']] = true;
    }
    $stats['favorites']['available_sessions'] = array_map('strval', array_keys($stats['favorites']['available_sessions']));
    $favoriteSessions = array();
    foreach ($favoriteStates as $bid => $sessions) {
        $beer = $beerLookup[$bid] ?? null;
        if ($beer === null) continue;
        if ($favSessionFilter !== '' && ($beer['session'] ?? '') !== $favSessionFilter) continue;
        $count = 0;
        foreach ($sessions as $sid => $on) {
            if (!$on) continue;
            $count++;
            $favoriteSessions[$sid] = ($favoriteSessions[$sid] ?? 0) + 1;
        }
        if ($count === 0) continue;
        $stats['favorites']['total'] += $count;
        $stats['favorites']['top'][] = array(
            'name' => (string) ($beer['name'] ?? $bid),
            'brewery' => (string) ($beer['brewery'] ?? ''),
            'favorites' => $count,
            'ratings' => isset($ratingSessionsPerBeer[$bid]) ? count($ratingSessionsPerBeer[$bid]) : 0
        );
    }
    $stats['favorites']['sessions'] = count($favoriteSessions);
    if ($favoriteSessions) {
        $perSession = array_values($favoriteSessions);
        sort($perSession);
        $mid = intdiv(count($perSession), 2);
        $stats['favorites']['median_per_session'] = count($perSession) % 2 ? $perSession[$mid] : ($perSession[$mid - 1] + $perSession[$mid]) / 2;
    }
    $stats['favorites']['beers'] = count($stats['favorites']['top']);
    $stats['favorites']['filter_sessions'] = count($favoriteFilterSessions);
    usort($stats['favorites']['top'], function($a, $b) {
        return ($b['favorites'] <=> $a['favorites']) ?: ($b['ratings'] <=> $a['ratings']);
    });
    $stats['favorites']['top'] = array_slice($stats['favorites']['top'], 0, 15);
    $stats['available_sessions'] = array_keys($stats['available_sessions']);

    return $stats;
}

// --- Controller logic ---
$filterSession = isset($_GET['session']) ? $_GET['session'] : '';
$excludeRaters = isset($_GET['exclude_raters']) && $_GET['exclude_raters'] === '1';

$validDevices = array('mobile', 'tablet', 'desktop', 'unknown');
$deviceFilter = isset($_GET['device']) ? $_GET['device'] : '';
if (!in_array($deviceFilter, $validDevices, true)) {
    $deviceFilter = '';
}
$usageDeviceFilter = isset($_GET['usage_device']) ? $_GET['usage_device'] : '';
if (!in_array($usageDeviceFilter, $validDevices, true)) {
    $usageDeviceFilter = '';
}
$filtersDeviceFilter = isset($_GET['filters_device']) ? $_GET['filters_device'] : '';
if (!in_array($filtersDeviceFilter, $validDevices, true)) {
    $filtersDeviceFilter = '';
}
$favSessionFilter = isset($_GET['fav_session']) && is_string($_GET['fav_session']) ? $_GET['fav_session'] : '';
if ($favSessionFilter !== '' && !in_array($favSessionFilter, array_column(loadBeerCatalog(), 'session'), true)) {
    $favSessionFilter = '';
}
$usageWindow = null;
if (isset($_GET['usage_from'], $_GET['usage_to']) && is_string($_GET['usage_from']) && is_string($_GET['usage_to'])
    && ctype_digit($_GET['usage_from']) && ctype_digit($_GET['usage_to']) && strlen($_GET['usage_from']) <= 15 && strlen($_GET['usage_to']) <= 15
    && (int) $_GET['usage_from'] < (int) $_GET['usage_to'] && (int) $_GET['usage_to'] - (int) $_GET['usage_from'] <= 400 * 86400000) {
    $usageWindow = array((int) $_GET['usage_from'], (int) $_GET['usage_to']);
}
$usageTz = null;
if (isset($_GET['usage_tz']) && is_string($_GET['usage_tz']) && in_array($_GET['usage_tz'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
    $usageTz = new DateTimeZone($_GET['usage_tz']);
}
$favDeviceFilter = isset($_GET['fav_device']) ? $_GET['fav_device'] : '';
if (!in_array($favDeviceFilter, $validDevices, true)) {
    $favDeviceFilter = '';
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
    $stats = calculateStats($ratingsLogPath, $consentLogPath, $filterSession, $excludedSessionIds, $deviceFilter, $eventsLogPath, $usageDeviceFilter, $filtersDeviceFilter, $favSessionFilter, $favDeviceFilter, $usageWindow, $usageTz);
    $stats['exclude_raters_active'] = $excludeRaters;
    $stats['excluded_count'] = count($excludedSessionIds);
    echo json_encode($stats);
    exit;
}

$initialData = calculateStats($ratingsLogPath, $consentLogPath, $filterSession, $excludedSessionIds, $deviceFilter, $eventsLogPath, $usageDeviceFilter, $filtersDeviceFilter, $favSessionFilter, $favDeviceFilter, $usageWindow, $usageTz);
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
        .tab-bar { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .tab-btn { padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 600; background-color: var(--card-background-color); border: 1px solid var(--card-border-color); color: var(--card-paragraph-color); transition: background-color 0.2s; cursor: pointer; }
        .tab-btn:hover { background-color: var(--palette-interactive); }
        body.simulating .scaled::before { content: '≈ '; }
        .tab-btn.active { background-color: var(--button-primary-background-color); border-color: transparent; color: white; }
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

        <!-- Page selector and global controls -->
        <div class="highlight-section mb-6">
            <div class="flex flex-col md:flex-row md:items-center gap-4">
                <div class="tab-bar" role="tablist">
                    <button type="button" class="tab-btn" role="tab" data-tab="consent" onclick="selectTab('consent')">Consent</button>
                    <button type="button" class="tab-btn" role="tab" data-tab="usage" onclick="selectTab('usage')">Usage</button>
                    <button type="button" class="tab-btn" role="tab" data-tab="filters" onclick="selectTab('filters')">Filters &amp; Search</button>
                    <button type="button" class="tab-btn" role="tab" data-tab="favorites" onclick="selectTab('favorites')">Favorites</button>
                    <button type="button" class="tab-btn" role="tab" data-tab="raters" onclick="selectTab('raters')">Raters</button>
                </div>
                <div class="flex items-center gap-4 md:ml-auto flex-wrap">
                    <label for="simulate-consent" class="inline-flex items-center gap-2 cursor-pointer text-sm" style="margin: 0;">
                        <input type="checkbox" id="simulate-consent" class="w-4 h-4 rounded border-gray-300" onchange="toggleSimulation()">
                        <span>Simulate 100% consent</span>
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
            <p id="simulate-note" class="text-xs mt-3 hidden" style="color: var(--card-paragraph-color);"></p>
        </div>

        <div class="tab-panel" data-tab="consent">
        <div class="highlight-section mb-6">
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="w-full md:w-48">
                    <label for="device-select">Device</label>
                    <select id="device-select" onchange="refreshData()">
                        <?php foreach (array('' => 'All Devices', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop', 'unknown' => 'Unknown') as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>"<?php echo $deviceFilter === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============ VISITORS ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Consent <span id="visitor-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="stat-card">
                    <span id="v-total" class="stat-number">0</span>
                    <span class="stat-label">Answered</span>
                </div>
                <div class="stat-card border-green-500/30">
                    <span id="v-yes" class="stat-number text-green-500">0</span>
                    <span class="stat-label">Accepted</span>
                </div>
                <div class="stat-card border-red-500/30">
                    <span id="v-no" class="stat-number text-red-500">0</span>
                    <span class="stat-label">Declined</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="highlight-section" style="margin-bottom: 0; display: flex; flex-direction: column;">
                    <h3 class="highlight-title">Consent answers per day (last 14 days)</h3>
                    <div id="visitor-chart" class="overflow-x-auto" style="flex: 1; min-height: 220px;"></div>
                </div>

                <div class="highlight-section" style="margin-bottom: 0;">
                    <h3 class="highlight-title">Devices</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-white/20">
                                    <th class="py-2">Device</th>
                                    <th class="py-2 text-right">Answers</th>
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

        </div>

        <div class="tab-panel" data-tab="usage">
        <div class="highlight-section mb-6">
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="w-full md:w-48">
                    <label for="usage-device-select">Device</label>
                    <select id="usage-device-select" onchange="refreshData()">
                        <?php foreach (array('' => 'All Devices', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop', 'unknown' => 'Unknown') as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>"<?php echo $usageDeviceFilter === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-full md:w-48">
                    <label for="usage-window-select">Time window</label>
                    <select id="usage-window-select" onchange="refreshData()">
                        <option value="all">All time</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============ USAGE ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Usage <span id="usage-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div class="stat-card">
                    <span id="u-visits" class="stat-number scaled">0</span>
                    <span class="stat-label">Visits / Unique Visitors</span>
                </div>
                <div class="stat-card">
                    <span id="u-browser" class="stat-number">–</span>
                    <span class="stat-label">In Browser</span>
                </div>
                <div class="stat-card">
                    <span id="u-standalone" class="stat-number">–</span>
                    <span class="stat-label">In Installed App</span>
                </div>
                <div class="stat-card">
                    <span id="u-installs" class="stat-number scaled">0</span>
                    <span class="stat-label">Installs</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="highlight-section" style="margin-bottom: 0; display: flex; flex-direction: column;">
                    <div class="highlight-title flex items-center justify-between gap-4">
                        <h3 id="usage-chart-title">Activity by Day</h3>
                    </div>
                    <div class="flex gap-4 text-xs mb-2" style="color: var(--card-paragraph-color);">
                        <span><span class="inline-block w-3 h-3 rounded-sm align-middle mr-1" style="background: var(--palette-text-primary);"></span>Browser</span>
                        <span><span id="usage-installed-swatch" class="inline-block w-3 h-3 rounded-sm align-middle mr-1" style="background: var(--palette-link);"></span>Installed app</span>
                        <span><span class="inline-block h-3 rounded-sm align-middle mr-1" style="width: 0.375rem; background: var(--card-paragraph-color);"></span>Unique visitors</span>
                    </div>
                    <div id="usage-chart" class="overflow-x-auto" style="flex: 1; min-height: 220px;"></div>
                </div>

                <div class="highlight-section" style="margin-bottom: 0;">
                    <h3 class="highlight-title">Installs</h3>
                    <div class="data-row"><span>iOS</span><span id="u-inst-ios" class="font-bold scaled">0</span></div>
                    <div class="data-row"><span>Android</span><span id="u-inst-android" class="font-bold scaled">0</span></div>
                    <div class="data-row"><span>Other (Mac, Windows, …)</span><span id="u-inst-other" class="font-bold scaled">0</span></div>

                    <h3 class="highlight-title" style="margin-top: 1.5rem;">Install Banner (Chrome/Edge)</h3>
                    <div class="data-row"><span>Banner shown</span><span id="u-funnel-banner_shown" class="font-bold scaled">0</span></div>
                    <div class="data-row"><span>Banner dismissed</span><span id="u-funnel-banner_dismissed" class="font-bold scaled">0</span></div>
                    <div class="data-row"><span>Install prompt accepted</span><span id="u-funnel-prompt_accepted" class="font-bold scaled">0</span></div>
                    <div class="data-row"><span>Install prompt declined</span><span id="u-funnel-prompt_declined" class="font-bold scaled">0</span></div>
                    <div class="data-row"><span>Installed</span><span id="u-funnel-installed" class="font-bold scaled">0</span></div>
                </div>
            </div>
            <p class="text-xs mt-3" style="color: var(--card-paragraph-color);">Only visitors who accepted statistics are counted. Unique visitors are counted per browser, so one person on two devices counts twice; logs from before v1.8.0 (per-visit IDs) overcount them. Installs are counted on the first launch of the installed app.</p>
        </div>

        </div>

        <div class="tab-panel" data-tab="filters">
        <div class="highlight-section mb-6">
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="w-full md:w-48">
                    <label for="filters-device-select">Device</label>
                    <select id="filters-device-select" onchange="refreshData()">
                        <?php foreach (array('' => 'All Devices', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop', 'unknown' => 'Unknown') as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>"<?php echo $filtersDeviceFilter === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============ FILTERS & SEARCH ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Filters &amp; Search <span id="filters-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="stat-card">
                    <span id="f-changes" class="stat-number scaled">0</span>
                    <span class="stat-label">Filter Changes</span>
                </div>
                <div class="stat-card">
                    <span id="f-sessions" class="stat-number scaled">0</span>
                    <span class="stat-label">Sessions Using Filters</span>
                </div>
                <div class="stat-card">
                    <span id="f-searches" class="stat-number scaled">0</span>
                    <span class="stat-label">Searches</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="highlight-section" style="margin-bottom: 0;">
                    <h3 class="highlight-title">Filter Usage</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-white/20">
                                    <th class="py-2">Filter</th>
                                    <th class="py-2 text-right">Times Used</th>
                                    <th class="py-2 text-right">Sessions</th>
                                </tr>
                            </thead>
                            <tbody id="f-usage-table"></tbody>
                        </table>
                    </div>
                    <div class="data-row" style="margin-top: 0.5rem;"><span>"Clear filters" clicked</span><span id="f-clears" class="font-bold scaled">0</span></div>
                </div>

                <div class="highlight-section" style="margin-bottom: 0;">
                    <div class="highlight-title flex items-center justify-between gap-4">
                        <h3>Top Selections</h3>
                        <select id="f-selection-select" onchange="renderSelections()" style="width: auto; height: 30px; padding: 0 0.5rem; font-size: 0.875rem; font-weight: 400;">
                            <option value="session">Session</option>
                            <option value="style">Style</option>
                            <option value="brewery">Brewery</option>
                            <option value="country">Country</option>
                            <option value="route">Tasting route</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-white/20">
                                    <th class="py-2">Selected</th>
                                    <th class="py-2 text-right">Times</th>
                                </tr>
                            </thead>
                            <tbody id="f-selection-table"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div>
                <div class="highlight-section" style="margin-bottom: 0;">
                    <h3 class="highlight-title">Top Searches</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-white/20">
                                    <th class="py-2">Search</th>
                                    <th class="py-2 text-right">Sessions</th>
                                    <th class="py-2 text-right">Results</th>
                                </tr>
                            </thead>
                            <tbody id="f-search-table"></tbody>
                        </table>
                    </div>
                </div>

            </div>
            <p class="text-xs mt-3" style="color: var(--card-paragraph-color);">Only visitors who accepted statistics are counted. A session is one browser and is kept across visits, so one person on two devices counts twice (logs from before v1.8.0 used a new session per visit). Results show how many beers matched, with any other active filters applied.</p>
        </div>
        </div>

        <div class="tab-panel" data-tab="favorites">
        <div class="highlight-section mb-6">
            <div class="flex flex-col md:flex-row md:items-end gap-4">
                <div class="w-full md:w-48">
                    <label for="fav-session-select"><?php echo t('session', 'Session'); ?></label>
                    <select id="fav-session-select" onchange="refreshData()">
                        <option value=""><?php echo t('all_sessions', 'All Sessions'); ?></option>
                        <?php foreach ($initialData['favorites']['available_sessions'] as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>"<?php echo $favSessionFilter === $s ? ' selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-full md:w-48">
                    <label for="fav-device-select">Device</label>
                    <select id="fav-device-select" onchange="refreshData()">
                        <?php foreach (array('' => 'All Devices', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop', 'unknown' => 'Unknown') as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>"<?php echo $favDeviceFilter === $val ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============ FAVORITES ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Favorites <span id="fav-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div class="stat-card">
                    <span id="fav-total" class="stat-number scaled">0</span>
                    <span class="stat-label">Favorites</span>
                </div>
                <div class="stat-card">
                    <span id="fav-sessions" class="stat-number scaled">0</span>
                    <span class="stat-label">Sessions Using Favorites</span>
                </div>
                <div class="stat-card">
                    <span id="fav-avg" class="stat-number">–</span>
                    <span class="stat-label">Avg / Median per Session</span>
                </div>
                <div class="stat-card">
                    <span id="fav-beers" class="stat-number">0</span>
                    <span class="stat-label">Beers Favorited</span>
                </div>
            </div>

            <div class="highlight-section">
                <h3 class="highlight-title">Most Favorited</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-white/20">
                                <th class="py-2">Beer</th>
                                <th class="py-2">Brewery</th>
                                <th class="py-2 text-center">Favorites</th>
                                <th class="py-2 text-right">Ratings</th>
                            </tr>
                        </thead>
                        <tbody id="favorites-table"></tbody>
                    </table>
                </div>
                <div class="data-row" style="margin-top: 0.5rem;"><span>Sessions that used the "Favorites" filter</span><span id="fav-filter-sessions" class="font-bold scaled">0</span></div>
            </div>
        </div>
        </div>

        <div class="tab-panel" data-tab="raters">
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
                <label for="exclude-raters" class="inline-flex items-center gap-2 cursor-pointer text-sm md:mb-2" style="margin-top: 0;">
                    <input type="checkbox" id="exclude-raters" class="w-4 h-4 rounded border-gray-300" onchange="refreshData()">
                    <span>Exclude flagged raters</span>
                </label>
            </div>
        </div>

        <!-- ============ RATERS ============ -->
        <div class="stats-group">
            <h2 class="section-heading">Raters <span id="raters-filter-note" class="filter-note"></span></h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="stat-card">
                    <span id="r-total" class="stat-number scaled">0</span>
                    <span class="stat-label">Total Ratings</span>
                </div>
                <div class="stat-card">
                    <span id="r-users" class="stat-number scaled">0</span>
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
        async function refreshData(silent = false) {
            const session = document.getElementById('session-select').value;
            const excludeRaters = document.getElementById('exclude-raters').checked ? '1' : '0';
            const device = document.getElementById('device-select').value;
            const usageDevice = document.getElementById('usage-device-select').value;
            const filtersDevice = document.getElementById('filters-device-select').value;
            const favSession = document.getElementById('fav-session-select').value;
            const favDevice = document.getElementById('fav-device-select').value;
            const usageRange = usageWindowRange(document.getElementById('usage-window-select').value);
            const url = `stats.php?format=json&session=${encodeURIComponent(session)}&exclude_raters=${excludeRaters}&device=${encodeURIComponent(device)}&usage_device=${encodeURIComponent(usageDevice)}&filters_device=${encodeURIComponent(filtersDevice)}&fav_session=${encodeURIComponent(favSession)}&fav_device=${encodeURIComponent(favDevice)}`
                + (usageRange ? `&usage_from=${usageRange[0]}&usage_to=${usageRange[1]}` : '')
                + `&usage_tz=${encodeURIComponent(Intl.DateTimeFormat().resolvedOptions().timeZone || '')}`;

            try {
                const response = await fetch(url);
                const data = await response.json();
                updateUI(data);
                if (!silent) showNotification();
            } catch (e) {
                console.error("Refresh failed", e);
            }
        }

        const TABS = ['consent', 'usage', 'filters', 'favorites', 'raters'];
        let lastData = null;

        function selectTab(tab) {
            if (!TABS.includes(tab)) tab = TABS[0];
            document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.toggle('hidden', panel.dataset.tab !== tab));
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tab);
                btn.setAttribute('aria-selected', btn.dataset.tab === tab ? 'true' : 'false');
            });
            history.replaceState(null, '', '#' + tab);
            if (tab === 'consent' && lastData) renderVisitorChart(lastData.visitors.daily || []);
        }

        /**
         * Filters the Consent section to a device type.
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

        // Installed-app bars use the link colour, or a lighter text colour in themes where
        // the two are the same and the stacked segments would merge.
        function installedAppFill() {
            const css = getComputedStyle(document.documentElement);
            const same = css.getPropertyValue('--palette-link').trim().toLowerCase() === css.getPropertyValue('--palette-text-primary').trim().toLowerCase();
            return same ? { color: 'var(--palette-text-primary)', opacity: 0.45 } : { color: 'var(--palette-link)', opacity: 1 };
        }

        const startOfDay = d => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
        const addDays = (ms, n) => { const d = new Date(ms); d.setDate(d.getDate() + n); return d.getTime(); };
        const dayLabel = key => new Date(key + 'T12:00:00').toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });

        // Selected Usage time window in the viewer's local time: [from, to) in ms, or null for all time.
        function usageWindowRange(value) {
            const today = startOfDay(new Date());
            if (value === 'today') return [today, addDays(today, 1)];
            if (value === '7d') return [addDays(today, -6), addDays(today, 1)];
            if (value.startsWith('day:')) {
                const [y, m, d] = value.slice(4).split('-').map(Number);
                const from = new Date(y, m - 1, d).getTime();
                return [from, addDays(from, 1)];
            }
            return null;
        }

        // Offers all time, the last 7 days, today and every other day with a visit.
        function fillUsageWindows(activeDays) {
            const select = document.getElementById('usage-window-select');
            const previous = select.value || 'all';
            const today = localDayKey(new Date());
            const days = activeDays.filter(d => d !== today).sort().reverse();
            const options = [['all', 'All time'], ['7d', 'Last 7 days'], ['today', 'Today'], ...days.map(d => ['day:' + d, dayLabel(d)])];
            select.innerHTML = '';
            options.forEach(([value, label]) => {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = label;
                select.appendChild(opt);
            });
            if (options.some(([value]) => value === previous)) {
                select.value = previous;
            } else {
                select.value = 'all';
                refreshData(true);
            }
        }

        // Visits per hour (a single day), day (up to 31 days) or week as stacked browser / installed-app
        // bars, with a narrow inner bar for the unique visitors of each bucket. The server groups them.
        function renderUsageChart(usage) {
            const bucket = usage.bucket || 'day';
            const buckets = (usage.buckets || []).map(b => {
                const counts = [Number(b.browser) || 0, Number(b.standalone) || 0, Number(b.unique) || 0];
                if (bucket === 'hour') {
                    const hour = parseInt(b.key.slice(11, 13), 10);
                    return { label: String(hour).padStart(2, '0') + ':00', tick: hour % 3 === 0 ? String(hour).padStart(2, '0') : '', counts };
                }
                const short = new Date(b.key + 'T12:00:00').toLocaleDateString([], bucket === 'week' ? { day: 'numeric', month: 'short' } : { weekday: 'short', day: 'numeric' });
                return { label: (bucket === 'week' ? 'Week of ' : '') + dayLabel(b.key), tick: short, counts };
            });
            if (bucket !== 'hour') {
                const every = Math.ceil(buckets.length / 12);
                buckets.forEach((b, i) => { if (i % every) b.tick = ''; });
            }
            document.getElementById('usage-chart-title').textContent = { hour: 'Activity by Hour', day: 'Activity by Day', week: 'Activity by Week' }[bucket];

            const host = document.getElementById('usage-chart');
            const installed = installedAppFill();
            const swatch = document.getElementById('usage-installed-swatch');
            swatch.style.background = installed.color;
            swatch.style.opacity = installed.opacity;

            const W = 760, H = 240;
            const padL = 12, padR = 12, padT = 18, padB = 28;
            const plotW = W - padL - padR;
            const plotH = H - padT - padB;
            const slot = plotW / Math.max(1, buckets.length);
            const barW = slot * 0.7;
            const maxY = Math.max(1, ...buckets.map(b => b.counts[0] + b.counts[1]));
            const h = c => plotH * c / maxY;

            let svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" '
                + 'preserveAspectRatio="xMidYMid meet" style="min-width:520px;width:100%;height:100%;display:block;">';
            const baseY = padT + plotH;
            svg += '<line x1="' + padL + '" y1="' + baseY + '" x2="' + (W - padR) + '" y2="' + baseY
                + '" stroke="var(--divider-color)" stroke-width="1" opacity="0.4"/>';

            buckets.forEach(({ label, tick, counts: [browser, standalone, unique] }, i) => {
                const x = (padL + slot * i + (slot - barW) / 2).toFixed(1);
                const total = browser + standalone;
                const title = label + ' — ' + total + ' visits (browser ' + browser + ', installed ' + standalone + '), ' + unique + ' unique visitors';
                if (browser > 0) {
                    svg += '<rect x="' + x + '" y="' + (baseY - h(browser)).toFixed(1) + '" width="' + barW.toFixed(1) + '" height="' + h(browser).toFixed(1)
                        + '" fill="var(--palette-text-primary)"><title>' + title + '</title></rect>';
                }
                if (standalone > 0) {
                    svg += '<rect x="' + x + '" y="' + (baseY - h(total)).toFixed(1) + '" width="' + barW.toFixed(1) + '" height="' + h(standalone).toFixed(1)
                        + '" fill="' + installed.color + '" fill-opacity="' + installed.opacity + '"><title>' + title + '</title></rect>';
                }
                if (unique > 0) {
                    const uniqueW = Math.max(2, barW * 0.3);
                    svg += '<rect x="' + x + '" y="' + (baseY - h(unique)).toFixed(1) + '" width="' + uniqueW.toFixed(1) + '" height="' + h(unique).toFixed(1)
                        + '" fill="var(--card-paragraph-color)"><title>' + title + '</title></rect>';
                }
                if (total > 0) {
                    svg += '<text x="' + (Number(x) + barW / 2).toFixed(1) + '" y="' + (baseY - h(total) - 4).toFixed(1)
                        + '" text-anchor="middle" font-size="10" fill="var(--card-paragraph-color)">' + total + '</text>';
                }
                if (tick) {
                    svg += '<text x="' + (padL + slot * i + slot / 2).toFixed(1) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="11" '
                        + 'fill="var(--card-paragraph-color)">' + tick + '</text>';
                }
            });

            svg += '</svg>';
            host.innerHTML = svg;
        }

        function localDayKey(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        function updateUsage(usage) {
            document.getElementById('u-visits').textContent = usage.visits + ' / ' + usage.unique_visitors;
            const raw = usage.unscaled || usage;
            const pct = n => raw.visits > 0 ? Math.round(n / raw.visits * 100) + '%' : '–';
            document.getElementById('u-browser').textContent = pct(raw.browser);
            document.getElementById('u-standalone').textContent = pct(raw.standalone);
            document.getElementById('u-installs').textContent = usage.installs;
            ['ios', 'android', 'other'].forEach(p => {
                document.getElementById('u-inst-' + p).textContent = usage.installs_by_platform[p] || 0;
            });
            Object.entries(usage.funnel).forEach(([step, count]) => {
                const el = document.getElementById('u-funnel-' + step);
                if (el) el.textContent = count;
            });

            fillUsageWindows(usage.active_days || []);
            renderUsageChart(usage);
        }

        const FILTER_LABELS = {
            session: 'Session', route: 'Tasting route', style: 'Style', brewery: 'Brewery', country: 'Country',
            my_rated: 'My rated beers', unrated: 'Unrated beers', favorites: 'Favorites'
        };
        let filterSelections = {};

        /**
         * Fills a table body with plain-text cells (search terms are visitor input — never use innerHTML).
         */
        function fillTable(tbodyId, rows, emptyText) {
            const tbody = document.getElementById(tbodyId);
            tbody.textContent = '';
            if (rows.length === 0) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.className = 'py-2 opacity-60';
                td.colSpan = 3;
                td.textContent = emptyText;
                tr.appendChild(td);
                tbody.appendChild(tr);
                return;
            }
            rows.forEach(cells => {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-white/10';
                cells.forEach((text, i) => {
                    const td = document.createElement('td');
                    td.className = i === 0 ? 'py-2' : 'py-2 text-right';
                    td.textContent = text;
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
        }

        function renderSelections() {
            const filter = document.getElementById('f-selection-select').value;
            const rows = (filterSelections[filter] || []).map(s => [s.value, String(s.count)]);
            fillTable('f-selection-table', rows, 'No selections yet');
        }

        function updateFilters(filters) {
            document.getElementById('f-changes').textContent = filters.changes;
            document.getElementById('f-sessions').textContent = filters.sessions;
            document.getElementById('f-searches').textContent = filters.searches;
            document.getElementById('f-clears').textContent = filters.clears;

            fillTable('f-usage-table', filters.usage.map(u => [FILTER_LABELS[u.filter] || u.filter, String(u.times), String(u.sessions)]), 'No filters used yet');
            fillTable('f-search-table', filters.top_searches.map(t => [t.term, String(t.sessions), t.results === null ? '–' : String(t.results)]), 'No searches yet');
            filterSelections = filters.selections || {};
            renderSelections();
        }

        function updateFavorites(fav, labels) {
            document.getElementById('fav-total').textContent = fav.total;
            document.getElementById('fav-sessions').textContent = fav.sessions;
            const favRaw = fav.unscaled || fav;
            document.getElementById('fav-avg').textContent = favRaw.sessions > 0 ? (favRaw.total / favRaw.sessions).toFixed(1) + ' / ' + fav.median_per_session : '–';
            document.getElementById('fav-beers').textContent = fav.beers;
            document.getElementById('fav-filter-sessions').textContent = fav.filter_sessions;

            const tbody = document.getElementById('favorites-table');
            tbody.textContent = '';
            if (fav.top.length === 0) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.colSpan = 4;
                td.className = 'py-2 opacity-60';
                td.textContent = 'No favorites yet';
                tr.appendChild(td);
                tbody.appendChild(tr);
            }
            fav.top.forEach(f => {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-white/10 hover:bg-white/5';
                [
                    ['py-3 font-semibold', f.name],
                    ['py-3 opacity-70', f.brewery],
                    ['py-3 text-center font-bold', String(Number(f.favorites))],
                    ['py-3 text-right', String(Number(f.ratings))]
                ].forEach(([cls, text]) => {
                    const td = document.createElement('td');
                    td.className = cls;
                    td.textContent = text;
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });

            const sessionSelect = document.getElementById('fav-session-select');
            const deviceSelect = document.getElementById('fav-device-select');
            sessionSelect.value = fav.session_filter || '';
            deviceSelect.value = fav.device_filter || '';
            const note = document.getElementById('fav-filter-note');
            note.textContent = '';
            const parts = [];
            if (fav.session_filter) parts.push(fav.session_filter);
            if (fav.device_filter) parts.push((labels[fav.device_filter] || fav.device_filter) + ' only');
            if (parts.length) {
                note.append('— ' + parts.join(', ') + ' ');
                const clear = document.createElement('button');
                clear.type = 'button';
                clear.textContent = '(clear)';
                clear.onclick = () => {
                    sessionSelect.value = '';
                    deviceSelect.value = '';
                    refreshData();
                };
                note.append(clear);
            }
        }

        // Answers to the consent banner per acceptance: how much larger the counts would be if everyone
        // who answered had accepted, assuming people who declined use the app like those who accepted.
        function consentFactor(visitors) {
            return visitors && visitors.yes > 0 ? visitors.total / visitors.yes : null;
        }

        // Copy of the stats with plain counts scaled by the factor. Shares, averages, medians, rating
        // scores and distinct counts (beers favourited or rated) are left unchanged.
        function simulateFullConsent(data, factor) {
            const d = structuredClone(data);
            // Ratios are computed from the unscaled counts, so rounding can't shift them.
            d.usage.unscaled = { visits: data.usage.visits, browser: data.usage.browser, standalone: data.usage.standalone };
            d.favorites.unscaled = { total: data.favorites.total, sessions: data.favorites.sessions };
            const scale = n => Math.round((Number(n) || 0) * factor);
            const scaleKeys = (obj, keys) => keys.forEach(k => { if (obj && typeof obj[k] === 'number') obj[k] = scale(obj[k]); });
            const scaleValues = obj => Object.keys(obj || {}).forEach(k => { obj[k] = scale(obj[k]); });
            scaleKeys(d.usage, ['visits', 'unique_visitors', 'browser', 'standalone', 'installs']);
            scaleValues(d.usage.installs_by_platform);
            scaleValues(d.usage.funnel);
            (d.usage.buckets || []).forEach(b => scaleKeys(b, ['browser', 'standalone', 'unique']));
            scaleKeys(d.filters, ['changes', 'clears', 'sessions', 'searches']);
            (d.filters.usage || []).forEach(u => scaleKeys(u, ['times', 'sessions']));
            Object.values(d.filters.selections || {}).forEach(list => list.forEach(x => scaleKeys(x, ['count'])));
            (d.filters.top_searches || []).forEach(x => scaleKeys(x, ['sessions']));
            scaleKeys(d.favorites, ['total', 'sessions', 'filter_sessions']);
            (d.favorites.top || []).forEach(x => scaleKeys(x, ['favorites', 'ratings']));
            scaleKeys(d.engagement, ['total_ratings', 'unique_users']);
            Object.values(d.top_beers || {}).forEach(x => scaleKeys(x, ['count']));
            Object.values(d.highlights || {}).forEach(x => scaleKeys(x, ['count']));
            return d;
        }

        function toggleSimulation() {
            try { localStorage.setItem('statsSimulateConsent', document.getElementById('simulate-consent').checked ? '1' : ''); } catch (e) {}
            if (lastData) updateUI(lastData);
        }

        /**
         * Updates the DOM with calculated metrics.
         */
        function updateUI(data) {
            lastData = data;
            const consent = data.consent_all || data.visitors;
            const factor = consentFactor(consent);
            const simulate = document.getElementById('simulate-consent').checked && factor !== null;
            document.body.classList.toggle('simulating', simulate);
            const simNote = document.getElementById('simulate-note');
            simNote.classList.toggle('hidden', !document.getElementById('simulate-consent').checked);
            simNote.textContent = simulate
                ? 'Simulating 100% consent: counts are scaled ×' + factor.toFixed(2) + ' (' + consent.yes + ' of ' + consent.total
                    + ' consent answers accepted, all devices), assuming people who declined use the app like those who accepted. Counts in cards and tables are scaled; percentages, averages and distinct counts are unchanged.'
                : 'Simulating 100% consent needs at least one accepted consent answer.';
            if (simulate) data = simulateFullConsent(data, factor);
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

            updateUsage(data.usage);
            updateFilters(data.filters);
            updateFavorites(data.favorites, labels);
            const filtersDevice = data.filters.device_filter || '';
            document.getElementById('filters-device-select').value = filtersDevice;
            const filtersNote = document.getElementById('filters-filter-note');
            filtersNote.textContent = '';
            if (filtersDevice) {
                filtersNote.append('— ' + (labels[filtersDevice] || filtersDevice) + ' only ');
                const filtersClear = document.createElement('button');
                filtersClear.type = 'button';
                filtersClear.textContent = '(clear)';
                filtersClear.onclick = () => {
                    document.getElementById('filters-device-select').value = '';
                    refreshData();
                };
                filtersNote.append(filtersClear);
            }
            const usageDevice = data.usage.device_filter || '';
            document.getElementById('usage-device-select').value = usageDevice;
            const usageNote = document.getElementById('usage-filter-note');
            usageNote.textContent = '';
            if (usageDevice) {
                usageNote.append('— ' + (labels[usageDevice] || usageDevice) + ' only ');
                const usageClear = document.createElement('button');
                usageClear.type = 'button';
                usageClear.textContent = '(clear)';
                usageClear.onclick = () => {
                    document.getElementById('usage-device-select').value = '';
                    refreshData();
                };
                usageNote.append(usageClear);
            }

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
        selectTab(location.hash.slice(1));
        try { document.getElementById('simulate-consent').checked = localStorage.getItem('statsSimulateConsent') === '1'; } catch (e) {}
        updateUI(<?php echo json_encode($initialData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        refreshData(true);  // the server-rendered data uses the server's time zone for the Usage chart
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