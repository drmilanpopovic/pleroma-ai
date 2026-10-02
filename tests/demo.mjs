#!/usr/bin/env node
import assert from 'node:assert/strict';
import {mkdtemp, mkdir, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join, resolve, dirname} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
let chromium;
for(const source of ['playwright',...[process.env.PLEROMA_NODE_MODULES,process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES].filter(Boolean).map(p=>join(p,'playwright'))]){
 try{({chromium}=require(source));break;}catch(e){if(e.code!=='MODULE_NOT_FOUND')throw e;}
}
if(!chromium)throw Error('Install Playwright or set PLEROMA_NODE_MODULES.');
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const output=process.env.PLEROMA_DEMO_OUTPUT?resolve(process.env.PLEROMA_DEMO_OUTPUT):await mkdtemp(join(tmpdir(),'pleroma-demo-'));
await mkdir(output,{recursive:true});
const html=join(output,'Pleroma-pregled.html');
execFileSync(process.execPath,[join(root,'tools/build-demo.mjs'),html]);
const executablePath=process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE;
let browser,checks=0;
function check(value,label){assert.ok(value,label);console.log(`ok ${++checks} - ${label}`);}
try{
 browser=await chromium.launch({headless:true,...(executablePath?{executablePath,args:['--no-sandbox','--disable-dev-shm-usage','--single-process','--no-zygote','--use-gl=angle','--use-angle=swiftshader']}:{} )});
 const page=await browser.newPage({viewport:{width:1440,height:1000},timezoneId:'Europe/Belgrade'});
 page.setDefaultTimeout(7000);
 const errors=[],remote=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('request',r=>{if(/^https?:/.test(r.url()))remote.push(r.url());});
 await page.goto(pathToFileURL(html).href);
 await page.waitForSelector('.shell');
 check(await page.locator('.demo-bar').isVisible(),'standalone HTML starts in clearly labeled demo mode');
 if(process.env.PLEROMA_DEMO_OUTPUT)await page.screenshot({path:join(output,'Pleroma-desktop.png')});
 for(const width of [320,412,768,1440]){
  await page.setViewportSize({width,height:width<768?915:1000});
  for(const route of ['home','patients','calendar','assistant','settings']){
   await page.locator(`[data-action="nav"][data-route="${route}"]:visible`).first().click();
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${route} fits ${width}px without page overflow`);
   if(process.env.PLEROMA_DEMO_OUTPUT&&width===412&&route==='home')await page.screenshot({path:join(output,'Pleroma-Android.png')});
  }
 }
 check(errors.length===0,'generated HTML has no JavaScript errors: '+errors.join('; '));
 check(remote.length===0,'generated HTML makes no remote requests');
 console.log(`PASS ${checks} standalone demo checks`);
}finally{
 await browser?.close();
 if(!process.env.PLEROMA_DEMO_OUTPUT)await rm(output,{recursive:true,force:true});
}
