(()=>{const $=(s,r=document)=>r.querySelector(s),header=$('[data-school-header]'),progress=$('.school-progress span'),menu=$('.school-menu-button'),nav=$('.school-nav nav'),logo=$('.school-logo img'),popup=$('[data-school-popup]'),reduce=matchMedia('(prefers-reduced-motion: reduce)').matches||document.body.classList.contains('prefers-reduced-motion'),update=()=>{const max=document.documentElement.scrollHeight-innerHeight;if(progress)progress.style.width=`${max>0?scrollY/max*100:0}%`;header?.classList.toggle('is-sticky',scrollY>48);if(logo){const source=header?.classList.contains('is-sticky')?logo.dataset.stickyLogo:logo.dataset.defaultLogo;if(source&&logo.getAttribute('src')!==source)logo.src=source}},close=()=>{nav?.classList.remove('is-open');menu?.setAttribute('aria-expanded','false')},count=el=>{const raw=el.textContent.trim(),match=raw.match(/\d[\d,]*/);if(!match)return;const target=Number(match[0].replaceAll(',',''));if(!Number.isFinite(target)||target<1)return;const suffix=raw.slice(match.index+match[0].length),prefix=raw.slice(0,match.index),start=performance.now(),duration=Math.min(1800,700+target*14),render=now=>{const progress=Math.min(1,(now-start)/duration),value=Math.round(target*(1-Math.pow(1-progress,3)));el.textContent=`${prefix}${value.toLocaleString()}${suffix}`;if(progress<1)requestAnimationFrame(render)};el.textContent=`${prefix}0${suffix}`;requestAnimationFrame(render)},popupClose=()=>{if(!popup)return;popup.classList.add('is-closing');setTimeout(()=>popup.remove(),220)};if(popup&&!popup.dataset.popupReady){const key=`eduvixo-popup-${popup.dataset.popupKey}`,frequency=popup.dataset.popupFrequency,store=frequency==='once'?localStorage:sessionStorage,dismiss=()=>{if(frequency!=='always')try{store.setItem(key,'1')}catch{}popupClose()};let dismissed=false;try{dismissed=frequency!=='always'&&store.getItem(key)==='1'}catch{}if(dismissed)popup.remove();else{popup.querySelectorAll('[data-school-popup-close]').forEach(el=>el.addEventListener('click',dismiss));popup.querySelector('.school-popup-backdrop')?.addEventListener('click',dismiss);addEventListener('keydown',e=>{if(e.key==='Escape'&&popup.isConnected)dismiss()},{once:true})}}addEventListener('scroll',update,{passive:true});addEventListener('keydown',e=>{if(e.key==='Escape')close()});menu?.addEventListener('click',()=>{const open=nav?.classList.toggle('is-open')||false;menu.setAttribute('aria-expanded',String(open))});nav?.querySelectorAll('a').forEach(a=>a.addEventListener('click',close));if('IntersectionObserver'in window){const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('is-visible');observer.unobserve(entry.target)}}),{threshold:.14});document.querySelectorAll('[data-reveal]').forEach(el=>observer.observe(el));const counterObserver=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){if(!reduce)count(entry.target);counterObserver.unobserve(entry.target)}}),{threshold:.55});document.querySelectorAll('[data-school-counter]').forEach(el=>counterObserver.observe(el))}update()})();

