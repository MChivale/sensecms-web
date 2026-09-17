(() => {
    const widget = document.querySelector('[data-public-chat]');
    if (!widget) return;
    const find = key => widget.querySelector(`[data-chat-${key}]`);
    const open = find('open'), close = find('close'), panel = document.getElementById('sense-chat-panel');
    const form = find('form'), messages = find('messages'), status = find('status'), fresh = find('new');
    const labels = JSON.parse(widget.dataset.labels), input = form.elements.message, submit = form.querySelector('[type=submit]');
    let timer = null, polling = false, sending = false, ended = false, restarting = false, seen = new Set();
    const request = async (path, data) => {
        const abort = new AbortController(), timeout = setTimeout(() => abort.abort(), 15000);
        try {
            const response = await fetch(path, {method: data ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}, body: data, signal: abort.signal});
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || result.message || labels.error);
            return result;
        } finally { clearTimeout(timeout); }
    };
    const controls = () => { submit.disabled = sending || ended; input.disabled = ended; fresh.hidden = !ended; submit.textContent = sending ? labels.sending : labels.send; };
    const render = state => {
        if (!state) return;
        const nearEnd = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 70;
        for (const message of state.messages || []) {
            if (seen.has(message.id)) continue;
            seen.add(message.id);
            const row = document.createElement('div'); row.className = 'sense-chat-message';
            row.dataset.role = ['visitor','agent','assistant','system'].includes(message.role) ? message.role : 'system';
            if (message.agent_name && message.role === 'agent') { const name = document.createElement('strong'); name.textContent = message.agent_name; row.append(name); }
            const text = document.createElement('p'); text.textContent = message.content; row.append(text); messages.append(row);
        }
        if (nearEnd) messages.scrollTop = messages.scrollHeight;
        ended = state.status === 'closed'; if (ended) status.textContent = labels.ended;
        controls();
    };
    const poll = async () => {
        if (polling || sending || panel.hidden || document.hidden || ended || restarting) return;
        polling = true;
        try { const result = await request('/api/chat/state'); render(result.data); if (!ended && status.textContent === labels.refresh) status.textContent = ''; }
        catch { if (!sending) status.textContent = labels.refresh; }
        finally { polling = false; }
    };
    const toggle = show => {
        panel.hidden = !show; open.setAttribute('aria-expanded', String(show)); clearInterval(timer); timer = null;
        if (show) { input.focus(); poll(); timer = setInterval(poll, 4000); } else open.focus();
    };
    open.addEventListener('click', () => toggle(panel.hidden)); close.addEventListener('click', () => toggle(false));
    widget.addEventListener('keydown', event => { if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); toggle(false); } });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    fresh.addEventListener('click', () => { restarting = true; ended = false; seen.clear(); messages.replaceChildren(); status.textContent = ''; controls(); input.focus(); });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (sending || ended || !input.value.trim() || !form.reportValidity()) return;
        sending = true; controls(); status.textContent = '';
        const body = new URLSearchParams({message: input.value.trim(), name: form.elements.name.value.trim(), locale: widget.dataset.locale, csrf: widget.dataset.csrf});
        try {
            await request('/api/chat/message', body); input.value = ''; restarting = false;
            const result = await request('/api/chat/state'); render(result.data); messages.scrollTop = messages.scrollHeight;
        } catch (error) { status.textContent = error.name === 'AbortError' ? labels.error : error.message || labels.error; }
        finally { sending = false; controls(); if (!panel.hidden) input.focus(); }
    });
})();
