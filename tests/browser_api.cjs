const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
if(!process.env.BROWSER_STAGE_PASSWORD) throw new Error('Set BROWSER_STAGE_PASSWORD');
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage();
  await page.goto('http://127.0.0.1:8765/index.php?page=login');
  await page.locator('input[name=email]').fill('browser-stage@local.invalid');
  await page.locator('input[name=password]').fill(process.env.BROWSER_STAGE_PASSWORD);
  await page.getByRole('button',{name:'Entrar'}).click();
  await page.waitForURL(/page=dashboard/);
  await page.goto('http://127.0.0.1:8765/index.php?page=create_event');
  const token=await page.locator('input[name=csrf_token]').first().inputValue();
  const send=async(method,path,body)=>page.evaluate(async({method,path,body,token})=>{
   const res=await fetch(path,{method,headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:body===undefined?undefined:JSON.stringify(body)});
   return {status:res.status,json:await res.json()};
  },{method,path,body,token});
  const bad=await send('POST','/api/events.php',{name:'Teste API inválido',start_date:'2026-10-11',modalities:['nao_existe'],csrf_token:token});
  if(bad.status!==422)throw new Error(`invalid modality: ${JSON.stringify(bad)}`);
  const made=await send('POST','/api/events.php',{name:'Teste API reversível',start_date:'2026-10-11',modalities:['fifa','clash_royale'],rules:{clash_royale:{best_of:5}},csrf_token:token});
  if(made.status!==201||!made.json.id)throw new Error(`create: ${JSON.stringify(made)}`);
  const id=made.json.id;
  const teams=await send('POST','/api/teams.php',{event_id:id,modality:'quiz',class_id:1,csrf_token:token});
  if(teams.status!==422)throw new Error(`unregistered modality: ${JSON.stringify(teams)}`);
  const classes=await send('GET','/api/classes.php');
  const classId=classes.json.data?.[0]?.id;
  if(!classId)throw new Error('No class fixture');
  const team=await send('POST','/api/teams.php',{event_id:id,modality:'fifa',class_id:classId,csrf_token:token});
  if(team.status!==201||!team.json.id)throw new Error(`team: ${JSON.stringify(team)}`);
  const player=await send('POST','/api/players.php',{team_id:team.json.id,name:'Aluno da API',csrf_token:token});
  if(player.status!==201||!player.json.id)throw new Error(`player: ${JSON.stringify(player)}`);
  const deleted=await send('DELETE',`/api/events.php?id=${id}`,{csrf_token:token});
  if(deleted.status!==200)throw new Error(`delete: ${JSON.stringify(deleted)}`);
  const lookup=await send('GET',`/api/events.php?id=${id}`);
  if(lookup.json.data!==false&&lookup.json.data!==null)throw new Error(`still exists: ${JSON.stringify(lookup)}`);
  console.log(JSON.stringify({invalid:bad.status,created:made.status,unregisteredTeam:teams.status,team:team.status,player:player.status,deleted:deleted.status,eventId:id}));
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
