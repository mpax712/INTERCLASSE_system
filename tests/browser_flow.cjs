const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const eventId = Number(process.env.EVENT_ID);
if (!process.env.BROWSER_STAGE_PASSWORD) throw new Error('Set BROWSER_STAGE_PASSWORD');
const base = 'http://127.0.0.1:8765/';
(async () => {
 const browser = await chromium.launch();
 try {
  const page = await browser.newPage({viewport:{width:390,height:800}});
  const errors=[];page.on('pageerror',e=>errors.push(String(e)));page.on('dialog',d=>d.accept());
  await page.goto(base+'index.php?page=login');
  await page.locator('input[name=email]').fill('browser-stage@local.invalid');
  await page.locator('input[name=password]').fill(process.env.BROWSER_STAGE_PASSWORD);
  await page.getByRole('button',{name:'Entrar'}).click();
  await page.waitForURL(/page=dashboard/);
  await page.goto(base+`index.php?page=event&id=${eventId}&modality=clash_royale`,{waitUntil:'networkidle'});
  const href=await page.locator('.match-board-card a[href*="page=scoreboard"]').first().getAttribute('href');
  await page.goto(base+href,{waitUntil:'networkidle'});
  await page.locator('[data-action=start]').click();
  await page.getByText('Alteração salva com sucesso.').waitFor();
  await page.locator('#game-home-crowns').fill('2');
  await page.locator('#game-away-crowns').fill('1');
  await page.locator('[data-action=record_battle]').click();
  await page.locator('#game-battle-list li').first().waitFor();
  await page.locator('[data-action=record_battle]').click();
  await page.locator('#game-battle-list li').nth(1).waitFor();
  await page.locator('[data-action=finish]').click();
  await page.getByText('Encerrada',{exact:true}).waitFor();
  const adminResult={status:await page.locator('#game-status').innerText(),score:await page.locator('#game-home-score').innerText(),errors};
  const publicPage=await browser.newPage({viewport:{width:390,height:800}});
  await publicPage.goto(base+`index.php?page=event&id=${eventId}&modality=clash_royale`,{waitUntil:'networkidle'});
  const publicResult={cards:await publicPage.locator('.match-board-card').count(),scores:await publicPage.locator('.match-board-card .match-board-detail').first().innerText(),adminLinks:await publicPage.locator('.match-board-card a[href*="page=scoreboard"]').count(),overflow:await publicPage.evaluate(()=>document.documentElement.scrollWidth-innerWidth)};
  console.log(JSON.stringify({adminResult,publicResult}));
  if(errors.length||publicResult.adminLinks||publicResult.overflow>0||adminResult.status!=='Encerrada')process.exitCode=1;
 } finally {await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
