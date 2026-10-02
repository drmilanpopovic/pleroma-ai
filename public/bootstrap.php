<?php
declare(strict_types=1);
// Za cPanel razdvojeni raspored: _private_path.php vraća APSOLUTNU putanju
// do privatnog app direktorijuma. U ovom fajlu ne čuvati ključeve.
$privatePath = getenv('PLEROMA_APP_DIR') ?: (is_file(__DIR__.'/_private_path.php') ? require __DIR__.'/_private_path.php' : dirname(__DIR__).'/app');
if (!is_string($privatePath) || !is_file($privatePath.'/core.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"ok":false,"error":"Putanja privatnog dela aplikacije nije podešena. Pogledajte uputstvo za instalaciju."}';
    exit;
}
define('PLEROMA_APP_DIR', $privatePath);
