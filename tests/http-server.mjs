#!/usr/bin/env node
/** Isolated development/test server. Binds only to loopback; never use for production. */
import http from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readFile, stat, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, extname, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { loadTestPHP } from './php-wasm.mjs';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = join(projectRoot, 'public');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const literal = text => `'${text.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;

export async function startTestServer({ wasm = false, port = 0 } = {}) {
  const temp = await mkdtemp(join(tmpdir(), 'pleroma-http-test-'));
  await mkdir(join(temp, 'data'), { mode: 0o700 });
  const setupToken = randomBytes(32).toString('hex');
  const configPath = join(temp, 'config.php');
  let php, server, child, base, logs = '';
  async function writeConfig() {
    const config = `<?php\n$c=require ${literal(join(projectRoot,'config.example.php'))};\n$c['data_dir']=${literal(join(temp,'data'))};\n$c['base_url']=${literal(base)};\n$c['encryption_key']=${literal(randomBytes(32).toString('hex'))};\n$c['setup_token']=${literal(setupToken)};\n$c['allow_local_http']=true;\n$c['openai_api_key']='';\n$c['openai_model']='';\n$c['google_client_id']='';\n$c['google_client_secret']='';\nreturn $c;\n`;
    await writeFile(configPath, config, { mode: 0o600 });
  }
  try {
    if (wasm) {
      php = await loadTestPHP();
      // Serialize PHP requests because one embedded runtime handles one request at a time.
      let queue = Promise.resolve();
      server = http.createServer((req,res) => {
        queue = queue.then(async () => {
          try {
            const url = new URL(req.url,base);
            const path = resolve(publicRoot, '.' + decodeURIComponent(url.pathname === '/' ? '/index.html' : url.pathname));
            if (!path.startsWith(publicRoot+sep)) { res.writeHead(404); res.end(); return; }
            if (!(await stat(path).catch(()=>null))?.isFile()) { res.writeHead(404); res.end(); return; }
            if (extname(path) === '.php') {
              // Only public entrypoints are callable in this bridge.
              if (!['api.php','google-callback.php'].includes(path.slice(publicRoot.length+1))) { res.writeHead(404); res.end(); return; }
              const chunks=[]; for await (const chunk of req) chunks.push(chunk);
              const headers = Object.fromEntries(Object.entries(req.headers).map(([k,v])=>[k,Array.isArray(v)?v.join(', '):v||'']));
              const result = await php.run({
                scriptPath:path, relativeUri:req.url, method:req.method, headers, body:Buffer.concat(chunks), protocol:'http',
                env:{PLEROMA_CONFIG:configPath},
                $_SERVER:{REMOTE_ADDR:'127.0.0.1',DOCUMENT_ROOT:publicRoot,HTTP_HOST:new URL(base).host,SERVER_NAME:'127.0.0.1',SERVER_PORT:String(server.address().port),HTTPS:'off'},
              });
              for (const [name,values] of Object.entries(result.headers)) {
                if (!['status','content-length','transfer-encoding','connection'].includes(name.toLowerCase())) res.setHeader(name,values);
              }
              if (result.errors) logs += result.errors;
              res.writeHead(result.httpStatusCode);
              res.end(Buffer.from(result.bytes));
            } else {
              const mime={'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.json':'application/json','.webmanifest':'application/manifest+json','.svg':'image/svg+xml','.png':'image/png'}[extname(path)]||'application/octet-stream';
              res.writeHead(200,{'Content-Type':mime,'Cache-Control':'no-store'});
              res.end(await readFile(path));
            }
          } catch(error) { logs += String(error)+'\n'; res.writeHead(500);res.end('Test server error'); }
        });
      });
      await new Promise((resolve,reject)=>{ server.once('error',reject);server.listen(port,'127.0.0.1',resolve); });
      base='http://127.0.0.1:'+server.address().port;
      await writeConfig();
    } else {
      const reserve = http.createServer();
      await new Promise((resolve,reject)=>{reserve.once('error',reject);reserve.listen(port,'127.0.0.1',resolve);});
      port=reserve.address().port;
      await new Promise(resolve=>reserve.close(resolve));
      base='http://127.0.0.1:'+port;
      await writeConfig();
      child=spawn(process.env.PHP_BIN||'php',['-S','127.0.0.1:'+port,'-t',publicRoot],{
        cwd:projectRoot, env:{...process.env,PLEROMA_CONFIG:configPath}, stdio:['ignore','ignore','pipe'],
      });
      child.stderr.on('data',chunk=>{logs+=chunk.toString();});
      let failure;
      child.on('error',error=>{failure=error;});
      let ready=false;
      for(let attempt=0;attempt<80;attempt++) {
        if (failure) throw failure;
        if (child.exitCode!==null) throw new Error('PHP server exited: '+logs);
        try { const response=await fetch(base+'/manifest.webmanifest'); if(response.ok){ready=true;break;} } catch {}
        await sleep(50);
      }
      if(!ready) throw new Error('PHP test server did not become ready: '+logs);
    }
    return { base,temp,setupToken,configPath,logs:()=>logs, async close() {
      if(server) {server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
      if(child&&child.exitCode===null) {child.kill('SIGTERM');await new Promise(resolve=>{child.once('exit',resolve);setTimeout(resolve,2000).unref();});}
      if(php) php.exit();
      await rm(temp,{recursive:true,force:true});
    }};
  } catch(error) {
    if(server) server.close();
    if(child) child.kill('SIGTERM');
    if(php) php.exit();
    await rm(temp,{recursive:true,force:true});
    throw error;
  }
}

if (process.argv[1] && resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
  const fixture=await startTestServer({wasm:process.argv.includes('--wasm'),port:Number(process.env.PORT)||8787});
  console.log('Isolated test server: '+fixture.base);
  console.log('Temporary setup token: '+fixture.setupToken);
  console.log('Synthetic data only. All test data is deleted on shutdown.');
  for(const signal of ['SIGINT','SIGTERM']) process.on(signal,async()=>{await fixture.close();process.exit(0);});
}
