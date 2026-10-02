<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
$errors=[];
if (PHP_VERSION_ID<80200) $errors[]='Potreban je PHP 8.2 ili noviji.';
foreach (['openssl','pdo_sqlite','curl','mbstring','fileinfo'] as $extension) {
    if (!extension_loaded($extension)) $errors[]='Nedostaje PHP ekstenzija: '.$extension;
}
if (extension_loaded('openssl') && !in_array('aes-256-gcm',openssl_get_cipher_methods(),true)) $errors[]='OpenSSL ne podržava AES-256-GCM.';
if (!$errors) {
    try {
        require dirname(__DIR__).'/app/core.php';
        $c=config();
        if (!is_writable($c['data_dir'])) $errors[]='Privatni direktorijum nije upisiv.';
        if (!is_string($c['setup_token']??null)||strlen($c['setup_token'])<32) $errors[]='Instalacioni kod nedostaje ili je prekratak.';
        if (!str_starts_with($c['base_url']??'','https://')&&!($c['allow_local_http']??false)) $errors[]='base_url mora koristiti HTTPS.';
    } catch (Throwable $e) { $errors[]=$e->getMessage(); }
}
foreach ($errors as $error) fwrite(STDERR,'GREŠKA: '.$error."\n");
if ($errors) exit(1);
echo 'OK: PHP '.PHP_VERSION.', ekstenzije i konfiguracija. Ključevi nisu prikazani.' . "\n";
