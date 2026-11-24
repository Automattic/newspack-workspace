// Render the actual hosted PHP controls to ui-fixture.html before running this harness.
const fs = require('fs');
const http = require('http');
const path = require('path');
const { chromium } = require(process.env.AA_PLAYWRIGHT_MODULE || 'playwright-core');
const dir = process.argv[2];
let captured = null;
let checks = 0;
function check(ok, label) { if (!ok) throw new Error(label); checks++; }
const server = http.createServer((req, res) => {
 if (req.url === '/qa-save') {
  let body = ''; req.on('data', b => body += b); req.on('end', () => { captured = new URLSearchParams(body); res.setHeader('Content-Type','application/json'); res.end('{"success":true}'); }); return;
 }
 const file = req.url === '/' ? 'ui-fixture.html' : path.basename(new URL(req.url, 'http://127.0.0.1').pathname);
 if (!fs.existsSync(path.join(dir, file))) { res.statusCode=404;res.end();return; }
 res.setHeader('Content-Type', file.endsWith('.js') ? 'application/javascript' : (file.endsWith('.css') ? 'text/css' : 'text/html'));
 res.end(fs.readFileSync(path.join(dir, file)));
});
(async () => {
 await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
 const browser = process.env.AA_HEADLESS === '1' ? await chromium.launch({executablePath:process.env.AA_CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true}) : await chromium.connectOverCDP('http://127.0.0.1:18800');
 const context = await browser.newContext({viewport:{width:900,height:1100}});
 const page = await context.newPage();
 try {
  await page.goto('http://127.0.0.1:'+server.address().port);
  const full=page.locator('.agent-access-scope-full'), read=page.locator('.agent-access-scope-read-only'), all=page.locator('.agent-access-scope-all-custom'), text=page.locator('textarea'), posts=page.locator('input[value="post"]');
  check(await posts.isChecked(),'existing post scope rendered');
  check((await text.inputValue()).includes('/inactive-plugin/v1/*'),'inactive route shown');
  check(await page.locator('label').filter({has:page.locator('input[value="/newspack-workflows/v1/*"]')}).count()===1,'friendly detected integration');
  const save=async()=>{captured=null;await page.getByRole('button',{name:'Save settings',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.agent-access-admin-update-btn').disabled===false);check(captured!==null,'settings request captured');return captured;};
  let data=await save();if(await page.locator('.agent-access-site-management').count()){check(data.get('site_management')==='0','site management default off serialized');await page.locator('.agent-access-site-management').check();data=await save();check(data.get('site_management')==='1','explicit site management grant serialized');await page.locator('.agent-access-site-management').uncheck();}check(data.get('scope_custom_routes')==='/inactive-plugin/v1/*','advanced route serialized');check(data.getAll('scope_types[]').includes('post'),'post selection serialized');
  await all.check();check(await text.isDisabled(),'all-custom disables specific routes');check(!(await posts.isDisabled()),'all-custom leaves core controls enabled');
  data=await save();check(data.get('scope_all_custom')==='1','all-custom marker serialized');check(!data.has('scope_full'),'all-custom is not full access');check(!data.has('scope_custom_routes'),'disabled custom text not serialized');
  await all.uncheck();check((await text.inputValue())==='/inactive-plugin/v1/*','toggling blanket preserves unsaved specific routes');
  await page.locator('input[value="/newspack-workflows/v1/*"]').check();
  await text.fill('/custom-agent/v1/*\n/custom-orders/v2/save');
  data=await save();check(data.getAll('scope_types[]').includes('/newspack-workflows/v1/*'),'integration checkbox serialized');check(data.get('scope_custom_routes').includes('\n'),'multiline advanced routes serialized');
  await full.check();check(await posts.isDisabled(),'full access disables narrower controls');data=await save();check(data.get('scope_full')==='1','full marker serialized');check(data.getAll('scope_types[]').length===0,'full does not serialize narrower grants');
  await read.check();check(await full.isDisabled(),'read-only disables full');data=await save();check(data.get('scope_read_only')==='1'&&!data.has('scope_full'),'read-only overrides full');
  await read.uncheck();await full.uncheck();check(await posts.isChecked(),'toggling modes preserves specific selections');
  const seo=page.locator('.agent-access-scope-seo-only');
  await seo.check();check(await full.isDisabled()&&await posts.isDisabled()&&await all.isDisabled()&&await text.isDisabled(),'SEO-only disables every broader grant control');
  check((await page.locator('.agent-access-scope-summary').innerText()).startsWith('Yoast SEO only'),'SEO-only summary is explicit');
  check(await page.getByRole('button',{name:'Help: Yoast SEO only',exact:true}).isEnabled(),'SEO help remains enabled');
  data=await save();check(data.get('scope_seo_only')==='1'&&!data.has('scope_full')&&!data.has('scope_all_custom')&&!data.has('scope_custom_routes')&&data.getAll('scope_types[]').length===0,'SEO-only submits no broader grants');
  await read.check();check(await seo.isDisabled(),'read-only takes priority over SEO');data=await save();check(data.get('scope_read_only')==='1'&&!data.has('scope_seo_only'),'read-only payload excludes SEO writes');
  await read.uncheck();check(await seo.isChecked()&&await full.isDisabled(),'SEO choice survives mode switch');
  await seo.uncheck();check(await posts.isChecked()&&!(await posts.isDisabled()),'leaving SEO preserves prior selected grants');
  await page.screenshot({path:path.join(dir,'scope-controls-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'mobile has no horizontal overflow');
  await page.screenshot({path:path.join(dir,'scope-controls-mobile.png'),fullPage:true});
  if(await page.locator('.agent-access-site-management').count()){await page.locator('.agent-access-site-management').evaluate(el=>el.remove());data=await save();check(!data.has('site_management'),'unauthorized/older forms omit grant field');}
  fs.writeFileSync(path.join(dir,'browser-results.json'),JSON.stringify({passed:checks,boundary:'Actual hosted PHP scope controls and production JS; mocked AJAX response, actual serialized payloads; not an authenticated wp-admin browser session'},null,2));
  console.log('PASS '+checks+' browser UI assertions.');
 } finally { await context.close();await browser.close();server.close(); }
})().catch(e=>{console.error(e.message);server.close();process.exitCode=1;});
