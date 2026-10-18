<?php
// PHP part: Handles environment variables and loads JSON data.
ob_start(); // Start output buffering

// Set application language from environment variable, default to 'da'.
$appLanguage = getenv('APP_LANGUAGE') ?: 'da';

// Load language strings from the appropriate config file.
$langFile = __DIR__ . "/lang/{$appLanguage}.conf";
$translations = [];
if (file_exists($langFile)) {
    $translations = parse_ini_file($langFile);
} else {
    // Fallback to English if the specified language file doesn't exist.
    $appLanguage = 'en';
    $langFile = __DIR__ . "/lang/{$appLanguage}.conf";
    if (file_exists($langFile)) {
        $translations = parse_ini_file($langFile);
    } else {
        die("Error: Language files not found. Please ensure lang/da.conf and lang/en.conf exist.");
    }
}

// Load configuration from environment variables with fallbacks.
$festivalTitle = getenv('FESTIVAL_TITLE') ?: $translations['default_festival_title'];
$festivalInfoText = getenv('FESTIVAL_INFO_TEXT') ?: $translations['default_info_text'];
$enableStatisticsLogging = getenv('ENABLE_STATISTICS_LOGGING') === 'true';
$enableMainstyleFiltering = getenv('ENABLE_MAINSTYLE_FILTERING') === 'true';
$contactEmail = getenv('CONTACT_EMAIL') ?: 'contact@mybeerfest.com';
require_once __DIR__ . '/config/theme_color.php';
$themeColor = getThemeColor();
$festivalTitleShort = getenv('FESTIVAL_TITLE_SHORT') ?: 'Ølfestival';
$devMode = getenv('DEV_MODE') === 'true';

$festivalSeoDescription = getenv('FESTIVAL_SEO_DESCRIPTION') ?: $festivalInfoText;

$envDomain = getenv('DOMAIN') ?: '';
if ($envDomain !== '') {
    $siteUrlBase = preg_match('#^https?://#', $envDomain)
        ? rtrim($envDomain, '/')
        : 'https://' . rtrim($envDomain, '/');
} else {
    $isHttpsCanonical = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $siteUrlBase = $host !== '' ? ($isHttpsCanonical ? 'https://' : 'http://') . $host : '';
}
$ogImagePath = file_exists(__DIR__ . '/custom/icon-512.png') ? '/custom/icon-512.png' : '/images/icon-512.png';
$ogImageUrl = $siteUrlBase !== '' ? $siteUrlBase . $ogImagePath : ltrim($ogImagePath, '/');
$canonicalUrl = $siteUrlBase !== '' ? $siteUrlBase . '/' : '';

$ogLocaleMap = [
    'da' => 'da_DK', 'en' => 'en_US', 'sv' => 'sv_SE', 'no' => 'nb_NO',
    'de' => 'de_DE', 'fr' => 'fr_FR', 'pl' => 'pl_PL', 'cs' => 'cs_CZ',
];
$ogLocale = $ogLocaleMap[$appLanguage] ?? 'en_US';

