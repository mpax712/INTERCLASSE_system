const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({headless:true});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    for (const width of [360,390,768,1366]) {
      await page.setViewportSize({width,height:800});
      for (const modality of ['volei','handbol','xadrez','cubo_magico','quiz']) {
        const response = await page.goto(`http://127.0.0.1:8765/index.php?page=event&id=3&modality=${modality}`, {waitUntil:'networkidle'});
        const title = await page.locator('h1').first().textContent().catch(()=>'');
        const overflow = await page.evaluate(() => Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)-innerWidth);
        console.log(JSON.stringify({width,modality,http:response.status(),title,overflow}));
        if (response.status() >= 400 || overflow > 1 || !title) throw new Error('Falha de regressão no evento 2026');
      }
    }
    const response = await page.goto('http://127.0.0.1:8765/index.php?page=home', {waitUntil:'networkidle'});
    if (response.status() >= 400 || errors.length) throw new Error(`Falha na página inicial: ${errors.join(',')}`);
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
