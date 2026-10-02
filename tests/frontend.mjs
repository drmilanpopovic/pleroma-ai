#!/usr/bin/env node
// Browser regression tests. Uses only synthetic records; no external API calls.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve, join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const require=createRequire(import.meta.url);
let chromium;
try{({chromium}=require('playwright'));}catch{({chromium}=require(join(process.env.PLEROMA_NODE_MODULES||process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES||'/opt/codex/runtimes/codex-primary-runtime/dependencies/node/node_modules','playwright')));}
const project=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE;
const browser=await chromium.launch({headless:true,...(executablePath?{executablePath,args:['--no-sandbox','--disable-dev-shm-usage','--single-process','--no-zygote','--use-gl=angle','--use-angle=swiftshader']}:{} )});
let checks=0;
function check(value,label){assert.ok(value,label);console.log(`ok ${++checks} - ${label}`);}
const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/New_York'});
const page=await context.newPage();
page.setDefaultTimeout(7000);
const errors=[];page.on('pageerror',error=>errors.push(error.message));
const click=action=>page.locator(`[data-action="${action}"]:visible`).first().click();
const modal=page.locator('#modal');
const confirmNext=accept=>page.once('dialog',dialog=>accept?dialog.accept():dialog.dismiss());
try{
 await page.goto('file://'+join(project,'public/index.html')+'?demo=1');
 await page.waitForSelector('.shell');
 check((await page.locator('.demo-bar').textContent()).includes('Izmišljeni'),'demo shows synthetic-data notice');
 const dates=await page.evaluate(()=>({summer:PleromaTest.toUTC('2026-07-15T18:30'),winter:PleromaTest.toUTC('2026-12-15T18:30'),gap:(()=>{try{PleromaTest.toUTC('2026-03-29T02:30');return false;}catch{return true;}})(),fold:(()=>{try{PleromaTest.toUTC('2026-10-25T02:30');return false;}catch{return true;}})(),invalid:(()=>{try{PleromaTest.toUTC('2026-02-30T18:30');return false;}catch{return true;}})()}));
 check(dates.summer==='2026-07-15T16:30:00.000Z'&&dates.winter==='2026-12-15T17:30:00.000Z','Belgrade appointments ignore browser timezone and handle summer/winter offsets');
 check(dates.gap&&dates.fold&&dates.invalid,'invalid dates and DST gaps/ambiguities reject appointment creation');
 await click('patientForm');await modal.locator('[name=name]').fill('Synthetic Frontend Patient');
 confirmNext(false);await modal.locator('[data-action=closeModal]').first().click();
 check(await modal.isVisible(),'canceling unsaved modal close preserves the form');
 check(await modal.locator('[name=name]').inputValue()==='Synthetic Frontend Patient','unsaved patient input remains intact');
 await modal.getByRole('button',{name:'Sačuvaj',exact:true}).click();
 await page.waitForSelector('.profile-banner');
 check((await page.locator('.profile-banner h1').textContent())==='Synthetic Frontend Patient','creating patient opens its saved chart');
 await click('newVisit');await page.locator('[name=anamnesis]').fill('Synthetic clinical note.');
 confirmNext(false);await page.locator('[data-action=nav][data-route=patients]:visible').first().click();
 check(await page.locator('#visit-form').count()===1&&await page.locator('[name=anamnesis]').inputValue()==='Synthetic clinical note.','navigation cancellation preserves unsaved clinical note');
 await page.getByRole('button',{name:'Sačuvaj nacrt',exact:true}).click();
 check((await page.locator('#save-state').textContent()).includes('Sačuvana verzija 1'),'draft save updates returned version without losing note');
 await page.locator('[name=anamnesis]').fill('Unsaved replacement');confirmNext(true);await click('refresh');
 await page.waitForFunction(()=>document.querySelector('[name=anamnesis]')?.value==='Synthetic clinical note.');
 check(await page.locator('[name=anamnesis]').inputValue()==='Synthetic clinical note.','refresh restores saved visit instead of rendering stale draft');
 await page.locator('[name=reviewed]').check();await page.getByRole('button',{name:'Potvrdi nalaz',exact:true}).click();
 check(await page.locator('[name=anamnesis]').getAttribute('readonly')!==null,'finalized report becomes read-only');
 check(await page.locator('[data-action=dictate]').count()===0&&await page.locator('[data-action=copyMeds]').count()===0,'finalized report does not offer dictation or therapy overwrite');
 await click('patient');await page.locator('[data-action=tab][data-tab=medications]').click();await click('medication');
 await modal.locator('[name=brand]').fill('SYNTHETIC MEDICINE');await modal.locator('[name=status]').selectOption('stopped');
 await modal.getByRole('button',{name:'Sačuvaj',exact:true}).click();
 check((await page.locator('#modal-error').textContent()).includes('datum obustave'),'stopping medicine requires a stop date');
 await modal.locator('[name=end]').fill(await page.evaluate(()=>PleromaTest.dayKey()));await modal.getByRole('button',{name:'Sačuvaj',exact:true}).click();
 check((await page.locator('.medication').textContent()).includes('SYNTHETIC MEDICINE'),'medication save renders updated medication list');
 await page.locator('[data-action=tab][data-tab=measures]').click();await click('measure');await modal.locator('[name=score]').fill('28');
 await modal.getByRole('button',{name:'Sačuvaj',exact:true}).click();
 check((await page.locator('#modal-error').textContent()).includes('van opsega'),'scale rejects score outside selected instrument range');
 await modal.locator('[name=score]').fill('12');await modal.getByRole('button',{name:'Sačuvaj',exact:true}).click();
 await page.locator('[data-action=measure][data-id]').click();
 check(await modal.locator('[name=patientId]').isDisabled(),'existing scale cannot be reassigned to another patient');
 await click('closeModal');
 await page.locator('[data-action=nav][data-route=assistant]:visible').first().click();
 await page.locator('#ai-form [name=text]').fill('Synthetic assistant draft');await page.locator('#ai-form [name=task]').selectOption('rebt');
 await page.locator('[data-action=nav][data-route=home]:visible').first().click();await page.locator('[data-action=nav][data-route=assistant]:visible').first().click();
 check(await page.locator('#ai-form [name=text]').inputValue()==='Synthetic assistant draft'&&await page.locator('#ai-form [name=task]').inputValue()==='rebt','assistant retains text and task while navigating');
 await page.locator('#ai-form [name=attachment]').setInputFiles({name:'synthetic.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\nsynthetic')});
 check(await page.locator('#attachment-confirmation').isVisible()&&await page.locator('[name=attachmentConfirmed]').getAttribute('required')!==null,'attachment requires its own confirmation');
 await page.locator('#ai-form [name=attachment]').setInputFiles([]);
 await page.setViewportSize({width:390,height:844});
 check(await page.locator('.mobile-nav').isVisible(),'Android-sized viewport exposes mobile navigation');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile assistant has no page-level horizontal overflow');
 await page.setViewportSize({width:1440,height:1000});
 // Mock API: deterministic stale-response / session / AI tests, no server data.
 await page.clock.install({time:new Date()});
 let loggedIn=true,delayState=null,expireState=false,aiDelay=null,aiIncomplete=false,apiCalls=[];
 const patientId='a'.repeat(32),visitId='b'.repeat(32);
 const data={patients:[{id:patientId,version:1,code:'SYN-001',name:'SYNTHETIC PRIVATE PATIENT',diagnosis:'SYNTHETIC',archived:false,updated:'2026-10-02T17:00:00Z'}],visits:[{id:visitId,version:1,patientId,date:'2026-10-02',kind:'Kontrola',status:'draft',anamnesis:'SYNTHETIC NOTE',reviewed:false}],medications:[],appointments:[],measures:[],labs:[],integrations:{ai:true,googleConfigured:false,googleConnected:false}};
 await page.route('http://pleroma.test/**',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname.endsWith('/api.php')){
   const action=url.searchParams.get('action');apiCalls.push(action);
   let body={ok:true};
   if(action==='session')body={ok:true,csrf:'synthetic-csrf',loggedIn,needsSetup:false};
   if(action==='login'){loggedIn=true;body={ok:true,csrf:'synthetic-csrf'};}
   if(action==='logout')loggedIn=false;
   if(action==='state'){
    if(delayState)await delayState;
    if(expireState){expireState=false;loggedIn=false;await route.fulfill({status:401,contentType:'application/json',body:JSON.stringify({ok:false,error:'Prijavite se ponovo.'})}).catch(()=>{});return;}
    body={ok:true,data};
   }
   if(action==='ai'){if(aiDelay)await aiDelay;body={ok:true,text:'SYNTHETIC AI RESULT',canApply:!aiIncomplete,incomplete:aiIncomplete};}
   if(action==='history')body={ok:true,items:[]};
   await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(body)}).catch(()=>{});return;
  }
  const path=join(project,'public',url.pathname==='/'?'index.html':url.pathname.slice(1));
  try{const body=await readFile(path);await route.fulfill({status:200,contentType:path.endsWith('.js')?'text/javascript':path.endsWith('.css')?'text/css':path.endsWith('.svg')?'image/svg+xml':'text/html',body});}catch{await route.fulfill({status:404,body:''});}
 });
 confirmNext(true);await page.goto('http://pleroma.test/');await page.waitForSelector('.shell');
 await page.locator('[data-action=patient]:visible').first().click();await click('editVisit');
 await page.locator('[data-action=visitAI][data-task=anamnesis]').click();await page.locator('#ai-consent').check();
 aiIncomplete=true;await click('runVisitAI');await page.waitForSelector('.ai-result');
 check((await modal.textContent()).includes('nepotpun')&&await modal.locator('[data-action=applyAI]').count()===0,'incomplete AI result displays a warning and cannot overwrite a report');
 await click('closeModal');
 let finishAI;aiDelay=new Promise(resolve=>{finishAI=resolve;});
 await page.locator('[data-action=visitAI][data-task=summary]').click();await page.locator('#ai-consent').check();await click('runVisitAI');await click('closeModal');finishAI();aiDelay=null;await page.waitForTimeout(80);
 check(!await modal.isVisible(),'late AI response does not reopen a closed dialog');
 aiIncomplete=false;await page.locator('[data-action=visitAI][data-task=anamnesis]').click();await page.locator('#ai-consent').check();await click('runVisitAI');
 await page.waitForSelector('[data-action=applyAI]');confirmNext(true);await click('applyAI');
 check(await page.locator('[name=anamnesis]').inputValue()==='SYNTHETIC AI RESULT','completed reviewed AI proposal can be transferred explicitly');
 confirmNext(true);await page.locator('[data-action=nav][data-route=home]:visible').first().click();
 let release;delayState=new Promise(resolve=>{release=resolve;});
 await click('refresh');await page.waitForFunction(()=>true);await click('logout');
 await page.waitForSelector('#login-form');release();delayState=null;
 await page.waitForTimeout(100);
 check(await page.locator('.shell').count()===0&&!(await page.locator('#root').textContent()).includes('SYNTHETIC PRIVATE PATIENT'),'late state response cannot reveal charts after logout');
 await page.locator('[name=email]').fill('doctor@example.invalid');await page.locator('[name=password]').fill('synthetic-password');await page.locator('#login-form button[type=submit]').click();await page.waitForSelector('.shell');
 expireState=true;await click('refresh');await page.waitForSelector('#login-form');
 check(!(await page.locator('#root').textContent()).includes('SYNTHETIC PRIVATE PATIENT'),'401 response immediately clears clinical information');
 await page.locator('[name=email]').fill('doctor@example.invalid');await page.locator('[name=password]').fill('synthetic-password');await page.locator('#login-form button[type=submit]').click();await page.waitForSelector('.shell');
 check(apiCalls.filter(a=>a==='session').length>=4,'re-login refreshes CSRF state without a page reload');
 await page.clock.fastForward(180000);await page.mouse.click(900,160);await page.clock.fastForward(65000);
 await page.waitForFunction(()=>document.querySelector('.shell')!==null);
 check(apiCalls.includes('heartbeat'),'active editing keeps server session alive through authenticated heartbeat');
 await page.clock.fastForward(1200001);await page.waitForSelector('#login-form');
 check(!(await page.locator('#root').textContent()).includes('SYNTHETIC PRIVATE PATIENT'),'20 minutes of inactivity locks and clears the workspace');
 await page.locator('[name=email]').fill('doctor@example.invalid');await page.locator('[name=password]').fill('synthetic-password');await page.locator('#login-form button[type=submit]').click();await page.waitForSelector('.shell');
 check(await page.locator('.shell').count()===1,'idle lock permits a fresh login without manual reload');
 check(errors.length===0,'no uncaught browser errors: '+errors.join('; '));
 console.log(`PASS ${checks} frontend checks`);
}finally{await browser.close();}
