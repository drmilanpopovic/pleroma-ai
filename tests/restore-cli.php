<?php
declare(strict_types=1);
// Recovery larger than the web limit, entirely with synthetic records and a temporary database.
$restoreTestRoot=sys_get_temp_dir().'/pleroma-restore-cli-'.bin2hex(random_bytes(8));
mkdir($restoreTestRoot,0700,true);
$restoreConfig=require dirname(__DIR__).'/config.example.php';
$restoreConfig['data_dir']=$restoreTestRoot.'/data';
$restoreConfig['encryption_key']=bin2hex(random_bytes(32));
$restoreConfig['base_url']='https://clinic.example.invalid';
file_put_contents($restoreTestRoot.'/config.php','<?php return '.var_export($restoreConfig,true).';');
putenv('PLEROMA_CONFIG='.$restoreTestRoot.'/config.php');
register_shutdown_function(function()use($restoreTestRoot){
    if(function_exists('db')){try{db()->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');}catch(Throwable){}}
    if(!is_dir($restoreTestRoot))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($restoreTestRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($restoreTestRoot);
});
require dirname(__DIR__).'/tools/restore.php';
$restoreChecks=0;
function restoreCheck(bool $ok,string $message):void{global $restoreChecks;if(!$ok)throw new RuntimeException('FAIL: '.$message);echo 'ok '.(++$restoreChecks).' - '.$message."\n";}
try{
    $p=saveEntity('patients',['name'=>'LARGE RECOVERY SYNTHETIC']);
    $text=rtrim(str_repeat('SYNTHETIC ',2900));$last=null;
    for($i=0;$i<45;$i++){
        $note=['patientId'=>$p['id'],'date'=>'2026-10-02'];
        foreach(['anamnesis','mentalStatus','diagnosis','therapy','conclusion','plan','risk','rebt'] as $field)$note[$field]=$text;
        $last=saveEntity('visits',$note);
    }
    $password='  Synthetic restore passphrase  ';
    $backupPath=$restoreTestRoot.'/large.pleroma';file_put_contents($backupPath,encode(makeBackup($password)));
    restoreCheck(filesize($backupPath)>12000000,'encrypted backup exceeds both web form and request limits');
    db()->exec('DELETE FROM records; DELETE FROM revisions;');
    $r=restoreFromFile($backupPath,$password);
    restoreCheck($r['count']===46&&count(records('visits'))===45,'CLI file recovery restores all records above the web limit');
    $restored=record('visits',$last['id']);
    restoreCheck($restored['rebt']===$text&&$restored['patientId']===$p['id'],'large recovery preserves exact clinical text and patient linkage');
    $oversized=$restoreTestRoot.'/oversized.pleroma';$handle=fopen($oversized,'wb');ftruncate($handle,RESTORE_FILE_LIMIT+1);fclose($handle);
    try{restoreFromFile($oversized,$password);throw new RuntimeException('oversize accepted');}catch(AppError $e){restoreCheck($e->status===400,'CLI size cap checked before reading or decrypting oversized file');}
    file_put_contents($restoreTestRoot.'/invalid.pleroma','{"broken":');
    try{restoreFromFile($restoreTestRoot.'/invalid.pleroma',$password);throw new RuntimeException('invalid JSON accepted');}catch(AppError $e){restoreCheck($e->status===400,'malformed backup file rejected without changing the recovered database');}
    restoreCheck(count(records('patients'))===1&&count(records('visits'))===45,'failed file operations leave the recovered data intact');
    echo "PASS: $restoreChecks CLI recovery checks.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
