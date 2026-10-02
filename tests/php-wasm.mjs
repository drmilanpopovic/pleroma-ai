#!/usr/bin/env node
/** Optional local test runner when native PHP is unavailable. Never use as production hosting. */
import { createRequire } from 'node:module';
import { resolve, join } from 'node:path';
import { pathToFileURL } from 'node:url';

export async function loadTestPHP() {
  const base = process.env.PLEROMA_NODE_MODULES;
  const require = createRequire(base ? join(resolve(base), '__pleroma-resolve.cjs') : import.meta.url);
  let universalPath, nodePath;
  try {
    universalPath = require.resolve('@php-wasm/universal');
    nodePath = require.resolve('@php-wasm/node');
  } catch {
    throw new Error('Install optional test runtime: npm install --no-save @php-wasm/node@3.1.56 @php-wasm/universal@3.1.56; or set PLEROMA_NODE_MODULES to its node_modules directory.');
  }
  const { PHP } = await import(pathToFileURL(universalPath).href);
  const { loadNodeRuntime, useHostFilesystem } = await import(pathToFileURL(nodePath).href);
  const php = new PHP(await loadNodeRuntime(process.env.PLEROMA_PHP_VERSION || '8.3', {
    emscriptenOptions: { processId: process.pid },
  }));
  useHostFilesystem(php);
  return php;
}

if (process.argv[1] && resolve(process.argv[1]) === new URL(import.meta.url).pathname) {
  const args = process.argv.slice(2);
  if (!args.length) throw new Error('Usage: node tests/php-wasm.mjs tests/run.php (or PHP CLI arguments such as -l file.php)');
  const php = await loadTestPHP();
  try {
    const result = await php.cli(['php', ...args], { cwd: process.cwd(), env: process.env });
    const [out, err, exitCode] = await Promise.all([result.stdoutText, result.stderrText, result.exitCode]);
    if (out) process.stdout.write(out);
    if (err) process.stderr.write(err);
    process.exitCode = exitCode;
  } finally { php.exit(); }
}
