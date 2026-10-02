#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { startTestServer } from './http-server.mjs';

const wasm=process.argv.includes('--wasm');
const fixtures=[];
let checks=0;
function check(condition,label) {assert.ok(condition,label);console.log(`ok ${++checks} - ${label}`);}
class Client {
  constructor(base){this.base=base;this.cookie='';this.csrf='';}
  async request(action,body,{method=body===undefined?'GET':'POST',csrf=this.csrf,raw,extraHeaders={}}={}) {
    const headers={...extraHeaders};
    if(this.cookie) headers.Cookie=this.cookie;
    if(csrf) headers['X-CSRF-Token']=csrf;
    if(method==='POST') headers['Content-Type']='application/json';
    const response=await fetch(this.base+'/api.php?action='+action,{method,headers,redirect:'manual',body:method==='POST'?(raw??JSON.stringify(body??{})):undefined});
    const cookies=response.headers.getSetCookie();
    const cookie=cookies.find(value=>value.startsWith('pleroma_clinic='));
    if(cookie) this.cookie=cookie.split(';',1)[0];
    const text=await response.text();
    let data;try{data=JSON.parse(text);}catch{throw new Error(`Non-JSON response for ${action} (${response.status}): ${text.slice(0,500)}`);}
    if(data.csrf) this.csrf=data.csrf;
    return {status:response.status,headers:response.headers,data,text,cookies};
  }
  async session(){return this.request('session');}
  async login(password='synthetic-test-password-27') {return this.request('login',{email:'doctor@example.invalid',password});}
}
async function setup(fixture) {
  const client=new Client(fixture.base);await client.session();
  const result=await client.request('setup',{setupToken:fixture.setupToken,email:'doctor@example.invalid',password:'synthetic-test-password-27'});
  assert.equal(result.status,200,'fixture account setup');assert.equal((await client.login()).status,200,'fixture login');
  return client;
}
const save=(client,type,record)=>client.request('save',{type,record});
try {
  const first=await startTestServer({wasm});fixtures.push(first);
  const a=new Client(first.base);
  let r=await a.session();
  check(r.status===200&&r.data.needsSetup===true&&r.data.loggedIn===false&&a.csrf.length===32,'fresh installation exposes setup status and CSRF token');
  check(r.cookies.some(c=>/HttpOnly/i.test(c)&&/SameSite=Lax/i.test(c)),'session cookie is HttpOnly and SameSite=Lax');
  check(r.headers.get('cache-control')?.includes('no-store')&&r.headers.get('x-frame-options')==='DENY'&&r.headers.get('content-security-policy')?.includes("frame-ancestors 'none'"),'API sends no-store and framing protections');
  check((await a.request('state')).status===401,'anonymous user cannot read the patient database');
  check((await a.request('save')).status===405,'mutations reject GET');
  check((await a.request('setup',{}, {csrf:'invalid'})).status===403,'mutation rejects wrong CSRF token');
  check((await a.request('setup',{setupToken:'bad-token',email:'doctor@example.invalid',password:'synthetic-test-password-27'})).status===403,'setup rejects the wrong installation token');
  r=await a.request('setup',{setupToken:first.setupToken,email:'doctor@example.invalid',password:'synthetic-test-password-27'});
  check(r.status===200&&(await a.session()).data.needsSetup===false,'owner account setup succeeds once');
  check((await a.request('setup',{setupToken:first.setupToken,email:'doctor@example.invalid',password:'synthetic-test-password-27'})).status===409,'account setup cannot overwrite an existing account');
  check((await a.login('wrong-password')).status===401,'wrong password rejects login');
  const beforeCookie=a.cookie,beforeCsrf=a.csrf;
  r=await a.login();
  check(r.status===200&&a.cookie!==beforeCookie&&a.csrf!==beforeCsrf,'login rotates session identifier and CSRF token');
  check((await a.request('heartbeat',{}, {csrf:beforeCsrf})).status===403,'pre-login CSRF token cannot be reused');
  check((await a.request('heartbeat',{})).status===200,'authenticated heartbeat succeeds');
  r=await a.request('state');
  check(r.status===200&&['patients','visits','medications','appointments','measures','labs'].every(key=>Array.isArray(r.data.data[key])),'authenticated state exposes all six entity collections');
  check(r.data.data.integrations.ai===false&&r.data.data.integrations.googleConnected===false,'unconfigured integrations are reported accurately');
  check((await a.request('save',{}, {raw:'{"type":'})).status===400,'malformed JSON is a client error');
  check((await save(a,'patients','invalid-record')).status===400,'non-object entity input is a client error');
  r=await save(a,'patients',{name:'HTTP SYNTHETIC PATIENT',birthDate:'1985-01-02',notes:'SYNTHETIC-HTTP-SECRET-9361'});
  check(r.status===200&&r.data.record.version===1,'patient creation through HTTP succeeds');
  let p=r.data.record;
  const b=new Client(first.base);await b.session();assert.equal((await b.login()).status,200);
  const stale=(await b.request('state')).data.data.patients[0];
  r=await save(a,'patients',{...p,city:'Synthetic city'});p=r.data.record;
  check(r.status===200&&p.version===2,'patient update increments version through API');
  check((await save(b,'patients',{...stale,city:'Stale edit'})).status===409,'concurrent device cannot silently overwrite newer patient data');
  check((await a.request('archive',{id:p.id,version:String(p.version)})).status===400,'archive rejects a non-integer version');
  r=await a.request('history&type=patients&id='+p.id);
  check(r.status===200&&r.data.items.length===1&&r.data.items[0].data.version===1&&!('payload' in r.data.items[0]),'version history returns decrypted authorized record without storage ciphertext');
  r=await save(a,'appointments',{patientId:p.id,start:'2099-10-05T18:30:00+02:00',end:'2099-10-05T19:30:00+02:00'});
  check(r.status===200&&r.data.record.start==='2099-10-05T16:30:00Z','HTTP booking stores a timezone-normalized appointment');
  const appointment=r.data.record;
  check((await save(b,'appointments',{patientId:p.id,start:'2099-10-05T17:00:00Z',end:'2099-10-05T18:00:00Z'})).status===409,'HTTP booking rejects overlapping appointments');
  check((await a.request('archive',{id:p.id,version:p.version})).status===409,'future appointment prevents patient archiving');
  assert.equal((await save(a,'appointments',{...appointment,status:'cancelled'})).status,200);
  r=await a.request('archive',{id:p.id,version:p.version});p=r.data.record;
  check(r.status===200&&p.archived===true,'patient archives after pending appointment is cancelled');
  check((await save(a,'medications',{patientId:p.id,brand:'TEST MEDICATION'})).status===404,'archived patient cannot receive a new medication record');
  r=await a.request('archive',{id:p.id,version:p.version});p=r.data.record;
  check(r.status===200&&p.archived===false,'archived patient can be restored');
  r=await save(a,'visits',{patientId:p.id,date:'2026-10-02',anamnesis:'Synthetic visit draft',status:'draft'});let visit=r.data.record;
  check(r.status===200&&!visit.reviewed,'visit starts as an unsigned draft');
  check((await save(a,'visits',{...visit,status:'final'})).status===400,'finalization requires physician review');
  r=await save(a,'visits',{...visit,status:'final',reviewed:true});visit=r.data.record;
  check(r.status===200&&visit.reviewed===true,'reviewed clinical visit is finalized');
  check((await save(a,'visits',{...visit,anamnesis:'Unapproved overwrite'})).status===409,'finalized visit is immutable through HTTP');
  check((await save(a,'medications',{patientId:p.id,brand:'TEST MEDICATION',strength:'10 mg',schedule:'synthetic schedule'})).status===200,'medication list can be saved');
  check((await save(a,'measures',{patientId:p.id,date:'2026-10-02',scale:'GAD-7',score:10})).status===200,'scale score can be saved');
  check((await save(a,'labs',{patientId:p.id,date:'2026-10-02',test:'SYNTHETIC LAB',result:'test result'})).status===200,'laboratory record can be saved');
  check((await a.request('ai',{task:'anamnesis',text:'Synthetic test',confirmed:true})).status===503,'AI without credentials fails closed');
  check((await a.request('googleStart',{})).status===503,'Google OAuth without configuration fails closed');
  check((await a.request('unknownAction',{})).status===404,'unknown action is rejected');
  const backupPass='synthetic-backup-password-48';
  r=await a.request('backup',{password:backupPass});
  check(r.status===200&&r.data.backup.format==='pleroma-encrypted-v1'&&!r.text.includes('HTTP SYNTHETIC PATIENT'),'HTTP backup exports encrypted content');
  const backup=r.data.backup;
  check((await a.request('restore',{backup,password:backupPass})).status===409,'HTTP restore refuses to overwrite populated database');
  const dataFiles=(await readdir(join(first.temp,'data'))).filter(name=>name.startsWith('clinic.sqlite'));
  const bytes=Buffer.concat(await Promise.all(dataFiles.map(name=>readFile(join(first.temp,'data',name)))));
  check(!bytes.includes(Buffer.from('HTTP SYNTHETIC PATIENT'))&&!bytes.includes(Buffer.from('SYNTHETIC-HTTP-SECRET-9361')),'raw SQLite and WAL files do not contain patient plaintext');
  const second=await startTestServer({wasm});fixtures.push(second);
  const c=await setup(second);
  check((await c.request('restore',{backup,password:'wrong-backup-password'})).status===422,'wrong passphrase cannot restore to a fresh instance');
  check((await c.request('state')).data.data.patients.length===0,'failed restore leaves fresh instance empty');
  r=await c.request('restore',{backup,password:backupPass});
  check(r.status===200&&r.data.count>=6,'encrypted backup restores into a separate installation');
  r=await c.request('state');
  check(r.data.data.patients[0].notes==='SYNTHETIC-HTTP-SECRET-9361'&&r.data.data.visits[0].status==='final','restored installation retains clinical content and locked report');
  check(r.data.data.appointments.every(item=>item.googleEventId===null&&item.googleDirty===true),'restored appointments are detached from old Google events');
  check((await a.request('password',{current:'wrong-current-password',password:'new-synthetic-test-password-29'})).status===403,'password change verifies current password');
  check((await a.request('password',{current:'synthetic-test-password-27',password:'new-synthetic-test-password-29'})).status===200,'password change succeeds with current password');
  check((await b.request('state')).status===401,'password change invalidates other device session');
  r=await a.request('audit');
  check(r.status===200&&r.data.items.some(item=>item.action==='password_changed')&&!r.text.includes('HTTP SYNTHETIC PATIENT'),'audit reports security actions without patient names');
  check((await a.request('logout',{})).status===200&&(await a.request('state')).status===401,'logout closes authenticated access');
  console.log(`PASS: ${checks} HTTP assertions (${wasm?'PHP-WASM 8.3, real loopback HTTP':'native PHP built-in HTTP server'}).`);
} catch(error) {
  console.error('FAIL:',error.stack);
  for(const fixture of fixtures) if(fixture.logs().includes('error')) console.error(fixture.logs().slice(-3000));
  process.exitCode=1;
} finally {
  for(const fixture of fixtures.reverse()) await fixture.close();
}
