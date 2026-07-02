<?php
header('Content-Type: text/plain; charset=utf-8');

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
?>
# Search engines and AI agents are welcome to index the public pages.

User-agent: *
Allow: /
Disallow: /log_rating.php
Disallow: /log_cookie_consent.php
Disallow: /data/
Allow: /data/beers.json
<?php if ($siteUrlBase !== ''): ?>

Sitemap: <?= $siteUrlBase ?>/sitemap.xml
<?php endif; ?>