// Build the JSON-LD Event schema before the gate check so both index.php's
// main render and coming-soon.php can emit it. Rich results / AI-agent
// discovery are arguably more valuable while the site is in coming-soon.
$festivalLocation = getenv('FESTIVAL_LOCATION') ?: '';
$festivalDate     = getenv('FESTIVAL_DATE') ?: '';
$festivalEndDate  = getenv('FESTIVAL_END_DATE') ?: '';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Event',
    'name'     => $festivalTitle,
];
if ($festivalSeoDescription !== '')  { $jsonLd['description'] = $festivalSeoDescription; }
if ($festivalDate !== '')            { $jsonLd['startDate']   = $festivalDate; }
if ($festivalEndDate !== '')         { $jsonLd['endDate']     = $festivalEndDate; }
if ($canonicalUrl !== '')            { $jsonLd['url']         = $canonicalUrl; }
if ($ogImageUrl !== '')              { $jsonLd['image']       = $ogImageUrl; }
$jsonLd['eventStatus']         = 'https://schema.org/EventScheduled';
$jsonLd['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
if ($festivalLocation !== '') {
    $jsonLd['location'] = ['@type' => 'Place', 'name' => $festivalLocation];
}
$organizer = ['@type' => 'Organization', 'name' => $festivalTitle];
if ($contactEmail !== '') { $organizer['email'] = $contactEmail; }
if ($canonicalUrl !== '') { $organizer['url']   = $canonicalUrl; }
$jsonLd['organizer'] = $organizer;
$jsonLdScript = '<script type="application/ld+json">'
    . json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    . '</script>';

// "Not public yet" gate. When NOT_PUBLIC=true, visitors see coming-soon.php
// unless they hold a valid bypass cookie (set via ?key=<secret>).
$notPublic = getenv('NOT_PUBLIC') === 'true';
if ($notPublic) {
    $bypassKey = getenv('NOT_PUBLIC_BYPASS_KEY') ?: '';
    $cookieName = 'bf_preview';
    $expectedToken = $bypassKey !== '' ? hash_hmac('sha256', 'preview', $bypassKey) : '';
    // X-Forwarded-Proto check because PHP-FPM behind nginx rarely sees $_SERVER['HTTPS'].
    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if (!empty($_GET['key']) && $bypassKey !== ''
        && hash_equals($bypassKey, (string)$_GET['key'])) {
        setcookie($cookieName, $expectedToken, [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $isHttps,
        ]);
        header('Location: /');
        exit;
    }

    $hasBypass = $expectedToken !== ''
        && !empty($_COOKIE[$cookieName])
        && hash_equals($expectedToken, (string)$_COOKIE[$cookieName]);

    if (!$hasBypass) {
        header('Cache-Control: no-store');
        $festivalDate = getenv('FESTIVAL_DATE') ?: '';
        $showCountdown = getenv('SHOW_COUNTDOWN') === 'true';
        define('BEERFEST_GATE_RENDER', true);
        include __DIR__ . '/coming-soon.php';
        exit;
    }

    header('Cache-Control: private, no-store');
}

// Prepare PHP data for injection into JavaScript.
$translationsJson = json_encode($translations);

// SSR fallback for #beer-list — visible to non-JS crawlers (Bing, DuckDuckGo,
// most LLM fetchers). Client JS wipes this container on its first render
// (see beerListContainer.innerHTML = '' below), so JS-enabled users never
// see it. Placed after the NOT_PUBLIC gate so it can't leak while gated.
$ssrBeers = [];
$beersFile = __DIR__ . '/data/beers.json';
if (is_readable($beersFile)) {
    $decoded = json_decode(file_get_contents($beersFile), true);
    if (is_array($decoded)) {
        $ssrBeers = $decoded;
    }
}

// Optional tasting routes — curated, ordered beer lists (data/routes.json).
// Absent/invalid file simply hides the feature. Only well-formed entries are kept.
$routes = [];
$routesFile = __DIR__ . '/data/routes.json';
if (is_readable($routesFile)) {
    $decodedRoutes = json_decode(file_get_contents($routesFile), true);
    if (is_array($decodedRoutes)) {
        foreach ($decodedRoutes as $r) {
            if (is_array($r) && isset($r['name']) && is_string($r['name']) && $r['name'] !== ''
                && isset($r['beers']) && is_array($r['beers'])) {
                $routes[] = [
                    'name'    => $r['name'],
                    'session' => (isset($r['session']) && is_string($r['session'])) ? $r['session'] : '',
                    'beers'   => array_values(array_filter($r['beers'], 'is_string')),
                ];
            }
        }
    }
}

// Optional custom ordering for the style dropdown (data/stylegroups.json).
// Accepts a grouped form ([{ "group": "...", "styles": [...] }, ...]) or a flat
// array of style names. Group labels are not rendered — the styles are flattened
// in file order into one ordered list. Absent/invalid file keeps alphabetical.
$styleOrder = [];
$styleGroupsFile = __DIR__ . '/data/stylegroups.json';
if (is_readable($styleGroupsFile)) {
    $decodedStyleGroups = json_decode(file_get_contents($styleGroupsFile), true);
    if (is_array($decodedStyleGroups)) {
        foreach ($decodedStyleGroups as $entry) {
            if (is_string($entry)) {
                $styles = [$entry];
            } elseif (is_array($entry) && isset($entry['styles']) && is_array($entry['styles'])) {
                $styles = $entry['styles'];
            } else {
                continue;
            }
            foreach ($styles as $style) {
                if (is_string($style) && trim($style) !== '') {
                    $styleOrder[] = trim($style);
                }
            }
        }
    }
}

// Optional floor maps (data/maps/*.svg), inlined so they work offline.
require_once __DIR__ . '/map_loader.php';
$festivalMaps = loadFestivalMaps(__DIR__ . '/data/maps');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($festivalTitle); ?></title>

    <!-- SEO / social share -->
    <meta name="description" content="<?php echo htmlspecialchars($festivalSeoDescription); ?>">
<?php if ($canonicalUrl !== ''): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">
<?php endif; ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($festivalTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($festivalSeoDescription); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($festivalTitle); ?>">
    <meta property="og:locale" content="<?php echo htmlspecialchars($ogLocale); ?>">
<?php if ($canonicalUrl !== ''): ?>
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl); ?>">
<?php endif; ?>
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImageUrl); ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($festivalTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($festivalSeoDescription); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImageUrl); ?>">

    <?= $jsonLdScript ?>

    <!-- PWA Manifest and Theme Color -->
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor); ?>">
    
    <!-- Apple PWA specific meta tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($festivalTitleShort); ?>">
    <link rel="apple-touch-icon" href="<?php echo file_exists(__DIR__ . '/custom/icon-192.png') ? 'custom/icon-192.png' : 'images/icon-192.png'; ?>">
    <link rel="apple-touch-icon" sizes="512x512" href="<?php echo file_exists(__DIR__ . '/custom/icon-512.png') ? 'custom/icon-512.png' : 'images/icon-512.png'; ?>">
    
    <link rel="stylesheet" href="dist/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo file_exists(__DIR__ . '/custom/theme.css') ? 'custom/theme.css' : 'config/theme.css'; ?>">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            background-color: var(--background-color);
            color: var(--text-color);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }
        .beer-card {
            background-color: var(--card-background-color);
            border: 1px solid var(--card-border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative; /* Needed for absolute positioning of the star */
        }
        .beer-card h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--card-heading-color);
            margin: 0 0 0.55rem;
            padding-right: 2.5rem; /* Ensure text doesn't overlap with the star */
        }
        .beer-card h2 .beer-abv {
            font-weight: 600;
            opacity: 0.8;
        }
        .beer-card p {
            font-size: 0.875rem;
            color: var(--card-paragraph-color);
            line-height: 1.3;
            margin-bottom: 0.25rem;
        }
        .beer-card .beer-brewery {
            color: var(--card-heading-color);
            font-size: 0.95rem;
            font-weight: 600;
            margin: 0 0 0.15rem;
        }
        .beer-card .beer-style {
            color: var(--card-paragraph-color);
            font-size: 0.875rem;
            font-weight: 400;
            margin: 0 0 0.35rem;
        }
        .beer-card .beer-session {
            color: var(--card-paragraph-color);
            opacity: 0.72;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.09em;
            text-transform: uppercase;
            margin: 0;
        }
        .beer-note {
            font-size: 0.83rem;
            font-style: italic;
            color: var(--card-paragraph-color);
            margin: 0.6rem 0 0;
        }
        .favorite-star {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 24px;
            height: 24px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .favorite-star polygon {
            fill: var(--icon-favorite-color);
            stroke: var(--icon-favorite-color);
            stroke-width: 1.5;
            stroke-linejoin: round; /* This creates the soft corners */
            transition: fill 0.2s, stroke 0.2s;
        }
        .favorite-star:hover {
            transform: scale(1.2);
        }
        .favorite-star.favorited polygon {
            fill: var(--icon-favorite-active-color);
            stroke: var(--icon-favorite-active-color);
        }
        .search-section, .filter-sort-section {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-sort-section label, .search-section label {
            font-weight: 600;
            margin-right: 0.5rem;
            display: block;
            margin-bottom: 0.5rem;
            color: var(--label-color);
        }
        .filter-sort-section .filter-checks label, .filter-sort-section .filter-checks input[type="checkbox"] {
            margin-bottom: 0;
        }
        .filter-sort-section select, .filter-sort-section input[type="checkbox"], .rating-select, .search-input {
            padding: 0.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            transition: border-color 0.2s, box-shadow 0.2s; /* Added for smooth focus transition */
        }
        .search-input::placeholder {
            color: var(--palette-text-secondary);
        }
        /* Custom focus styles for form elements to match the theme */
        .search-input:focus,
        .filter-sort-section select:focus,
        .rating-select:focus,
        .untappd-button:focus {
            outline: none; /* Remove default browser outline */
            border-color: var(--palette-text-primary); /* Use high-contrast color for border */
            box-shadow: 0 0 3px 1px var(--palette-text-primary); /* Add a subtle glow */
        }
        .search-container {
            position: relative;
            display: flex;
            align-items: center;
        }
        .search-section .search-input {
            margin-bottom: 0;
        }
        .clear-search-btn {
            position: absolute;
            right: 0.25rem;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--icon-color);
            padding: 0.5rem;
        }
        .clear-search-btn:hover {
            color: var(--icon-hover-color);
        }
        .rating-select {
            width: 100%;
            height: 30px; /* Explicit height */
            padding: 0 0.5rem; /* Adjust padding */
            border-radius: 0.375rem;
            font-size: 0.875rem;
            text-align: center;
        }
        .beer-card .rating-select {
            margin-bottom: 0;
        }
        .beer-actions-container {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            padding-top: 0.75rem;
        }
        .untappd-button, .rating-select {
            flex: 1;
        }
        .untappd-button {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 30px; /* Explicit height */
            padding: 0 0.5rem; /* Adjust padding */
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--palette-primary);
            color: var(--palette-text-primary);
            text-decoration: none;
            font-size: 0.875rem;
            transition: background-color 0.2s;
        }
        .untappd-button:hover {
            background-color: var(--palette-interactive);
        }
        .untappd-logo {
            width: 16px;
            height: 16px;
            margin-right: 0.5rem;
            background-color: var(--palette-text-primary);
            -webkit-mask-image: url(/images/untappd.svg);
            mask-image: url(/images/untappd.svg);
            -webkit-mask-size: contain;
            mask-size: contain;
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
        }
        .beer-grid {
            display: grid;
            gap: 1rem;
        }
        @media (min-width: 640px) {
            .beer-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
        }
        .error-message {
            background-color: var(--error-background-color, #fef2f2);
            color: var(--error-text-color, #ef4444);
            padding: 1rem;
            border: 1px solid var(--error-border-color, #fca5a5);
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .info-container {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .info-container p {
            margin-bottom: 1rem;
            color: var(--card-paragraph-color);
        }
        .info-container > p:last-child {
            margin-bottom: 0;
        }
        .info-container button {
            background-color: var(--button-primary-background-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
        }
        .info-container button:hover {
            background-color: var(--button-primary-hover-bg);
        }
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
        .section-header {
            background-color: var(--header-background-color);
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            color: var(--header-text-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: margin-bottom 0.3s ease-in-out;
        }
        .filter-sort-section:has(> .section-content.collapsed) > .section-header {
            margin-bottom: 0;
        }
        .section-header:hover {
            background-color: var(--header-hover-bg);
        }
        .section-content {
            max-height: 1500px;
            opacity: 1;
            overflow: hidden;
            transition: max-height 0.5s ease-in-out, opacity 0.3s ease-in-out, padding 0.3s ease-in-out;
            padding: 1rem;
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .section-content.collapsed {
            max-height: 0;
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
            border: none;
            box-shadow: none;
        }
        .filter-summary {
            max-height: 0;
            opacity: 0;
            margin: 0;
            padding: 0 1rem;
            font-size: 0.875rem;
            color: var(--card-paragraph-color);
            overflow: hidden;
            transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out, margin-top 0.3s ease-in-out, margin-bottom 0.3s ease-in-out;
        }
        .filter-summary-label {
            font-weight: 600;
            color: var(--label-color);
        }
        .filter-summary.visible {
            max-height: 10rem;
            opacity: 1;
            margin-top: 0.75rem;
            margin-bottom: -0.25rem;
            transition-delay: 0.35s;
        }
        .toggle-icon {
            display: inline-block;
            transition: transform 0.3s ease-in-out;
            font-size: 1.2rem;
            margin-left: auto; /* Pushes the icon to the right */
        }
        .toggle-icon.rotated {
            transform: rotate(-90deg);
        }
        .consent-banner, .update-banner, .install-banner {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: var(--palette-surface, #333);
            color: var(--text-color, white);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            z-index: 2000;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
            text-align: center;
        }
        .consent-banner.hidden, .update-banner.hidden, .install-banner.hidden {
            display: none;
        }
        .consent-banner p, .update-banner p, .install-banner p {
            margin: 0;
            font-size: 0.9rem;
        }
        .consent-banner .button-group, .update-banner .button-group, .install-banner .button-group {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
            width: 100%;
            flex-shrink: 0;
        }
        .consent-banner button, .update-banner button, .install-banner button {
            background-color: var(--button-primary-background-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
            width: auto;
        }
        .consent-banner button:hover, .update-banner button:hover, .install-banner button:hover {
            background-color: var(--button-primary-hover-bg);
        }
        .consent-banner .decline-button, .install-banner .decline-button {
            background-color: var(--button-secondary-bg);
        }
        .consent-banner .decline-button:hover, .install-banner .decline-button:hover {
            background-color: var(--button-secondary-hover-bg);
        }
        @media (min-width: 768px) {
            .consent-banner, .update-banner, .install-banner {
                flex-direction: row;
                justify-content: flex-end;
                align-items: center;
                padding: 1rem 2rem;
                gap: 2rem;
            }
            .consent-banner p, .update-banner p, .install-banner p {
                text-align: right;
                flex-grow: 0;
            }
            .consent-banner .button-group, .update-banner .button-group, .install-banner .button-group {
                width: auto;
            }
        }
        .privacy-link {
            display: block;
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--link-color);
            text-decoration: underline;
        }
        .clear-filters-button {
            background-color: var(--button-secondary-bg);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
            margin-top: 0.5rem;
            width: 100%;
        }
        .clear-filters-button:hover {
            background-color: var(--button-secondary-hover-bg);
        }
        @media (min-width: 768px) {
            .clear-filters-button {
                width: auto;
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }
        }
        .search-row {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .search-row .search-container {
            flex: 1;
            min-width: 0;
        }
        .map-open-button {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            font-size: 1rem;
            font-weight: 600;
            line-height: 1.6;
            cursor: pointer;
            transition: background-color 0.2s, border-color 0.2s, box-shadow 0.2s;
        }
        .map-open-button:hover {
            background-color: var(--palette-interactive);
        }
        .map-open-button svg {
            width: 1.25rem;
            height: 1.25rem;
            flex-shrink: 0;
        }
        .map-open-button:focus-visible, .beer-location-link:focus-visible, .map-overlay button:focus-visible {
            outline: none;
            border-color: var(--palette-text-primary);
            box-shadow: 0 0 3px 1px var(--palette-text-primary);
        }
        .beer-location-link {
            display: inline;
            padding: 0;
            border: none;
            border-radius: 0.25rem;
            background: none;
            color: inherit;
            font: inherit;
            letter-spacing: inherit;
            text-transform: inherit;
            text-decoration: underline dotted;
            text-underline-offset: 0.2em;
            cursor: pointer;
        }
        body.map-is-open {
            overflow: hidden;
        }
        /* The bottom banners sit above the map; they come back when it closes. */
        body.map-is-open .consent-banner,
        body.map-is-open .install-banner,
        body.map-is-open .update-banner {
            display: none;
        }
        .map-overlay {
            position: fixed;
            inset: 0;
            z-index: 1500;
            display: flex;
            flex-direction: column;
            background-color: var(--background-color);
            color: var(--text-color);
        }
        .map-overlay[hidden] {
            display: none;
        }
        .map-header {
            padding: max(1rem, env(safe-area-inset-top)) 1rem 1rem;
        }
        .map-icon-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .map-icon-button:hover {
            background-color: var(--palette-interactive);
        }
        .map-icon-button[aria-pressed="true"] {
            background-color: var(--palette-text-primary);
            border-color: var(--palette-text-primary);
            color: var(--palette-primary);
        }
        .map-icon-button svg {
            width: 1.25rem;
            height: 1.25rem;
        }
        .map-toolbar {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 1rem;
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .map-tabs {
            flex: 1 1 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .map-tab.map-close-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            padding: 0.5rem;
        }
        .map-close-button svg {
            width: 1.6em;
            height: 1.6em;
        }
        .map-tab {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            background-color: var(--card-background-color);
            border: 1px solid var(--card-border-color);
            color: var(--card-paragraph-color);
            transition: background-color 0.2s;
            cursor: pointer;
        }
        .map-tab:hover {
            background-color: var(--palette-interactive);
        }
        .map-tab[aria-selected="true"] {
            background-color: var(--button-primary-background-color);
            border-color: transparent;
            color: white;
        }
        @media (max-width: 639px) {
            .map-toolbar {
                padding: 0.75rem;
            }
            .map-tab {
                padding: 0.5rem 0.75rem;
            }
        }
        .map-viewport {
            position: relative;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            background-color: var(--palette-interactive);
        }
        .map-floor {
            position: absolute;
            inset: 0;
        }
        .map-floor[hidden] {
            display: none;
        }
        /* Kept clear of rounded screen corners and the home bar on phones. */
        .map-zoom-controls {
            position: absolute;
            right: 1rem;
            bottom: 2rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .map-zoom-controls .map-icon-button {
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-4xl font-bold text-center mb-6 p-4 rounded-lg shadow-lg" style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            <?php echo htmlspecialchars($festivalTitle); ?>
        </h1>

        <!-- Filter and Sort Section (collapsible) -->
        <div class="filter-sort-section mb-6">
            <div class="section-header" id="filter-sort-header">
                <h2 class="text-xl font-bold flex-grow text-left"><?php echo htmlspecialchars($translations['filters_and_sorting_heading'] ?? 'Filters & Sorting'); ?></h2>
                <span class="toggle-icon">&#9660;</span>
            </div>
            <p class="filter-summary" id="filter-summary"></p>
            <div class="section-content" id="filter-sort-content">
                <!-- Filter Controls -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-x-4 gap-y-2">
                    <div>
                        <label for="session-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_session'] ?? 'Session'); ?>:</label>
                        <select id="session-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_sessions'] ?? 'All Sessions'); ?></option>
                        </select>
                    </div>
<?php if (!empty($routes)): ?>
                    <div>
                        <label for="route-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_route'] ?? 'Tasting route'); ?>:</label>
                        <select id="route-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_routes'] ?? 'All routes'); ?></option>
                        </select>
                    </div>
<?php endif; ?>
                    <div>
                        <label for="style-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_style'] ?? 'Style'); ?>:</label>
                        <select id="style-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_styles'] ?? 'All Styles'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="brewery-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_brewery'] ?? 'Brewery'); ?>:</label>
                        <select id="brewery-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_breweries'] ?? 'All Breweries'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="country-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_country'] ?? 'Country'); ?>:</label>
                        <select id="country-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_countries'] ?? 'All Countries'); ?></option>
                        </select>
                    </div>
                    <div class="filter-checks flex flex-wrap items-center gap-x-4 gap-y-2 mt-2 md:mt-0 col-span-1 md:col-span-2 lg:col-span-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="my-rated-filter" class="mr-2 rounded">
                            <label for="my-rated-filter"><?php echo htmlspecialchars($translations['my_rated_beers'] ?? 'My Rated Beers'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="unrated-filter" class="mr-2 rounded">
                            <label for="unrated-filter"><?php echo htmlspecialchars($translations['unrated_beers'] ?? 'Unrated Beers'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="my-favorites-filter" class="mr-2 rounded">
                            <label for="my-favorites-filter"><?php echo htmlspecialchars($translations['my_favorites'] ?? 'My Favorites'); ?></label>
                        </div>
                    </div>
                </div>
                <button id="clear-filters-button" class="clear-filters-button mt-4 hidden"><?php echo htmlspecialchars($translations['clear_filters_button'] ?? 'Clear Filters'); ?></button>

                <!-- Divider -->
                <hr class="my-4" style="border-color: var(--divider-color);">

                <!-- Sorting Controls -->
                <div>
                    <h3 class="text-lg font-semibold mb-2"><?php echo htmlspecialchars($translations['sorting_heading'] ?? 'Sorting'); ?></h3>
                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                        <select id="sort-by" class="w-full md:w-auto">
                            <option value="brewery-asc"><?php echo htmlspecialchars($translations['sort_brewery_asc'] ?? 'Brewery (A-Z)'); ?></option>
                            <option value="name-asc"><?php echo htmlspecialchars($translations['sort_name_asc'] ?? 'Name (A-Z)'); ?></option>
                            <option value="alc-asc"><?php echo htmlspecialchars($translations['sort_alc_asc'] ?? 'Alcohol (Low-High)'); ?></option>
                            <option value="alc-desc"><?php echo htmlspecialchars($translations['sort_alc_desc'] ?? 'Alcohol (High-Low)'); ?></option>
                            <option value="rating-desc"><?php echo htmlspecialchars($translations['sort_global_rating_desc'] ?? 'Global Rating (High-Low)'); ?></option>
                            <option value="my-rating-desc"><?php echo htmlspecialchars($translations['sort_my_rating_desc'] ?? 'My Rating (High-Low)'); ?></option>
<?php if (!empty($routes)): ?>
                            <option value="route-order"><?php echo htmlspecialchars($translations['sort_route_order'] ?? 'Route order'); ?></option>
<?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // Apply collapsed state immediately to avoid flash of expanded content
            (function() {
                try {
                    var s = JSON.parse(localStorage.getItem('beerFestivalSettings'));
                    var shouldCollapse = s ? s.filterSortCollapsed : true;
                    if (shouldCollapse) {
                        var el = document.getElementById('filter-sort-content');
                        el.style.transition = 'none';
                        document.getElementById('filter-sort-header').style.transition = 'none';
                        el.classList.add('collapsed');
                        document.querySelector('#filter-sort-header .toggle-icon').classList.add('rotated');
                        // Force reflow then restore transitions
                        el.offsetHeight;
                        el.style.transition = '';
                        document.getElementById('filter-sort-header').style.transition = '';
                    }
                } catch(e) {}
            })();
        </script>

        <!-- Search Section (non-collapsible) -->
        <div class="search-section mb-4">
            <div class="search-row">
            <div class="search-container">
                <input type="text" id="search-input" class="w-full search-input pr-12" placeholder="<?php echo htmlspecialchars($translations['search_placeholder'] ?? 'Search by beer name or brewery...'); ?>">
                <button id="clear-search-btn" class="clear-search-btn hidden" aria-label="Clear search">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                    </svg>
                </button>
            </div>
<?php if (!empty($festivalMaps)): ?>
            <button type="button" id="map-open-button" class="map-open-button">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
                <span><?php echo htmlspecialchars($translations['map_button'] ?? 'Map'); ?></span>
            </button>
<?php endif; ?>
            </div>
        </div>

        <div id="beer-list" class="beer-grid">
<?php foreach ($ssrBeers as $beer): ?>
            <article class="beer-card">
                <h2><?= htmlspecialchars($beer['name'] ?? '') ?></h2>
<?php if (!empty($beer['brewery'])): ?>
                <p><?= htmlspecialchars($beer['brewery'] . (!empty($beer['served_by']) ? ' · ' . $beer['served_by'] : '')) ?></p>
<?php endif; ?>
<?php
                $meta = [];
                if (!empty($beer['alc']))     { $meta[] = htmlspecialchars($beer['alc']) . '%'; }
                if (!empty($beer['style']))   { $meta[] = htmlspecialchars($beer['style']); }
                if (!empty($beer['country'])) { $meta[] = htmlspecialchars($beer['country']); }
                if (!empty($beer['session'])) { $meta[] = htmlspecialchars($beer['session']); }
                if ($meta): ?>
                <p><?= implode(' &middot; ', $meta) ?></p>
<?php endif; ?>
            </article>
<?php endforeach; ?>
        </div>

        <!-- Load More Button -->
        <div id="load-more-container" class="flex justify-center mt-4 hidden">
            <button id="load-more-button" class="info-container" style="margin: 0; padding: 0.75rem 2rem;">
                <?php echo htmlspecialchars($translations['load_more_button'] ?? 'Load more beers'); ?>
            </button>
        </div>

        <!-- Info Container -->
        <div class="info-container" id="info-container">
            <p class="text-lg font-semibold mb-2"><?php echo htmlspecialchars($festivalInfoText); ?></p>
            <p><?php echo htmlspecialchars($translations['local_storage_info'] ?? 'Your ratings are saved locally in your browser.'); ?></p>
            <div class="flex justify-center flex-wrap gap-4 mt-4 mb-4">
                <button onclick="window.location.href='my_stats.php'"><?php echo htmlspecialchars($translations['my_stats_title'] ?? 'My Statistics'); ?></button>
                <button id="copy-data-button"><?php echo htmlspecialchars($translations['copy_my_ratings_link'] ?? 'Copy Data'); ?></button>
                <button id="import-data-button"><?php echo htmlspecialchars($translations['import_data_button_text'] ?? 'Import Data'); ?></button>
            </div>
            <div id="statistics-notice-container" class="text-sm">
                <!-- Statistics consent text and button will be injected here -->
            </div>
            <a href="privacy-policy.php" target="_blank" class="privacy-link"><?php echo htmlspecialchars($translations['privacy_policy_link_text'] ?? 'Privacy Policy'); ?></a>
            <p class="text-sm mt-4">
                &copy; <?php echo date("Y"); ?> My BeerFest. All rights reserved.
                <br>
                Source code on <a href="https://github.com/craftbeer-dk/My-BeerFest" target="_blank" class="untappd-link">GitHub</a>
            </p>
        </div>
    </div>

<?php if (!empty($festivalMaps)): ?>
    <div id="map-overlay" class="map-overlay" role="dialog" aria-modal="true" aria-label="<?php echo htmlspecialchars($translations['map_title'] ?? 'Festival map'); ?>" hidden>
        <div class="map-header">
            <div class="map-toolbar">
                <div id="map-tabs" class="map-tabs" role="tablist"></div>
                <button type="button" id="map-close-button" class="map-tab map-close-button" aria-label="<?php echo htmlspecialchars($translations['back_to_app_link'] ?? 'Back to the app'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                </button>
            </div>
        </div>
        <div id="map-viewport" class="map-viewport">
            <div class="map-zoom-controls">
                <button type="button" id="map-loupe" class="map-icon-button" aria-pressed="false" aria-label="<?php echo htmlspecialchars($translations['map_loupe'] ?? 'Magnifier'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><line x1="15.5" y1="15.5" x2="21" y2="21"/></svg>
                </button>
                <button type="button" id="map-zoom-in" class="map-icon-button" aria-label="<?php echo htmlspecialchars($translations['map_zoom_in'] ?? 'Zoom in'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
                <button type="button" id="map-zoom-out" class="map-icon-button" aria-label="<?php echo htmlspecialchars($translations['map_zoom_out'] ?? 'Zoom out'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </button>
                <button type="button" id="map-zoom-reset" class="map-icon-button" aria-label="<?php echo htmlspecialchars($translations['map_reset'] ?? 'Show the whole map'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 9 4 4 9 4"/><polyline points="20 9 20 4 15 4"/><polyline points="4 15 4 20 9 20"/><polyline points="20 15 20 20 15 20"/></svg>
                </button>
            </div>
        </div>
    </div>
    <template id="map-floors">
<?php foreach ($festivalMaps as $map): ?>
        <div class="map-floor" data-floor-name="<?php echo htmlspecialchars($map['name']); ?>"><?php echo $map['svg']; ?></div>
<?php endforeach; ?>
    </template>
<?php endif; ?>

    <!-- Message Box for user feedback -->
    <div id="message-box" class="message-box"></div>

    <!-- Update Banner -->
    <div id="update-banner" class="update-banner hidden">
        <p><?php echo htmlspecialchars($translations['update_available_text'] ?? 'A new version is available.'); ?></p>
        <div class="button-group">
            <button id="update-app-button"><?php echo htmlspecialchars($translations['update_button_text'] ?? 'Update'); ?></button>
        </div>
    </div>

    <!-- PWA Install Banner (Android/Desktop) -->
    <div id="install-banner" class="install-banner hidden">
        <p><?php echo htmlspecialchars($translations['install_app_text'] ?? 'Install this app on your device for quick access'); ?></p>
        <div class="button-group">
            <button id="install-app-button"><?php echo htmlspecialchars($translations['install_app_button'] ?? 'Install'); ?></button>
            <button id="install-dismiss-button" class="decline-button"><?php echo htmlspecialchars($translations['install_app_dismiss'] ?? 'Not now'); ?></button>
        </div>
    </div>

    <!-- PWA Install Banner (iOS Safari) -->
    <div id="install-ios-banner" class="install-banner hidden">
        <p><?php
            $iosText = htmlspecialchars($translations['install_ios_text'] ?? 'To install this app, tap {icon} and choose "Add to Home Screen"');
            $shareIconSvg = '<svg style="display:inline-block;vertical-align:middle;width:1.2em;height:1.2em;margin:0 0.15em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>';
            echo str_replace('{icon}', $shareIconSvg, $iosText);
        ?></p>
        <div class="button-group">
            <button id="install-ios-dismiss-button" class="decline-button"><?php echo htmlspecialchars($translations['install_ios_dismiss'] ?? 'OK'); ?></button>
        </div>
    </div>

    <!-- PWA Install Banner (iOS non-Safari) -->
    <div id="install-ios-safari-banner" class="install-banner hidden">
        <p><?php echo htmlspecialchars($translations['install_ios_open_safari'] ?? 'To install this app, open it in Safari first'); ?></p>
        <div class="button-group">
            <button id="install-ios-safari-dismiss-button" class="decline-button"><?php echo htmlspecialchars($translations['install_ios_dismiss'] ?? 'OK'); ?></button>
        </div>
    </div>

    <!-- Statistics Consent Banner -->
    <?php if ($enableStatisticsLogging): ?>
    <div id="consent-banner" class="consent-banner hidden">
        <p id="consent-text"><?php echo htmlspecialchars($translations['statistics_consent_text'] ?? 'May we collect anonymous usage statistics to improve the app? Your personal selections are always saved locally in your browser regardless of your choice.'); ?></p>
        <div class="button-group">
            <button id="accept-stats-button"><?php echo htmlspecialchars($translations['accept_stats'] ?? 'Accept'); ?></button>
            <button id="decline-stats-button" class="decline-button"><?php echo htmlspecialchars($translations['decline_stats'] ?? 'Decline'); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- App State ---
            let allBeers = [];
            let userRatings = {};
            let userFavorites = {};
            let statsConsent = null;
            const SESSION_ID_KEY = 'statsSessionId';
            let sessionId = null;
            let countryFlags = {};
            let validBeerIds = new Set();
            let lastFetchTimestamp = 0;
            let lastResultCount = 0;
            let eventQueue = [];
            let eventFlushTimer = null;
            let searchLogTimer = null;
            let appOpenTracked = false;
            let hiddenAt = null;
            const RESUME_AFTER_MS = 30 * 60 * 1000;
            const devicePlatform = (/iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) ? 'ios'
                : /Android/i.test(navigator.userAgent) ? 'android' : 'other';
            const displayMode = (window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches) ? 'standalone' : 'browser';
            const REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes
            
            // Pagination state
            const ITEMS_PER_PAGE = 12;
            let displayedCount = ITEMS_PER_PAGE;

            // --- Injected PHP Data ---
            const translations = <?php echo $translationsJson; ?>;
            const enableStatisticsLogging = <?php echo json_encode($enableStatisticsLogging); ?>;
            const enableMainstyleFiltering = <?php echo json_encode(true); ?>;
            const routes = <?php echo json_encode($routes); ?>;
            const styleOrder = <?php echo json_encode($styleOrder); ?>;
            const beerDataUrl = '/data/beers.json';

            // --- DOM Elements ---
            const beerListContainer = document.getElementById('beer-list');
            const loadMoreContainer = document.getElementById('load-more-container');
            const loadMoreButton = document.getElementById('load-more-button');
            const searchInput = document.getElementById('search-input');
            const clearSearchBtn = document.getElementById('clear-search-btn');
            const styleFilter = document.getElementById('style-filter');
            const breweryFilter = document.getElementById('brewery-filter');
            const countryFilter = document.getElementById('country-filter');
            const sessionFilter = document.getElementById('session-filter');
            const routeFilter = document.getElementById('route-filter');
            const sortBy = document.getElementById('sort-by');
            const myRatedFilter = document.getElementById('my-rated-filter');
            const unratedFilter = document.getElementById('unrated-filter');
            const myFavoritesFilter = document.getElementById('my-favorites-filter');
            const copyDataButton = document.getElementById('copy-data-button');
            const importDataButton = document.getElementById('import-data-button');
            const messageBox = document.getElementById('message-box');
            const consentBanner = document.getElementById('consent-banner');
            const acceptStatsButton = document.getElementById('accept-stats-button');
            const declineStatsButton = document.getElementById('decline-stats-button');
            const statsNoticeContainer = document.getElementById('statistics-notice-container');
            const clearFiltersButton = document.getElementById('clear-filters-button');
            const filterSortHeader = document.getElementById('filter-sort-header');
            const filterSortContent = document.getElementById('filter-sort-content');
            const filterSortToggleIcon = filterSortHeader.querySelector('.toggle-icon');
            const filterSummary = document.getElementById('filter-summary');

            // --- Functions (full definitions) ---

            // Security helpers: escape for HTML attributes and sanitize URLs
            function escAttr(str) {
                if (str == null) return '';
                return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function safeUrl(url) {
                if (typeof url !== 'string') return '#';
                const trimmed = url.trim();
                if (trimmed.startsWith('https://') || trimmed.startsWith('http://')) return trimmed;
                return '#';
            }

            function saveState() {
                const settings = {
                    search: searchInput.value,
                    style: styleFilter.value,
                    brewery: breweryFilter.value,
                    country: countryFilter.value,
                    session: sessionFilter.value,
                    route: routeFilter ? routeFilter.value : '',
                    myRated: myRatedFilter.checked,
                    unrated: unratedFilter.checked,
                    myFavorites: myFavoritesFilter.checked,
                    sortBy: sortBy.value,
                    filterSortCollapsed: filterSortContent.classList.contains('collapsed')
                };

                try {
                    localStorage.setItem('userRatings', JSON.stringify(userRatings));
                    localStorage.setItem('userFavorites', JSON.stringify(userFavorites));
                    if (statsConsent !== null) {
                        localStorage.setItem('statsConsent', statsConsent);
                    }
                    localStorage.setItem('beerFestivalSettings', JSON.stringify(settings));
                } catch (e) {
                    console.error("Could not save data to localStorage", e);
                }
            }

            function loadState() {
                try {
                    userRatings = JSON.parse(localStorage.getItem('userRatings')) || {};
                    userFavorites = JSON.parse(localStorage.getItem('userFavorites')) || {};
                    statsConsent = localStorage.getItem('statsConsent'); // Can be null, 'true', or 'false'

                    const savedSettings = JSON.parse(localStorage.getItem('beerFestivalSettings'));
                    if (savedSettings) {
                        searchInput.value = savedSettings.search || '';
                        styleFilter.value = savedSettings.style || '';
                        breweryFilter.value = savedSettings.brewery || '';
                        countryFilter.value = savedSettings.country || '';
                        sessionFilter.value = savedSettings.session || '';
                        if (routeFilter) {
                            populateRouteOptions();
                            routeFilter.value = savedSettings.route || '';
                        }
                        myRatedFilter.checked = savedSettings.myRated || false;
                        unratedFilter.checked = savedSettings.unrated || false;
                        myFavoritesFilter.checked = savedSettings.myFavorites || false;
                        sortBy.value = savedSettings.sortBy || 'brewery-asc';
                        // Fall back to a sane sort if the saved option is gone (e.g. routes
                        // removed) or "route-order" was restored without a selected route.
                        if (sortBy.selectedIndex < 0 ||
                            (sortBy.value === 'route-order' && !(routeFilter && routeFilter.value))) {
                            sortBy.value = 'brewery-asc';
                        }
                        toggleSection(filterSortContent, filterSortToggleIcon, savedSettings.filterSortCollapsed);
                    } else {
                        toggleSection(filterSortContent, filterSortToggleIcon, true);
                    }
                } catch (e) {
                    console.error("Could not load data from localStorage", e);
                    // Clear potentially corrupted data
                    localStorage.removeItem('userRatings');
                    localStorage.removeItem('userFavorites');
                    localStorage.removeItem('statsConsent');
                    localStorage.removeItem(SESSION_ID_KEY);
                    localStorage.removeItem('beerFestivalSettings');
                }
            }
            
            function showMessage(message, type = 'success') {
                messageBox.textContent = message;
                messageBox.className = `message-box active ${type === 'success' ? 'bg-green-500' : 'bg-red-500'}`;
                setTimeout(() => {
                    messageBox.classList.remove('active');
                }, 3000);
            }

            function copyShareableLink() {
                try {
                    const settings = {
                        search: searchInput.value,
                        style: styleFilter.value,
                        brewery: breweryFilter.value,
                        country: countryFilter.value,
                        session: sessionFilter.value,
                        route: routeFilter ? routeFilter.value : '',
                        myRated: myRatedFilter.checked,
                        unrated: unratedFilter.checked,
                        myFavorites: myFavoritesFilter.checked,
                        sortBy: sortBy.value,
                        filterSortCollapsed: filterSortContent.classList.contains('collapsed')
                    };
                    const allData = {
                        ratings: userRatings,
                        favorites: userFavorites,
                        consent: statsConsent,
                        settings: settings
                    };
                    const jsonString = JSON.stringify(allData);
                    const base64String = btoa(encodeURIComponent(jsonString));
                    
                    const currentUrl = window.location.origin + window.location.pathname;
                    let shareableLink = `${currentUrl}?data=${base64String}`;

                    const tempInput = document.createElement('textarea');
                    tempInput.value = shareableLink;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);

                    showMessage(translations['link_copied_success'] ?? 'Link with ratings copied to clipboard!', 'success');
                } catch (error) {
                    console.error('Error copying data link:', error);
                    showMessage(translations['error_copying_link'] ?? 'Error copying link.', 'error');
                }
            }
            
            function processImportedData(encodedData) {
                 try {
                    const jsonString = decodeURIComponent(atob(encodedData));
                    const allData = JSON.parse(jsonString);
                    
                    const importedRatings = allData.ratings || {};
                    const sanitizedRatings = {};
                    for (const beerId in importedRatings) {
                        if (Object.prototype.hasOwnProperty.call(importedRatings, beerId) && validBeerIds.has(beerId)) {
                            const rating = importedRatings[beerId];
                            if (typeof rating === 'number' && isFinite(rating) && rating >= 0 && rating <= 5) {
                                sanitizedRatings[beerId] = rating;
                            }
                        }
                    }
                    localStorage.setItem('userRatings', JSON.stringify(sanitizedRatings));

                    const importedFavorites = allData.favorites || {};
                    const sanitizedFavorites = {};
                    for (const beerId in importedFavorites) {
                        if (Object.prototype.hasOwnProperty.call(importedFavorites, beerId) && validBeerIds.has(beerId)) {
                            sanitizedFavorites[beerId] = true;
                        }
                    }
                    localStorage.setItem('userFavorites', JSON.stringify(sanitizedFavorites));

                    if (allData.consent === 'false') {
                        const wasConsented = localStorage.getItem('statsConsent') === 'true';
                        localStorage.setItem('statsConsent', 'false');
                        if (wasConsented) logStatsConsentToServer('false');
                        localStorage.removeItem('standaloneLaunched');
                        clearSessionId();
                    }

                    if (allData.settings) {
                        localStorage.setItem('beerFestivalSettings', JSON.stringify(allData.settings));
                    }

                    showMessage(translations['ratings_imported_success'] ?? 'Data imported successfully!', 'success');
                    
                    const newUrl = window.location.origin + window.location.pathname;
                    history.replaceState({}, document.title, newUrl);

                    loadState();
                    renderBeers();
                    updateClearButtonState();

                } catch (e) {
                    console.error("Import error:", e);
                    showMessage(translations['error_importing_ratings_invalid_format'] ?? 'Error importing data: Invalid format.', 'error');
                }
            }

            function importDataFromUrlOnLoad() {
                const urlParams = new URLSearchParams(window.location.search);
                const encodedData = urlParams.get('data');
                if (encodedData) {
                    processImportedData(encodedData);
                }
            }

            function toggleSection(contentElement, iconElement, forceCollapse = null, animate = false) {
                const isCollapsed = contentElement.classList.contains('collapsed');
                const shouldCollapse = forceCollapse !== null ? forceCollapse : !isCollapsed;
                
                if (shouldCollapse) {
                    contentElement.classList.add('collapsed');
                    iconElement.classList.add('rotated');
                } else {
                    contentElement.classList.remove('collapsed');
                    iconElement.classList.remove('rotated');
                }
                updateFilterSummary(animate);
            }

            function updateFilterSummary(animate = false) {
                const selectText = sel => (sel && sel.value) ? sel.options[sel.selectedIndex].text : '';
                const parts = [
                    selectText(sessionFilter),
                    selectText(routeFilter),
                    selectText(breweryFilter),
                    selectText(styleFilter),
                    selectText(countryFilter),
                    myRatedFilter.checked ? (translations['my_rated_beers'] ?? 'My Rated Beers') : '',
                    unratedFilter.checked ? (translations['unrated_beers'] ?? 'Unrated Beers') : '',
                    myFavoritesFilter.checked ? (translations['my_favorites'] ?? 'My Favorites') : ''
                ].filter(Boolean);
                filterSummary.textContent = '';
                const summaryLabel = document.createElement('span');
                summaryLabel.className = 'filter-summary-label';
                summaryLabel.textContent = (translations['active_filters_label'] ?? 'Active filters') + ': ';
                filterSummary.append(summaryLabel, parts.join(', '));
                const visible = parts.length > 0 && filterSortContent.classList.contains('collapsed');
                if (!animate) filterSummary.style.transition = 'none';
                filterSummary.classList.toggle('visible', visible);
                if (!animate) {
                    filterSummary.offsetHeight;
                    filterSummary.style.transition = '';
                }
            }
            
            function updateClearButtonState() {
                const isSessionActive = sessionFilter.value !== '';
                const areOtherFiltersActive = styleFilter.value !== '' ||
                                              breweryFilter.value !== '' ||
                                              countryFilter.value !== '' ||
                                              searchInput.value !== '' ||
                                              (routeFilter && routeFilter.value !== '') ||
                                              myRatedFilter.checked ||
                                              unratedFilter.checked ||
                                              myFavoritesFilter.checked;

                if (!isSessionActive && !areOtherFiltersActive) {
                    clearFiltersButton.classList.add('hidden');
                } else {
                    clearFiltersButton.classList.remove('hidden');
                    if (areOtherFiltersActive) {
                        clearFiltersButton.textContent = translations['clear_filters_button'] ?? 'Clear Filters';
                    } else {
                        clearFiltersButton.textContent = translations['clear_session_button'] ?? 'Clear Session';
                    }
                }
                updateFilterSummary();
            }

            // Order the style dropdown by the optional stylegroups.json config:
            // configured styles first (in config order, case-insensitive match),
            // then any remaining styles alphabetically. Empty config = alphabetical.
            function sortStylesByConfig(styles) {
                if (!styleOrder || !styleOrder.length) return styles.slice().sort();
                const rank = new Map(styleOrder.map((s, i) => [s.toLowerCase(), i]));
                const configured = [], rest = [];
                styles.forEach(s => (rank.has(s.toLowerCase()) ? configured : rest).push(s));
                configured.sort((a, b) => rank.get(a.toLowerCase()) - rank.get(b.toLowerCase()));
                rest.sort((a, b) => a.localeCompare(b));
                return configured.concat(rest);
            }

            function initializeFilters() {
                const stylesToFilter = enableMainstyleFiltering ? allBeers.map(beer => beer.mainstyle) : allBeers.map(beer => beer.style);
                populateSelect(styleFilter, sortStylesByConfig([...new Set(stylesToFilter)]));
                populateSelect(breweryFilter, [...new Set(allBeers.flatMap(beer => [beer.brewery, beer.served_by]).filter(Boolean))].sort());
                populateSelect(countryFilter, [...new Set(allBeers.map(beer => beer.country))].sort());
                populateSelect(sessionFilter, [...new Set(allBeers.map(beer => beer.session))]);
                if (routeFilter) populateRouteOptions();
            }

            // Populate the tasting-route dropdown for the active session. A route with no
            // session applies to every session; a route with a session shows only when that
            // session (case-insensitive: beers.json "Fredag" vs routes.json "fredag") is
            // active or no session is filtered.
            function populateRouteOptions() {
                const session = sessionFilter.value.toLowerCase();
                const visible = routes.filter(r => !session || !r.session || r.session.toLowerCase() === session);
                populateSelect(routeFilter, visible.map(r => r.name));
            }

            function populateSelect(selectElement, options) {
                // Preserve the current selection across repopulation when it's still offered.
                const prev = selectElement.value;
                // Keep the first option ("All …") and remove the rest
                while (selectElement.options.length > 1) {
                    selectElement.removeChild(selectElement.lastChild);
                }
                options.forEach(option => {
                    const opt = document.createElement('option');
                    opt.value = option;
                    opt.textContent = option;
                    selectElement.appendChild(opt);
                });
                if (options.indexOf(prev) >= 0) selectElement.value = prev;
            }

            function newSessionId() {
                return 'user_' + Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
            }

            function getSessionId() {
                if (sessionId) return sessionId;
                try { sessionId = localStorage.getItem(SESSION_ID_KEY); } catch (e) {}
                if (!sessionId || !/^[a-zA-Z0-9_.-]{1,64}$/.test(sessionId)) {
                    sessionId = newSessionId();
                    try { localStorage.setItem(SESSION_ID_KEY, sessionId); } catch (e) {}
                }
                return sessionId;
            }

            function clearSessionId() {
                sessionId = null;
                try { localStorage.removeItem(SESSION_ID_KEY); } catch (e) {}
            }

            function sendRatingToServer(beerId, rating) {
                if (!enableStatisticsLogging || statsConsent !== 'true') return;
                fetch('log_rating.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ beer_id: beerId, rating: rating, session_id: getSessionId() }),
                }).catch(error => console.error('Error sending rating log:', error));
            }

            function trackEvent(type, data) {
                if (!enableStatisticsLogging || statsConsent !== 'true') return;
                eventQueue.push({ type, data });
                if (eventQueue.length >= 20) {
                    flushEvents();
                } else if (!eventFlushTimer) {
                    eventFlushTimer = setTimeout(flushEvents, 10000);
                }
            }

            function trackAppOpen(source) {
                trackEvent('app_open', { mode: displayMode, source, platform: devicePlatform });
            }

            // Safari gives no install events, so count the first consented launch as an installed app.
            function trackFirstLaunch() {
                if (!enableStatisticsLogging || statsConsent !== 'true' || displayMode !== 'standalone') return;
                try {
                    if (localStorage.getItem('standaloneLaunched')) return;
                    trackEvent('install', { step: 'first_launch', platform: devicePlatform });
                    localStorage.setItem('standaloneLaunched', Date.now().toString());
                } catch (e) {}
            }
            window.trackEvent = trackEvent;

            function flushEvents() {
                clearTimeout(eventFlushTimer);
                eventFlushTimer = null;
                if (statsConsent !== 'true') {
                    eventQueue.length = 0;
                    return;
                }
                while (eventQueue.length > 0) {
                    fetch('log_event.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ session_id: getSessionId(), events: eventQueue.splice(0, 25) }),
                        keepalive: true,
                    }).catch(error => console.error('Error sending event log:', error));
                }
            }

            function logStatsConsentToServer(consent) {
                if (!enableStatisticsLogging) return;
                let id;
                if (consent === 'true') {
                    id = getSessionId();
                } else {
                    id = sessionId;
                    if (!id) { try { id = localStorage.getItem(SESSION_ID_KEY); } catch (e) {} }
                    id = id || newSessionId();
                }
                fetch('log_cookie_consent.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ consent: consent === 'true', session_id: id }),
                }).catch(error => console.error('Error sending cookie consent log:', error));
            }

            function renderBeers() {
                let filteredBeers = [...allBeers];
                const currentRoute = (routeFilter && routeFilter.value) ? routes.find(r => r.name === routeFilter.value) : null;
                const routePos = currentRoute ? new Map(currentRoute.beers.map((id, i) => [id, i])) : null;
                // The "Route order" sort only means something while a route is selected; grey it out otherwise.
                const routeSortOption = sortBy.querySelector('option[value="route-order"]');
                if (routeSortOption) routeSortOption.disabled = !currentRoute;
                const searchTerm = searchInput.value.trim().replace(/\s+/g, ' ').toLowerCase();
                
                if (searchTerm) {
                    filteredBeers = filteredBeers.filter(beer => beer.name.toLowerCase().includes(searchTerm) || beer.brewery.toLowerCase().includes(searchTerm)
                        || (beer.served_by || '').toLowerCase().includes(searchTerm));
                }
                if (styleFilter.value) {
                    const styleProp = enableMainstyleFiltering ? 'mainstyle' : 'style';
                    filteredBeers = filteredBeers.filter(beer => beer[styleProp] === styleFilter.value);
                }
                // A pourer (served_by, e.g. a bar or importer) lists everything it serves.
                if (breweryFilter.value) filteredBeers = filteredBeers.filter(beer => beer.brewery === breweryFilter.value || beer.served_by === breweryFilter.value);
                if (countryFilter.value) filteredBeers = filteredBeers.filter(beer => beer.country === countryFilter.value);
                if (sessionFilter.value) filteredBeers = filteredBeers.filter(beer => beer.session === sessionFilter.value);
                if (currentRoute) filteredBeers = filteredBeers.filter(beer => routePos.has(beer.id));

                if (myRatedFilter.checked) filteredBeers = filteredBeers.filter(beer => userRatings[beer.id] !== undefined);
                if (unratedFilter.checked) filteredBeers = filteredBeers.filter(beer => userRatings[beer.id] === undefined);
                if (myFavoritesFilter.checked) filteredBeers = filteredBeers.filter(beer => userFavorites[beer.id]);

                const sortOption = sortBy.value;
                filteredBeers.sort((a, b) => {
                    const myRatingA = userRatings[a.id] || 0;
                    const myRatingB = userRatings[b.id] || 0;
                    switch (sortOption) {
                        case 'name-asc': return a.name.localeCompare(b.name);
                        case 'brewery-asc': return (a.served_by || a.brewery).localeCompare(b.served_by || b.brewery) || a.brewery.localeCompare(b.brewery);
                        case 'alc-asc': return (a.alc || 0) - (b.alc || 0);
                        case 'alc-desc': return (b.alc || 0) - (a.alc || 0);
                        case 'rating-desc': return (b.rating || 0) - (a.rating || 0);
                        case 'my-rating-desc': return myRatingB - myRatingA;
                        case 'route-order': return routePos ? (routePos.get(a.id) - routePos.get(b.id)) : 0;
                        default: return 0;
                    }
                });

                lastResultCount = filteredBeers.length;

                // Pagination visibility logic
                if (filteredBeers.length > displayedCount) {
                    loadMoreContainer.classList.remove('hidden');
                } else {
                    loadMoreContainer.classList.add('hidden');
                }

                // Slice array for current page
                const beersToRender = filteredBeers.slice(0, displayedCount);

                beerListContainer.innerHTML = '';
                if (beersToRender.length === 0) {
                    beerListContainer.innerHTML = `<p class="text-center col-span-full">${translations['no_beers_match'] ?? 'No beers match the current filters.'}</p>`;
                    return;
                }
                
                const fragment = document.createDocumentFragment();
                beersToRender.forEach(beer => {
                    const beerCard = document.createElement('div');
                    beerCard.className = 'beer-card';
                    const userRating = userRatings[beer.id] !== undefined ? userRatings[beer.id] : '';
                    const isFavorited = userFavorites[beer.id];
                    let displayedStyle = beer.style;
                    if (enableMainstyleFiltering) {
                        displayedStyle = beer.mainstyle + (beer.substyle ? ` (${beer.substyle})` : '');
                    }
                    
                    const ratingPlaceholder = translations['rate_beer'] ?? 'Rate this beer';
                    const flagEmoji = countryFlags[beer.country.toLowerCase()] || '';
                    const untappdUrl = safeUrl(beer.untappd);
                    const hasUntappd = untappdUrl !== '#';

                    beerCard.innerHTML = `
                        <svg class="favorite-star ${isFavorited ? 'favorited' : ''}" data-beer-id="${escAttr(beer.id)}" title="Favorite" viewBox="0 0 24 24">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                        <h2></h2>
                        <p class="beer-brewery brewery-text"></p>
                        <p class="beer-style style-text"></p>
                        <p class="beer-session session-text"></p>
                        ${beer.note ? '<p class="beer-note"><span class="note-text"></span></p>' : ''}
                        <div class="beer-actions-container">
                            <a href="${hasUntappd ? escAttr(untappdUrl) : '#'}" ${hasUntappd ? 'target="_blank"' : 'data-nolink="1"'} class="untappd-button">
                                <div class="untappd-logo"></div>
                                <span class="global-rating-text"></span>
                            </a>
                            <select class="rating-select" data-beer-id="${escAttr(beer.id)}">
                                <option value="">${ratingPlaceholder}</option>
                                ${generateRatingOptions(userRating)}
                            </select>
                        </div>
                    `;

                    const headingEl = beerCard.querySelector('h2');
                    headingEl.textContent = beer.name;
                    if (beer.alc !== null && beer.alc !== undefined) {
                        const abvEl = document.createElement('span');
                        abvEl.className = 'beer-abv';
                        abvEl.textContent = ` – ${beer.alc}%`;
                        headingEl.appendChild(abvEl);
                    }
                    const breweryText = beer.served_by ? `${beer.brewery} · ${beer.served_by}` : beer.brewery;
                    beerCard.querySelector('.brewery-text').textContent = `${breweryText} ${flagEmoji}`;
                    beerCard.querySelector('.style-text').textContent = displayedStyle;
                    const sessionEl = beerCard.querySelector('.session-text');
                    sessionEl.textContent = beer.session !== undefined ? beer.session : 'N/A';
                    if (beer.location) {
                        sessionEl.append(' - ');
                        if (floorMap && floorMap.resolve(beer.location)) {
                            const locationButton = document.createElement('button');
                            locationButton.type = 'button';
                            locationButton.className = 'beer-location-link';
                            locationButton.dataset.location = beer.location;
                            locationButton.setAttribute('aria-label', `${translations['map_show_on_map'] ?? 'Show on map'}: ${beer.location}`);
                            locationButton.textContent = beer.location;
                            sessionEl.appendChild(locationButton);
                        } else {
                            sessionEl.append(beer.location);
                        }
                    }
                    const noteEl = beerCard.querySelector('.note-text');
                    if (noteEl) noteEl.textContent = beer.note;
                    beerCard.querySelector('.global-rating-text').textContent = beer.rating !== null && beer.rating !== undefined ? beer.rating.toFixed(2) : 'N/A';
                    
                    fragment.appendChild(beerCard);
                });
                beerListContainer.appendChild(fragment);

                document.querySelectorAll('.rating-select').forEach(select => {
                    select.addEventListener('change', handleRatingChange);
                });

                document.querySelectorAll('.untappd-button[data-nolink]').forEach(button => {
                    button.addEventListener('click', (event) => {
                        event.preventDefault();
                        showMessage(translations['not_on_untappd'] ?? 'Not found on Untappd', 'error');
                    });
                });
            }

            function generateRatingOptions(currentRating) {
                let optionsHtml = '';
                for (let i = 5; i >= 0.25; i -= 0.25) {
                    const value = i.toFixed(2);
                    const selected = (parseFloat(currentRating) === parseFloat(value)) ? 'selected' : '';
                    optionsHtml += `<option value="${value}" ${selected}>${value}</option>`;
                }
                const noRatingLabel = translations['no_rating'] ?? 'No rating';
                const noRatingSelected = (currentRating === 0) ? 'selected' : '';
                optionsHtml += `<option value="0" ${noRatingSelected}>${noRatingLabel}</option>`;
                return optionsHtml;
            }

            function handleRatingChange(event) {
                const { beerId } = event.target.dataset;
                const newRating = parseFloat(event.target.value);

                if (beerId) {
                    if (!isNaN(newRating)) {
                        userRatings[beerId] = newRating;
                        sendRatingToServer(beerId, newRating);
                    } else {
                        delete userRatings[beerId];
                    }
                }
                saveState();
                if (myRatedFilter.checked || unratedFilter.checked) {
                    renderBeers();
                }
            }

            function handleFavoriteToggle(event) {
                const starSvg = event.target.closest('.favorite-star');
                if (!starSvg) return;

                const beerId = starSvg.dataset.beerId;
                if (!beerId) return;

                if (userFavorites[beerId]) {
                    delete userFavorites[beerId];
                    starSvg.classList.remove('favorited');
                } else {
                    userFavorites[beerId] = true;
                    starSvg.classList.add('favorited');
                }
                saveState();
                trackEvent('favorite_toggle', { beer_id: beerId, on: !!userFavorites[beerId] });
                
                if (myFavoritesFilter.checked) {
                    renderBeers();
                }
            }

            function setStatsConsent(consent, fromUserInteraction = false) {
                const wasConsented = statsConsent === 'true';
                if (!consent && wasConsented) flushEvents();
                statsConsent = consent ? 'true' : 'false';
                if (consent && !wasConsented && fromUserInteraction) {
                    trackAppOpen('load');
                    trackFirstLaunch();
                }
                if (fromUserInteraction) {
                    logStatsConsentToServer(statsConsent);
                }
                if (!consent) {
                    try { localStorage.removeItem('standaloneLaunched'); } catch (e) {}
                    clearSessionId();
                }
                
                if(consentBanner) consentBanner.classList.add('hidden');

                if (fromUserInteraction) {
                    const message = consent ? (translations['stats_accepted_message'] ?? 'Thank you for helping improve the app!') : (translations['stats_declined_message'] ?? 'You have opted out of statistics collection.');
                    showMessage(message, 'success');
                }
                updateStatisticsNotice();
                saveState();
            }

            function updateStatisticsNotice() {
                if (!enableStatisticsLogging) {
                    statsNoticeContainer.innerHTML = `<p>${translations['stats_disabled_by_owner'] ?? 'Statistics collection is disabled by the site owner.'}</p>`;
                    return;
                }

                let noticeHtml = '';
                if (statsConsent === 'true') {
                    noticeHtml = `<p>${translations['stats_notice_accepted'] ?? 'Anonymous usage statistics are being collected.'}</p><button id="change-consent-button" class="text-sm underline">${translations['change_consent_button'] ?? 'Change my choice'}</button>`;
                } else if (statsConsent === 'false') {
                    noticeHtml = `<p>${translations['stats_notice_declined'] ?? 'You are not contributing to anonymous usage statistics.'}</p><button id="change-consent-button" class="text-sm underline">${translations['change_consent_button'] ?? 'Change my choice'}</button>`;
                }
                statsNoticeContainer.innerHTML = noticeHtml;
                
                const changeButton = document.getElementById('change-consent-button');
                if(changeButton) {
                    changeButton.addEventListener('click', () => {
                        if(consentBanner) consentBanner.classList.remove('hidden');
                    });
                }
            }
            
            async function refreshDataIfNeeded() {
                try {
                    const response = await fetch(beerDataUrl + '?t=' + new Date().getTime()); // Cache-busting
                    if (!response.ok) return;

                    const newBeers = await response.json();
                    
                    if (JSON.stringify(newBeers) !== JSON.stringify(allBeers)) {
                        allBeers = newBeers;
                        processBeerData(); 
                        renderBeers();
                        showMessage(translations['beer_list_updated'] ?? "Beer list updated!", 'success');
                    }
                } catch (error) {
                    console.warn("Could not refresh beer list, might be offline.", error);
                }
            }
            
            function processBeerData() {
                validBeerIds = new Set(allBeers.map(beer => beer.id));
                allBeers.forEach(beer => {
                    if (beer.style && typeof beer.style === 'string') {
                        const parts = beer.style.split(' - ');
                        beer.mainstyle = parts[0].trim();
                        beer.substyle = parts.length > 1 ? parts.slice(1).join(' - ').trim() : '';
                    } else {
                        beer.mainstyle = beer.style || 'N/A';
                        beer.substyle = '';
                    }
                });
            }

            function initializeAppListeners() {
                // sessionFilter (and routeFilter) get dedicated handlers below because
                // changing the session repopulates the route dropdown.
                const allFilters = [styleFilter, breweryFilter, countryFilter, sortBy, myRatedFilter, unratedFilter, myFavoritesFilter];

                const filterNames = {
                    'session-filter': 'session', 'route-filter': 'route', 'style-filter': 'style',
                    'brewery-filter': 'brewery', 'country-filter': 'country', 'my-rated-filter': 'my_rated',
                    'unrated-filter': 'unrated', 'my-favorites-filter': 'favorites'
                };
                const trackFilterChange = el => trackEvent('filter_change', {
                    filter: filterNames[el.id],
                    value: el.type === 'checkbox' ? (el.checked ? 'on' : 'off') : el.value,
                    results: lastResultCount
                });

                allFilters.forEach(el => el.addEventListener('input', () => {
                    displayedCount = ITEMS_PER_PAGE; // Reset to page 1 on filter change
                    saveState();
                    renderBeers();
                    updateClearButtonState();
                    if (el === sortBy) {
                        trackEvent('sort_change', { value: sortBy.value });
                    } else {
                        trackFilterChange(el);
                    }
                }));

                sessionFilter.addEventListener('input', () => {
                    displayedCount = ITEMS_PER_PAGE;
                    if (routeFilter) {
                        // Repopulate for the new session; a still-valid selection is preserved,
                        // otherwise it resets to '' and we drop the now-meaningless route sort.
                        populateRouteOptions();
                        if (!routeFilter.value && sortBy.value === 'route-order') sortBy.value = 'brewery-asc';
                    }
                    saveState();
                    renderBeers();
                    updateClearButtonState();
                    trackFilterChange(sessionFilter);
                });

                if (routeFilter) {
                    routeFilter.addEventListener('input', () => {
                        displayedCount = ITEMS_PER_PAGE;
                        if (routeFilter.value) {
                            // Selecting a route clears every other filter except the session.
                            searchInput.value = '';
                            styleFilter.value = '';
                            breweryFilter.value = '';
                            countryFilter.value = '';
                            myRatedFilter.checked = false;
                            unratedFilter.checked = false;
                            myFavoritesFilter.checked = false;
                            clearSearchBtn.classList.add('hidden');
                            sortBy.value = 'route-order';
                        } else if (sortBy.value === 'route-order') {
                            sortBy.value = 'brewery-asc';
                        }
                        saveState();
                        renderBeers();
                        updateClearButtonState();
                        trackFilterChange(routeFilter);
                    });
                }

                searchInput.addEventListener('input', () => {
                    displayedCount = ITEMS_PER_PAGE; // Reset to page 1 on search
                    clearSearchBtn.classList.toggle('hidden', !searchInput.value);
                    saveState();
                    renderBeers();
                    updateClearButtonState();
                    clearTimeout(searchLogTimer);
                    searchLogTimer = setTimeout(() => {
                        const term = searchInput.value.trim().replace(/\s+/g, ' ').toLowerCase();
                        if (term.length >= 2) trackEvent('search', { term: term.slice(0, 50), results: lastResultCount });
                    }, 1500);
                });

                clearSearchBtn.addEventListener('click', () => {
                    displayedCount = ITEMS_PER_PAGE;
                    searchInput.value = '';
                    clearSearchBtn.classList.add('hidden');
                    saveState();
                    renderBeers();
                    updateClearButtonState();
                });
                
                // Load More click listener
                loadMoreButton.addEventListener('click', () => {
                    displayedCount += ITEMS_PER_PAGE;
                    renderBeers();
                });

                clearFiltersButton.addEventListener('click', () => {
                    displayedCount = ITEMS_PER_PAGE;
                    const areOtherFiltersActive = styleFilter.value !== '' ||
                                                  breweryFilter.value !== '' ||
                                                  countryFilter.value !== '' ||
                                                  searchInput.value !== '' ||
                                                  (routeFilter && routeFilter.value !== '') ||
                                                  myRatedFilter.checked ||
                                                  unratedFilter.checked ||
                                                  myFavoritesFilter.checked;

                    if (areOtherFiltersActive) {
                        searchInput.value = '';
                        styleFilter.value = '';
                        breweryFilter.value = '';
                        countryFilter.value = '';
                        myRatedFilter.checked = false;
                        unratedFilter.checked = false;
                        myFavoritesFilter.checked = false;
                        clearSearchBtn.classList.add('hidden');
                        if (routeFilter) routeFilter.value = '';
                        if (sortBy.value === 'route-order') sortBy.value = 'brewery-asc';
                    } else {
                        sessionFilter.value = '';
                        // Session no longer constrains the route list — show all routes again.
                        if (routeFilter) populateRouteOptions();
                    }

                    saveState();
                    renderBeers();
                    updateClearButtonState();
                    trackEvent('filter_clear', { cleared: areOtherFiltersActive ? 'filters' : 'session' });
                });

                filterSortHeader.addEventListener('click', () => {
                    toggleSection(filterSortContent, filterSortToggleIcon, null, true);
                    setTimeout(saveState, 10);
                    trackEvent('panel_toggle', { open: !filterSortContent.classList.contains('collapsed'), auto: false });
                });

                let autoCollapseQueued = false;
                window.addEventListener('scroll', () => {
                    if (autoCollapseQueued || filterSortContent.classList.contains('collapsed')) return;
                    autoCollapseQueued = true;
                    requestAnimationFrame(() => {
                        autoCollapseQueued = false;
                        if (filterSortContent.classList.contains('collapsed')) return;
                        if (filterSortContent.getBoundingClientRect().bottom > 0) return;

                        const anchorTopBefore = beerListContainer.getBoundingClientRect().top;
                        filterSortContent.style.transition = 'none';
                        filterSortHeader.style.transition = 'none';
                        toggleSection(filterSortContent, filterSortToggleIcon, true);
                        const shift = beerListContainer.getBoundingClientRect().top - anchorTopBefore;
                        if (shift !== 0) window.scrollBy(0, shift);
                        filterSortContent.offsetHeight;
                        filterSortContent.style.transition = '';
                        filterSortHeader.style.transition = '';
                        saveState();
                        trackEvent('panel_toggle', { open: false, auto: true });
                    });
                }, { passive: true });

                copyDataButton.addEventListener('click', copyShareableLink);
                importDataButton.addEventListener('click', () => {
                    const link = prompt(translations['paste_import_link_prompt'] ?? 'Please paste the link to import your data:');
                    if (link) {
                        try {
                            const url = new URL(link);
                            const encodedData = url.searchParams.get('data');
                            if (encodedData) {
                                processImportedData(encodedData);
                            } else {
                                showMessage(translations['error_importing_ratings_no_valid'] ?? 'No valid data found in the link.', 'error');
                            }
                        } catch (e) {
                            showMessage(translations['error_importing_ratings_invalid_format'] ?? 'Invalid link format.', 'error');
                        }
                    }
                });
                
                if (enableStatisticsLogging && consentBanner) {
                    acceptStatsButton.addEventListener('click', () => setStatsConsent(true, true));
                    declineStatsButton.addEventListener('click', () => setStatsConsent(false, true));
                }
                
                beerListContainer.addEventListener('click', (event) => {
                    if (event.target.closest('.favorite-star')) {
                        handleFavoriteToggle(event);
                    }
                });
                
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'hidden') {
                        hiddenAt = Date.now();
                        flushEvents();
                    }
                    if (document.visibilityState === 'visible') {
                        if (hiddenAt && Date.now() - hiddenAt >= RESUME_AFTER_MS) trackAppOpen('resume');
                        hiddenAt = null;
                        const now = new Date().getTime();
                        if (now - lastFetchTimestamp > REFRESH_INTERVAL) {
                            lastFetchTimestamp = now;
                            refreshDataIfNeeded();
                        }
                    }
                });
            }

            // --- Floor map ---
            // Each SVG is shown as authored inside its own shadow root, so its styles and ids stay
            // separate from the page. Stands are elements with data-stand (a legend entry may repeat
            // a stand). Beers link to a stand through their location: the stand's location, a stand
            // number that only one floor has, or just a floor name. Opening the map from a beer card
            // rings that stand. Several SVGs with the same floor name are layouts of one floor (e.g.
            // legend below / legend beside); the one that shows the floor largest on screen is used.
            function createFloorMap() {
                const template = document.getElementById('map-floors');
                if (!template) return null;

                const SVG_NS = 'http://www.w3.org/2000/svg';
                const overlay = document.getElementById('map-overlay');
                const viewport = document.getElementById('map-viewport');
                const tabsEl = document.getElementById('map-tabs');
                const closeButton = document.getElementById('map-close-button');
                const loupeButton = document.getElementById('map-loupe');
                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const LOUPE_SIZE = 200;
                const LOUPE_ZOOM_MIN = 1.25;
                const LOUPE_ZOOM_MAX = 6;
                const TOP_GAP = 32;
                const DETAIL_PX = 12;
                const normalize = value => String(value || '').toLowerCase().replace(/#/g, ' ').replace(/\s+/g, ' ').trim();
                const shadowCss = `
                    :host { display: block; }
                    svg { display: block; width: 100%; height: 100%; }
                    .bf-ring { fill: none; stroke: var(--palette-text-primary); stroke-width: 4px; vector-effect: non-scaling-stroke; opacity: 0; pointer-events: none; }
                    .bf-marker.is-selected .bf-ring { opacity: 1; }
                    .bf-fav { display: none; fill: var(--icon-favorite-active-color); stroke: var(--palette-primary); stroke-width: 1px; vector-effect: non-scaling-stroke; pointer-events: none; }
                    .bf-marker.is-fav .bf-fav { display: inline; }
                    .bf-loupe { position: absolute; top: 0; left: 0; width: ${LOUPE_SIZE}px; height: ${LOUPE_SIZE}px; border-radius: 50%; overflow: hidden; border: 3px solid var(--palette-text-primary); box-shadow: 0 4px 12px rgba(0,0,0,0.4); pointer-events: none; display: none; }
                    .bf-loupe svg { display: block; width: 100%; height: 100%; }
                    .map-overview, .map-detail { transition: opacity 0.2s; }
                    svg[data-zoom="far"] .map-detail, svg[data-zoom="near"] .map-overview { opacity: 0; }
                `;

                const floors = [...template.content.querySelectorAll('.map-floor')].map(el => {
                    const svg = el.querySelector('svg');
                    const vb = (svg.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number);
                    return {
                        name: el.dataset.floorName,
                        key: normalize(el.dataset.floorName),
                        el,
                        svg,
                        vbW: vb[2] || 1,
                        vbH: vb[3] || 1,
                        stands: [],
                        standByKey: new Map(),
                        laidOut: false,
                    };
                });
                const groups = [];
                floors.forEach(floor => {
                    let group = groups.find(g => g.key === floor.key);
                    if (!group) groups.push(group = { name: floor.name, key: floor.key, variants: [] });
                    group.variants.push(floor);
                    floor.group = group;
                });
                const standsByKey = new Map();
                const standsByLabel = new Map();
                floors.forEach(floor => {
                    const floorStands = new Map();
                    floor.svg.querySelectorAll('[data-stand]').forEach(el => {
                        const label = el.getAttribute('data-stand').trim();
                        if (!label) return;
                        const ownLocation = (el.getAttribute('data-location') || '').trim();
                        const location = ownLocation || `${floor.name} #${label}`;
                        const key = normalize(location);
                        let stand = floorStands.get(key);
                        if (!stand) {
                            stand = { floor, label, location, key, primary: null, cx: 0, cy: 0, r: 0, marker: null };
                            floorStands.set(key, stand);
                            floor.stands.push(stand);
                        }
                        if (!stand.primary || (ownLocation && !stand.primary.hasAttribute('data-location'))) stand.primary = el;
                    });
                    floor.stands.forEach(stand => {
                        floor.standByKey.set(stand.key, stand);
                        if (!standsByKey.has(stand.key)) standsByKey.set(stand.key, stand);
                        const labelKey = normalize(stand.label);
                        if (!standsByLabel.has(labelKey)) standsByLabel.set(labelKey, new Map());
                        standsByLabel.get(labelKey).set(stand.key, stand);
                    });
                });
                const groupsByNameLength = [...groups].sort((a, b) => b.key.length - a.key.length);

                const resolveCache = new Map();
                function resolve(location) {
                    const key = normalize(location);
                    if (!key) return null;
                    if (resolveCache.has(key)) return resolveCache.get(key);
                    let result = null;
                    const byLabel = standsByLabel.get(key);
                    if (standsByKey.has(key)) {
                        const stand = standsByKey.get(key);
                        result = { floor: stand.floor, stand };
                    } else if (byLabel && byLabel.size === 1) {
                        const [stand] = byLabel.values();
                        result = { floor: stand.floor, stand };
                    } else {
                        const group = groupsByNameLength.find(g => key === g.key || key.startsWith(g.key + ' '));
                        if (group) result = { floor: group.variants[0], stand: null };
                    }
                    resolveCache.set(key, result);
                    return result;
                }

                let built = false;
                let active = null;
                let selectedKey = null;
                let historyPushed = false;
                let lastFocus = null;
                let lastSize = null;
                let anim = null;
                let loupeOn = false;
                let loupePos = null;
                let loupeDrag = null;
                let loupePinch = false;
                let loupeZoom = 2;

                function svgEl(name, attrs) {
                    const el = document.createElementNS(SVG_NS, name);
                    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
                    return el;
                }

                function build() {
                    if (built) return;
                    built = true;
                    floors.forEach(floor => {
                        document.adoptNode(floor.el);
                        const root = floor.el.attachShadow({ mode: 'open' });
                        const style = document.createElement('style');
                        style.textContent = shadowCss;
                        root.append(style, floor.svg);
                        floor.el.hidden = true;
                        viewport.insertBefore(floor.el, viewport.firstChild);
                    });
                    groups.forEach(group => {
                        const tab = document.createElement('button');
                        tab.type = 'button';
                        tab.className = 'map-tab';
                        tab.setAttribute('role', 'tab');
                        tab.textContent = group.name;
                        tab.addEventListener('click', () => showGroup(group));
                        group.tab = tab;
                        tabsEl.appendChild(tab);
                    });
                }

                function viewportSize() {
                    return { cw: viewport.clientWidth, ch: viewport.clientHeight };
                }

                function layout(floor) {
                    if (floor.laidOut) return;
                    const svg = floor.svg;
                    const ctm = svg.getScreenCTM();
                    if (!ctm || !viewport.clientWidth) return;
                    const inv = ctm.inverse();
                    const boxOf = el => {
                        const r = el.getBoundingClientRect();
                        if (!r.width && !r.height) return null;
                        const a = new DOMPoint(r.left, r.top).matrixTransform(inv);
                        const b = new DOMPoint(r.right, r.bottom).matrixTransform(inv);
                        return { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(b.x - a.x), h: Math.abs(b.y - a.y) };
                    };
                    const union = (a, b) => {
                        if (!a) return b;
                        const x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
                        return { x, y, w: Math.max(a.x + a.w, b.x + b.w) - x, h: Math.max(a.y + a.h, b.y + b.h) - y };
                    };

                    let content = null;
                    for (const child of svg.children) {
                        if (!/^(g|rect|circle|ellipse|line|polyline|polygon|path|text|use|image|svg|switch|a)$/.test(child.localName)) continue;
                        if (child.matches('.page, .background')) {
                            const fill = getComputedStyle(child).fill;
                            if (fill && fill !== 'none' && !fill.startsWith('url')) floor.el.style.backgroundColor = fill;
                            continue;
                        }
                        const box = boxOf(child);
                        if (box) content = union(content, box);
                    }
                    if (!content) {
                        const vb = svg.viewBox.baseVal;
                        content = { x: vb.x, y: vb.y, w: vb.width, h: vb.height };
                    }
                    const pad = Math.max(content.w, content.h) * 0.01;
                    floor.fit = { x: content.x - pad, y: content.y - pad, w: content.w + 2 * pad, h: content.h + 2 * pad };

                    floor.stands.forEach(stand => {
                        const box = boxOf(stand.primary) || { x: 0, y: 0, w: 0, h: 0 };
                        stand.cx = box.x + box.w / 2;
                        stand.cy = box.y + box.h / 2;
                        stand.r = Math.min(box.w, box.h) / 2 || floor.fit.w / 200;
                    });
                    const radii = floor.stands.map(s => s.r).sort((a, b) => a - b);
                    floor.standSize = radii.length ? radii[Math.floor(radii.length / 2)] * 2 : 0;

                    createMarkers(floor);
                    floor.laidOut = true;
                    resetView(floor);
                }

                function starPoints(cx, cy, r) {
                    const points = [];
                    for (let i = 0; i < 10; i++) {
                        const radius = i % 2 === 0 ? r : r * 0.45;
                        const angle = -Math.PI / 2 + i * Math.PI / 5;
                        points.push(`${(cx + radius * Math.cos(angle)).toFixed(3)},${(cy + radius * Math.sin(angle)).toFixed(3)}`);
                    }
                    return points.join(' ');
                }

                function createMarkers(floor) {
                    const layer = svgEl('g', { class: 'bf-markers' });
                    floor.stands.forEach(stand => {
                        const marker = svgEl('g', { class: 'bf-marker' });
                        const ringR = stand.r * 1.45;
                        const offset = ringR * 0.8;
                        marker.appendChild(svgEl('circle', { class: 'bf-ring', cx: stand.cx, cy: stand.cy, r: ringR }));
                        marker.appendChild(svgEl('polygon', { class: 'bf-fav', points: starPoints(stand.cx + offset, stand.cy - offset, stand.r * 0.6) }));
                        stand.marker = marker;
                        layer.appendChild(marker);
                    });
                    floor.svg.appendChild(layer);
                }

                // View widths at which a typical stand is drawn about 64px (max zoom) or 26px (focus) wide.
                function minViewWidth(floor) {
                    return floor.standSize ? viewportSize().cw * floor.standSize / 64 : floor.fit.w / 8;
                }

                function focusViewWidth(floor) {
                    return floor.standSize ? viewportSize().cw * floor.standSize / 26 : floor.fit.w / 3;
                }

                function clampWidth(floor, w) {
                    const { cw, ch } = viewportSize();
                    const maxW = Math.max(floor.fit.w, floor.fit.h * cw / ch);
                    return Math.min(Math.max(w, Math.min(minViewWidth(floor), maxW)), maxW);
                }

                function clampView(floor, v) {
                    const { cw, ch } = viewportSize();
                    const fit = floor.fit;
                    const w = clampWidth(floor, v.w);
                    const h = w * ch / cw;
                    // Content smaller than the view is centred horizontally and kept near the top.
                    const clampAxis = (center, size, start, length, overflowCenter) => size >= length
                        ? overflowCenter
                        : Math.min(Math.max(center, start + size / 2), start + length - size / 2);
                    const gap = Math.min(TOP_GAP * h / ch, h - fit.h);
                    const cx = clampAxis(v.x + v.w / 2, w, fit.x, fit.w, fit.x + fit.w / 2);
                    const cy = clampAxis(v.y + v.h / 2, h, fit.y, fit.h, fit.y - gap + h / 2);
                    return { x: cx - w / 2, y: cy - h / 2, w, h };
                }

                // Optional zoom-dependent content: .map-overview shows when zoomed out, .map-detail when
                // stands are drawn at least DETAIL_PX wide.
                function setZoomLevel(svg, floor, pxPerUnit) {
                    if (floor.standSize) svg.setAttribute('data-zoom', floor.standSize * pxPerUnit >= DETAIL_PX ? 'near' : 'far');
                }

                function applyView(floor) {
                    const v = floor.view;
                    floor.svg.setAttribute('viewBox', `${v.x} ${v.y} ${v.w} ${v.h}`);
                    setZoomLevel(floor.svg, floor, viewportSize().cw / v.w);
                    lastSize = viewportSize();
                    updateLoupe();
                }

                function setView(floor, v) {
                    floor.view = clampView(floor, v);
                    applyView(floor);
                }

                function cancelAnimation() {
                    if (anim) cancelAnimationFrame(anim);
                    anim = null;
                }

                function animateTo(floor, target, duration = 300) {
                    cancelAnimation();
                    const to = clampView(floor, target);
                    if (reduceMotion || !floor.view) {
                        floor.view = to;
                        applyView(floor);
                        return;
                    }
                    const from = { ...floor.view };
                    const start = performance.now();
                    const step = now => {
                        const t = Math.min(1, (now - start) / duration);
                        const e = 1 - Math.pow(1 - t, 3);
                        floor.view = {
                            x: from.x + (to.x - from.x) * e,
                            y: from.y + (to.y - from.y) * e,
                            w: from.w + (to.w - from.w) * e,
                            h: from.h + (to.h - from.h) * e,
                        };
                        applyView(floor);
                        anim = t < 1 ? requestAnimationFrame(step) : null;
                    };
                    anim = requestAnimationFrame(step);
                }

                // Start with the whole floor on screen, or, when that would shrink it a lot (a tall plan
                // with its legend on a phone), fill the width and let the rest be panned to.
                function resetView(floor, animate = false) {
                    const { cw, ch } = viewportSize();
                    const fit = floor.fit;
                    const whole = Math.max(fit.w, fit.h * cw / ch);
                    const w = whole > fit.w * 1.25 ? fit.w : whole;
                    const h = w * ch / cw;
                    const target = { x: fit.x + fit.w / 2 - w / 2, y: fit.y - Math.max(0, Math.min(TOP_GAP * h / ch, h - fit.h)), w, h };
                    if (animate) animateTo(floor, target);
                    else setView(floor, target);
                }

                function zoomTarget(floor, factor, px, py) {
                    const v = floor.view;
                    const { cw, ch } = viewportSize();
                    const ax = v.x + px / cw * v.w;
                    const ay = v.y + py / ch * v.h;
                    const w = clampWidth(floor, v.w / factor);
                    const h = w * ch / cw;
                    return { x: ax - px / cw * w, y: ay - py / ch * h, w, h };
                }

                function centerOn(stand, w) {
                    const { cw, ch } = viewportSize();
                    const h = w * ch / cw;
                    return { x: stand.cx - w / 2, y: stand.cy - h / 2, w, h };
                }

                function focusStand(stand) {
                    animateTo(stand.floor, centerOn(stand, Math.min(stand.floor.view.w, focusViewWidth(stand.floor))), 450);
                }


                function showFloor(floor) {
                    active = floor;
                    floors.forEach(f => {
                        f.el.hidden = f !== floor;
                    });
                    groups.forEach(g => {
                        g.tab.setAttribute('aria-selected', g === floor.group ? 'true' : 'false');
                    });
                    layout(floor);
                    refresh();
                    floor.group.tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }

                // The layout of a floor that draws it largest in the current viewport.
                function bestVariant(group) {
                    const { cw, ch } = viewportSize();
                    if (group.variants.length < 2 || !cw || !ch) return group.variants[0];
                    return group.variants.reduce((best, f) =>
                        Math.min(cw / f.vbW, ch / f.vbH) > Math.min(cw / best.vbW, ch / best.vbH) ? f : best);
                }

                function showGroup(group) {
                    showFloor(bestVariant(group));
                }

                function selectedStand() {
                    return selectedKey && active ? active.standByKey.get(selectedKey) || null : null;
                }

                function refresh() {
                    if (!built) return;
                    const session = sessionFilter.value;
                    const favKeys = new Set();
                    allBeers.forEach(beer => {
                        if (!userFavorites[beer.id] || !beer.location || (session && beer.session !== session)) return;
                        const target = resolve(beer.location);
                        if (target && target.stand) favKeys.add(target.stand.key);
                    });
                    floors.forEach(floor => floor.stands.forEach(stand => {
                        if (!stand.marker) return;
                        stand.marker.classList.toggle('is-fav', favKeys.has(stand.key));
                        stand.marker.classList.toggle('is-selected', stand.key === selectedKey);
                    }));
                    hideLoupe();
                    updateLoupe();
                }

                function ringStand(key) {
                    selectedKey = key;
                    refresh();
                    const stand = selectedStand();
                    if (!stand) return;
                    focusStand(stand);
                    trackEvent('map_stand_select', { stand: stand.location.slice(0, 100) });
                }

                function open({ location = null, source = 'button' } = {}) {
                    build();
                    const wasOpen = !overlay.hidden;
                    overlay.hidden = false;
                    document.body.classList.add('map-is-open');
                    if (!wasOpen) {
                        lastFocus = document.activeElement;
                        try {
                            history.pushState({ beerfestMap: true }, '');
                            historyPushed = true;
                        } catch (e) {}
                        trackEvent('map_open', { source });
                    }
                    const target = location ? resolve(location) : null;
                    showGroup(target ? target.floor.group : (active || floors[0]).group);
                    if (target && target.stand) {
                        ringStand(target.stand.key);
                    } else {
                        selectedKey = null;
                        if (target) resetView(active);
                        refresh();
                    }
                    if (!wasOpen) closeButton.focus({ preventScroll: true });
                }

                // While on, the loupe stays visible and shows a magnified copy of the floor under it.
                function loupeSize() {
                    const { cw, ch } = viewportSize();
                    return Math.max(80, Math.min(LOUPE_SIZE, cw - 16, ch - 16));
                }

                // The loupe may hang over the edges, but its centre stays on screen so it can be grabbed.
                function clampLoupe(pos) {
                    const { cw, ch } = viewportSize();
                    return { x: Math.min(Math.max(pos.x, 0), cw), y: Math.min(Math.max(pos.y, 0), ch) };
                }

                function updateLoupe() {
                    const floor = active;
                    if (!loupeOn || !floor || !floor.view || overlay.hidden) return;
                    if (!floor.loupe) {
                        floor.loupe = document.createElement('div');
                        floor.loupe.className = 'bf-loupe';
                        floor.el.shadowRoot.appendChild(floor.loupe);
                    }
                    if (!floor.loupe.firstChild) {
                        floor.loupe.appendChild(floor.svg.cloneNode(true));
                        floor.loupe.style.backgroundColor = floor.el.style.backgroundColor || getComputedStyle(viewport).backgroundColor;
                    }
                    const { cw } = viewportSize();
                    const size = loupeSize();
                    loupePos = clampLoupe(loupePos || { x: cw / 2, y: viewportSize().ch / 2 });
                    const v = floor.view;
                    const unitsPerPx = v.w / cw;
                    const span = size / loupeZoom * unitsPerPx;
                    const x = v.x + loupePos.x * unitsPerPx;
                    const y = v.y + loupePos.y * unitsPerPx;
                    floor.loupe.firstChild.setAttribute('viewBox', `${x - span / 2} ${y - span / 2} ${span} ${span}`);
                    floor.loupe.firstChild.setAttribute('data-zoom', 'near');
                    floor.loupe.style.width = floor.loupe.style.height = `${size}px`;
                    floor.loupe.style.transform = `translate(${loupePos.x - size / 2}px, ${loupePos.y - size / 2}px)`;
                    floor.loupe.style.display = 'block';
                }

                function nearLoupe(point) {
                    return loupeOn && loupePos && Math.hypot(point.x - loupePos.x, point.y - loupePos.y) <= loupeSize() / 2 + 24;
                }

                function zoomLoupe(factor) {
                    loupeZoom = Math.min(Math.max(loupeZoom * factor, LOUPE_ZOOM_MIN), LOUPE_ZOOM_MAX);
                    updateLoupe();
                }

                function hideLoupe() {
                    floors.forEach(floor => {
                        if (!floor.loupe) return;
                        floor.loupe.style.display = 'none';
                        floor.loupe.textContent = '';
                    });
                }

                function setLoupe(on) {
                    loupeOn = on;
                    loupeDrag = null;
                    loupeButton.setAttribute('aria-pressed', on ? 'true' : 'false');
                    hideLoupe();
                    updateLoupe();
                }

                function close(fromPopstate = false) {
                    if (overlay.hidden) return;
                    overlay.hidden = true;
                    hideLoupe();
                    document.body.classList.remove('map-is-open');
                    cancelAnimation();
                    pointers.clear();
                    if (historyPushed && !fromPopstate) history.back();
                    historyPushed = false;
                    if (lastFocus && document.contains(lastFocus)) lastFocus.focus({ preventScroll: true });
                }

                // Pan with one pointer (or drag the loupe when touching near it), pinch with two (on the
                // loupe it zooms only the loupe), double-tap to zoom in.
                const pointers = new Map();
                let tap = null;
                let lastTap = null;
                const localPoint = e => {
                    const rect = viewport.getBoundingClientRect();
                    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
                };

                viewport.addEventListener('pointerdown', e => {
                    if (!active || !active.view || e.target.closest('.map-zoom-controls')) return;
                    if (e.pointerType === 'mouse' && e.button !== 0) return;
                    cancelAnimation();
                    viewport.setPointerCapture(e.pointerId);
                    const point = localPoint(e);
                    pointers.set(e.pointerId, point);
                    const wasDraggingLoupe = !!loupeDrag;
                    loupeDrag = null;
                    loupePinch = false;
                    if (pointers.size === 1 && nearLoupe(point)) {
                        loupeDrag = { id: e.pointerId, dx: point.x - loupePos.x, dy: point.y - loupePos.y };
                    } else if (pointers.size === 2) {
                        const [a, b] = [...pointers.values()];
                        loupePinch = wasDraggingLoupe || nearLoupe({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
                    }
                    tap = pointers.size === 1 && !loupeDrag ? { id: e.pointerId, start: point, time: performance.now(), moved: 0 } : null;
                });

                viewport.addEventListener('pointermove', e => {
                    if (!pointers.has(e.pointerId) || !active || !active.view) return;
                    const prev = pointers.get(e.pointerId);
                    const point = localPoint(e);
                    const { cw } = viewportSize();
                    if (loupeDrag && loupeDrag.id === e.pointerId) {
                        loupePos = { x: point.x - loupeDrag.dx, y: point.y - loupeDrag.dy };
                        updateLoupe();
                    } else if (pointers.size === 1) {
                        const scale = active.view.w / cw;
                        setView(active, { ...active.view, x: active.view.x - (point.x - prev.x) * scale, y: active.view.y - (point.y - prev.y) * scale });
                    } else if (pointers.size === 2 && loupePinch) {
                        const [otherId] = [...pointers.keys()].filter(id => id !== e.pointerId);
                        const other = pointers.get(otherId);
                        const prevDist = Math.hypot(prev.x - other.x, prev.y - other.y) || 1;
                        zoomLoupe((Math.hypot(point.x - other.x, point.y - other.y) || 1) / prevDist);
                    } else if (pointers.size === 2) {
                        const [otherId] = [...pointers.keys()].filter(id => id !== e.pointerId);
                        const other = pointers.get(otherId);
                        const prevMid = { x: (prev.x + other.x) / 2, y: (prev.y + other.y) / 2 };
                        const mid = { x: (point.x + other.x) / 2, y: (point.y + other.y) / 2 };
                        const prevDist = Math.hypot(prev.x - other.x, prev.y - other.y) || 1;
                        const dist = Math.hypot(point.x - other.x, point.y - other.y) || 1;
                        const scale = active.view.w / cw;
                        setView(active, { ...active.view, x: active.view.x - (mid.x - prevMid.x) * scale, y: active.view.y - (mid.y - prevMid.y) * scale });
                        setView(active, zoomTarget(active, dist / prevDist, mid.x, mid.y));
                    }
                    pointers.set(e.pointerId, point);
                    if (tap && tap.id === e.pointerId) {
                        tap.moved = Math.max(tap.moved, Math.hypot(point.x - tap.start.x, point.y - tap.start.y));
                    }
                });

                const endPointer = e => {
                    if (!pointers.has(e.pointerId)) return;
                    pointers.delete(e.pointerId);
                    if (loupeDrag && loupeDrag.id === e.pointerId) loupeDrag = null;
                    if (pointers.size < 2) loupePinch = false;
                    if (e.type !== 'pointerup' || !tap || tap.id !== e.pointerId) return;
                    const now = performance.now();
                    if (tap.moved < 10 && now - tap.time < 500) {
                        const point = tap.start;
                        if (lastTap && now - lastTap.time < 300 && Math.hypot(point.x - lastTap.x, point.y - lastTap.y) < 30) {
                            animateTo(active, zoomTarget(active, 2, point.x, point.y), 250);
                            lastTap = null;
                        } else {
                            lastTap = { time: now, x: point.x, y: point.y };
                        }
                    }
                    tap = null;
                };
                viewport.addEventListener('pointerup', endPointer);
                viewport.addEventListener('pointercancel', endPointer);

                viewport.addEventListener('wheel', e => {
                    if (!active || !active.view) return;
                    e.preventDefault();
                    cancelAnimation();
                    const point = localPoint(e);
                    const factor = Math.exp(-e.deltaY * (e.deltaMode === 1 ? 0.05 : 0.002));
                    if (nearLoupe(point)) zoomLoupe(factor);
                    else setView(active, zoomTarget(active, factor, point.x, point.y));
                }, { passive: false });

                const zoomButton = factor => () => {
                    if (!active || !active.view) return;
                    const { cw, ch } = viewportSize();
                    animateTo(active, zoomTarget(active, factor, cw / 2, ch / 2), 250);
                };
                loupeButton.addEventListener('click', () => setLoupe(!loupeOn));
                document.getElementById('map-zoom-in').addEventListener('click', zoomButton(1.6));
                document.getElementById('map-zoom-out').addEventListener('click', zoomButton(1 / 1.6));
                document.getElementById('map-zoom-reset').addEventListener('click', () => {
                    if (active && active.laidOut) resetView(active, true);
                });

                new ResizeObserver(() => {
                    if (overlay.hidden || !active) return;
                    const size = viewportSize();
                    if (!size.cw || !size.ch) return;
                    const best = bestVariant(active.group);
                    if (best !== active) {
                        showFloor(best);
                        resetView(best);
                        const stand = selectedStand();
                        if (stand) setView(best, centerOn(stand, Math.min(best.view.w, focusViewWidth(best))));
                        return;
                    }
                    if (!active.laidOut) {
                        layout(active);
                        refresh();
                        return;
                    }
                    if (!lastSize || !lastSize.cw) return resetView(active);
                    const v = active.view;
                    const scale = v.w / lastSize.cw;
                    const cx = v.x + v.w / 2, cy = v.y + v.h / 2;
                    const w = scale * size.cw, h = scale * size.ch;
                    setView(active, { x: cx - w / 2, y: cy - h / 2, w, h });
                }).observe(viewport);

                document.getElementById('map-open-button').addEventListener('click', () => open({ source: 'button' }));
                closeButton.addEventListener('click', () => close());
                document.addEventListener('click', event => {
                    const link = event.target.closest('.beer-location-link');
                    if (link) open({ location: link.dataset.location, source: 'card' });
                });
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape' && !overlay.hidden) close();
                });
                window.addEventListener('popstate', () => close(true));

                // Warm the service worker cache for raster backgrounds so the map works offline.
                const imageUrls = floors.flatMap(floor => [...floor.svg.querySelectorAll('image')]
                    .map(img => img.getAttribute('href') || img.getAttributeNS('http://www.w3.org/1999/xlink', 'href'))
                    .filter(href => href && !href.startsWith('data:')));
                setTimeout(() => {
                    if (!navigator.serviceWorker || !navigator.serviceWorker.controller) return;
                    imageUrls.forEach(href => fetch(href).catch(() => {}));
                }, 5000);

                return { resolve, open };
            }

            const floorMap = createFloorMap();

            // --- Start Application ---
            let listenersInitialized = false;

            function bootstrapApp() {
                processBeerData();
                if (!listenersInitialized) {
                    initializeAppListeners();
                    listenersInitialized = true;
                }
                initializeFilters();
                importDataFromUrlOnLoad();
                loadState();
                if (!appOpenTracked) {
                    appOpenTracked = true;
                    trackAppOpen('load');
                    trackFirstLaunch();
                }

                if (enableStatisticsLogging && statsConsent === null) {
                    if(consentBanner) consentBanner.classList.remove('hidden');
                } else if (enableStatisticsLogging) {
                    updateStatisticsNotice();
                }

                renderBeers();
                updateClearButtonState();
                clearSearchBtn.classList.toggle('hidden', !searchInput.value);
            }

            // Try to render instantly from cached data (avoids flash of empty page on reload)
            let bootstrapped = false;
            try {
                const cachedBeers = localStorage.getItem('cachedBeers');
                const cachedFlags = localStorage.getItem('cachedFlags');
                if (cachedBeers && cachedFlags) {
                    allBeers = JSON.parse(cachedBeers);
                    countryFlags = JSON.parse(cachedFlags);
                    bootstrapped = true;
                    bootstrapApp();
                }
            } catch (e) { /* ignore corrupt cache */ }

            // Fetch fresh data from network (updates cache and re-renders if data changed)
            const asJson = res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            };
            Promise.all([
                fetch(beerDataUrl).then(asJson),
                fetch('data/flags.json').then(asJson)
            ])
            .then(([beers, flags]) => {
                lastFetchTimestamp = new Date().getTime();

                // Save to localStorage for instant render on next load
                try {
                    localStorage.setItem('cachedBeers', JSON.stringify(beers));
                    localStorage.setItem('cachedFlags', JSON.stringify(flags));
                } catch (e) { /* quota exceeded — not critical */ }

                if (!bootstrapped) {
                    // First visit (no cache) — full init
                    allBeers = beers;
                    countryFlags = flags;
                    bootstrapApp();
                } else if (JSON.stringify(beers) !== JSON.stringify(allBeers)) {
                    // Data changed — update and re-render
                    allBeers = beers;
                    countryFlags = flags;
                    processBeerData();
                    initializeFilters();
                    renderBeers();
                    showMessage(translations['beer_list_updated'] ?? "Beer list updated!", 'success');
                }
            })
            .catch(error => {
                if (!bootstrapped) {
                    console.error('Failed to load initial data:', error);
                    document.getElementById('beer-list').innerHTML = `<div class="error-message">${translations['error_fetching_data'] ?? 'Could not fetch beer data'}</div>`;
                }
            });
        });
    </script>
    <?php if (!$devMode && !$notPublic): ?>
    <script>
        // PWA Service Worker Registration
        if ('serviceWorker' in navigator) {
            let newWorker;

            function showUpdateBar() {
                const banner = document.getElementById('update-banner');
                if(banner) banner.classList.remove('hidden');
            }

            navigator.serviceWorker.register('/sw.js').then(reg => {
                reg.addEventListener('updatefound', () => {
                    newWorker = reg.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                           showUpdateBar();
                        }
                    });
                });
            });

            let refreshing;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (refreshing) return;
                window.location.reload();
                refreshing = true;
            });

            const updateButton = document.getElementById('update-app-button');
            if(updateButton) {
                updateButton.addEventListener('click', () => {
                    newWorker.postMessage({ action: 'skipWaiting' });
                });
            }
        }

        // PWA Install Prompt
        const INSTALL_DISMISS_DAYS = 7;
        const INSTALL_DISMISS_MS = INSTALL_DISMISS_DAYS * 24 * 60 * 60 * 1000;
        const INSTALL_SHOW_DELAY = 15000; // 15 seconds

        function isInstallDismissed() {
            const dismissed = localStorage.getItem('installDismissed');
            return dismissed && (Date.now() - parseInt(dismissed, 10)) < INSTALL_DISMISS_MS;
        }

        function trackInstall(step) {
            if (window.trackEvent) window.trackEvent('install', { step, platform: installPlatform });
        }

        function showBannerWithDelay(banner) {
            if (banner) setTimeout(() => {
                banner.classList.remove('hidden');
                trackInstall('banner_shown');
            }, INSTALL_SHOW_DELAY);
        }

        // --- Android/Desktop install (beforeinstallprompt) ---
        let deferredPrompt;
        const installBanner = document.getElementById('install-banner');
        const installButton = document.getElementById('install-app-button');
        const dismissButton = document.getElementById('install-dismiss-button');

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            if (isInstallDismissed()) return;
            showBannerWithDelay(installBanner);
        });

        if (installButton) {
            installButton.addEventListener('click', () => {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(choice => {
                    trackInstall(choice.outcome === 'accepted' ? 'prompt_accepted' : 'prompt_declined');
                    deferredPrompt = null;
                    if (installBanner) installBanner.classList.add('hidden');
                });
            });
        }

        if (dismissButton) {
            dismissButton.addEventListener('click', () => {
                localStorage.setItem('installDismissed', Date.now().toString());
                if (installBanner) installBanner.classList.add('hidden');
                trackInstall('banner_dismissed');
            });
        }

        window.addEventListener('appinstalled', () => {
            if (installBanner) installBanner.classList.add('hidden');
            deferredPrompt = null;
            trackInstall('installed');
        });

        // --- iOS install guide ---
        const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
        // Safari on iOS: no CriOS (Chrome), no FxiOS (Firefox), no OPiOS (Opera)
        const isIosSafari = isIos && /Safari/.test(navigator.userAgent) && !/CriOS|FxiOS|OPiOS|EdgiOS/.test(navigator.userAgent);
        const installPlatform = isIosSafari ? 'ios' : isIos ? 'ios_other_browser' : /Android/i.test(navigator.userAgent) ? 'android' : 'other';

        if (isIos && !isStandalone && !isInstallDismissed()) {
            if (isIosSafari) {
                showBannerWithDelay(document.getElementById('install-ios-banner'));
            } else {
                showBannerWithDelay(document.getElementById('install-ios-safari-banner'));
            }
        }

        document.querySelectorAll('#install-ios-dismiss-button, #install-ios-safari-dismiss-button').forEach(btn => {
            btn.addEventListener('click', () => {
                localStorage.setItem('installDismissed', Date.now().toString());
                btn.closest('.install-banner').classList.add('hidden');
                trackInstall('banner_dismissed');
            });
        });
    </script>
    <?php else: ?>
    <script>
        // DEV_MODE or NOT_PUBLIC: unregister any existing service worker so a stale
        // pre-gate or bypass-only shell can't be served to other visitors from cache.
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(registrations => {
                registrations.forEach(reg => reg.unregister());
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>
<?php
// Get all the generated HTML content from the buffer
$html = ob_get_clean();

// Define a more robust regex pattern that matches HTML comments, including multiline ones.
$pattern = '/<!--[\s\S]*?-->/';

// Remove all comments from the HTML
$html_without_comments = preg_replace($pattern, '', $html);

// Send the cleaned HTML to the browser
echo $html_without_comments;
?>
