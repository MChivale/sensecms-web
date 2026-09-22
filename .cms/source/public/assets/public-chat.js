(() => {
    const widget = document.querySelector('[data-public-chat]');
    if (!widget) return;
    const find = key => widget.querySelector(`[data-chat-${key}]`);
    const open = find('open'), close = find('close'), panel = document.getElementById('sense-chat-panel');
    const form = find('form'), messages = find('messages'), status = find('status'), fresh = find('new'), intro = find('intro'), emailWrap = find('email');
    const labels = JSON.parse(widget.dataset.labels), input = form.elements.message, email = form.elements.email, submit = form.querySelector('[type=submit]');
    let timer = 0, polling = false, sending = false, ended = false, restarting = false, queued = false, emailRequested = false, seen = new Set();
    const request = async (path, data) => {
        const abort = new AbortController(), timeout = setTimeout(() => abort.abort(), 15000);
        try {
            const response = await fetch(path, {method: data ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}, body: data, signal: abort.signal});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || result.message || labels.error);
            return result;
        } finally { clearTimeout(timeout); }
    };
    const setStatus = (message = '', type = '') => {
        status.textContent = message;
        status.className = 'sense-chat-status' + (type ? ` is-${type}` : '');
    };
    const controls = () => {
        submit.disabled = sending || ended;
        input.disabled = ended;
        fresh.hidden = !ended;
        submit.setAttribute('aria-label', sending ? labels.sending : labels.send);
    };
    const autosize = () => { input.style.height = 'auto'; input.style.height = `${Math.min(input.scrollHeight, 112)}px`; };
    const messageRow = message => {
        const row = document.createElement('div');
        row.className = 'sense-chat-message';
        row.dataset.role = ['visitor','agent','assistant','system'].includes(message.role) ? message.role : 'system';
        const bubble = document.createElement('div');
        if (message.agent_name && message.role === 'agent') {
            const name = document.createElement('strong'); name.textContent = message.agent_name; bubble.append(name);
        }
        const text = document.createElement('p'), parts = String(message.content || '').split(/\n(Source:\s+[^\n]+)/g);
        for (const part of parts) {
            const source = part.match(/^Source:\s+(\/[^\s]+)$/);
            if (source) {
                const link = document.createElement('a'); link.href = source[1]; link.textContent = labels.source || 'Source'; link.className = 'sense-chat-source'; text.append(link);
            } else if (part) text.append(document.createTextNode(part));
        }
        bubble.append(text);
        if (message.created_at) { const time = document.createElement('time'); time.textContent = String(message.created_at).slice(11, 16); bubble.append(time); }
        row.append(bubble);
        return row;
    };
    const render = state => {
        if (!state) return;
        const nearEnd = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 80;
        for (const message of state.messages || []) {
            if (seen.has(message.id)) continue;
            seen.add(message.id);
            messages.append(messageRow(message));
        }
        intro.hidden = seen.size > 0;
        if (nearEnd) messages.scrollTop = messages.scrollHeight;
        ended = state.status === 'closed';
        queued = state.status === 'queued';
        const requestEmail = Boolean(state.email_requested);
        if (requestEmail !== emailRequested) { emailRequested = requestEmail; emailWrap.hidden = !emailRequested; email.required = emailRequested; input.required = !emailRequested; if (emailRequested && !panel.hidden) setTimeout(() => email.focus(), 0); }
        if (ended) setStatus(labels.ended);
        else if (queued && state.ai_takeover_at) setStatus((labels.waiting || 'Team notified · AI joins in {seconds}s').replace('{seconds}', Math.max(0, Number(state.takeover_seconds_remaining || 0))), 'waiting');
        else if (queued) setStatus(labels.waitingNoAi || 'Team notified · waiting for an operator', 'waiting');
        else if (status.classList.contains('is-waiting')) setStatus(labels.assistant || 'AI assistant joined the conversation');
        controls();
    };
    const schedule = () => {
        clearTimeout(timer); timer = 0;
        if (!panel.hidden && !ended) timer = setTimeout(poll, queued ? 1000 : 3500);
    };
    const poll = async () => {
        if (polling || sending || panel.hidden || document.hidden || ended || restarting) { schedule(); return; }
        polling = true;
        try {
            const result = await request('/api/chat/state'); render(result.data);
            if (!ended && status.textContent === labels.refresh) setStatus();
        } catch { if (!sending) setStatus(labels.refresh, 'error'); }
        finally { polling = false; schedule(); }
    };
    const toggle = show => {
        panel.hidden = !show; open.setAttribute('aria-expanded', String(show)); clearTimeout(timer); timer = 0;
        if (show) { input.focus(); poll(); } else open.focus();
    };
    open.addEventListener('click', () => toggle(panel.hidden));
    close.addEventListener('click', () => toggle(false));
    widget.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); toggle(false); } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    input.addEventListener('input', autosize);
    input.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); form.requestSubmit(); } });
    fresh.addEventListener('click', () => { restarting = true; ended = false; queued = false; emailRequested = false; emailWrap.hidden = true; email.required = false; input.required = true; email.value = ''; seen.clear(); messages.replaceChildren(); intro.hidden = false; setStatus(); controls(); input.focus(); });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (sending || ended || (!input.value.trim() && !email.value.trim()) || !form.reportValidity()) return;
        sending = true; controls(); setStatus();
        const body = new URLSearchParams({message: input.value.trim(), name: form.elements.name.value.trim(), email: email.value.trim(), locale: widget.dataset.locale, csrf: widget.dataset.csrf});
        try {
            await request(widget.dataset.endpoint || '/api/chat/message', body); input.value = ''; input.style.height = ''; restarting = false;
            const result = await request('/api/chat/state'); render(result.data); messages.scrollTop = messages.scrollHeight;
        } catch (error) { setStatus(error.name === 'AbortError' ? labels.error : error.message || labels.error, 'error'); }
        finally { sending = false; controls(); schedule(); if (!panel.hidden) input.focus(); }
    });
})();
