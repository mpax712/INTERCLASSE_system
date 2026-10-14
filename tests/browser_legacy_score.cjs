const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
if(!process.env.BROWSER_STAGE_PASSWORD)throw new Error('Set BROWSER_STAGE_PASSWORD');
(async()=>{const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage();
 await page.goto('http://127.0.0.1:8765/index.php?page=login');
 await page.locator('input[name=email]').fill('browser-stage@local.invalid');
 await page.locator('input[name=password]').fill(process.env.BROWSER_STAGE_PASSWORD);
 await page.getByRole('button',{name:'Entrar'}).click();await page.waitForURL(/page=dashboard/);
 await page.goto('http://127.0.0.1:8765/index.php?page=create_event');
 const csrf=await page.locator('input[name=csrf_token]').first().inputValue();
 const get=async path=>page.evaluate(async path=>{const r=await fetch(path);return {status:r.status,json:await r.json()}},path);
 const post=async body=>page.evaluate(async body=>{const r=await fetch('/api/score.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return {status:r.status,json:await r.json()}},body);
 const games=await get('/api/games.php?event_id=3&limit=200');
 if(games.status!==200)throw new Error(JSON.stringify(games));
 const results=[];let firstPayload=null;
 for(const modality of ['handbol','volei','xadrez','cubo_magico','quiz']){
  const match=games.json.data.find(m=>m.modality===modality&&m.status==='agendada'&&m.home_team_id&&m.away_team_id);
  if(!match)throw new Error(`No scheduled ${modality} match`);
  const payload={match_id:Number(match.id),action:'wo',team:'away',expected_version:Number(match.version),request_id:`legacy-wo-${modality}-${match.id}-2026`,csrf_token:csrf};
  const saved=await post(payload);
  results.push({modality,http:saved.status,status:saved.json.status,score:`${saved.json.home_score} x ${saved.json.away_score}`});
  if(saved.status!==200||saved.json.status!=='wo')throw new Error(JSON.stringify({modality,saved}));
  if(!firstPayload)firstPayload=payload;
 }
 const repeated=await post(firstPayload);
 const stale=await post({...firstPayload,request_id:'legacy-stale-request-2026'});
 if(repeated.status!==200||stale.status!==409)throw new Error(JSON.stringify({repeated,stale}));
 console.log(JSON.stringify({results,repeated:repeated.status,stale:stale.status}));
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exitCode=1});
