<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once dirname(__DIR__).'/app/transfers.php';
const RESTORE_FILE_LIMIT=268435456; // 256 MiB; clinical data is never streamed to stdout.

function restoreFromFile(string $filename,string $password): array {
    $path=realpath($filename);
    if($path===false||!is_file($path)||!is_readable($path))fail('Šifrovana kopija nije dostupna za čitanje.');
    $size=filesize($path);
    if($size===false||$size<1||$size>RESTORE_FILE_LIMIT)fail('CLI prihvata šifrovane kopije do 256 MiB.');
    $raw=file_get_contents($path,false,null,0,RESTORE_FILE_LIMIT+1);
    if($raw===false||strlen($raw)>RESTORE_FILE_LIMIT)fail('Kopija ne može da se pročita u dozvoljenoj veličini.');
    try{$backup=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){fail('Datoteka nije ispravna JSON rezervna kopija.');}
    unset($raw);
    if(!is_array($backup))fail('Datoteka nije ispravna rezervna kopija.');
    return restoreBackup(['password'=>$password,'backup'=>$backup]);
}
function restoreReadPassword(bool $fromStdin): string {
    $isTerminal=function_exists('stream_isatty')&&stream_isatty(STDIN);
    if(!$isTerminal&&!$fromStdin)fail('Za rad bez terminala dodajte --password-stdin i dostavite samo lozinku preko standardnog ulaza.');
    $terminalState=null;
    $restoreEcho=function()use(&$terminalState):void{
        if($terminalState!==null){$state=$terminalState;$terminalState=null;exec('stty '.escapeshellarg($state).' < /dev/tty 2>/dev/null');}
    };
    if($isTerminal){
        if(!function_exists('exec'))fail('Skriveni unos nije dostupan u ovom PHP okruženju. Koristite --password-stdin prema uputstvu.');
        $lines=[];$status=1;exec('stty -g < /dev/tty 2>/dev/null',$lines,$status);
        $state=trim(implode('', $lines));
        if($status!==0||$state==='')fail('Skriveni unos nije dostupan. Koristite --password-stdin prema uputstvu.');
        $unused=[];exec('stty -echo < /dev/tty 2>/dev/null',$unused,$status);
        if($status!==0)fail('Skriveni unos nije dostupan. Koristite --password-stdin prema uputstvu.');
        $terminalState=$state;register_shutdown_function($restoreEcho);
        if(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')){
            pcntl_async_signals(true);
            foreach([SIGINT,SIGTERM] as $signal)pcntl_signal($signal,function(int $caught)use($restoreEcho){$restoreEcho();fwrite(STDERR,"\nVraćanje je prekinuto.\n");exit(128+$caught);});
        }
        fwrite(STDERR,'Lozinka šifrovane kopije (unos je skriven): ');
    }
    try{$line=fgets(STDIN,2048);}finally{$restoreEcho();if($isTerminal)fwrite(STDERR,"\n");}
    if($line===false)fail('Lozinka nije dostavljena.');
    $password=preg_replace('/\r?\n$/D','',$line);
    return rawSecret(['password'=>$password],'password');
}
function restoreCli(array $arguments): int {
    $help="Upotreba: php tools/restore.php [/putanja/kopija.pleroma] [--password-stdin]\n".
        "Kopija se vraća samo u praznu bazu iz config.php / PLEROMA_CONFIG.\n".
        "Bez opcije lozinka se čita skriveno iz terminala. Sa --password-stdin čita se jedan red sa standardnog ulaza.\n".
        "Lozinku nikada ne navodite u argumentima komande. Uputstvo: docs/REZERVNE_KOPIJE.md\n";
    if(in_array('--help',$arguments,true)||in_array('-h',$arguments,true)){fwrite(STDOUT,$help);return 0;}
    $path=null;$fromStdin=false;
    foreach($arguments as $argument){
        if($argument==='--password-stdin'){$fromStdin=true;continue;}
        if(str_starts_with($argument,'-')||$path!==null){fwrite(STDERR,$help);return 2;}
        $path=$argument;
    }
    if($path===null){fwrite(STDERR,$help);return 2;}
    try{
        // Fail before asking for a password if the destination already has clinical data.
        if((int)db()->query('SELECT COUNT(*) FROM records')->fetchColumn()>0||(int)db()->query('SELECT COUNT(*) FROM revisions')->fetchColumn()>0)fail('Odredišna baza nije prazna. Postojeći podaci neće biti prepisani.',409);
        $password=restoreReadPassword($fromStdin);
        try{$result=restoreFromFile($path,$password);}finally{if(function_exists('sodium_memzero'))sodium_memzero($password);unset($password);}
        fwrite(STDOUT,'Kopija je vraćena. Broj kliničkih zapisa: '.$result['count'].".\n");
        return 0;
    }catch(AppError $e){fwrite(STDERR,$e->getMessage()."\n");return 1;}
    catch(Throwable){fwrite(STDERR,"Vraćanje nije završeno. Proverite PHP dodatke, memoriju, dozvole i privatnu konfiguraciju.\n");return 1;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)exit(restoreCli(array_slice($argv,1)));
