<?php
declare(strict_types=1);
// Focused regression checks for integrity boundaries, using synthetic data only.
$securityRoot=sys_get_temp_dir().'/pleroma-security-'.bin2hex(random_bytes(8));
mkdir($securityRoot,0700,true);mkdir($securityRoot.'/web',0700);
$configFile=$securityRoot.'/config.php';
$securityConfig=require dirname(__DIR__).'/config.example.php';
$securityConfig['encryption_key']=bin2hex(random_bytes(32));
$securityConfig['data_dir']=$securityRoot.'/web/private';
$securityConfig['base_url']='https://clinic.example.invalid';
$_SERVER['DOCUMENT_ROOT']=$securityRoot.'/web';
file_put_contents($configFile,'<?php return '.var_export($securityConfig,true).';');
putenv('PLEROMA_CONFIG='.$configFile);
register_shutdown_function(function()use($securityRoot){
    if(!is_dir($securityRoot))return;
    if(function_exists('db')){try{db()->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');}catch(Throwable){}}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($securityRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $f){$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($securityRoot);
});
require dirname(__DIR__).'/app/transfers.php';
$securityChecks=0;
function secureCheck(bool $ok,string $label):void{global $securityChecks;if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'ok '.(++$securityChecks).' - '.$label."\n";}
function secureReject(callable $fn,int $status,string $label):void{try{$fn();}catch(AppError $e){secureCheck($e->status===$status,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}

try{
    secureReject(fn()=>config(),503,'public storage rejected');
    secureReject(fn()=>config(),503,'failed configuration is never cached as valid');
    $securityConfig['data_dir']=$securityRoot.'/private';
    file_put_contents($configFile,'<?php return '.var_export($securityConfig,true).';');
    secureCheck(config()['data_dir']===$securityRoot.'/private','corrected private configuration can initialize');
    secureCheck(rawSecret(['password'=>'  meaningful secret  '],'password')==='  meaningful secret  ','password boundaries are preserved exactly');
    secureReject(fn()=>rawSecret(['password'=>"a\0b"],'password'),400,'NUL password rejected');

    atomic(function(){
        setSetting('security_outer','kept');
        secureReject(fn()=>atomic(function(){setSetting('security_inner','rollback');fail('synthetic',409);}),409,'nested operation fails independently');
        secureCheck(setting('security_inner')===null,'nested SAVEPOINT rolls back its changes');
    });
    secureCheck(setting('security_outer')==='kept','outer transaction commits after handled inner rollback');
    secureReject(fn()=>atomic(function(){saveEntity('patients',['name'=>'ROLLBACK SYNTHETIC']);fail('synthetic',409);}),409,'outer transaction failure propagated');
    secureCheck(records('patients')===[],'nested record writes roll back with the outer transaction');
    $p=saveEntity('patients',['name'=>'SYNTHETIC ONE']);$q=saveEntity('patients',['name'=>'SYNTHETIC TWO']);
    secureReject(fn()=>saveEntity('patients',['name'=>'SYNTHETIC','version'=>'0']),400,'string version cannot bypass optimistic version checks');
    secureReject(fn()=>instant('2026-10-02T24:00:00Z'),400,'normalized invalid hour rejected');
    secureReject(fn()=>instant('2026-10-02T12:00:60Z'),400,'invalid second rejected');
    secureReject(fn()=>instant('2026-10-02T12:00:00+14:30'),400,'invalid timezone offset rejected');
    secureCheck(instant('2026-10-25T02:30:00+02:00')!==instant('2026-10-25T02:30:00+01:00'),'DST repeated hours retain their distinct instants');
    $p['archived']=true;$p=put('patients',$p);
    secureReject(fn()=>saveEntity('patients',$p),409,'archived patient cannot be edited without restoration');
    $p['archived']=false;$p=put('patients',$p);
    $v=saveEntity('visits',['patientId'=>$p['id'],'date'=>'2026-10-02','anamnesis'=>'Synthetic history']);
    secureReject(fn()=>saveEntity('measures',['patientId'=>$p['id'],'date'=>'2026-10-02','scale'=>'GAD-7','score'=>true]),400,'boolean cannot become a clinical score');
    secureReject(fn()=>saveEntity('visits',array_merge($v,['status'=>'final','reviewed'=>'false'])),400,'truthy string is not an explicit clinical review');
    secureReject(fn()=>atomic(function()use($p,$q){
        $s=db()->prepare('UPDATE records SET payload=(SELECT payload FROM records WHERE id=? AND type=?) WHERE id=? AND type=?');
        $s->execute([$q['id'],'patients',$p['id'],'patients']);
        record('patients',$p['id']);
    }),422,'swapped encrypted rows cannot relabel clinical records');
    secureCheck(record('patients',$p['id'])['name']==='SYNTHETIC ONE','integrity failure rolls back tampered row');

    $source=['patients'=>[['id'=>'synthetic-legacy-1','name'=>'SYNTHETIC LEGACY','godiste'=>1980,'quickNotes'=>[['text'=>'Synthetic original note']],'reports'=>[['id'=>'r1','date'=>'2.10.2026.','anamneza'=>'Synthetic first source','napomena'=>'Synthetic instruction','phq9_score'=>9]]]]];
    $preview=importLegacy(['source'=>$source,'confirmed'=>'false']);
    secureCheck(($preview['preview']??false)===true,'import requires boolean confirmation');
    $first=importLegacy(['source'=>$source,'confirmed'=>true]);
    secureCheck($first['patients']===1&&$first['visits']===1&&$first['measures']===1,'original report and documented scale imported');
    $legacyId=legacyPatientId($source['patients'][0]);$legacyPatient=record('patients',$legacyId);
    $legacyPatient['name']='SYNTHETIC EDITED';saveEntity('patients',$legacyPatient);
    secureCheck(record('patients',$legacyId)['legacySource']===$source['patients'][0],'editing clinical fields preserves original source');
    $source['patients'][0]['reports'][]=['id'=>'r2','date'=>'31.02.2026.','anamneza'=>'Second synthetic report'];
    $second=importLegacy(['source'=>$source,'confirmed'=>true]);
    secureCheck($second['patients']===0&&$second['visits']===1&&$second['skipped']===1&&$second['warningCount']===1,'repeat import adds new reports and flags invalid source dates');
    secureCheck(record('patients',$legacyId)['name']==='SYNTHETIC EDITED','incremental import never overwrites the edited patient name');
    $source['patients'][0]['reports'][0]['anamneza']='Changed synthetic source';
    $third=importLegacy(['source'=>$source,'confirmed'=>true]);
    secureCheck($third['changed']===1&&$third['visits']===1,'changed legacy report is retained as a separate draft');
    $fourth=importLegacy(['source'=>$source,'confirmed'=>true]);
    secureCheck($fourth['visits']===0,'repeating the same changed source is idempotent');
    secureCheck(legacyDate('31.02.2026.')===''&&legacyDate('29.02.2024.')==='2024-02-29','source dates never silently normalize');

    $a=saveEntity('appointments',['patientId'=>$p['id'],'start'=>'2026-10-06T08:00:00Z','end'=>'2026-10-06T09:00:00Z']);
    $event=['eventId'=>'pl'.str_repeat('a',64),'calendarId'=>'synthetic@example.invalid','phase'=>'pending'];
    setSetting('google_event_'.$a['id'],$event);setSetting('google_tokens',['access_token'=>'DO-NOT-BACKUP-SYNTHETIC']);setSetting('user',['password'=>'DO-NOT-BACKUP-AUTH']);
    $password='  synthetic backup password  ';
    $backup=makeBackup($password);$key=hash_pbkdf2('sha256',$password,base64_decode($backup['salt'],true),600000,32,true);$snapshot=unseal($backup['data'],$key);
    secureCheck(count($snapshot['calendarLinks'])===1&&$snapshot['calendarLinks'][0]['phase']==='pending','backup retains pending Google idempotency mapping');
    secureCheck(!str_contains(encode($snapshot),'DO-NOT-BACKUP'),'backup excludes OAuth and account secrets');
    $badLink=$snapshot['calendarLinks'][0];$badLink['calendarId']='primary';
    secureReject(fn()=>validateCalendarBackupLink($badLink),422,'unresolved calendar aliases rejected during restore validation');
    $badSnapshot=$snapshot;$badSnapshot['records'][0]['data']['id']=str_repeat('0',32);
    $forged=$backup;$forged['data']=seal($badSnapshot,$key);
    db()->exec('DELETE FROM records; DELETE FROM revisions;');
    secureReject(fn()=>restoreBackup(['password'=>$password,'backup'=>$forged]),422,'forged snapshot metadata rejected before writes');
    secureCheck(records('patients')===[],'rejected restore leaves clinical tables empty');
    $restored=restoreBackup(['password'=>$password,'backup'=>$backup]);
    secureCheck($restored['count']===count($snapshot['records']),'verified snapshot restores clinical records and historical versions');
    $restoredAppointment=record('appointments',$a['id']);
    secureCheck(setting('google_event_'.$a['id'])===$event&&$restoredAppointment['googleEventId']===$event['eventId']&&$restoredAppointment['googleDirty']===true,'restored pending mapping reconciles without creating another event ID');
    secureCheck(record('patients',$legacyId)['legacySource']===$source['patients'][0],'restored backup retains complete legacy provenance');
    echo "PASS: $securityChecks security regression checks.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
