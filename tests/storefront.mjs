// Actual native-rendered Magento HTML/static assets, intercepted synthetic shop origin.
// No real shop, order request, blockchain payment or outbound customer notification.
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
const platform=process.env.WHOLLY_TEST_MAGENTO_ROOT, fixture=process.env.WHOLLY_TEST_HTML_DIRECTORY;
assert.ok(platform&&fixture,'Disposable platform and rendered HTML required');
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const config=JSON.parse(await readFile(path.join(fixture,'quote.json'),'utf8')).checkout_config;
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
try{
 const context=await browser.newContext({ignoreHTTPSErrors:true});
 const page=await context.newPage();const errors=[];
 page.on('pageerror',e=>errors.push(e.stack));
 await page.route('**/*',async route=>{
   const u=new URL(route.request().url());
   assert.equal(u.hostname,'shop.example.test','No external request');
   if(u.pathname==='/checkout/')return route.fulfill({contentType:'text/html',body:await readFile(path.join(fixture,'checkout.html'))});
   if(u.pathname.includes('/customer/section/load'))return route.fulfill({contentType:'application/json',body:JSON.stringify({messages:{messages:[]},cart:{summary_count:1},customer:{},'directory-data':{}})});
   if(u.pathname.startsWith('/rest/')){
     const body=u.pathname.includes('payment-information')?{payment_methods:config.paymentMethods,totals:config.totalsData}:config.totalsData;
     return route.fulfill({contentType:'application/json',body:JSON.stringify(body)});
   }
   if(u.pathname.startsWith('/static/')){
     const relative=u.pathname.replace(/^\/static\/(?:version\d+\/)?/,'');
     const file=path.resolve(platform,'pub/static',relative);
     assert.ok(file.startsWith(path.resolve(platform,'pub/static')+path.sep));
     try{return route.fulfill({body:await readFile(file),contentType:({'.js':'application/javascript','.html':'text/html','.css':'text/css','.svg':'image/svg+xml','.json':'application/json','.woff2':'font/woff2','.woff':'font/woff','.png':'image/png'})[path.extname(file)]||'application/octet-stream'});}catch{}
   }
   return route.fulfill({status:404,body:''});
 });
 for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});
   await page.goto('https://shop.example.test/checkout/');
   await page.locator('#whollycrypto').waitFor({state:'visible'});
   await page.locator('#whollycrypto').check();
   await page.getByText('Choose a network and token on the secure Wholly Crypto checkout.').waitFor({state:'visible'});
   assert.equal(await page.locator('#whollycrypto').isChecked(),true);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
   const redirect=await page.evaluate(()=>new Promise((resolve,reject)=>require(['WhollyCrypto_Payment/js/view/payment/method'],m=>resolve(m.prototype.redirectAfterPlaceOrder),reject)));
   assert.equal(redirect,false,'Native success redirect cannot race the hosted payment redirect');
   await page.screenshot({path:path.join(fixture,`checkout-${width}.png`),fullPage:true});
 }
 assert.deepEqual(errors,[]);
 console.log('Native Magento Luma checkout: rendered payment choice, RequireJS component, mobile/desktop layout, no client errors and redirect sequencing passed.');
}finally{await browser.close();}
