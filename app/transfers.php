<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

function makeBackup(string $password): array {
    if(strlen($password)<12||strlen($password)>200)fail('Lozinka kopije mora imati 12–200 znakova.');
    $snapshot=atomic(function(){
        $rows=db()->query('SELECT * FROM records')->fetchAll();
        foreach($rows as &$r){$r['data']=unseal($r['payload']);$r['version']=(int)$r['version'];unset($r['payload']);}unset($r);
        $revisions=db()->query('SELECT * FROM revisions ORDER BY seq')->fetchAll();
        foreach($revisions as &$r){$r['data']=unseal($r['payload']);$r['version']=(int)$r['version'];unset($r['payload']);}unset($r);
        $links=[];
        foreach(db()->query("SELECT key,payload FROM settings WHERE key LIKE 'google_event_%'")->fetchAll() as $r){
            if(!preg_match('/^google_event_([a-f0-9]{32})$/D',$r['key'],$m))continue;
            $map=unseal($r['payload']);
            if(!is_array($map))fail('Google mapa termina nije ispravna.',422);
            $links[]=validateCalendarBackupLink(['appointmentId'=>$m[1]]+$map);
        }
        return ['records'=>$rows,'revisions'=>$revisions,'audit'=>db()->query('SELECT action,resource,time FROM audit ORDER BY seq')->fetchAll(),'calendarLinks'=>$links,'exported'=>now(),'schema'=>1];
    });
    $salt=random_bytes(16);$key=hash_pbkdf2('sha256',$password,$salt,600000,32,true);
    $backup=['format'=>'pleroma-encrypted-v1','salt'=>base64_encode($salt),'iterations'=>600000,'data'=>seal($snapshot,$key)];
    audit('backup');return $backup;
}
function backupTimestamp(mixed $value): bool {
    if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$value))return false;
    try{return instant($value)===$value;}catch(AppError){return false;}
}
function validateCalendarBackupLink(mixed $link): array {
    if(!is_array($link)||!is_string($link['appointmentId']??null)||!preg_match('/^[a-f0-9]{32}$/D',$link['appointmentId'])||!is_string($link['eventId']??null)||!preg_match('/^[a-v0-9]{5,1024}$/D',$link['eventId'])||!is_string($link['calendarId']??null)||$link['calendarId']===''||$link['calendarId']==='primary'||strlen($link['calendarId'])>1024||preg_match('/[\x00-\x20\x7F]/',$link['calendarId'])||!in_array($link['phase']??null,['pending','active','deleted'],true))fail('Neispravna Google mapa termina u kopiji.',422);
    // Whitelist fields explicitly: OAuth tokens, credentials and unrelated settings never travel here.
    return ['appointmentId'=>$link['appointmentId'],'eventId'=>$link['eventId'],'calendarId'=>$link['calendarId'],'phase'=>$link['phase']];
}
/** Validate metadata before trusting it to relink encrypted clinical records. */
function validateBackupRow(mixed $r,bool $revision=false): array {
    if(!is_array($r)||!in_array($r['type']??null,ENTITY_TYPES,true)||!is_string($r['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$r['id'])||!is_int($r['version']??null)||$r['version']<1||$r['version']>=PHP_INT_MAX||!is_array($r['data']??null)||!backupTimestamp($r['updated']??null))fail('Neispravan zapis ili verzija u kopiji.',422);
    $d=$r['data'];
    if(($d['id']??null)!==$r['id']||($d['version']??null)!==$r['version']||($d['entityType']??$r['type'])!==$r['type']||!backupTimestamp($d['updated']??null)||!backupTimestamp($d['created']??null)||(!$revision&&$d['updated']!==$r['updated']))fail('Metapodaci kopije se ne podudaraju sa sadržajem zapisa.',422);
    if($r['type']==='patients'){
        if((!$revision&&($r['patient_id']??null)!==null)||isset($d['patientId'])||!is_string($d['name']??null)||trim($d['name'])===''||!is_string($d['code']??null)||!is_bool($d['archived']??null))fail('Neispravan karton u kopiji.',422);
    }else{
        if(!is_string($d['patientId']??null)||!preg_match('/^[a-f0-9]{32}$/D',$d['patientId'])||(!$revision&&($r['patient_id']??null)!==$d['patientId']))fail('Veza zapisa sa pacijentom nije ispravna.',422);
    }
    $textFields=[
        'patients'=>['birthDate','phone','email','city','allergies','notes','diagnosis'],
        'visits'=>['date','kind','status','anamnesis','mentalStatus','diagnosis','therapy','conclusion','plan','risk','rebt','followup','amends'],
        'medications'=>['brand','inn','strength','schedule','instructions','start','end','status'],
        'appointments'=>['start','end','kind','location','status'],
        'measures'=>['scale','date','note','riskItem'],
        'labs'=>['test','date','result','due']
    ][$r['type']];
    foreach($textFields as $field)if(!is_string($d[$field]??null))fail('Kopija sadrži neispravno polje zapisa.',422);
    if($r['type']==='visits'&&(!in_array($d['status'],['draft','final'],true)||!is_bool($d['reviewed']??null)||($d['status']==='final'&&($d['reviewed']!==true||!backupTimestamp($d['signedAt']??null)))))fail('Neispravan status nalaza u kopiji.',422);
    if($r['type']==='appointments'){
        if(!in_array($d['status'],['scheduled','completed','cancelled','noShow'],true)||!backupTimestamp($d['start'])||!backupTimestamp($d['end'])||$d['end']<=$d['start'])fail('Neispravan termin u kopiji.',422);
        if(isset($d['googleEventId'])&&(!is_string($d['googleEventId'])||!preg_match('/^[a-v0-9]{5,1024}$/D',$d['googleEventId'])))fail('Neispravna Google oznaka termina u kopiji.',422);
    }
    if($r['type']==='medications'&&!in_array($d['status'],['active','stopped'],true))fail('Neispravan status terapije u kopiji.',422);
    if($r['type']==='measures'){
        $limits=['PHQ-9'=>27,'GAD-7'=>21,'HAM-A'=>56,'MADRS'=>60];
        if(!isset($limits[$d['scale']])||!is_int($d['score']??null)||$d['score']<0||$d['score']>$limits[$d['scale']]||!in_array($d['riskItem'],['notAssessed','no','yes'],true))fail('Neispravan skor u kopiji.',422);
    }
    return $r;
}
function restoreBackup(array $b): array {
    $password=rawSecret($b,'password',200);$blob=$b['backup']??null;
    if(!is_array($blob)||($blob['format']??'')!=='pleroma-encrypted-v1'||($blob['iterations']??0)!==600000||!is_string($blob['salt']??null)||!is_string($blob['data']??null))fail('Format kopije nije podržan.');
    $salt=base64_decode($blob['salt'],true);if($salt===false||strlen($salt)!==16)fail('Neispravna kopija.');
    $snapshot=unseal($blob['data'],hash_pbkdf2('sha256',$password,$salt,600000,32,true));
    if(!is_array($snapshot)||($snapshot['schema']??0)!==1||!is_array($snapshot['records']??null)||!array_is_list($snapshot['records'])||!is_array($snapshot['revisions']??[])||!array_is_list($snapshot['revisions']??[])||!is_array($snapshot['audit']??[])||!array_is_list($snapshot['audit']??[])||!is_array($snapshot['calendarLinks']??[])||!array_is_list($snapshot['calendarLinks']??[]))fail('Neispravna šema kopije.',422);
    $current=[];$patientIds=[];
    foreach($snapshot['records'] as $row){
        $r=validateBackupRow($row);$key=$r['type'].':'.$r['id'];
        if(isset($current[$key]))fail('Kopija sadrži dupliranu oznaku zapisa.',422);
        $current[$key]=$r;if($r['type']==='patients')$patientIds[$r['id']]=true;
    }
    foreach($current as $r){
        if($r['type']!=='patients'&&!isset($patientIds[$r['patient_id']]))fail('Kopija sadrži zapis bez pacijenta.',422);
        if($r['type']==='visits'&&($r['data']['amends']??'')!==''){
            $previous=$current['visits:'.$r['data']['amends']]??null;
            if(!$previous||$previous['id']===$r['id']||$previous['patient_id']!==$r['patient_id'])fail('Kopija sadrži neispravnu vezu između nalaza.',422);
        }
    }
    $revisionKeys=[];
    foreach($snapshot['revisions']??[] as $row){
        $r=validateBackupRow($row,true);$currentRow=$current[$r['type'].':'.$r['id']]??null;$key=$r['type'].':'.$r['id'].':'.$r['version'];
        if(!$currentRow||$r['version']>=$currentRow['version']||isset($revisionKeys[$key])||($r['type']!=='patients'&&$r['data']['patientId']!==$currentRow['patient_id']))fail('Kopija sadrži nepovezanu ili dupliranu istorijsku verziju.',422);
        $revisionKeys[$key]=true;
    }
    foreach($snapshot['audit']??[] as $r)if(!is_array($r)||!is_string($r['action']??null)||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$r['action'])||!is_string($r['resource']??null)||strlen($r['resource'])>160||!backupTimestamp($r['time']??null))fail('Neispravan dnevnik aktivnosti u kopiji.',422);
    $calendarLinks=[];
    foreach($snapshot['calendarLinks']??[] as $raw){
        $link=validateCalendarBackupLink($raw);
        if(isset($calendarLinks[$link['appointmentId']])||!isset($current['appointments:'.$link['appointmentId']]))fail('Google mapa nema jedinstven odgovarajući termin.',422);
        $calendarLinks[$link['appointmentId']]=$link;
    }
    return atomic(function()use($snapshot,$calendarLinks){
        if((int)db()->query('SELECT COUNT(*) FROM records')->fetchColumn()>0||(int)db()->query('SELECT COUNT(*) FROM revisions')->fetchColumn()>0)fail('Vraćanje kopije dozvoljeno je samo u praznu bazu. Postojeći podaci neće biti prepisani.',409);
        foreach($snapshot['records'] as $r){
            $d=$r['data'];
            if($r['type']==='appointments'){
                $link=$calendarLinks[$r['id']]??null;
                if($link)$d['googleEventId']=$link['phase']==='deleted'?null:$link['eventId'];
                // Older backups retain their event ID; sync must verify remote ownership before reuse.
                $d['googleSyncedAt']=null;$d['googleDirty']=true;
            }
            db()->prepare('INSERT INTO records(type,id,patient_id,version,payload,updated) VALUES (?,?,?,?,?,?)')->execute([$r['type'],$r['id'],$r['patient_id']??null,$r['version'],seal($d),$r['updated']]);
        }
        foreach($snapshot['revisions']??[] as $r)db()->prepare('INSERT INTO revisions(type,id,version,payload,updated) VALUES (?,?,?,?,?)')->execute([$r['type'],$r['id'],$r['version'],seal($r['data']),$r['updated']]);
        foreach($snapshot['audit']??[] as $r)db()->prepare('INSERT INTO audit(action,resource,time) VALUES (?,?,?)')->execute([$r['action'],$r['resource'],$r['time']]);
        db()->exec("DELETE FROM settings WHERE key GLOB 'google_event_*'");
        foreach($calendarLinks as $id=>$link){unset($link['appointmentId']);setSetting('google_event_'.$id,$link);}
        // A subsequent Google sync resolves the actual calendar ID and rejects a different account/calendar.
        audit('restore_backup');return ['count'=>count($snapshot['records'])];
    });
}
function legacyDate(string $s): string {
    $candidate='';
    if(preg_match('/^\d{4}-\d{2}-\d{2}(?:$|T| )/D',$s))$candidate=substr($s,0,10);
    elseif(preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:\.|$|\s)/D',$s,$m))$candidate=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);
    if($candidate==='')return '';
    try{return dateOnly($candidate,false);}catch(AppError){return '';}
}
function legacyText(mixed $v): string {
    if($v===null)return '';
    if(!is_array($v))return is_scalar($v)?(string)$v:encode($v);
    if(!array_is_list($v))return implode(' · ',array_map(fn($key,$value)=>(string)$key.': '.legacyText($value),array_keys($v),array_values($v)));
    return implode("\n",array_map(fn($value)=>legacyText($value),$v));
}
function legacyClip(string $text,int $bytes): string {
    if(strlen($text)<=$bytes)return $text;
    $short=substr($text,0,$bytes);while($short!==''&&preg_match('//u',$short)!==1)$short=substr($short,0,-1);return $short;
}
function legacyPatientId(array $p): string {
    $oldKey=$p['id']??null;
    if($oldKey===null||$oldKey==='')$oldKey='card:'.legacyText($p['kartonBroj']??'');
    if($oldKey==='card:')$oldKey='identity:'.encode([$p['name'],$p['godiste']??'',$p['telefon']??'',$p['mesto']??'']);
    if(!is_string($oldKey)&&!is_int($oldKey))fail('Neispravna oznaka kartona u starom izvozu.');
    return substr(hash('sha256','legacy:'.(string)$oldKey),0,32);
}
function importLegacy(array $b): array {
    $input=$b['source']??null;$patients=is_array($input)?($input['patients']??(array_is_list($input)?$input:null)):null;
    if(!is_array($patients)||!array_is_list($patients)||count($patients)>5000)fail('Očekivan je JSON izvoz stare baze, do 5000 kartona.');
    $reports=0;$warnings=[];$sourceIds=[];
    foreach($patients as $i=>$p){
        if(!is_array($p)||!is_string($p['name']??null)||trim($p['name'])===''||!is_array($p['reports']??[])||!array_is_list($p['reports']??[]))fail('Izvoz sadrži neispravan karton ili spisak nalaza.');
        $id=legacyPatientId($p);if(isset($sourceIds[$id]))fail('Izvoz sadrži duplirane oznake kartona. Razdvojite ih pre uvoza.');$sourceIds[$id]=true;
        foreach($p['reports']??[] as $j=>$r){
            if(!is_array($r)||array_is_list($r))fail('Izvoz sadrži neispravan nalaz.');
            if(isset($r['id'])&&!is_string($r['id'])&&!is_int($r['id']))fail('Izvoz sadrži neispravnu oznaku nalaza.');
            $raw=legacyText($r['datum_pregleda']??$r['date']??'');
            if(legacyDate($raw)==='')$warnings[]='Karton '.($i+1).', nalaz '.($j+1).': datum nije prepoznat; izvor ostaje sačuvan za proveru.';
        }
        $reports+=count($p['reports']??[]);
        if($reports>50000)fail('Uvoz može sadržati najviše 50000 nalaza.');
    }
    $preview=['patients'=>count($patients),'visits'=>$reports,'warnings'=>array_slice($warnings,0,100),'warningCount'=>count($warnings)];
    if(($b['confirmed']??false)!==true)return $preview+['preview'=>true];
    return atomic(function()use($patients,$preview){
        $added=0;$visits=0;$skipped=0;$changed=0;$measures=0;
        foreach($patients as $p){
            $id=legacyPatientId($p);$existing=record('patients',$id);
            if($existing){
                if(!isset($existing['legacySource']))fail('Oznaka uvoznog kartona već pripada drugom zapisu.',409);
                $skipped++;
                // Updating the source creates a revision, preserving the previous complete export.
                if(encode($existing['legacySource'])!==encode($p)){$existing['legacySource']=$p;put('patients',$existing);}
            }else{
                $notes='Uvezen karton: '.legacyText($p['kartonBroj']??'').' · Godište: '.legacyText($p['godiste']??'nije navedeno');
                if(!empty($p['quickNotes']))$notes.="\nBeleške iz prethodne aplikacije:\n".legacyText($p['quickNotes']);
                put('patients',['id'=>$id,'version'=>0,'name'=>legacyClip(trim($p['name']),160),'code'=>'ST-'.strtoupper(substr($id,0,8)),'birthDate'=>'','phone'=>legacyClip(legacyText($p['telefon']??''),50),'email'=>'','city'=>legacyClip(legacyText($p['mesto']??''),200),'allergies'=>legacyClip(legacyText($p['alergije']??''),3000),'diagnosis'=>'','notes'=>legacyClip($notes,12000),'archived'=>($p['archived']??false)===true,'legacySource'=>$p]);
                $added++;
            }
            $occurrences=[];
            foreach($p['reports']??[] as $r){
                $sourceHash=hash('sha256',encode($r));
                $occurrences[$sourceHash]=($occurrences[$sourceHash]??0)+1;
                $sourceKey=(isset($r['id'])&&$r['id']!=='')?(string)$r['id']:('content:'.$sourceHash.':'.$occurrences[$sourceHash]);
                $rid=substr(hash('sha256','legacy-report:'.$id.'|'.$sourceKey),0,32);$previous=record('visits',$rid);$replaces='';
                if($previous){
                    if(($previous['legacySource']??null)===$r)continue;
                    // A changed source never overwrites an existing clinical note, including a signed note.
                    $replaces=$rid;$rid=substr(hash('sha256','legacy-report-change:'.$rid.'|'.$sourceHash),0,32);
                    if(record('visits',$rid))continue;
                    $changed++;
                }
                $therapy=legacyText($r['terapija']??'');if(!empty($r['napomena']))$therapy.="\nNapomena iz izvornog nalaza: ".legacyText($r['napomena']);
                $anamnesis=legacyText($r['anamneza']??'');if(!empty($r['alergije']))$anamnesis.="\nAlergije navedene u izvornom nalazu: ".legacyText($r['alergije']);
                $plan=implode("\n",array_filter(array_map(fn($key)=>legacyText($r[$key]??''),['plan_kratkorocni','plan_dugorocni','plan_intervencije'])));
                if($replaces!=='')$plan.="\nIzvorni nalaz je promenjen od prethodnog uvoza. Ova verzija je zaseban nacrt za poređenje; prethodni nalaz ostaje sačuvan.";
                $date=legacyDate(legacyText($r['datum_pregleda']??$r['date']??''));
                put('visits',['id'=>$rid,'version'=>0,'patientId'=>$id,'date'=>$date,'kind'=>'Kontrola','status'=>'draft','anamnesis'=>legacyClip($anamnesis,30000),'mentalStatus'=>legacyClip(legacyText($r['psihicki']??''),30000),'diagnosis'=>legacyClip(legacyText($r['dijagnoze']??''),30000),'therapy'=>legacyClip($therapy,30000),'conclusion'=>legacyClip(legacyText($r['zakljucak']??''),30000),'plan'=>legacyClip($plan,30000),'risk'=>'','rebt'=>'','followup'=>legacyDate(legacyText($r['plan_datum_kontrole']??'')),'reviewed'=>false,'signedAt'=>null,'amends'=>'','legacySource'=>$r,'legacyReplaces'=>$replaces]);
                $visits++;
                foreach(['phq9_score'=>['PHQ-9',27],'gad7_score'=>['GAD-7',21]] as $field=>[$scale,$max]){
                    $score=$r[$field]??null;if($date===''||!is_int($score)||$score<0||$score>$max)continue;
                    $mid=substr(hash('sha256','legacy-measure:'.$rid.'|'.$scale),0,32);
                    put('measures',['id'=>$mid,'version'=>0,'patientId'=>$id,'scale'=>$scale,'date'=>$date,'score'=>$score,'riskItem'=>'notAssessed','note'=>'Uvezen ukupan skor iz prethodne aplikacije; pojedinačne stavke nisu procenjene.','legacySource'=>['reportId'=>$sourceKey,'field'=>$field,'score'=>$score]]);$measures++;
                }
            }
        }
        audit('import_legacy');return ['patients'=>$added,'visits'=>$visits,'skipped'=>$skipped,'changed'=>$changed,'measures'=>$measures,'warnings'=>$preview['warnings'],'warningCount'=>$preview['warningCount']];
    });
}
