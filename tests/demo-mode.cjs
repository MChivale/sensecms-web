'use strict';
const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
(async()=>{
    let calls=0,now=2000,focused=false;const events={},toasts=[];
    const button={addEventListener:(_,fn)=>button.click=fn};
    const layer={dataset:{},hidden:false,querySelector:selector=>selector==='[role="dialog"]'?{focus:()=>focused=true}:button};
    class Form{constructor(method,action){this.method=method;this.action=action;}}
    const document={body:{dataset:{sensecmsDemo:'1'}},readyState:'complete',documentElement:{style:{overflow:''}},
        addEventListener:(event,fn)=>events[event]=fn,querySelector:selector=>selector.startsWith('[data-demo-welcome]')?layer:{focus:()=>focused=true}};
    const window={SenseCMSUI:{toast:(type,text)=>toasts.push({type,text})},fetch:async()=>{calls++;return new Response('{}');}};
    vm.runInNewContext(readFileSync(__dirname+'/../.cms/source/public/theme/sensecms-demo-mode.js','utf8'),
        {window,document,Request,Response,URL,HTMLFormElement:Form,location:{origin:'https://demo.example.test',href:'https://demo.example.test/dashboard'},Date:{now:()=>now},requestAnimationFrame:fn=>fn()});
    assert.equal(focused,true);assert.equal(document.documentElement.style.overflow,'hidden');
    button.click();assert.equal(layer.hidden,true);assert.equal(document.documentElement.style.overflow,'');
    for(const method of ['POST','PUT','PATCH','DELETE']){
        now+=2000;const response=await window.fetch('/system/access/users',{method});
        assert.equal(response.status,403);assert.equal(response.headers.get('X-SenseCMS-Demo-Mode'),'1');assert.match((await response.json()).message,/read-only/);
    }
    assert.equal(calls,0);assert.equal(toasts.length,4);assert.ok(toasts.every(t=>t.type==='warning'));
    now+=2000;let prevented=false,stopped=false;
    events.submit({target:new Form('post','https://demo.example.test/settings'),preventDefault:()=>prevented=true,stopImmediatePropagation:()=>stopped=true});
    assert.equal(prevented,true);assert.equal(stopped,true);assert.equal(toasts.length,5);
    await window.fetch(new Request('https://demo.example.test/settings',{method:'POST'}));assert.equal(toasts.length,5);
    for(const method of ['GET','HEAD','OPTIONS'])await window.fetch('/dashboard',{method});
    for(const path of ['/login','/logout'])await window.fetch(path,{method:'POST'});
    assert.equal(calls,5);
    document.body.dataset.sensecmsDemo='0';await window.fetch('/system/access/users',{method:'POST'});assert.equal(calls,6);
    prevented=false;events.submit({target:new Form('post','/settings'),preventDefault:()=>prevented=true});assert.equal(prevented,false);
    console.log('PASS Demo welcome, repeated warning toasts, write methods, forms, request objects, read/login/logout exceptions and writable Owner.');
})().catch(error=>{console.error(error);process.exitCode=1;});
