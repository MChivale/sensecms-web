'use strict';
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
(async () => {
    const events = {}; let calls = 0, posts = [], failure = false;
    const host = () => {
        const controls = new Map();
        return {dataset: {csrf:'fixture', updateBridge:'a'.repeat(48)}, querySelector(selector) {
            if (!controls.has(selector)) controls.set(selector, {disabled:false, textContent:'', listeners:[], addEventListener(event, callback) {this.listeners.push(callback);}});
            return controls.get(selector);
        }};
    };
    let current = null;
    const context = {URLSearchParams, Date, location:{hash:'#'+'a'.repeat(48)}, window:{opener:{postMessage(data, origin){posts.push({data, origin});}}},
        document:{querySelector:() => current, addEventListener:(event, callback)=>events[event]=callback},
        async fetch(url, options){calls++;assert.equal(url,'/system/update');assert.equal(options.method,'POST');assert.equal(options.body.get('csrf'),'fixture');
            return {ok:!failure, async json(){return failure ? {ok:false,message:'Failed verification'} : {ok:true,data:{verified:true,version:'0.1.0',latest:[],available:false,compatible:true,checked_at:Math.floor(Date.now()/1000)}};}};},
        setInterval(){throw Error('Panel must not poll');}};
    vm.runInNewContext(readFileSync(__dirname+'/../.cms/source/public/theme/sensecms-system-update.js','utf8'),context);
    current=host();events.DOMContentLoaded();events['sensecms:content-ready']();
    const button=current.querySelector('[data-update-check]');
    assert.equal(button.listeners.length,1);assert.equal(calls,0);
    assert.equal(current.querySelector('[data-update-install]').disabled,true);
    await button.listeners[0]();
    assert.equal(calls,1);assert.equal(button.disabled,false);assert.equal(posts.length,0);
    assert.match(current.querySelector('[data-update-heading]').textContent,/No Stable Core release/);
    assert.match(current.querySelector('[data-update-message]').textContent,/Signature verified/);
    failure=true;await button.listeners[0]();assert.equal(posts.length,0);assert.equal(button.disabled,false);
    assert.equal(current.querySelector('[data-update-message]').textContent,'Failed verification');
    current=host();events['sensecms:content-ready']();assert.equal(current.querySelector('[data-update-check]').listeners.length,1);
    current=null;events['sensecms:content-ready']();
    console.log('PASS update UI: explicit check, CSRF, no cross-window sharing, failure and AJAX lifecycle.');
})().catch(error=>{console.error(error);process.exitCode=1;});
