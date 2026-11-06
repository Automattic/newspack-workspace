// Actual WordPress-rendered fixture and production CSS/JS; synthetic save transport.
const fs = require('fs');
const path = require('path');
const http = require('http');
const {chromium} = require(process.env.AA_PLAYWRIGHT_MODULE || 'playwright-core');
const dir = process.argv[2];
let passed = 0, capture = null, behavior = 'ok', release = null, receive = null;
let errors = [];
function check(ok, label) { if (!ok) throw new Error(label); passed++; }
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const server = http.createServer((req, res) => {
 if (req.url === '/qa-save') {
  let body=''; req.on('data', bytes=>body+=bytes); req.on('end',()=>{
   capture = new URLSearchParams(body);
   const mode=behavior;
   const reply=()=>{if(mode==='network'){res.destroy();return;}res.setHeader('Content-Type','application/json');res.end(mode==='error'?'Finvalid-json':(mode==='denied'?'{"success":false,"data":"Synthetic permission error."}':'{"success":true}'));};
   if(mode==='hold')release=reply;else reply();
   if(receive){receive();receive=null;}
  }); return;
 }
 const file=req.url==='/'?'ui-fixture.html':path.basename(new URL(req.url,'http://127.0.0.1').pathname);
 const full=path.join(dir,file);
 if(!fs.existsSync(full)){res.statusCode=404;res.end();return;}
 res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':(file.endsWith('.css')?'text/css':'text/html'));
 res.end(fs.readFileSync(full));
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const url='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({executablePath:process.env.AA_CHROME_PATH||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const context=await browser.newContext({viewport:{width:1100,height:1000}});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 try {
  await page.goto(url);await page.evaluate(()=>document.fonts.ready);
  const saveButton=page.locator('.agent-access-admin-update-btn');
  const status=page.locator('.agent-access-admin-update-status');
  const summary=page.locator('.agent-access-scope-summary');
  const count=page.locator('.agent-access-integration-count');
  const full=page.locator('.agent-access-scope-full');
  const read=page.locator('.agent-access-scope-read-only');
  const all=page.locator('.agent-access-scope-all-custom');
  const posts=page.locator('input[value="post"]');
  const textarea=page.locator('.agent-access-scope-custom-routes');
  const site=page.locator('.agent-access-site-management');
  const help=page.getByRole('button',{name:'Help: Site management',exact:true});
  const integrationHelp=page.getByRole('button',{name:'Help: Plugin integration access',exact:true});
  const panel=page.locator('#'+await help.getAttribute('aria-controls'));
  const integrationPanel=page.locator('#'+await integrationHelp.getAttribute('aria-controls'));
  const save=async()=>{capture=null;await saveButton.click();await page.waitForFunction(()=>!document.querySelector('.agent-access-admin-update-btn').disabled);check(capture!==null,'save payload captured');return capture;};
  check(await status.getAttribute('role')==='status'&&await status.getAttribute('aria-live')==='polite','save status is an accessible live region');
  check(!(await page.locator('main').innerText()).includes('Grants manage_options'),'technical capability instructions not shown inline');
  check(!(await page.locator('main').innerText()).includes('REST namespaces'),'namespace instructions not shown inline');
  check((await page.locator('main').innerText()).includes('Applies to every session and credential'),'account-wide consequence remains visible');
  check((await page.locator('.agent-access-settings-table').boundingBox()).width>650,'settings table has useful desktop width');
  check(await page.locator('.agent-access-integration-list').evaluate(e=>e.clientHeight<=290&&e.scrollHeight>e.clientHeight),'integration list is bounded and scrollable');
  check(await page.locator('.agent-access-help-toggle').evaluateAll(nodes=>new Set(nodes.map(n=>n.getAttribute('aria-controls'))).size===nodes.length&&nodes.every(n=>document.getElementById(n.getAttribute('aria-controls'))?.getAttribute('role')==='tooltip')),'unique tooltip IDs and targets');
  await help.hover();check(await panel.isVisible(),'help opens on hover');
  const triggerBox=await help.boundingBox();const panelBox=await panel.boundingBox();await page.mouse.move(triggerBox.x+triggerBox.width/2,triggerBox.y+triggerBox.height+4);await pause(80);await page.mouse.move(panelBox.x+30,panelBox.y+20);await pause(350);
  check(await panel.isVisible(),'pointer crosses gap and enters help without dismissal');
  await page.mouse.move(5,5);await pause(350);check(!(await panel.isVisible()),'hover help dismisses after pointer leaves');
  await help.focus();check(await panel.isVisible(),'help opens on keyboard focus');
  await help.press('Escape');check(!(await panel.isVisible()),'Escape dismisses help');
  await help.press('Enter');check(await panel.isVisible(),'Enter opens and pins contextual help');
  await help.press('Space');check(!(await panel.isVisible()),'Space toggles pinned help closed');
  await help.click();check(await panel.isVisible(),'click opens help');
  check(!(await site.isChecked()),'help click cannot toggle site-management grant');
  await page.locator('.agent-access-title').click();check(!(await panel.isVisible()),'outside click dismisses help');
  await full.check();check(await posts.isDisabled()&&await textarea.isDisabled(),'full access still disables narrower controls');
  check(await integrationHelp.isEnabled(),'integration help remains enabled in full-access mode');
  await integrationHelp.focus();check(await integrationPanel.isVisible(),'disabled permission section still has keyboard-operable help');await integrationHelp.press('Escape');
  check((await summary.innerText()).startsWith('Full write access'),'effective full mode shown');
  await read.check();check((await summary.innerText()).includes('writes disabled'),'read-only mode takes precedence');check(await integrationHelp.isEnabled(),'help remains enabled in read-only mode');
  await read.uncheck();await full.uncheck();check(await posts.isChecked(),'mode toggles preserve selections');
  await all.check();check(await textarea.isDisabled()&&!(await posts.isDisabled()),'all-plugin toggle disables only plugin grant controls');
  check((await count.innerText()).includes('All detected'),'integration count reflects blanket selection');
  const routeHelp=page.getByRole('button',{name:'Help: Custom route prefixes',exact:true});check(await routeHelp.isEnabled(),'advanced help remains usable under all-plugins mode');
  await all.uncheck();check((await textarea.inputValue()).includes('/inactive-plugin/v1/*'),'all-plugin toggle preserves inactive route text');
  await save();check(await status.innerText()==='Settings saved.','save success shown');await pause(3250);check(await status.innerText()==='Settings saved.','saved feedback persists beyond prior three-second timeout');
  await posts.uncheck();check(await status.innerText()==='Unsaved changes','editing changes status to unsaved');
  behavior='hold';const received=new Promise(resolve=>receive=resolve);await saveButton.click();await received;
  await posts.check();release();release=null;await page.waitForFunction(()=>!document.querySelector('.agent-access-admin-update-btn').disabled);
  check(await status.innerText()==='Unsaved changes remain.','in-flight edit cannot be misreported as saved');
  behavior='ok';await save();check(await status.innerText()==='Settings saved.','subsequent save confirms current revision');
  behavior='denied';await save();check(await status.innerText()==='Synthetic permission error.','server error remains visible');
  behavior='error';await save();check((await status.innerText()).includes('not confirmed saved'),'invalid response never claims a save');
  behavior='network';await save();check((await status.innerText()).includes('Network error'),'network failure is recoverable and visible');
  behavior='ok';await save();
  await page.evaluate(()=>{window.aaLiveChanges=0;const observer=new MutationObserver(records=>{window.aaLiveChanges+=records.length;});document.querySelectorAll('.agent-access-scope-summary,.agent-access-integration-count').forEach(el=>observer.observe(el,{childList:true,characterData:true,subtree:true}));});
  await textarea.fill('/inactive-plugin/v1/*\n/custom-one/v1/*');await textarea.fill('/inactive-plugin/v1/*\n/custom-two/v1/*');
  check(await page.evaluate(()=>window.aaLiveChanges)===0,'unchanged live summaries are not re-announced on every keystroke');
  await page.locator('.agent-access-scope-type:checked').evaluateAll(nodes=>nodes.forEach(n=>{n.checked=false;n.dispatchEvent(new Event('change',{bubbles:true}));}));await textarea.fill('');
  check((await summary.innerText()).includes('No selections'),'empty selection clearly shows writes disabled');
  await posts.check();await textarea.fill('/inactive-plugin/v1/*');
  await page.locator('input[value="/newspack-workflows/v1/*"]').check();await save();
  await page.locator('summary').filter({hasText:'Advanced: specific route patterns'}).click();
  await page.screenshot({path:path.join(dir,'design-desktop.png'),fullPage:true});
  await help.click();await page.screenshot({path:path.join(dir,'design-help-open.png'),fullPage:false});await help.press('Escape');
  check(errors.length===0,'no browser JavaScript errors');
  const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:1});
  const phone=await mobile.newPage();await phone.goto(url);const mobileHelp=phone.getByRole('button',{name:'Help: Site management',exact:true});
  await mobileHelp.tap();const mobilePanel=phone.locator('#'+await mobileHelp.getAttribute('aria-controls'));check(await mobilePanel.isVisible(),'touch opens contextual help');
  const box=await mobilePanel.boundingBox();check(box.x>=15&&box.x+box.width<=391&&box.y>=15&&box.y+box.height<=845,'mobile help stays inside viewport');
  check(!(await phone.locator('.agent-access-site-management').isChecked()),'touching help never enables privilege grant');
  await phone.touchscreen.tap(4,4);check(!(await mobilePanel.isVisible()),'outside touch closes help');
  check(await phone.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'native settings layout has no mobile horizontal overflow');
  await phone.screenshot({path:path.join(dir,'design-mobile.png'),fullPage:true});await mobile.close();
  // Check localized values are honored rather than overwritten in English.
  const localized=await browser.newContext();const localPage=await localized.newPage();
  await localPage.route('**/admin.js',route=>route.fulfill({contentType:'application/javascript',body:'agentAccess.ui_text.read_only="LECTURE SEULE";agentAccess.ui_text.saved="ENREGISTRE";\n'+fs.readFileSync(path.join(dir,'admin.js'),'utf8')}));
  await localPage.goto(url);await localPage.locator('.agent-access-scope-read-only').check();check(await localPage.locator('.agent-access-scope-summary').innerText()==='LECTURE SEULE','mode summaries respect localized copy');
  await localPage.locator('.agent-access-admin-update-btn').click();await localPage.waitForFunction(()=>!document.querySelector('.agent-access-admin-update-btn').disabled);check(await localPage.locator('.agent-access-admin-update-status').innerText()==='ENREGISTRE','save status respects localized copy');await localized.close();
  fs.writeFileSync(path.join(dir,'design-browser-results.json'),JSON.stringify({passed,boundary:'Actual WordPress-rendered profile controls with native forms/dashicons and production CSS/JS; synthetic local AJAX responses; real backend saves tested separately.'},null,2));
  console.log('PASS '+passed+' design, tooltip, accessibility and save-state assertions.');
 } finally {await context.close();await browser.close();server.close();}
})().catch(e=>{console.error(e.stack);server.close();process.exitCode=1;});
