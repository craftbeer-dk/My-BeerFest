<?php
// Set the content type to application/json
header('Content-Type: application/json');

// Load environment variables with fallbacks
$festivalTitle = getenv('FESTIVAL_TITLE') ?: 'My BeerFest';
$festivalTitleShort = getenv('FESTIVAL_TITLE_SHORT') ?: 'My BeerFest';
$festivalInfoText = getenv('FESTIVAL_INFO_TEXT') ?: 'My BeerFest';
$themeColor = getenv('THEME_COLOR') ?: '#2B684B';

// Create the manifest data as a PHP array
$manifest = [
    'name' => $festivalTitle,
    'short_name' => $festivalTitleShort,
    'description' => $festivalInfoText,
    'start_url' => '.',
    'display' => 'standalone',
    'background_color' => $themeColor,
    'theme_color' => $themeColor,
    'icons' => [
        [
            'src' => 'images/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => 'images/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
];

// Encode the array to JSON and output it
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
