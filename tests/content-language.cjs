const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const listeners={},stored=new Map(),links=[],forms=[],tabs=[],panels=[],returns=[{value:'/dashboard',closest:()=>({})}];
let current=new URL('https://cms.test/content/pages/2/edit?return=%2Fcontent%2Fpages&lang=pl'),focus=0;
const state={dataset:{editorLanguages:'["en","pl"]',editorLocale:'en'}};
const document={body:{dataset:{sensecmsUser:'{"id":4}'}},querySelector:s=>s==='[data-editor-languages]'?state:null,
 querySelectorAll:s=>s==='a[href^="/"]'?links:s==='[data-language-tabs]'?tabs:s==='form[data-content-filter]'?forms:s==='[data-workspace-switch] [name="return"]'?returns:[],
 addEventListener:(name,fn)=>(listeners[name]??=[]).push(fn)};
const window={};
const context={window,document,URL,location:{get href(){return current.href},origin:current.origin},
 history:{state:{},replaceState:(_,__,url)=>current=new URL(url,current)},
 sessionStorage:{getItem:k=>stored.get(k),setItem:(k,v)=>stored.set(k,v)},
 queueMicrotask:fn=>fn(),requestAnimationFrame:fn=>fn(),setTimeout:()=>0,Event};
vm.runInNewContext(fs.readFileSync('.cms/source/public/theme/sensecms-ecosystem.js','utf8'),context);
const language=window.SenseCMSContentLanguage;
assert.equal(language.current(),'pl');
assert.equal(language.url('/seo?type=page&document=2'),'/seo?type=page&document=2&lang=pl');
assert.equal(language.url('/extensions/catalog'),'/extensions/catalog');
assert.equal(language.url('/conversations/configuration?from=ai#teams'),'/conversations/configuration?from=ai&lang=pl#teams');
language.remember('pl');assert.equal(stored.get('sensecms-content-language-4'),'pl');
language.remember('pl');assert.equal(returns[0].value,'/dashboard?lang=pl','Repeated initialization retains the Work here destination and language');
current=new URL('https://cms.test/content/posts/new');assert.equal(language.current(),'pl');
current=new URL('https://cms.test/seo?lang=disabled');assert.equal(language.current(),'en');
current=new URL('https://cms.test/seo?lang=pl');
const values=['Original English','Oryginalny polski'];
for(let index=0;index<2;index++){
 const hidden={value:'en'},group=[],localPanels=['en','pl'].map((locale,i)=>({dataset:{languagePanel:locale},hidden:i===1,value:values[i]}));
 const root={querySelectorAll:s=>s==='[data-language-panel]'?localPanels:s==='[data-ui-locale]'?[hidden]:[]};
 const nav={closest:()=>root,querySelectorAll:()=>group};
 for(const locale of ['en','pl'])group.push({dataset:{languageTab:locale},classList:{toggle(){}},setAttribute(k,v){this[k]=v},closest:()=>nav,focus(){focus++}});
 tabs.push(nav);panels.push(...localPanels);nav.group=group;nav.hiddenInput=hidden;
}
listeners.DOMContentLoaded[0]();
for(const nav of tabs){assert.equal(nav.hiddenInput.value,'pl');assert.equal(nav.group[1]['aria-selected'],'true');assert.equal(nav.group[0].tabIndex,-1)}
assert.deepEqual(panels.map(p=>p.hidden),[true,false,true,false]);
assert.deepEqual(panels.map(p=>p.value),[...values,...values]);
const target={closest:selector=>selector==='[data-language-panel]'?{...panels[0],closest:()=>null}:null,focus(){focus++}};
listeners.invalid[0]({target});
assert.equal(language.current(),'en');assert.equal(panels[0].hidden,false);assert.equal(focus,1);
assert.deepEqual(panels.map(p=>p.value),[...values,...values]);
const keyTarget={closest:selector=>selector==='[data-language-tab]'?tabs[0].group[0]:null};
listeners.keydown[0]({target:keyTarget,key:'ArrowRight',preventDefault(){}});
assert.equal(language.current(),'pl');
state.dataset.editorLanguages='["en"]';current=new URL('https://cms.test/content/pages/new');assert.equal(language.current(),'en');
const extensionPanels=['modules','addons','plugins','catalog'].map(name=>({dataset:{extensionPanel:name},hidden:name!=='modules'})),extensionButtons=[];
const extensionRoot={querySelectorAll:()=>extensionButtons};
for(const panel of extensionPanels)extensionButtons.push({dataset:{extensionTab:panel.dataset.extensionPanel},classList:{toggle(){}},setAttribute(k,v){this[k]=v},closest(selector){return selector==='[data-extension-tab]'?this:extensionRoot},focus(){focus++}});
const originalQuery=document.querySelector,originalQueries=document.querySelectorAll;
document.querySelector=s=>s==='[data-extension-tabs]'?extensionRoot:originalQuery(s);
document.querySelectorAll=s=>s==='[data-extension-panel]'?extensionPanels:originalQueries(s);
current=new URL('https://cms.test/system/extensions?lang=pl');
for(const [from,key,expected] of [[0,'End',3],[3,'Home',0],[0,'ArrowLeft',3],[3,'ArrowRight',0]]){
 listeners.keydown[0]({target:extensionButtons[from],key,preventDefault(){}});
 assert.equal(extensionButtons[expected]['aria-selected'],'true');assert.equal(extensionButtons[expected].tabIndex,0);
 assert.deepEqual(extensionPanels.map(panel=>panel.hidden),extensionPanels.map((_,index)=>index!==expected));
 assert.equal(current.searchParams.get('lang'),'pl');assert.equal(current.searchParams.get('tab'),extensionButtons[expected].dataset.extensionTab);
}
console.log('Content language and section tabs: URL continuity, allowed languages, per-user preference, synchronized forms, keyboard navigation, fixed workspace destination and hidden-field validation passed; translation values unchanged.');
