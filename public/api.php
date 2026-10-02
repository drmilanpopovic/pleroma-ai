<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once PLEROMA_APP_DIR.'/core.php';
require_once PLEROMA_APP_DIR.'/integrations.php';
require_once PLEROMA_APP_DIR.'/transfers.php';
ini_set('display_errors','0');headersForApp();
try {
    sessionBoot();$action=$_GET['action']??'session';
    if(!is_string($action)||!preg_match('/^[A-Za-z]+$/',$action))fail('Neispravna radnja.');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true))fail('Metod nije podržan.',405);
    if($method==='POST'&&$action!=='transcribe'&&strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')fail('Očekivan je JSON zahtev.',415);
    if($action==='session') {
        response(['ok'=>true,'csrf'=>$_SESSION['csrf'],'loggedIn'=>!empty($_SESSION['user']),'needsSetup'=>setting('user')===null,'doctor'=>config()['doctor_name'],'practice'=>config()['practice_name']]);
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')csrf();
    $getActions=['state','audit','history'];
    if(!in_array($action,$getActions,true)&&($_SERVER['REQUEST_METHOD']??'GET')!=='POST')fail('Ova radnja zahteva POST.',405);
    $b=($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$action!=='transcribe'?jsonBody():[];
    if($action==='setup'){
        rateLimit('setup',5,900);
        atomic(function()use($b){
            if(setting('user')!==null)fail('Početno podešavanje je već završeno.',409);
            $token=txt($b,'setupToken',128,true);$expected=config()['setup_token']??'';
            if(strlen($expected)<32||!hash_equals($expected,$token))fail('Instalacioni kod nije ispravan.',403);
            $pass=rawSecret($b,'password');$email=txt($b,'email',180,true);
            if(strlen($pass)<12||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Unesite e-poštu i lozinku od najmanje 12 znakova.');
            setSetting('user',['email'=>strtolower($email),'password'=>password_hash($pass,PASSWORD_DEFAULT)]);audit('setup');
        });response(['ok'=>true]);
    }
    if($action==='login'){
        rateLimit('login',8,900);$u=setting('user');$password=rawSecret($b,'password');
        if(!$u||strtolower(txt($b,'email',180,true))!==$u['email']||!password_verify($password,$u['password']))fail('E-pošta ili lozinka nisu ispravne.',401);
        session_regenerate_id(true);$_SESSION['user']=true;$_SESSION['seen']=time();$_SESSION['issued']=time();$_SESSION['csrf']=uid();audit('login');response(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    auth();
    if($action==='heartbeat')response(['ok'=>true]);
    if($action==='logout'){audit('logout');$_SESSION=[];session_destroy();response(['ok'=>true]);}
    if($action==='state'){
        $data=atomic(function(){
            $snapshot=[];foreach(ENTITY_TYPES as $type)$snapshot[$type]=records($type);
            $snapshot['integrations']=['ai'=>!empty(config()['openai_api_key'])&&!empty(config()['openai_model']),'googleConfigured'=>googleConfigured(),'googleConnected'=>(bool)setting('google_tokens')];
            audit('read_workspace');return $snapshot;
        });response(['ok'=>true,'data'=>$data]);
    }
    if($action==='save'){
        if(!isset($b['record'])||!is_array($b['record'])||($b['record']!==[]&&array_is_list($b['record'])))fail('Zapis mora biti JSON objekat.');
        response(['ok'=>true,'record'=>saveEntity(txt($b,'type',30,true),$b['record'])]);
    }
    if($action==='archive'){
        if(!is_int($b['version']??null)||$b['version']<1)fail('Neispravna verzija kartona.');
        $p=atomic(function()use($b){$p=record('patients',txt($b,'id',32,true));if(!$p)fail('Karton ne postoji.',404);if((int)($b['version']??0)!==$p['version'])fail('Karton je izmenjen na drugom uređaju.',409);
            if(!($p['archived']??false))foreach(records('appointments') as $a)if($a['patientId']===$p['id']&&$a['status']==='scheduled'&&$a['end']>now())fail('Prvo otkažite buduće termine ovog pacijenta.',409);
            $p['archived']=!($p['archived']??false);return put('patients',$p);});response(['ok'=>true,'record'=>$p]);
    }
    if($action==='history'){
        $s=db()->prepare('SELECT version,payload,updated FROM revisions WHERE type=? AND id=? ORDER BY seq DESC LIMIT 30');$s->execute([txt($_GET,'type',30),txt($_GET,'id',32)]);$rows=$s->fetchAll();foreach($rows as &$r){$r['data']=unseal($r['payload']);unset($r['payload']);}response(['ok'=>true,'items'=>$rows]);
    }
    if($action==='audit')response(['ok'=>true,'items'=>db()->query('SELECT action,resource,time FROM audit ORDER BY seq DESC LIMIT 100')->fetchAll()]);
    if($action==='ai')response(['ok'=>true]+runAI($b));
    if($action==='transcribe')response(['ok'=>true]+transcribe());
    if($action==='backup')response(['ok'=>true,'backup'=>makeBackup(rawSecret($b,'password'))]);
    if($action==='restore')response(['ok'=>true]+restoreBackup($b));
    if($action==='importLegacy')response(['ok'=>true]+importLegacy($b));
    if($action==='googleStart')response(['ok'=>true,'url'=>googleStart()]);
    if($action==='googleSync')response(['ok'=>true]+googleSync());
    if($action==='googleDisconnect'){
        $t=setting('google_tokens');
        if($t){$r=httpRequest('https://oauth2.googleapis.com/revoke','POST',['token'=>$t['refresh_token']??$t['access_token']],[],true);if(!in_array($r['status'],[200,400],true))fail('Opoziv Google dozvole nije uspeo. Pokušajte ponovo.',502);}
        setSetting('google_tokens',null);audit('google_disconnected');response(['ok'=>true]);
    }
    if($action==='password'){
        $u=setting('user');if(!password_verify(rawSecret($b,'current'),$u['password']))fail('Trenutna lozinka nije ispravna.',403);
        $p=rawSecret($b,'password');if(strlen($p)<12)fail('Nova lozinka mora imati najmanje 12 znakova.');$u['password']=password_hash($p,PASSWORD_DEFAULT);setSetting('user',$u);
        foreach(glob(config()['data_dir'].'/sessions/sess_*')?:[] as $f)if(basename($f)!=='sess_'.session_id())@unlink($f);
        audit('password_changed');response(['ok'=>true]);
    }
    fail('Nepoznata radnja.',404);
} catch(AppError $e){response(['ok'=>false,'error'=>$e->getMessage()],$e->status);}
catch(Throwable $e){error_log('Pleroma server error: '.get_class($e));response(['ok'=>false,'error'=>'Serverska greška. Podaci nisu potvrđeni kao sačuvani.'],500);}
