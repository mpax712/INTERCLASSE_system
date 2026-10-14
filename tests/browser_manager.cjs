const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
if(!process.env.BROWSER_STAGE_PASSWORD||!process.env.EVENT_ID)throw new Error('Set BROWSER_STAGE_PASSWORD and EVENT_ID');
(async()=>{const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(String(e)));
 await page.goto('http://127.0.0.1:8765/index.php?page=login');
 await page.locator('input[name=email]').fill('browser-stage@local.invalid');
 await page.locator('input[name=password]').fill(process.env.BROWSER_STAGE_PASSWORD);
 await page.getByRole('button',{name:'Entrar'}).click();await page.waitForURL(/page=dashboard/);
 let total=0;
 for(const width of [360,1366]){
  await page.setViewportSize({width,height:800});
  const response=await page.goto(`http://127.0.0.1:8765/index.php?page=match_manager&event_id=${process.env.EVENT_ID}&modality=fifa`,{waitUntil:'networkidle'});
  const count=await page.locator('.game-manager-match').count();
  total=Math.max(total,Number((await page.locator('.card-header h2').last().innerText()).match(/\((\d+)\)/)?.[1]||0));
  const overflow=await page.evaluate(()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)-innerWidth);
  if(response.status()!==200||!count||overflow>1)throw new Error(JSON.stringify({width,http:response.status(),count,overflow}));
  console.log(JSON.stringify({width,count,overflow}));
 }
 await page.locator('input[name=manager_q]').fill('Ana');
 await page.getByRole('button',{name:'Buscar'}).click();await page.waitForLoadState('networkidle');
 const filtered=await page.locator('.game-manager-match').count();
 if(!filtered||filtered>=total||errors.length)throw new Error(JSON.stringify({filtered,errors}));
 console.log(JSON.stringify({filtered,total,errors}));
 if(total>40){await page.goto(`http://127.0.0.1:8765/index.php?page=match_manager&event_id=${process.env.EVENT_ID}&modality=fifa&manager_page=2`,{waitUntil:'networkidle'});const pageTwo=await page.locator('.game-manager-match').count();if(!pageTwo||pageTwo>40)throw new Error(`Pagination failed: ${pageTwo}`);console.log(JSON.stringify({pageTwo}));}
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exitCode=1});