(() => {
    const root = document.querySelector('[data-school-search]');
    const trigger = document.querySelector('[data-school-search-open]');
    if (!root || !trigger) return;

    const input = root.querySelector('[data-school-search-input]');
    const status = root.querySelector('[data-school-search-status]');
    const results = root.querySelector('[data-school-search-results]');
    const clearButton = root.querySelector('[data-school-search-clear]');
    const closeButtons = root.querySelectorAll('[data-school-search-close]');
    let timer;
    let request;
    let previousFocus;

    const setStatus = (message, loading = false) => {
        status.textContent = message;
        status.classList.toggle('is-loading', loading);
        root.querySelector('.school-search-dialog')?.setAttribute('aria-busy', loading ? 'true' : 'false');
    };

    const minimumLength = query => /[\u1780-\u17ff\u3400-\u9fff]/u.test(query) ? 2 : 3;

    const resultLinks = () => [...results.querySelectorAll('.school-search-result')];

    const highlight = (element, text, query) => {
        const tokens = [...new Set(query.split(/\s+/u).filter(token => token.length > 1))].sort((left, right) => right.length - left.length);
        if (!tokens.length) { element.textContent = text; return; }
        const escaped = tokens.map(token => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const pattern = new RegExp(`(${escaped.join('|')})`, 'giu');
        const normalized = new Set(tokens.map(token => token.toLocaleLowerCase()));
        text.split(pattern).filter(Boolean).forEach(part => {
            if (!normalized.has(part.toLocaleLowerCase())) { element.append(document.createTextNode(part)); return; }
            const mark = document.createElement('mark');
            mark.textContent = part;
            element.append(mark);
        });
    };

    const renderSkeleton = () => {
        results.replaceChildren();
        for (let index = 0; index < 3; index += 1) {
            const row = document.createElement('div');
            row.className = 'school-search-skeleton';
            row.setAttribute('aria-hidden', 'true');
            row.innerHTML = '<i></i><span><b></b><small></small></span>';
            results.append(row);
        }
    };

    const renderFeedback = (message, error = false) => {
        const feedback = document.createElement('div');
        feedback.className = `school-search-feedback${error ? ' is-error' : ''}`;
        feedback.innerHTML = error
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5M12 16.5h.01"/></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m19 19-4-4M8 10.5h5"/></svg>';
        const copy = document.createElement('p');
        copy.textContent = message;
        feedback.append(copy);
        results.replaceChildren(feedback);
    };

    const close = () => {
        request?.abort();
        clearTimeout(timer);
        root.classList.remove('is-open');
        root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('school-search-open');
        document.documentElement.classList.remove('school-search-open');
        setTimeout(() => {
            root.hidden = true;
            previousFocus?.focus();
        }, 180);
    };

    const open = () => {
        previousFocus = document.activeElement;
        root.hidden = false;
        root.setAttribute('aria-hidden', 'false');
        document.body.classList.add('school-search-open');
        document.documentElement.classList.add('school-search-open');
        requestAnimationFrame(() => {
            root.classList.add('is-open');
            input.focus();
        });
    };

    const render = (items, query) => {
        results.replaceChildren();
        if (!items.length) {
            root.dataset.state = 'empty';
            setStatus(root.dataset.empty);
            renderFeedback(root.dataset.empty);
            return;
        }
        root.dataset.state = 'results';
        setStatus(items.length === 1 ? root.dataset.resultOne : root.dataset.resultMany.replace('{count}', items.length));
        items.forEach((item, index) => {
            const link = document.createElement('a');
            link.className = 'school-search-result';
            link.href = item.url;
            link.style.setProperty('--search-index', index);
            const icon = document.createElement('i');
            const copy = document.createElement('span');
            const type = document.createElement('small');
            const title = document.createElement('strong');
            const excerpt = document.createElement('span');
            const arrow = document.createElement('b');
            icon.innerHTML = item.type === 'page'
                ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.5h7l3 3V20H7zM14 3.5V7h3M10 11h4M10 15h4"/></svg>'
                : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6.5h14M5 12h14M5 17.5h9"/></svg>';
            type.textContent = item.type === 'section' ? root.dataset.typeSection : root.dataset.typePage;
            highlight(title, item.title, query);
            highlight(excerpt, item.excerpt || '', query);
            arrow.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 7l5 5-5 5"/></svg>';
            copy.append(type, title, excerpt);
            link.append(icon, copy, arrow);
            link.addEventListener('click', close);
            results.append(link);
        });
    };

    const search = async query => {
        request?.abort();
        request = new AbortController();
        root.dataset.state = 'loading';
        setStatus(root.dataset.searching, true);
        renderSkeleton();
        try {
            const url = new URL(root.dataset.endpoint, location.origin);
            url.searchParams.set('locale', root.dataset.locale);
            url.searchParams.set('campus', document.body.dataset.campusId || '0');
            url.searchParams.set('q', query);
            const response = await fetch(url, {headers: {Accept: 'application/json'}, signal: request.signal});
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Search failed');
            render(Array.isArray(payload.data?.results) ? payload.data.results : [], query);
        } catch (error) {
            if (error.name === 'AbortError') return;
            root.dataset.state = 'error';
            setStatus(root.dataset.error);
            renderFeedback(root.dataset.error, true);
        }
    };

    trigger.addEventListener('click', open);
    closeButtons.forEach(button => button.addEventListener('click', close));
    clearButton.addEventListener('click', () => {
        input.value = '';
        clearButton.hidden = true;
        request?.abort();
        clearTimeout(timer);
        results.replaceChildren();
        root.dataset.state = 'idle';
        setStatus(root.dataset.hint);
        input.focus();
    });
    input.addEventListener('input', () => {
        const query = input.value.trim().replace(/\s+/g, ' ');
        request?.abort();
        clearTimeout(timer);
        clearButton.hidden = query.length === 0;
        if (query.length < minimumLength(query)) {
            root.dataset.state = query.length ? 'hint' : 'idle';
            results.replaceChildren();
            setStatus(root.dataset.hint);
            return;
        }
        root.dataset.state = 'loading';
        setStatus(root.dataset.searching, true);
        renderSkeleton();
        timer = setTimeout(() => search(query), 240);
    });
    input.addEventListener('keydown', event => {
        if (event.key !== 'ArrowDown') return;
        const first = resultLinks()[0];
        if (!first) return;
        event.preventDefault();
        event.stopPropagation();
        first.focus();
    });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            root.hidden ? open() : input.focus();
            return;
        }
        if (event.key === 'Escape' && !root.hidden) close();
        if (!root.hidden && (event.key === 'ArrowDown' || event.key === 'ArrowUp') && event.target instanceof Element && event.target.classList.contains('school-search-result')) {
            const links = resultLinks();
            const index = links.indexOf(document.activeElement);
            event.preventDefault();
            links[(index + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length]?.focus();
            return;
        }
        if (event.key !== 'Tab' || root.hidden) return;
        const focusable = [...root.querySelectorAll('button, input, a[href]')].filter(element => !element.hidden);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();

(()=>{
    const launch=document.querySelector('[data-school-chat-open]'),panel=document.querySelector('[data-school-chat]');
    if(!launch||!panel)return;
    const copy=(()=>{try{return JSON.parse(panel.dataset.chatCopy||'{}')}catch{return{}}})(),messages=panel.querySelector('[data-chat-messages]'),form=panel.querySelector('form'),nameInput=form.elements.name,messageInput=form.elements.message,send=form.querySelector('button[type="submit"]'),status=panel.querySelector('[data-chat-status]'),avatar=panel.querySelector('[data-chat-avatar] img');
    const soundName=/^notification-0[1-5]\.mp3$/.test(panel.dataset.messageSound||'')?panel.dataset.messageSound:'notification-03.mp3',notification=new Audio('/sensecms/audio/'+soundName);notification.preload='auto';
    let open=false,started=false,count=0,polling=false,initialized=false,ended=false;
    try{nameInput.value=localStorage.getItem('school-theme-chat-name')||''}catch{}
    const playNotification=()=>{notification.currentTime=0;notification.play().catch(()=>{})};
    const setOpen=value=>{open=value;panel.hidden=!value;launch.setAttribute('aria-expanded',String(value));launch.classList.toggle('is-open',value);if(value){launch.classList.remove('has-unread');refresh().finally(()=>{if(!ended)(nameInput.value?messageInput:nameInput).focus()})}},label=(role,state)=>role==='visitor'?copy.you:role==='agent'?(state.agent_name||copy.team):role==='assistant'?copy.assistant:copy.team;
    const empty=()=>{messages.innerHTML='';const intro=document.createElement('div');intro.className='school-chat-intro';intro.innerHTML='<i></i><strong></strong><p></p>';intro.querySelector('strong').textContent=copy.welcome;intro.querySelector('p').textContent=copy.intro;messages.append(intro)};
    const endConversation=()=>{ended=true;started=false;count=0;status.textContent=copy.ended;nameInput.disabled=true;messageInput.disabled=true;send.disabled=true;messages.innerHTML='';const notice=document.createElement('div');notice.className='school-chat-ended';notice.innerHTML='<i></i><strong></strong>';notice.querySelector('strong').textContent=copy.ended;messages.append(notice)};
    const render=state=>{
        if(state?.status==='closed'||state?.ended){endConversation();initialized=true;return}
        ended=false;messageInput.disabled=false;send.disabled=false;
        if(!state){started=false;count=0;status.textContent=copy.online;nameInput.disabled=false;if(avatar)avatar.src='/theme-assets/images/logo-square.svg';empty();initialized=true;return}
        started=true;const list=Array.isArray(state.messages)?state.messages:[],previous=count;count=list.length;messages.innerHTML='';
        for(const item of list){const row=document.createElement('div');row.className='school-chat-message is-'+item.role;const meta=document.createElement('span'),bubble=document.createElement('p');meta.textContent=label(item.role,state);bubble.textContent=item.content;row.append(meta,bubble);messages.append(row)}
        if(!list.length)empty();
        if(state.status==='assigned'&&state.agent_name){status.textContent=copy.connected.replace('{name}',state.agent_name);if(avatar&&state.agent_avatar)avatar.src=state.agent_avatar}else if(state.status==='queued')status.textContent=copy.waiting;else status.textContent=copy.online;
        nameInput.disabled=true;messages.scrollTop=messages.scrollHeight;
        const incoming=initialized&&count>previous&&['agent','assistant'].includes(list.at(-1)?.role);
        if(incoming){if(!open)launch.classList.add('has-unread');playNotification()}
        initialized=true;
    };
    const refresh=async()=>{if(polling)return;polling=true;try{const response=await fetch('/api/chat/state',{headers:{Accept:'application/json'},cache:'no-store'}),result=await response.json();if(response.ok&&result.ok&&!(ended&&result.data===null))render(result.data)}catch{}finally{polling=false}};
    launch.addEventListener('click',()=>setOpen(!open));panel.querySelector('[data-school-chat-close]').addEventListener('click',()=>setOpen(false));addEventListener('keydown',event=>{if(event.key==='Escape'&&open)setOpen(false)});
    form.addEventListener('submit',async event=>{event.preventDefault();if(ended)return;const name=nameInput.value.trim(),message=messageInput.value.trim();if(!name){nameInput.focus();return}if(!message){messageInput.focus();return}try{localStorage.setItem('school-theme-chat-name',name)}catch{}send.disabled=true;status.textContent=copy.sending;const data=new FormData();data.set('name',name);data.set('message',message);data.set('locale',panel.dataset.locale||'en');try{const response=await fetch('/api/ai/chat',{method:'POST',body:data,headers:{Accept:'application/json'}}),result=await response.json();if(!response.ok||result.error)throw new Error(result.error||copy.error);messageInput.value='';started=true;await refresh()}catch(error){status.textContent=copy.error;const notice=document.createElement('div');notice.className='school-chat-message is-system';const bubble=document.createElement('p');bubble.textContent=error.message||copy.error;notice.append(bubble);messages.append(notice);messages.scrollTop=messages.scrollHeight}finally{if(!ended){send.disabled=false;messageInput.focus()}}});
    empty();refresh();setInterval(()=>{if(started||open)refresh()},3500);document.addEventListener('visibilitychange',()=>{if(!document.hidden&&(started||open))refresh()});
})();

(()=>{const notify=(type,message)=>{let box=document.querySelector('.school-action-toasts');if(!box){box=document.createElement('div');box.className='school-action-toasts';box.setAttribute('aria-live','polite');document.body.append(box)}const item=document.createElement('article');item.className='school-action-toast is-'+type;item.innerHTML='<b></b><span></span><button type="button" aria-label="Close">×</button>';item.querySelector('b').textContent=type==='success'?'✓':'!';item.querySelector('span').textContent=message;item.querySelector('button').onclick=()=>item.remove();box.append(item);new Audio('/sensecms/audio/'+(type==='success'?'confirmation.mp3':'error.mp3')).play().catch(()=>{});setTimeout(()=>item.remove(),type==='success'?4500:8000)};document.querySelectorAll('[data-hero-slider]').forEach(root=>{const slides=[...root.querySelectorAll('.school-hero-slide')],dots=[...root.querySelectorAll('[data-slider-dot]')];if(slides.length<2)return;let index=0,timer;const show=next=>{slides[index].classList.remove('is-active');slides[index].setAttribute('aria-hidden','true');slides[index].querySelector('video')?.pause();dots[index]?.classList.remove('is-active');index=root.dataset.loop==='1'?(next+slides.length)%slides.length:Math.max(0,Math.min(slides.length-1,next));slides[index].classList.add('is-active');slides[index].setAttribute('aria-hidden','false');dots[index]?.classList.add('is-active');slides[index].querySelector('video')?.play().catch(()=>{})};const start=()=>{clearInterval(timer);if(root.dataset.autoplay==='1'&&(root.dataset.loop==='1'||index<slides.length-1)&&!matchMedia('(prefers-reduced-motion: reduce)').matches)timer=setInterval(()=>show(index+1),Number(root.dataset.interval||6)*1000)};root.querySelector('[data-slider-prev]')?.addEventListener('click',()=>{show(index-1);start()});root.querySelector('[data-slider-next]')?.addEventListener('click',()=>{show(index+1);start()});dots.forEach(dot=>dot.addEventListener('click',()=>{show(Number(dot.dataset.sliderDot));start()}));if(root.dataset.pauseHover==='1'){root.addEventListener('mouseenter',()=>clearInterval(timer));root.addEventListener('mouseleave',start)}root.addEventListener('keydown',event=>{if(event.key==='ArrowLeft')show(index-1);if(event.key==='ArrowRight')show(index+1)});start()});document.querySelectorAll('[data-school-contact-form]').forEach(form=>{const status=form.querySelector('[data-form-status]'),button=form.querySelector('button[type="submit"]'),captcha=form.querySelector('[data-form-captcha]');const refresh=url=>{if(captcha)captcha.src=url||(captcha.src.split('?')[0]+'?t='+Date.now())};form.querySelector('[data-form-captcha-refresh]')?.addEventListener('click',()=>refresh());form.addEventListener('submit',async event=>{event.preventDefault();if(button.disabled)return;button.disabled=true;status.classList.remove('is-error');status.textContent=form.dataset.sending||'Sending…';try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json','X-SenseCMS-Request':'1'}}),result=await response.json();if(!response.ok||!result.ok)throw Object.assign(new Error(result.message||'The message could not be sent.'),{data:result.data});status.textContent=result.message;form.reset();refresh(result.data?.captcha_url);notify('success',result.message)}catch(error){status.classList.add('is-error');status.textContent=error.message||form.dataset.error||'The message could not be sent.';notify('error',status.textContent);refresh(error.data?.captcha_url)}finally{button.disabled=false}})})})();

(()=>{const root=document.querySelector('[data-campus-switcher]'),body=document.body;if(!root)return;const toggle=root.querySelector('[data-campus-toggle]'),menu=root.querySelector('[data-campus-menu]'),items=()=>[...menu.querySelectorAll('[data-campus-option]')],close=()=>{root.classList.remove('is-open');toggle.setAttribute('aria-expanded','false');menu.hidden=true};toggle.addEventListener('click',()=>{const open=menu.hidden;menu.hidden=!open;root.classList.toggle('is-open',open);toggle.setAttribute('aria-expanded',open?'true':'false')});document.addEventListener('click',event=>{if(!root.contains(event.target))close()});document.addEventListener('keydown',event=>{if(event.key==='Escape')close()});let config={};try{config=JSON.parse(body.dataset.campusGeolocation||'{}')}catch{}const days=Math.max(1,Math.min(365,+config.remember_days||30)),preferenceKey='eduvixo-campus-preference',remember=id=>{try{localStorage.setItem(preferenceKey,JSON.stringify({id:+id,expires:Date.now()+days*86400000}))}catch{}},preference=()=>{try{const value=JSON.parse(localStorage.getItem(preferenceKey)||'null');if(value&&value.expires>Date.now())return value;localStorage.removeItem(preferenceKey)}catch{}return null};items().forEach(item=>item.addEventListener('click',()=>remember(item.dataset.campusId)));if(!config.enabled||!config.auto_prompt||items().length<2||!navigator.geolocation)return;const sessionKey='eduvixo-campus-location-requested';try{if(sessionStorage.getItem(sessionKey)==='1')return;sessionStorage.setItem(sessionKey,'1')}catch{}navigator.geolocation.getCurrentPosition(async position=>{try{const url=new URL('/api/campuses',location.origin);url.searchParams.set('locale',document.documentElement.lang||'en');url.searchParams.set('lat',position.coords.latitude.toFixed(7));url.searchParams.set('lng',position.coords.longitude.toFixed(7));const response=await fetch(url,{headers:{Accept:'application/json'}}),payload=await response.json();if(!response.ok||!payload.ok)return;const campuses=payload.data?.campuses||[],map=new Map(items().map(item=>[+item.dataset.campusId,item]));campuses.forEach(campus=>{const item=map.get(+campus.id);if(!item)return;const distance=item.querySelector('[data-campus-distance]');if(distance)distance.textContent=campus.distance_km===null?'':`${campus.distance_km} km`;menu.append(item)});const nearest=campuses.find(campus=>campus.distance_km!==null);if(config.mode==='redirect'&&nearest&&+nearest.id!==+body.dataset.campusId&&!preference()){remember(nearest.id);location.assign(nearest.url)}}catch{}},()=>{},{enableHighAccuracy:false,timeout:7000,maximumAge:300000})})();

(()=>{
    const menu=document.querySelector('.school-menu-button');
    const nav=document.querySelector('.school-nav nav');
    const header=document.querySelector('[data-school-header]');
    if(!menu||!nav||!header)return;
    const mobile=matchMedia('(max-width:900px)');
    const focusable=()=>[menu,...nav.querySelectorAll('a[href]'),...header.querySelectorAll('.school-nav-actions button,.school-nav-actions a[href]')].filter(element=>!element.hidden&&element.getClientRects().length);
    const setOpen=(open,{focus=false,restore=false}={})=>{
        const next=Boolean(open&&mobile.matches);
        nav.classList.toggle('is-open',next);
        menu.setAttribute('aria-expanded',String(next));
        menu.setAttribute('aria-label',next?(menu.dataset.closeLabel||'Close navigation'):(menu.dataset.openLabel||'Open navigation'));
        document.documentElement.classList.toggle('school-menu-open',next);
        document.body.classList.toggle('school-menu-open',next);
        if(next&&focus)nav.querySelector('a[href]')?.focus();
        if(!next&&restore)menu.focus();
    };
    menu.addEventListener('click',event=>{
        if(!mobile.matches)return;
        event.preventDefault();
        event.stopImmediatePropagation();
        setOpen(menu.getAttribute('aria-expanded')!=='true',{focus:false});
    },true);
    document.addEventListener('pointerdown',event=>{
        if(menu.getAttribute('aria-expanded')==='true'&&!header.contains(event.target))setOpen(false);
    },true);
    document.addEventListener('keydown',event=>{
        if(menu.getAttribute('aria-expanded')!=='true')return;
        if(event.key==='Escape'){
            event.preventDefault();
            setOpen(false,{restore:true});
            return;
        }
        if(event.key!=='Tab')return;
        const elements=focusable();
        if(!elements.length)return;
        const first=elements[0],last=elements[elements.length-1];
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}
    },true);
    nav.querySelectorAll('a[href]').forEach(link=>link.addEventListener('click',()=>setOpen(false),true));
    mobile.addEventListener?.('change',()=>setOpen(false));
})();
