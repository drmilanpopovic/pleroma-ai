#!/usr/bin/env node
// Build a self-contained, synthetic-data review artifact. Never include a database.
import {readFile, writeFile, mkdir} from 'node:fs/promises';
import {resolve, dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'..');
const destination=resolve(process.argv[2]||join(root,'dist','Pleroma-pregled.html'));
const read=path=>readFile(join(root,'public',path),'utf8');
let [html,css,catalog,app,icon]=await Promise.all([
  read('index.html'),read('assets/app.css'),read('assets/catalog.js'),read('assets/app.js'),readFile(join(root,'public/assets/icon.svg'))
]);
html=html.replace(/<link[^>]+rel="(?:manifest|apple-touch-icon|stylesheet)"[^>]*>/g,'')
  .replace(/<link[^>]+rel="icon"[^>]*>/g,`<link rel="icon" href="data:image/svg+xml;base64,${icon.toString('base64')}">`)
  .replace(/<script[^>]+src="[^"]+"[^>]*><\/script>/g,'')
  .replace('<body>','<body data-demo="true">')
  .replace('</head>',()=>`<style>${css.replace(/<\/style/gi,'<\\/style')}</style></head>`);
app=app.replace('<a href="./">Serverska verzija</a>','<a href="https://github.com/drmilanpopovic/pleroma-ai/tree/codex/pleroma-ordinacija-v1" target="_blank" rel="noopener noreferrer">Kod i uputstvo ↗</a>');
html=html.replace('</body>',()=>`<script>${(catalog+'\n'+app).replace(/<\/script/gi,'<\\/script')}</script></body>`);
await mkdir(dirname(destination),{recursive:true});
await writeFile(destination,html);
console.log(destination);
