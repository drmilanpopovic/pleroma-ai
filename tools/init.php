<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$file=dirname(__DIR__).'/config.php';
if(is_file($file)){fwrite(STDERR,"config.php već postoji; nije izmenjen.\n");exit(1);}
$c=require dirname(__DIR__).'/config.example.php';$c['encryption_key']=bin2hex(random_bytes(32));$c['setup_token']=bin2hex(random_bytes(32));
file_put_contents($file,"<?php\nreturn ".var_export($c,true).";\n");chmod($file,0600);
echo "config.php je napravljen. Unesite base_url i data_dir izvan public_html.\nInstalacioni kod pročitajte u config.php. Sačuvajte encryption_key izvan servera.\n";
