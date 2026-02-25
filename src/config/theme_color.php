<?php
/**
 * Resolve the theme color for the meta tag and manifest.
 *
 * Priority:
 *  1. THEME_COLOR environment variable (if set)
 *  2. --palette-primary from custom/theme.css (if file exists)
 *  3. --palette-primary from config/theme.css
 *  4. Hardcoded fallback (#2B684B)
 */
function getThemeColor(): string {
    $env = getenv('THEME_COLOR');
    if ($env !== false && $env !== '') {
        return $env;
    }

    $customTheme = __DIR__ . '/../custom/theme.css';
    $defaultTheme = __DIR__ . '/theme.css';
    $themeFile = file_exists($customTheme) ? $customTheme : $defaultTheme;

    $css = file_get_contents($themeFile);
    if ($css !== false && preg_match('/--palette-primary:\s*(#[0-9a-fA-F]{3,8})/', $css, $matches)) {
        return $matches[1];
    }

    return '#2B684B';
}
