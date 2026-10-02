<?php
declare(strict_types=1);
final class AppError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}
function fail(string $message, int $status = 400): never { throw new AppError($message, $status); }
const ENTITY_TYPES = ['patients','visits','medications','appointments','measures','labs'];
function config(): array {
    static $c;
    if ($c !== null) return $c;
    $path = getenv('PLEROMA_CONFIG') ?: dirname(__DIR__) . '/config.php';
    if (!is_file($path)) fail('Administrator treba da pripremi config.php prema uputstvu.', 503);
    // Cache only a completely validated configuration. A caught failure must not bypass checks.
    $candidate = require $path;
    if (!is_array($candidate) || !is_string($candidate['encryption_key'] ?? null) || !preg_match('/^[a-f0-9]{64}$/iD', $candidate['encryption_key'])) fail('Ključ za šifrovanje nije podešen.', 503);
    $dir = $candidate['data_dir'] ?? '';
    if (!is_string($dir) || $dir === '' || $dir[0] !== '/' || str_contains($dir, "\0")) fail('Privatni direktorijum mora imati apsolutnu putanju.', 503);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) fail('Privatni direktorijum nije dostupan.', 503);
    $public = realpath(dirname(__DIR__) . '/public'); $real = realpath($dir);
    if ($real === false || $real === DIRECTORY_SEPARATOR) fail('Privatni direktorijum nije važeći.', 503);
    $docroot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    foreach ([$public, $docroot] as $root) {
        if ($root && ($real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/'))) fail('Baza mora biti izvan javnog web direktorijuma.', 503);
        $configReal = realpath($path);
        if ($root && $configReal && str_starts_with($configReal, rtrim($root, '/') . '/')) fail('Konfiguracija mora biti izvan javnog web direktorijuma.', 503);
    }
    if (!@chmod($real, 0700) || !is_readable($real) || !is_writable($real)) fail('Privatni direktorijum mora biti dostupan samo vlasniku aplikacije.', 503);
    $base = $candidate['base_url'] ?? '';
    $url = is_string($base) ? parse_url($base) : false;
    $localHttp = ($candidate['allow_local_http'] ?? false) === true && is_array($url) && in_array($url['host'] ?? '', ['localhost','127.0.0.1','[::1]'], true);
    if (!is_array($url) || empty($url['host']) || (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && $localHttp)) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) fail('Podesite važeću HTTPS adresu aplikacije.', 503);
    $candidate['data_dir'] = $real;
    umask(0077);
    $c = $candidate;
    return $c;
}
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function uid(): string { return bin2hex(random_bytes(16)); }
function encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
function seal(mixed $value, ?string $key = null): string {
    $key ??= hex2bin(config()['encryption_key']); $iv = random_bytes(12); $tag = '';
    if (strlen($key) !== 32) fail('Ključ za šifrovanje nije važeći.', 503);
    $cipher = openssl_encrypt(encode($value), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'pleroma-v1');
    if ($cipher === false) fail('Šifrovanje nije uspelo.', 500);
    return base64_encode($iv . $tag . $cipher);
}
function unseal(string $value, ?string $key = null): mixed {
    $key ??= hex2bin(config()['encryption_key']); $raw = base64_decode($value, true);
    if (strlen($key) !== 32) fail('Ključ za šifrovanje nije važeći.', 503);
    if ($raw === false || strlen($raw) < 29) fail('Neispravan šifrovani zapis.', 422);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw,0,12), substr($raw,12,16), 'pleroma-v1');
    if ($plain === false) fail('Podaci ne mogu da se otključaju. Proverite ključ ili rezervnu kopiju.', 422);
    try { return json_decode($plain, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { fail('Šifrovani zapis ne sadrži ispravne podatke.', 422); }
}
function db(): PDO {
    static $db;
    if ($db) return $db;
    $path = config()['data_dir'] . '/clinic.sqlite';
    if (is_link($path)) fail('Datoteka baze ne sme biti simbolička veza.', 503);
    $candidate = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $candidate->exec('PRAGMA busy_timeout=8000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA synchronous=FULL;');
    $candidate->exec('CREATE TABLE IF NOT EXISTS records (type TEXT NOT NULL, id TEXT NOT NULL, patient_id TEXT, version INTEGER NOT NULL, payload TEXT NOT NULL, updated TEXT NOT NULL, PRIMARY KEY(type,id));
        CREATE INDEX IF NOT EXISTS records_patient ON records(patient_id,type);
        CREATE TABLE IF NOT EXISTS revisions (seq INTEGER PRIMARY KEY, type TEXT, id TEXT, version INTEGER, payload TEXT, updated TEXT);
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, payload TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS audit (seq INTEGER PRIMARY KEY, action TEXT NOT NULL, resource TEXT, time TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS attempts (key TEXT PRIMARY KEY, count INTEGER NOT NULL, until INTEGER NOT NULL);');
    if (!@chmod($path, 0600)) fail('Dozvole datoteke baze nisu bezbedne.', 503);
    $db = $candidate; return $db;
}
function setting(string $key, mixed $default = null): mixed { $s=db()->prepare('SELECT payload FROM settings WHERE key=?');$s->execute([$key]);$r=$s->fetch();return $r?unseal($r['payload']):$default; }
function setSetting(string $key, mixed $value): void { db()->prepare('INSERT INTO settings VALUES (?,?) ON CONFLICT(key) DO UPDATE SET payload=excluded.payload')->execute([$key,seal($value)]); }
function audit(string $action, string $resource=''): void { db()->prepare('INSERT INTO audit(action,resource,time) VALUES (?,?,?)')->execute([$action,$resource,now()]); }
function storedRecord(array $row): array {
    $data=unseal($row['payload']);
    if(!is_array($data)||($data['id']??null)!==$row['id']||($data['version']??null)!==(int)$row['version']||($data['updated']??null)!==$row['updated']||(($data['entityType']??$row['type'])!==$row['type'])||($row['type']!=='patients'&&($data['patientId']??null)!==$row['patient_id']))fail('Integritet sačuvanog zapisa nije potvrđen. Vratite ispravnu rezervnu kopiju.',422);
    return $data;
}
function records(string $type): array {
    $s=db()->prepare('SELECT * FROM records WHERE type=? ORDER BY updated DESC');$s->execute([$type]);
    return array_map(fn($r)=>storedRecord($r), $s->fetchAll());
}
function record(string $type,string $id): ?array { $s=db()->prepare('SELECT * FROM records WHERE type=? AND id=?');$s->execute([$type,$id]);$r=$s->fetch();return $r?storedRecord($r):null; }
function atomic(callable $fn): mixed {
    static $depth = 0;
    $d = db(); $outer = $depth === 0; $savepoint = 'pleroma_' . $depth;
    $d->exec($outer ? 'BEGIN IMMEDIATE' : 'SAVEPOINT ' . $savepoint);
    $depth++;
    try { $value = $fn(); $d->exec($outer ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $savepoint); return $value; }
    catch (Throwable $e) {
        try { $d->exec($outer ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $savepoint); if (!$outer) $d->exec('RELEASE SAVEPOINT ' . $savepoint); }
        catch (Throwable) { /* Preserve the operation error; the request must fail. */ }
        throw $e;
    } finally { $depth--; }
}
function put(string $type,array $data): array {
    return atomic(function() use ($type, $data) {
    if (!in_array($type, ENTITY_TYPES, true)) fail('Nepoznata vrsta zapisa.');
    $id=$data['id']??uid(); if(!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) fail('Neispravna oznaka zapisa.');
    $old=record($type,$id);$version=$data['version']??0;
    if (!is_int($version) || $version < 0 || $version >= PHP_INT_MAX) fail('Neispravna verzija zapisa.');
    if ($old && $old['version']!==$version) fail('Zapis je izmenjen na drugom uređaju. Osvežite prikaz pre novog čuvanja.',409);
    if (!$old && $version!==0) fail('Zapis više ne postoji.',409);
    if ($type !== 'patients') {
        $patientId = $data['patientId'] ?? null;
        if (!is_string($patientId) || !preg_match('/^[a-f0-9]{32}$/D', $patientId) || !record('patients', $patientId)) fail('Zapis mora pripadati postojećem kartonu.', 404);
        if ($old && ($old['patientId'] ?? null) !== $patientId) fail('Pacijent sačuvanog zapisa ne može da se promeni.', 409);
    } else { unset($data['patientId']); }
    if ($old && array_key_exists('legacySource', $old) && !array_key_exists('legacySource', $data)) $data['legacySource'] = $old['legacySource'];
    if ($old && isset($old['legacyReplaces']) && !isset($data['legacyReplaces'])) $data['legacyReplaces'] = $old['legacyReplaces'];
    if ($old) db()->prepare('INSERT INTO revisions(type,id,version,payload,updated) VALUES (?,?,?,?,?)')->execute([$type,$id,$version,seal($old),now()]);
    $data['id']=$id;$data['version']=$version+1;$data['updated']=now();$data['created']=$old['created']??now();$data['entityType']=$type;
    db()->prepare('INSERT INTO records VALUES (?,?,?,?,?,?) ON CONFLICT(type,id) DO UPDATE SET patient_id=excluded.patient_id,version=excluded.version,payload=excluded.payload,updated=excluded.updated')->execute([$type,$id,$data['patientId']??null,$data['version'],seal($data),$data['updated']]);
    audit('save_'.$type,$id);return $data;
    });
}
function txt(array $b,string $key,int $max=500,bool $required=false): string {
    if (isset($b[$key]) && !is_string($b[$key])) fail('Polje '.$key.' mora biti tekst.');
    $v=trim($b[$key]??''); if(strlen($v)>$max || ($required && $v==='')) fail('Proverite polje: '.$key.'.');
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) fail('Nedozvoljeni znaci u polju '.$key.'.'); return $v;
}
function rawSecret(array $b,string $key,int $max=200): string {
    $value=$b[$key]??null;
    if(!is_string($value)||$value===''||strlen($value)>$max||str_contains($value,"\0"))fail('Proverite tajno polje: '.$key.'.');
    return $value;
}
function choice(array $b,string $key,array $allowed,string $default): string { $v=txt($b,$key);$v=$v?:$default;if(!in_array($v,$allowed,true))fail('Neispravna vrednost: '.$key.'.');return $v; }
function dateOnly(string $s,bool $empty=true): string { if($s===''&&$empty)return ''; $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);if(!$d||str_starts_with($s,'0000-')||$d->format('Y-m-d')!==$s)fail('Datum mora biti u obliku GGGG-MM-DD.');return $s; }
function instant(string $s): string {
    if(!preg_match('/^\d{4}-\d\d-\d\dT([0-2]\d):([0-5]\d)(?::([0-5]\d)(?:\.\d+)?)?(Z|[+-](\d\d):([0-5]\d))$/D',$s,$m))fail('Termin mora sadržati važeće vreme i vremensku zonu.');
    if((int)$m[1]>23 || ($m[4]!=='Z' && ((int)$m[5]>14 || ((int)$m[5]===14 && (int)$m[6]!==0))))fail('Neispravno vreme ili vremenska zona termina.');
    dateOnly(substr($s,0,10),false);
    try{$d=new DateTimeImmutable($s);}catch(Throwable){fail('Neispravan termin.');}
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
function patientRef(array $b): string { $id=txt($b,'patientId',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$id))fail('Neispravna oznaka kartona.');$p=record('patients',$id);if(!$p||($p['archived']??false))fail('Izaberite aktivan karton pacijenta.',404);return $id; }
function meta(array $b): array { $out=[];if(isset($b['id']))$out['id']=txt($b,'id',32,true);$version=$b['version']??0;if(!is_int($version)||$version<0||$version>=PHP_INT_MAX)fail('Neispravna verzija zapisa.');$out['version']=$version;return $out; }
function saveEntity(string $type,array $b): array {
    return atomic(function() use($type,$b) {
        $v=meta($b);
        if($type==='patients') {
            $v+=['name'=>txt($b,'name',160,true),'birthDate'=>dateOnly(txt($b,'birthDate',10)), 'phone'=>txt($b,'phone',50),'email'=>txt($b,'email',180),'city'=>txt($b,'city',200),'allergies'=>txt($b,'allergies',3000),'notes'=>txt($b,'notes',12000),'diagnosis'=>txt($b,'diagnosis',2000)];
            if($v['birthDate']!==''&&$v['birthDate']>gmdate('Y-m-d'))fail('Datum rođenja ne može biti u budućnosti.');
            $old=isset($v['id'])?record($type,$v['id']):null;
            if($old && ($old['archived']??false))fail('Vratite karton iz arhive pre uređivanja.',409);
            $v['code']=$old['code']??('PL-'.strtoupper(substr(uid(),0,8)));$v['archived']=$old['archived']??false;
            if($v['email']!==''&&!filter_var($v['email'],FILTER_VALIDATE_EMAIL))fail('Proverite adresu e-pošte.');
        } else {
            $v['patientId']=patientRef($b);
            $old=isset($v['id'])?record($type,$v['id']):null;
            if($old && $old['patientId']!==$v['patientId'])fail('Pacijent sačuvanog zapisa ne može da se promeni.');
            if($type==='visits') {
                if($old&&($old['status']??'')==='final')fail('Završen pregled ostaje zaključan. Napravite novi pregled ili dopunu.',409);
                $v+=['date'=>dateOnly(txt($b,'date',10),false),'kind'=>choice($b,'kind',['Prvi pregled','Kontrola','Psihoterapija','Dopuna'],'Kontrola'),'status'=>choice($b,'status',['draft','final'],'draft')];
                foreach(['anamnesis','mentalStatus','diagnosis','therapy','conclusion','plan','risk','rebt'] as $key)$v[$key]=txt($b,$key,30000);
                $v['followup']=dateOnly(txt($b,'followup',10));$v['amends']=txt($b,'amends',32);
                if($v['amends']!==''&&(!($am=record('visits',$v['amends']))||$am['patientId']!==$v['patientId']||($am['status']??'')!=='final'||$v['amends']===($v['id']??'')))fail('Dopuna mora biti vezana za završen pregled istog pacijenta.');
                if($v['status']==='final'&&(($b['reviewed']??false)!==true||$v['anamnesis']===''))fail('Pregledajte tekst i potvrdite nalaz pre zaključavanja.');
                $v['reviewed']=$v['status']==='final';$v['signedAt']=$v['reviewed']?now():null;
            } elseif($type==='medications') {
                foreach(['brand','inn','strength','schedule','instructions'] as $key)$v[$key]=txt($b,$key,$key==='instructions'?3000:250);
                if($v['brand']===''&&$v['inn']==='')fail('Unesite naziv leka.');
                $v['start']=dateOnly(txt($b,'start',10));$v['end']=dateOnly(txt($b,'end',10));
                if($v['start']&&$v['end']&&$v['end']<$v['start'])fail('Završetak terapije prethodi početku.');
                $v['status']=choice($b,'status',['active','stopped'],'active');
                if($v['status']==='stopped'&&!$v['end'])fail('Unesite datum obustave terapije.');
            } elseif($type==='appointments') {
                $v+=['start'=>instant(txt($b,'start',40,true)),'end'=>instant(txt($b,'end',40,true)), 'kind'=>choice($b,'kind',['Pregled','Kontrola','REBT','Partnerska terapija'],'Kontrola'), 'location'=>txt($b,'location',180),'status'=>choice($b,'status',['scheduled','completed','cancelled','noShow'],'scheduled')];
                $seconds=strtotime($v['end'])-strtotime($v['start']);if($seconds<300||$seconds>86400)fail('Trajanje termina mora biti između 5 minuta i 24 sata.');
                if(!in_array($v['status'],['cancelled','noShow'],true))foreach(records('appointments') as $a) {
                    if($a['id']!==($v['id']??'')&&!in_array($a['status'],['cancelled','noShow'],true)&&$a['start']<$v['end']&&$a['end']>$v['start'])fail('Termin se preklapa sa postojećim zakazivanjem.',409);
                }
                $v['googleEventId']=$old['googleEventId']??null;$v['googleSyncedAt']=$old['googleSyncedAt']??null;$v['googleDirty']=true;
            } elseif($type==='measures') {
                $v+=['scale'=>choice($b,'scale',['PHQ-9','GAD-7','HAM-A','MADRS'],'PHQ-9'),'date'=>dateOnly(txt($b,'date',10),false),'note'=>txt($b,'note',3000)];
                $max=['PHQ-9'=>27,'GAD-7'=>21,'HAM-A'=>56,'MADRS'=>60][$v['scale']];
                if(!is_int($b['score']??null))fail('Unesite ceo broj poena.');
                $v['score']=(int)$b['score'];if($v['score']<0||$v['score']>$max)fail('Skor je van opsega skale.');
                $v['riskItem']=choice($b,'riskItem',['notAssessed','no','yes'],'notAssessed');
            } elseif($type==='labs') {
                $v+=['test'=>txt($b,'test',300,true),'date'=>dateOnly(txt($b,'date',10),false),'result'=>txt($b,'result',12000),'due'=>dateOnly(txt($b,'due',10))];
            } else fail('Nepoznata vrsta zapisa.');
        }
        return put($type,$v);
    });
}
function headersForApp(): void {
    header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('Permissions-Policy: camera=(), geolocation=(), microphone=(self)');
}
function sessionBoot(): void {
    $c=config();$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
    $local=in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true);
    if(!$secure&&!($local&&($c['allow_local_http']??false)))fail('Aplikacija zahteva HTTPS.',403);
    if($secure)header('Strict-Transport-Security: max-age=31536000');
    $sessionDir=$c['data_dir'].'/sessions';if(is_link($sessionDir))fail('Direktorijum sesija ne sme biti simbolička veza.',503);if(!is_dir($sessionDir)&&!@mkdir($sessionDir,0700)&&!is_dir($sessionDir))fail('Direktorijum sesija nije dostupan.',503);
    if(!@chmod($sessionDir,0700)||!is_writable($sessionDir))fail('Direktorijum sesija nije bezbedan ili dostupan.',503);
    session_save_path($sessionDir);session_name('pleroma_clinic');ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    $path=rtrim(parse_url($c['base_url'],PHP_URL_PATH)?:'', '/').'/';
    session_set_cookie_params(['lifetime'=>0,'path'=>$path,'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);if(!session_start())fail('Sesija ne može da se pokrene.',503);
    if(!isset($_SESSION['csrf']))$_SESSION['csrf']=uid();
    if(isset($_SESSION['user'])&&(time()-($_SESSION['seen']??0)>1200||time()-($_SESSION['issued']??0)>43200)){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=uid();}
}
function auth(): void { if(empty($_SESSION['user']))fail('Prijavite se ponovo.',401);$_SESSION['seen']=time(); }
function csrf(): void { if(!hash_equals($_SESSION['csrf']??'',$_SERVER['HTTP_X_CSRF_TOKEN']??'')||empty($_SESSION['csrf']))fail('Sesija nije važeća. Osvežite stranicu.',403); }
function rateLimit(string $label,int $max,int $seconds): void {
    $key=hash_hmac('sha256',$label.'|'.($_SERVER['REMOTE_ADDR']??'local'),config()['encryption_key']);
    atomic(function()use($key,$max,$seconds){$s=db()->prepare('SELECT * FROM attempts WHERE key=?');$s->execute([$key]);$r=$s->fetch();if($r&&$r['until']>time()&&$r['count']>=$max)fail('Previše pokušaja. Pokušajte kasnije.',429);$count=$r&&$r['until']>time()?(int)$r['count']+1:1;$until=$r&&$r['until']>time()?(int)$r['until']:time()+$seconds;db()->prepare('INSERT OR REPLACE INTO attempts VALUES (?,?,?)')->execute([$key,$count,$until]);});
}
function jsonBody(): array { $raw=file_get_contents('php://input',false,null,0,12000001);if($raw===false)fail('Zahtev ne može da se pročita.');if(strlen($raw)>12000000)fail('Zahtev je prevelik.',413);$raw=$raw===''?'{}':$raw;try{$b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){fail('Neispravan JSON.');}if(!is_array($b)||!str_starts_with(ltrim($raw),'{'))fail('Očekivan je JSON objekat.');return $b; }
function response(array $v,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo encode($v);exit; }
