<?php
// Gated proxy for /data/beers.json.
//
// When NOT_PUBLIC=true, the beer catalog is only served to visitors holding a
// valid bf_preview cookie (set by index.php via ?key=…). Everyone else gets
// 403, so a casual visitor can't fetch the catalog directly while the gate is
// up. When NOT_PUBLIC is off, this behaves like nginx's previous static
// handler: streams the file with a Last-Modified header so browsers can
// revalidate cheaply.
//
// Gate logic mirrors src/index.php:42-80 — keep in sync.

$beersFile = __DIR__ . '/data/beers.json';

if (!is_readable($beersFile)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not found']);
    exit;
}

$notPublic = getenv('NOT_PUBLIC') === 'true';

if ($notPublic) {
    $bypassKey = getenv('NOT_PUBLIC_BYPASS_KEY') ?: '';
    $cookieName = 'bf_preview';
    $expectedToken = $bypassKey !== '' ? hash_hmac('sha256', 'preview', $bypassKey) : '';

    $hasBypass = $expectedToken !== ''
        && !empty($_COOKIE[$cookieName])
        && hash_equals($expectedToken, (string)$_COOKIE[$cookieName]);

    if (!$hasBypass) {
        http_response_code(403);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    // Authorized but gated — don't let intermediaries or the service worker
    // pin this response across a NOT_PUBLIC toggle.
    header('Cache-Control: no-store');
} else {
    // Public mode — match nginx's previous static-file caching behavior so
    // browsers can revalidate with If-Modified-Since instead of refetching
    // the whole catalog every time.
    $mtime = filemtime($beersFile);
    $ifMod = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ifMod !== '' && strtotime($ifMod) >= $mtime) {
        http_response_code(304);
        exit;
    }
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
}

header('Content-Type: application/json');
header('Content-Length: ' . filesize($beersFile));
readfile($beersFile);
