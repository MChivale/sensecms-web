'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../.cms/source/public/theme/sensecms-media-library.js','utf8');
const handler=source.split('\n').find(line=>line.includes("querySelector('[data-media-upload-form]').addEventListener('submit'"));
const transport=source.split('\n').find(line=>line.includes('const request=async'));
(async()=>{
 let listener,calls=0,status=200,notices=[],reset=0,files=[],removed=0;
 const button={disabled:false},form={elements:{'assets[]':{get files(){return files;}}},reportValidity:()=>true,querySelector:()=>button,append(){},reset(){reset++;}};
 const context={root:{querySelector(){return {addEventListener(_,fn){listener=fn;}};}},boot:{csrf:'fixture'},FormData:class{set(name,value){assert.equal(name,'csrf');assert.equal(value,'fixture');}},
 document:{createElement(){return {remove(){removed++;}};}},upload:{},folders:[],tags:[],filters:{},close(){},load:async()=>{},syncScopedOptions(){},toast:(type,message)=>notices.push({type,message}),
 fetch:async(url,options)=>{calls++;assert.equal(url,'/content/media/upload');assert.equal(options.method,'POST');assert.equal(options.credentials,'same-origin');return {status,ok:status===200,json:async()=>{if(status===413)throw Error('HTML body');return {ok:true,message:'Uploaded',data:{items:[],errors:[]}};}};}};
 vm.runInNewContext(transport+'\n'+handler,context);
 const submit=()=>listener({preventDefault(){},stopImmediatePropagation(){},currentTarget:form});
 files=Array.from({length:11},()=>({size:1}));await submit();assert.equal(calls,0);assert.match(notices.at(-1).message,/10 files/);
 files=[{size:99614721}];await submit();assert.equal(calls,0);assert.match(notices.at(-1).message,/95 MiB/);
 files=[{size:83886080}];await submit();assert.equal(calls,1);assert.equal(reset,1);assert.equal(button.disabled,false);
 status=413;await submit();assert.equal(calls,2);assert.match(notices.at(-1).message,/request limit/);assert.equal(reset,1);assert.equal(button.disabled,false);assert.equal(removed,2);
 console.log('PASS upload UI: count/total limits, 80 MiB request, CSRF, actionable HTTP413, failure preserves selection and reenables button.');
})().catch(error=>{console.error(error);process.exitCode=1;});
