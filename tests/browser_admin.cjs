// Use only the isolated database/fixture produced by admin_integration.php --browser-fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.env.ADMIN_FIXTURE, 'utf8'));
const base = process.env.BASE_URL;
if (!/^interclasse_admin_test_/.test(fixture.database) || !['localhost','127.0.0.1'].includes(new URL(base).hostname)) throw Error('Isolated local fixture required.');
(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_EXECUTABLE});
    try {
        const context=await browser.newContext();await context.addCookies([{name:'PHPSESSID',value:fixture.session_id,domain:new URL(base).hostname,path:'/'}]);
        const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.goto(base+`index.php?page=match_manager&event_id=${fixture.event_id}&modality=handbol`);
        await page.locator('[data-edit]').first().waitFor();await page.evaluate(()=>window.adminTestMarker='preserved');
        let navigations=0,executions=0;page.on('request',r=>{if(r.isNavigationRequest()&&r.frame()===page.mainFrame())navigations++;if(r.url().includes('admin_operations.php')&&r.postData()?.includes('"action":"execute"'))executions++;});
        await page.locator('[data-admin-scope=gender]').selectOption('feminino');assert.equal(await page.locator('[data-edit]').count(),0);await page.locator('[data-admin-scope=gender]').selectOption('masculino');
        await page.locator('[data-admin-tab=players]').click();await page.locator(`[data-edit="${fixture.player_id}"]`).click();
        const dialog=page.locator('#admin-operation-dialog');await dialog.waitFor({state:'visible'});await dialog.locator('[name=name]').fill('Atleta corrigido na interface');
        await dialog.locator('[data-dialog-submit]').click();await dialog.locator('[data-admin-reason]').waitFor();await dialog.locator('[data-dialog-cancel]').click();assert.equal(await dialog.locator('[name=name]').inputValue(),'Atleta corrigido na interface');
        await page.route('**/api/admin_operations.php',r=>r.request().method()==='POST'?r.abort():r.continue());await dialog.locator('[data-dialog-submit]').click();await dialog.locator('[role=alert]').waitFor();assert.equal(await dialog.locator('[name=name]').inputValue(),'Atleta corrigido na interface');await page.unroute('**/api/admin_operations.php');
        await dialog.locator('[data-dialog-submit]').click();await dialog.locator('[data-admin-reason]').waitFor();await dialog.locator('[data-admin-reason]').fill('Correção validada pelo teste da interface');await dialog.locator('[data-dialog-submit]').evaluate(b=>{b.click();b.click();});await dialog.waitFor({state:'hidden'});await page.locator('[data-admin-notice] .alert-success').waitFor();assert.equal(executions,1);
        assert.equal(await page.evaluate(()=>window.adminTestMarker),'preserved');assert.equal(navigations,0);
        const state=await (await context.request.get(base+'api/admin_operations.php')).json();assert.equal(state.data.players[fixture.player_id].name,'Atleta corrigido na interface');
        const csrf=await context.request.post(base+'api/admin_operations.php',{data:{action:'preview',csrf_token:'invalid',command:{operation:'archive',entity:'players',id:fixture.player_id}}});assert.equal(csrf.status(),419);
        const legacy=await context.request.post(base+'api/players.php',{data:{csrf_token:state.csrf_token,name:'Bypass',team_id:state.data.players[fixture.player_id].team_id}});assert.equal(legacy.status(),428);
        await page.locator('[data-admin-tab=history]').click();await page.locator('[data-restore-operation]').first().waitFor();
        await page.locator('[data-admin-tab=matches]').click();await page.locator('[data-edit]').first().click();await dialog.waitFor({state:'visible'});await context.clearCookies();await dialog.locator('[data-dialog-submit]').click();await page.locator('[data-admin-notice] a').waitFor();assert.equal(navigations,0);
        assert.deepEqual(errors,[]);console.log('PASS: scopes, preview cancellation, retained fields, network failure, double click, immediate update, CSRF, legacy guard, history and expired session; no document reload.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
