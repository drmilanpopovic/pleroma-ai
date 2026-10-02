<?php
declare(strict_types=1);
// All tests use a newly created temporary database and synthetic records.
$testRoot = sys_get_temp_dir().'/pleroma-core-test-'.bin2hex(random_bytes(8));
mkdir($testRoot,0700,true);
$cfg = require dirname(__DIR__).'/config.example.php';
$cfg['data_dir'] = $testRoot.'/data';
$cfg['encryption_key'] = bin2hex(random_bytes(32));
$cfg['setup_token'] = bin2hex(random_bytes(32));
$cfg['base_url'] = 'http://127.0.0.1:9090';
$cfg['allow_local_http'] = true;
file_put_contents($testRoot.'/config.php', '<?php return '.var_export($cfg,true).';');
putenv('PLEROMA_CONFIG='.$testRoot.'/config.php');
register_shutdown_function(function() use ($testRoot): void {
    if (!is_dir($testRoot)) return;
    // SQLite-WASM closes WAL mappings after shutdown callbacks; detach the WAL first.
    if (function_exists('db') && is_file($testRoot.'/data/clinic.sqlite')) {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE); PRAGMA journal_mode=DELETE;');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($testRoot);
});
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/transfers.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    $checks++;
    echo 'ok '.$checks.' - '.$label."\n";
}
function rejects(callable $fn, int $status, string $label): void {
    try { $fn(); } catch (AppError $e) { check($e->status===$status,$label.' (HTTP '.$status.')'); return; }
    throw new RuntimeException('FAIL: accepted '.$label);
}

