import { closeSync, openSync, rmSync } from 'node:fs'
import { resolve } from 'node:path'
import { spawn, spawnSync } from 'node:child_process'
const database=resolve('storage/framework/testing/e2e.sqlite')
rmSync(database,{force:true});closeSync(openSync(database,'w'))
const env={...process.env,APP_ENV:'e2e',APP_DEBUG:'false',APP_LOCALE:'fa',APP_KEY:'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',DB_CONNECTION:'sqlite',DB_DATABASE:database,CACHE_STORE:'array',SESSION_DRIVER:'file',QUEUE_CONNECTION:'sync',E2E_PASSWORD:'Phase17-Test-Only!'}
const prepared=spawnSync('php',['artisan','migrate:fresh','--seed','--seeder=Database\\Seeders\\E2eDemoSeeder','--force'],{cwd:resolve('.'),env,stdio:'inherit'})
if(prepared.status!==0)process.exit(prepared.status??1)
const server=spawn('php',['-S','127.0.0.1:8017','-t','public','tests/e2e/router.php'],{cwd:resolve('.'),env,stdio:'ignore'})
try{
  for(let attempt=0;attempt<60;attempt++){try{const response=await fetch('http://127.0.0.1:8017/up');if(response.ok)break}catch{}if(attempt===59)throw new Error('E2E server did not start');await new Promise(resolve=>setTimeout(resolve,250))}
  const test=spawnSync(process.execPath,['node_modules/@playwright/test/cli.js','test',...process.argv.slice(2)],{cwd:resolve('.'),env,stdio:'inherit'})
  process.exitCode=test.status??1
}finally{server.kill()}
