'use strict';
const vm = require('node:vm');
const {readFileSync} = require('node:fs');
const assert = require('node:assert/strict');
const source = readFileSync(__dirname+'/../.themes/sensecms/assets/updates.js','utf8');
const element = () => ({children:[], textContent:'', append(...items){this.children.push(...items);}, replaceChildren(){this.children=[];}});
async function fixture(releases=[], fail=false, overrides={}) {
    const list=element();let calls=0;
    const data={product:'Sense CMS',channel:'stable',expires_at:Math.floor(Date.now()/1000)+3600,releases,...overrides};
    const context={Uint8Array, TextDecoder, Date, atob:value=>Buffer.from(value,'base64').toString('binary'),
        document:{createElement:element,querySelector(){return {querySelector(selector){assert.equal(selector,'[data-stable-releases]');return list;}};}},
        window:{open(){throw Error('No customer connection');},addEventListener(){throw Error('No message listener');}},
        async fetch(url,options){calls++;assert.equal(url,'/api/updates/v1/catalog');assert.equal(options.credentials,'omit');return {ok:!fail,async json(){return {signed_payload:Buffer.from(JSON.stringify(data)).toString('base64')};}};}};
    vm.runInNewContext(source,context);await new Promise(resolve=>setImmediate(resolve));
    assert.equal(calls,1);return list;
}
(async()=>{
    const empty=await fixture();assert.equal(empty.children.length,1);assert.match(empty.children[0].children[0].textContent,/first Stable/);
    const notes=['Changes in the fixture','<img onerror=alert(1)>'];
    const listed=await fixture([{version:'1.0.0',released_at:1788960000,php_min:'8.5.0',notes}]);
    assert.equal(listed.children[0].children[0].textContent,'Sense CMS 1.0.0');
    assert.match(listed.children[0].children[1].textContent,/PHP 8.5.0/);
    assert.equal(listed.children[0].children[2].children[1].textContent,notes[1]);
    for(const list of [await fixture([],true),await fixture([],false,{expires_at:1}),await fixture([],false,{channel:'development'})]){
        assert.match(list.textContent,/temporarily unavailable/);assert.doesNotMatch(list.textContent,/No Stable Core release has been published/);
    }
    const view=readFileSync(__dirname+'/../.themes/sensecms/views/updates.php','utf8');
    assert.doesNotMatch(view,/data-site-update-form|Check my CMS|update-connect/);
    console.log('PASS releases-only website: empty, release notes as text, errors, expiry, channel, no popup or message listener.');
})().catch(error=>{console.error(error);process.exitCode=1;});