try {
    check(PHP_VERSION_ID>=80200,'PHP 8.2 or newer');
    check(extension_loaded('openssl')&&in_array('sqlite',PDO::getAvailableDrivers(),true),'encryption and SQLite are available');
    $secret = ['clinical'=>'SYNTHETIC-CONFIDENTIAL-4815','unicode'=>'čćžšđ'];
    $sealed = seal($secret);
    check(unseal($sealed)===$secret,'AES-GCM round trip including Serbian characters');
    check(seal($secret)!==$sealed,'encryption uses independent random nonces');
    $raw=base64_decode($sealed,true);$raw[29]=chr(ord($raw[29])^1);
    rejects(fn()=>unseal(base64_encode($raw)),422,'modified ciphertext rejected');

    rejects(fn()=>saveEntity('patients',[]),400,'patient requires a name');
    rejects(fn()=>saveEntity('patients',['name'=>'Synthetic','email'=>'not-email']),400,'invalid patient email');
    rejects(fn()=>saveEntity('patients',['name'=>'Synthetic','birthDate'=>'2099-01-01']),400,'future birth date');
    $p=saveEntity('patients',['name'=>'TEST PATIENT ALPHA','birthDate'=>'1986-02-12','email'=>'synthetic@example.invalid','phone'=>'+381000000','allergies'=>'SYNTHETIC ALLERGY','notes'=>$secret['clinical'],'diagnosis'=>'SYNTHETIC DIAGNOSIS']);
    check($p['version']===1&&strlen($p['id'])===32,'patient created with opaque identifier and version');
    check(record('patients',$p['id'])['notes']===$secret['clinical'],'patient read decrypts data');
    $old=$p;$p=saveEntity('patients',array_merge($p,['city'=>'Test city']));
    check($p['version']===2&&$p['created']===$old['created'],'patient update increments version and preserves creation');
    rejects(fn()=>saveEntity('patients',$old),409,'stale update from a second device');
    $rev=db()->query('SELECT * FROM revisions')->fetchAll();
    check(count($rev)===1&&unseal($rev[0]['payload'])['version']===1,'previous patient version retained');
    $q=saveEntity('patients',['name'=>'TEST PATIENT BETA']);

    $visit=saveEntity('visits',['patientId'=>$p['id'],'date'=>'2026-10-02','kind'=>'Prvi pregled','anamnesis'=>'Synthetic anamnesis','mentalStatus'=>'Synthetic examination','status'=>'draft']);
    check($visit['status']==='draft'&&!$visit['reviewed'],'clinical draft is unsigned');
    rejects(fn()=>saveEntity('visits',array_merge($visit,['status'=>'final'])),400,'final report requires explicit review');
    $final=saveEntity('visits',array_merge($visit,['status'=>'final','reviewed'=>true]));
    check($final['reviewed']&&is_string($final['signedAt']),'reviewed report can be finalized');
    rejects(fn()=>saveEntity('visits',array_merge($final,['anamnesis'=>'Changed'])),409,'final report cannot be overwritten');
    $amend=saveEntity('visits',['patientId'=>$p['id'],'date'=>'2026-10-03','kind'=>'Dopuna','amends'=>$final['id'],'anamnesis'=>'Synthetic addendum']);
    check($amend['amends']===$final['id'],'separate addendum references original report');
    rejects(fn()=>saveEntity('visits',['patientId'=>$q['id'],'date'=>'2026-10-03','amends'=>$final['id']]),400,'addendum cannot reference another patient');

    rejects(fn()=>saveEntity('medications',['patientId'=>$p['id']]),400,'medication requires a name');
    rejects(fn()=>saveEntity('medications',['patientId'=>$p['id'],'brand'=>'TEST MEDICINE','start'=>'2026-10-02','end'=>'2026-10-01']),400,'medication end precedes start');
    rejects(fn()=>saveEntity('medications',['patientId'=>$p['id'],'brand'=>'TEST MEDICINE','status'=>'stopped']),400,'stopped medication requires stop date');
    $med=saveEntity('medications',['patientId'=>$p['id'],'brand'=>'TEST MEDICINE','inn'=>'SYNTHETIC SUBSTANCE','strength'=>'10 mg','schedule'=>'test schedule','start'=>'2026-10-02']);
    check($med['status']==='active'&&$med['schedule']==='test schedule','active medication dose schedule retained');
    $med=saveEntity('medications',array_merge($med,['status'=>'stopped','end'=>'2026-10-03']));
    check($med['status']==='stopped'&&$med['version']===2,'medication can be stopped with history');
    rejects(fn()=>saveEntity('medications',array_merge($med,['patientId'=>$q['id']])),400,'existing medication cannot move between patients');

    $slot=['patientId'=>$p['id'],'start'=>'2026-10-05T18:30:00+02:00','end'=>'2026-10-05T19:30:00+02:00'];
    $a=saveEntity('appointments',$slot);
    check($a['start']==='2026-10-05T16:30:00Z'&&$a['end']==='2026-10-05T17:30:00Z','appointments normalize timezone to UTC');
    rejects(fn()=>saveEntity('appointments',array_merge($slot,['patientId'=>$q['id']])),409,'double booking rejected across patients');
    rejects(fn()=>saveEntity('appointments',array_merge($slot,['start'=>'2026-10-05T16:45:00Z','end'=>'2026-10-05T17:00:00Z'])),409,'contained booking overlap rejected');
    $next=saveEntity('appointments',['patientId'=>$q['id'],'start'=>'2026-10-05T17:30:00Z','end'=>'2026-10-05T18:00:00Z']);
    check($next['start']===$a['end'],'adjacent appointments allowed');
    $cancelled=saveEntity('appointments',array_merge($a,['status'=>'cancelled']));
    $replacement=saveEntity('appointments',$slot);
    check($replacement['status']==='scheduled','cancelled appointment releases its timeslot');
    rejects(fn()=>saveEntity('appointments',['patientId'=>$p['id'],'start'=>'2026-10-06T18:30','end'=>'2026-10-06T19:30']),400,'appointment requires explicit timezone');
    rejects(fn()=>saveEntity('appointments',['patientId'=>$p['id'],'start'=>'2026-10-06T18:30:00Z','end'=>'2026-10-06T18:32:00Z']),400,'appointment under five minutes rejected');
    rejects(fn()=>saveEntity('appointments',['patientId'=>$p['id'],'start'=>'2026-10-06T18:30:00Z','end'=>'2026-10-07T18:31:00Z']),400,'appointment over 24 hours rejected');

    rejects(fn()=>saveEntity('measures',['patientId'=>$p['id'],'date'=>'2026-10-02','scale'=>'PHQ-9','score'=>28]),400,'out of range PHQ-9 score rejected');
    rejects(fn()=>saveEntity('measures',['patientId'=>$p['id'],'date'=>'2026-10-02','scale'=>'GAD-7','score'=>1.5]),400,'fractional clinical scale score rejected');
    $measure=saveEntity('measures',['patientId'=>$p['id'],'date'=>'2026-10-02','scale'=>'GAD-7','score'=>12,'riskItem'=>'notAssessed']);
    check($measure['score']===12&&$measure['riskItem']==='notAssessed','clinical score stores assessment status without inferring risk');
    $lab=saveEntity('labs',['patientId'=>$p['id'],'date'=>'2026-10-02','test'=>'SYNTHETIC LAB','result'=>'test result','due'=>'2026-11-02']);
    check($lab['due']==='2026-11-02','laboratory result and follow-up stored');

    setSetting('test_secret',['access_token'=>'SYNTHETIC-ACCESS-TOKEN']);
    $payloads=implode('',db()->query('SELECT payload FROM records UNION ALL SELECT payload FROM revisions UNION ALL SELECT payload FROM settings')->fetchAll(PDO::FETCH_COLUMN));
    check(!str_contains($payloads,$secret['clinical'])&&!str_contains($payloads,'TEST PATIENT')&&!str_contains($payloads,'SYNTHETIC-ACCESS-TOKEN'),'record, revision and setting payloads are encrypted');
    $auditText=encode(db()->query('SELECT * FROM audit')->fetchAll());
    check(!str_contains($auditText,'TEST PATIENT')&&!str_contains($auditText,$secret['clinical']),'audit omits patient names and clinical text');
    rejects(fn()=>makeBackup('short'),400,'backup requires a long passphrase');
    $password='test-backup-passphrase-only';$backup=makeBackup($password);
    check($backup['format']==='pleroma-encrypted-v1'&&!str_contains(encode($backup),'TEST PATIENT'),'portable backup contains encrypted records');
    rejects(fn()=>restoreBackup(['backup'=>$backup,'password'=>'wrong-backup-passphrase']),422,'wrong backup passphrase rejected');
    rejects(fn()=>restoreBackup(['backup'=>$backup,'password'=>$password]),409,'restore never overwrites a populated database');
    $count=(int)db()->query('SELECT COUNT(*) FROM records')->fetchColumn();
    $revisionCount=(int)db()->query('SELECT COUNT(*) FROM revisions')->fetchColumn();
    db()->exec('DELETE FROM records; DELETE FROM revisions');
    $restored=restoreBackup(['backup'=>$backup,'password'=>$password]);
    check($restored['count']===$count&&record('patients',$p['id'])['notes']===$secret['clinical'],'backup restores records and clinical contents to empty database');
    check((int)db()->query('SELECT COUNT(*) FROM revisions')->fetchColumn()===$revisionCount,'backup restores revision history');
    $restoredAppointment=record('appointments',$a['id']);
    check($restoredAppointment['googleEventId']===null&&$restoredAppointment['googleDirty']===true,'restored appointments require a fresh Google sync');

    $legacy=['patients'=>[['id'=>'synthetic-legacy-1','name'=>'TEST LEGACY PATIENT','telefon'=>'000','reports'=>[['id'=>'r1','datum_pregleda'=>'02.10.2026.','anamneza'=>'Synthetic old anamnesis','dijagnoze'=>[['code'=>'TEST','text'=>'Synthetic']]]]]]];
    $preview=importLegacy(['source'=>$legacy]);
    check($preview['preview']===true&&$preview['patients']===1&&$preview['visits']===1,'legacy import offers a read-only preview');
    check((int)db()->query('SELECT COUNT(*) FROM records')->fetchColumn()===$count,'legacy preview changes no records');
    $imported=importLegacy(['source'=>$legacy,'confirmed'=>true]);
    check($imported['patients']===1&&$imported['visits']===1,'legacy patient and report import succeeds');
    $again=importLegacy(['source'=>$legacy,'confirmed'=>true]);
    check($again['patients']===0&&$again['visits']===0,'same legacy export does not duplicate records');

    rateLimit('unit-test',2,60);rateLimit('unit-test',2,60);
    rejects(fn()=>rateLimit('unit-test',2,60),429,'attempt limiter rejects excess attempts');
    echo "PASS: $checks core assertions (PHP ".PHP_VERSION.").\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
}
