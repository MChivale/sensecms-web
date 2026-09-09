const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const listeners = {}, shown = [], opened = [];
const origin = 'https://www.sensecms.com';
const self = {location:{origin}, addEventListener:(event,fn)=>listeners[event]=fn,
    skipWaiting:async()=>{}, clients:{claim:async()=>{},matchAll:async()=>[],openWindow:async url=>opened.push(url)},
    registration:{showNotification:async(...args)=>shown.push(args)}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../.cms/source/public/service-worker.js','utf8'),{self,URL});
(async()=>{
    let count=0;
    for (const url of ['https://evil.example','//evil.example','javascript:alert(1)','/logout','https://user:pass@www.sensecms.com/settings']) {
        let task;listeners.push({data:{json:()=>({title:'Test',body:'Body',url})},waitUntil:p=>task=p});await task;
        assert.equal(shown.at(-1)[1].data.url,origin+'/dashboard');count++;
    }
    for (const value of [null,[],42,'bad']) {
        let task;listeners.push({data:{json:()=>value},waitUntil:p=>task=p});await task;
        assert.equal(shown.at(-1)[0],'Sense CMS');count++;
    }
    let task;listeners.push({data:{json:()=>({title:'x'.repeat(200),body:'y'.repeat(2200),url:'/calendar?day=1'})},waitUntil:p=>task=p});await task;
    assert.equal(shown.at(-1)[0].length,180);assert.equal(shown.at(-1)[1].body.length,2000);count+=2;
    const receipts=[];self.clients.matchAll=async()=>[{postMessage:data=>receipts.push(data)}];
    listeners.push({data:{json:()=>({title:'Test',test:true,tag:'unique-test'})},waitUntil:p=>task=p});await task;
    assert.equal(receipts.at(-1).displayed,true);assert.equal(shown.at(-1)[1].renotify,true);count+=2;
    self.clients.matchAll=async()=>[];
    listeners.notificationclick({notification:{close:()=>{},data:{url:'/calendar?day=1'}},waitUntil:p=>task=p});await task;
    assert.equal(opened.at(-1),origin+'/calendar?day=1');count++;
    assert.equal(listeners.fetch,undefined);count++;
    console.log(`${count} Web Push worker checks passed; no browser permissions or messages sent.`);
})().catch(error=>{console.error(error.message);process.exitCode=1;});
