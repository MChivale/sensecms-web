'use strict';
// Execute the shipped browser code with an isolated DOM; never contact Google.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync('.plugins/google-analytics/assets/analytics.js','utf8');
let checks = 0;
function check(ok,label) { assert.ok(ok,label); checks++; console.log('PASS '+label); }
function page(choice, storageFailure=false) {
    class Element {
        constructor(tag) { this.tag=tag; this.children=[]; this.events={}; this.dataset={}; this.hidden=false; }
        append(...children) { this.children.push(...children); }
        setAttribute(key,value) { this[key]=value; }
        addEventListener(key,handler) { this.events[key]=handler; }
        focus() {}
    }
    const storage = new Map(), listeners = {}, head = new Element('head'), body = new Element('body');
    const script = new Element('script'); script.dataset.measurementId='G-ABC123DEF4';
    const key='sensecms:analytics:G-ABC123DEF4';
    if (choice) storage.set(key,JSON.stringify(choice));
    const location={origin:'https://example.test',pathname:'/news',hostname:'example.test',reload(){this.reloaded=true;}};
    const window={location,addEventListener:(event,fn)=>listeners[event]=fn};
    const document={head,body,documentElement:{lang:'en'},referrer:'https://referrer.test/page?private=yes',cookie:'',querySelector:selector=>selector==='[data-sense-ga]'?script:null,createElement:tag=>new Element(tag)};
    const localStorage={getItem:key=>{if(storageFailure)throw Error();return storage.get(key)||null;},setItem:(key,value)=>{if(storageFailure)throw Error();storage.set(key,value);}};
    vm.runInNewContext(source,{document,window,localStorage,Date,JSON,encodeURIComponent});
    const box=body.children[0], toggle=body.children[1], [reject,accept]=box.children[2].children;
    return {head,box,toggle,reject,accept,window,storage,listeners,key};
}
let p=page();
check(p.head.children.length===0 && !p.box.hidden,'no Google tag before choice');
check(!p.window.dataLayer,'no dataLayer or pings before choice');
p.reject.events.click();
check(p.head.children.length===0 && p.box.hidden,'decline keeps tracking unloaded');
p.toggle.events.click();p.accept.events.click();
check(p.head.children.length===1 && p.head.children[0].src.startsWith('https://www.googletagmanager.com/gtag/js?id=G-'),'accept loads only configured Google tag');
p.accept.events.click();check(p.head.children.length===1,'repeated consent cannot load twice');
check(p.window.dataLayer[0][2].ad_storage==='denied'&&p.window.dataLayer[0][2].ad_user_data==='denied','advertising consent denied');
check(p.window.dataLayer[3][2].page_location==='https://example.test/news'&&p.window.dataLayer[3][2].page_referrer==='https://referrer.test/page','page-view URLs omit queries');
p.reject.events.click();check(p.window['ga-disable-G-ABC123DEF4']&&p.window.location.reloaded,'withdraw disables collection and reloads');
p=page({choice:'denied',until:Date.now()+60000});check(p.head.children.length===0&&p.box.hidden,'saved denial remains inactive');
p=page({choice:'granted',until:Date.now()-1});check(p.head.children.length===0&&!p.box.hidden,'expired consent asks again');
p=page({choice:'granted',until:Date.now()+60000});check(p.head.children.length===1&&p.box.hidden,'unexpired consent loads once');
p.storage.delete(p.key);p.listeners.storage({key:p.key});check(p.window.location.reloaded&&p.window['ga-disable-G-ABC123DEF4'],'withdrawal propagates between tabs');
p=page(null,true);check(p.head.children.length===0&&!p.box.hidden,'blocked storage remains consent-first');
p.accept.events.click();check(p.head.children.length===1,'explicit current-page consent works without storage');
console.log(`${checks} consent checks passed.`);

// Reproduce the panel's pre-existing bubbling submit handler around the plugin.
(async () => {
    const handlers=[], button={disabled:false}, result={textContent:''}, attrs=new Map();
    let requests=0, panelSubmits=0, fail=false;
    const form={action:'/system/extensions/google-analytics',elements:{measurement_id:{value:''}},
        reportValidity:()=>true, querySelector:selector=>selector==='[type=submit]'?button:result,
        setAttribute:(key,value)=>attrs.set(key,value),removeAttribute:key=>attrs.delete(key),
        addEventListener:(type,fn,capture=false)=>handlers.push({fn,capture})};
    form.addEventListener('submit',()=>{panelSubmits++;button.disabled=true;});
    vm.runInNewContext(fs.readFileSync('.plugins/google-analytics/assets/settings.js','utf8'),{
        document:{querySelectorAll:()=>[form]},FormData:class {},fetch:async()=>{
            requests++;if(fail)throw Error('network');
            return {ok:true,json:async()=>({ok:true,measurement_id:'',message:'Analytics disabled.'})};
        }});
    async function submit() {
        let stopped=false;
        const event={preventDefault(){},stopImmediatePropagation(){stopped=true;}};
        for(const handler of [...handlers].sort((a,b)=>Number(b.capture)-Number(a.capture))){
            await handler.fn(event);if(stopped)break;
        }
    }
    await submit();
    check(requests===1&&panelSubmits===0,'settings submit bypasses competing panel handler');
    check(!button.disabled&&!attrs.has('aria-busy'),'successful save restores controls');
    await submit();check(requests===2&&!button.disabled,'settings can be saved repeatedly');
    fail=true;await submit();
    check(!button.disabled&&result.textContent.includes('could not be confirmed'),'failed save restores controls and reports uncertainty');
})().catch(error=>{console.error(error);process.exitCode=1;});
