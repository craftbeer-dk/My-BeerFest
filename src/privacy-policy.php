<?php
// Handles language loading and server-side environment setup.
ob_start();

// Set application language from environment variable, default to Danish.
$appLanguage = getenv('APP_LANGUAGE') ?: 'da';
$langFile = __DIR__ . "/lang/{$appLanguage}.conf";
$translations = [];

if (file_exists($langFile)) {
    $translations = parse_ini_file($langFile);
} else {
    // Fallback to English if the specified language file doesn't exist.
    $appLanguage = 'en';
    $langFile = __DIR__ . "/lang/en.conf";
    if (file_exists($langFile)) {
        $translations = parse_ini_file($langFile);
    } else {
        die("Error: Language files not found. Please ensure lang/da.conf and lang/en.conf exist.");
    }
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

// Configuration for contact details.
$contactEmail = getenv('CONTACT_EMAIL') ?: 'contact@mybeerfest.com';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('privacy_policy_title', 'Privacy Policy'); ?></title>
    
    <!-- External assets and theme configuration -->
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

        /* Main content container for the policy text */
        .policy-card {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .policy-card > :last-child {
            margin-bottom: 0;
        }

        h2 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-top: 2rem;
            margin-bottom: 1rem;
            color: var(--card-heading-color);
            border-bottom: 1px solid var(--divider-color);
            padding-bottom: 0.5rem;
        }

        h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 0.75rem;
            color: var(--card-heading-color);
        }

        p, ul {
            margin-bottom: 1rem;
            color: var(--card-paragraph-color);
        }

        ul {
            padding-left: 1.5rem;
            list-style-type: disc;
        }

        li {
            margin-bottom: 0.5rem;
        }


        .page-close {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 0.75rem;
        }
        .close-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.375rem;
            background-color: var(--card-background-color);
            border: 1px solid var(--card-border-color);
            color: var(--card-paragraph-color);
            transition: background-color 0.2s;
        }
        .close-button:hover {
            background-color: var(--palette-interactive);
        }
        .close-button svg {
            width: 1.6rem;
            height: 1.6rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-close">
            <a href="index.php" class="close-button" aria-label="<?php echo t('back_to_app_link', 'Back to the app'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></a>
        </div>

        <!-- Centered page header -->
        <h1 class="text-4xl font-bold text-center mb-6 p-4 rounded-lg shadow-lg" style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            <?php echo t('privacy_policy_heading', 'Privacy Policy'); ?>
        </h1>

        <div class="policy-card">
            <p><?php echo t('privacy_policy_intro'); ?></p>

            <h2><?php echo t('data_collection_heading'); ?></h2>
            <h3><?php echo t('local_storage_heading'); ?></h3>
            <p><?php echo t('local_storage_description'); ?></p>
            <ul>
                <li><strong><?php echo t('beer_ratings_label'); ?></strong> <?php echo t('beer_ratings_desc'); ?></li>
                <li><strong><?php echo t('filter_sort_choices_label'); ?></strong> <?php echo t('filter_sort_choices_desc'); ?></li>
            </ul>

            <h3><?php echo t('server_data_heading'); ?></h3>
            <p><?php echo t('server_data_intro'); ?></p>
            <ul>
                <li><strong><?php echo t('session_id_label'); ?></strong> <?php echo t('session_id_desc'); ?></li>
                <li><strong><?php echo t('beer_id_name_label'); ?></strong> <?php echo t('beer_id_name_desc'); ?></li>
                <li><strong><?php echo t('interaction_data_label'); ?></strong> <?php echo t('interaction_data_desc'); ?></li>
                <li><strong><?php echo t('timestamp_label'); ?></strong> <?php echo t('timestamp_desc'); ?></li>
            </ul>

            <h2><?php echo t('how_we_use_data_heading'); ?></h2>
            <ul>
                <li><strong><?php echo t('local_storage_use_label'); ?></strong> <?php echo t('local_storage_use_desc'); ?></li>
                <li><strong><?php echo t('server_stats_use_label'); ?></strong> <?php echo t('server_stats_use_desc'); ?></li>
            </ul>

            <h2><?php echo t('cookies_heading'); ?></h2>
            <p><?php echo t('cookies_main_desc'); ?></p>

            <h2><?php echo t('data_sharing_heading'); ?></h2>
            <p><?php echo t('data_sharing_desc'); ?></p>

            <h2><?php echo t('your_rights_heading'); ?></h2>
            <p><?php echo t('your_rights_intro'); ?></p>
            <ul>
                <li><strong><?php echo t('right_to_access_label'); ?></strong> <?php echo t('right_to_access_desc'); ?></li>
                <li><strong><?php echo t('right_to_rectify_label'); ?></strong> <?php echo t('right_to_rectify_desc'); ?></li>
                <li><strong><?php echo t('right_to_erase_label'); ?></strong> <?php echo t('right_to_erase_desc'); ?></li>
                <li><strong><?php echo t('right_to_restrict_label'); ?></strong> <?php echo t('right_to_restrict_desc'); ?></li>
                <li><strong><?php echo t('right_to_data_portability_label'); ?></strong> <?php echo t('right_to_data_portability_desc'); ?></li>
            </ul>

            <h2><?php echo t('policy_changes_heading'); ?></h2>
            <p><?php echo t('policy_changes_desc'); ?></p>

            <h2><?php echo t('contact_us_heading'); ?></h2>
            <p><?php echo t('contact_us_desc'); ?></p>
            <p><strong><a href="mailto:<?php echo htmlspecialchars($contactEmail); ?>"><?php echo htmlspecialchars($contactEmail); ?></a></strong></p>
        </div>
    </div>
</body>
</html>
<?php
// End output buffering and sanitize HTML output.
$html = ob_get_clean();
echo preg_replace('/<!--[\s\S]*?-->/', '', $html);
?>