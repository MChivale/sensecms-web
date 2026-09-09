'use strict';
const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const {webcrypto}=require('node:crypto');
const src=name=>readFileSync(__dirname+'/../.cms/source/public/theme/'+name,'utf8');
const flush=async()=>{for(let i=0;i<15;i++)await new Promise(resolve=>setImmediate(resolve));};
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
function host(){const controls=new Map();return {dataset:{},isConnected:true,setAttribute(){},querySelector(s){if(!controls.has(s))controls.set(s,{dataset:{},hidden:false,disabled:false,checked:false,textContent:'',addEventListener(t,fn){this[t]=fn;}});return controls.get(s);},querySelectorAll(){return []}};}
async function push(){
    let currentHost=host(),posts=[],unsubscribed=0,subscribed=0,gets=0,toasts=[];
    const events={};let browserSub={endpoint:'https://fcm.googleapis.com/expired',toJSON(){return {endpoint:this.endpoint}},async unsubscribe(){unsubscribed++;return true}};
    const digest=async s=>Buffer.from(await webcrypto.subtle.digest('SHA-256',new TextEncoder().encode(s))).toString('hex');
    let status={available:true,public_key:'AQID',subscription_hashes:[],devices:[],sources:[]};
    const context={console,Uint8Array,TextEncoder,crypto:webcrypto,atob:s=>Buffer.from(s,'base64').toString('binary'),Notification:{permission:'granted',async requestPermission(){return 'granted'}},window:{isSecureContext:true,PushManager:{},Notification:{},SenseCMSUI:{toast:(...x)=>toasts.push(x)}},document:{body:{dataset:{}},querySelector:()=>currentHost,addEventListener:(e,fn)=>events[e]=fn},navigator:{serviceWorker:{addEventListener(){},async register(){return {pushManager:{async getSubscription(){return browserSub},async subscribe(){subscribed++;browserSub={...browserSub,endpoint:'https://fcm.googleapis.com/renewed'};return browserSub}}}}}},async fetch(url,options={}){if(options.method!=='POST'){gets++;return {ok:true,json:async()=>({ok:true,data:status})}}const p=JSON.parse(options.body);posts.push(p.action);if(p.action==='test'){status={...status,subscription_hashes:[],devices:[]};return {ok:false,json:async()=>({ok:false,message:'Expired; renew this browser.',data:status})}}if(p.action==='subscribe'){status={...status,subscription_hashes:[await digest(p.subscription.endpoint)],devices:[{}]};return {ok:true,json:async()=>({ok:true,data:status,message:'Enabled'})}}throw Error('Unexpected POST');}};
    vm.runInNewContext(src('sensecms-web-push.js'),context);
    events.DOMContentLoaded();await flush();
    check(gets===1,'Initial status loads once');
    check(posts.length===0,'Reading settings never reactivates an expired endpoint');
    check(currentHost.querySelector('[data-web-push-badge]').textContent==='Available','Expired browser is not shown as enabled');
    events['sensecms:content-ready']();await flush();check(gets===1,'Same DOM is not initialized twice');
    const old=currentHost;old.isConnected=false;currentHost=host();events['sensecms:content-ready']();await flush();
    check(gets===2,'Replacement settings DOM reloads its state');
    check(currentHost.querySelector('[data-web-push-enable]').click,'Replacement controls receive handlers');
    await currentHost.querySelector('[data-web-push-enable]').click();await flush();
    check(unsubscribed===1&&subscribed===1,'Explicit enable replaces the expired browser endpoint');
    check(currentHost.querySelector('[data-web-push-badge]').textContent==='Enabled','Fresh subscription renders enabled');
    await currentHost.querySelector('[data-web-push-test]').click();await flush();
    check(toasts.at(-1)[0]==='error','Rejected delivery is not a success toast');
    check(currentHost.querySelector('[data-web-push-badge]').textContent==='Available','Rejected subscription status is retained from error response');
    check(posts.join(',')==='subscribe,test','No silent reactivation or duplicate test');
}
async function telegram(){
    let current=host(),gets=0;const events={};
    const context={document:{querySelector:()=>current,body:{dataset:{}},addEventListener:(e,fn)=>events[e]=fn},window:{SenseCMSUI:{toast(){}}},clearTimeout(){},setTimeout(){},async fetch(){gets++;return {ok:true,json:async()=>({ok:true,data:{available:true,connected:true,account:{}}})}}};
    vm.runInNewContext(src('sensecms-telegram-connect.js'),context);await flush();
    check(current.querySelector('[data-telegram-badge]').textContent==='Connected','Telegram initializes immediately');
    events.DOMContentLoaded();events['sensecms:content-ready']();await flush();check(gets===1,'Telegram handlers do not duplicate');
    current.isConnected=false;current=host();events['sensecms:content-ready']();await flush();
    check(gets===2&&current.querySelector('[data-telegram-badge]').textContent==='Connected','Telegram reconnects UI after AJAX profile save');
}
(async()=>{await push();await telegram();check(src('sensecms-ui.js').includes("document.dispatchEvent(new Event('sensecms:content-ready'))"),'Shared UI boot announces replacement content');console.log(checks+' notification settings regression checks passed. No live subscriptions or messages.');})().catch(error=>{console.error(error);process.exitCode=1;});
