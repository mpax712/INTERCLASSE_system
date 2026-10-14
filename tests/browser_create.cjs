const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
if(!process.env.BROWSER_STAGE_PASSWORD)throw new Error('Set BROWSER_STAGE_PASSWORD');
const base='http://127.0.0.1:8765/';
(async()=>{const b=await chromium.launch();try{
 const p=await b.newPage({viewport:{width:390,height:800}});const errors=[];p.on('pageerror',e=>errors.push(String(e)));
 await p.goto(base+'index.php?page=login');await p.locator('input[name=email]').fill('browser-stage@local.invalid');await p.locator('input[name=password]').fill(process.env.BROWSER_STAGE_PASSWORD);await p.getByRole('button',{name:'Entrar'}).click();await p.waitForURL(/page=dashboard/);
 await p.goto(base+'index.php?page=create_event',{waitUntil:'networkidle'});
 await p.locator('input[name=name]').fill('Fluxo de cadastro visual');await p.locator('input[name=start_date]').fill('2026-10-10');
 await p.locator('label[for=mod-fifa]').click();await p.locator('label[for=mod-clash_royale]').click();
 await p.getByRole('button',{name:'Criar evento'}).click();await p.waitForURL(/page=event/);
 const eventId=new URL(p.url()).searchParams.get('id');
 await p.goto(base+`index.php?page=teams&event_id=${eventId}&modality=fifa`,{waitUntil:'networkidle'});
 await p.locator('select[name=class_id]').first().selectOption({index:1});await p.getByRole('button',{name:'Salvar time'}).click();await p.waitForLoadState('networkidle');
 const playerHref=await p.locator('a[href*="page=players"]').first().getAttribute('href');await p.goto(base+playerHref,{waitUntil:'networkidle'});
 await p.locator('form input[name=name]').first().fill('Aluno de teste visual');await p.getByRole('button',{name:'Salvar',exact:true}).first().click();await p.waitForLoadState('networkidle');
 console.log(JSON.stringify({eventId,playerVisible:await p.locator('input[name=name][value="Aluno de teste visual"]').count(),errors,overflow:await p.evaluate(()=>document.documentElement.scrollWidth-innerWidth)}));
 if(errors.length || !(await p.locator('input[name=name][value="Aluno de teste visual"]').count()))process.exitCode=1;
}finally{await b.close()}})().catch(e=>{console.error(e);process.exitCode=1});
