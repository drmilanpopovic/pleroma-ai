<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/bootstrap.php';
require_once PLEROMA_APP_DIR . '/integrations.php';
headersForApp();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        fail('Povratak sa Google prijave zahteva GET zahtev.', 405);
    }
    sessionBoot();
    auth();
    // The one-time state is checked against this authenticated session before
    // the authorization code is exchanged. No callback query is ever echoed.
    googleCallback();
    session_write_close();
    header('Location: ' . rtrim(config()['base_url'], '/') . '/#settings', true, 303);
    exit;
} catch (Throwable $e) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    http_response_code($e instanceof AppError ? $e->status : 500);
    header('Content-Type: text/html; charset=utf-8');
    $message = $e instanceof AppError ? $e->getMessage() : 'Povezivanje nije uspelo. Pokušajte ponovo iz podešavanja aplikacije.';
    echo '<!doctype html><html lang="sr-Latn"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>Google kalendar · Pleroma</title>'
        . '</head><body><main><h1>Google kalendar</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p><p><a href="./#settings">Nazad u podešavanja aplikacije</a></p></main></body></html>';
}
