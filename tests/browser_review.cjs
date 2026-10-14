const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs');
const eventId = Number(process.env.EVENT_ID);
if (!eventId) throw new Error('Set EVENT_ID');
if (!process.env.BROWSER_STAGE_PASSWORD) throw new Error('Set BROWSER_STAGE_PASSWORD');
const base = 'http://127.0.0.1:8765/';
const out = '/home/rmzin/Músicas/ProjetoManada/backups/review';
fs.mkdirSync(out, {recursive: true});
(async () => {
  const browser = await chromium.launch({headless: true});
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  await page.goto(base + 'index.php?page=login');
  await page.locator('input[name=email]').fill('browser-stage@local.invalid');
  await page.locator('input[name=password]').fill(process.env.BROWSER_STAGE_PASSWORD);
  await page.getByRole('button', {name: 'Entrar'}).click();
  await page.waitForURL(/page=dashboard/);
  for (const width of [360,390,768,1366]) {
    await page.setViewportSize({width, height: 800});
    for (const modality of ['fifa','clash_royale']) {
      await page.goto(base + `index.php?page=event&id=${eventId}&modality=${modality}`, {waitUntil:'networkidle'});
      const cards = await page.locator('.match-board-card').count();
      const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - innerWidth);
      await page.screenshot({path:`${out}/event_${modality}_${width}.png`, fullPage:true});
      console.log(JSON.stringify({width,modality,cards,overflow,title:await page.title()}));
      if (!cards || overflow > 1) throw new Error(`event ${modality} ${width}: cards=${cards}, overflow=${overflow}`);
      if (width === 390) {
        await page.locator('input[name=match_q]').fill('Ana');
        await page.getByRole('button',{name:'Filtrar'}).click();
        await page.waitForLoadState('networkidle');
        console.log(JSON.stringify({filter:modality,count:await page.locator('.match-board-card').count()}));
      }
    }
    await page.goto(base + `index.php?page=standings&event_id=${eventId}&modality=fifa`, {waitUntil:'networkidle'});
    const standingOverflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - innerWidth);
    await page.screenshot({path:`${out}/standings_${width}.png`, fullPage:true});
    console.log(JSON.stringify({width,standingsOverflow:standingOverflow}));
  }
  await page.setViewportSize({width:390,height:800});
  await page.goto(base + `index.php?page=event&id=${eventId}&modality=clash_royale`, {waitUntil:'networkidle'});
  await page.locator('.match-board-card a[href*="page=scoreboard"]').first().click();
  await page.waitForLoadState('networkidle');
  await page.screenshot({path:`${out}/scoreboard_clash_390.png`, fullPage:true});
  console.log(JSON.stringify({scoreboard:await page.locator('#game-scoreboard').count(),overflow:await page.evaluate(() => document.documentElement.scrollWidth-innerWidth)}));
  console.log(JSON.stringify({errors}));
  await browser.close();
  if (errors.length) process.exitCode = 1;
})().catch(e => {console.error(e);process.exitCode=1});
