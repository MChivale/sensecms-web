'use strict';
document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-async]')) return;
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    const status = form.querySelector('[role="status"]');
    if (button.disabled) return;
    button.disabled = true;
    if (status) { status.className = 'status'; status.textContent = 'Working…'; }
    try {
        const res = await fetch(form.action, { method: 'POST', body: new URLSearchParams(new FormData(form)), headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await res.json();
        if (data.redirect && /^\/(?!\/)/.test(data.redirect)) { location.assign(data.redirect); return; }
        if (status) { status.classList.add(res.ok ? 'success' : 'error'); status.textContent = data.message || 'Request could not be completed.'; }
        if (res.ok) form.querySelectorAll('input[type="password"]').forEach(input => { input.value = ''; });
    } catch { if (status) { status.classList.add('error'); status.textContent = 'The result is unknown. Check your connection and refresh before retrying.'; } }
    finally { button.disabled = false; }
});
const menu = document.querySelector('.menu-button');
const sidebar = document.querySelector('.sidebar');
menu?.addEventListener('click', () => { const open = sidebar.classList.toggle('open'); menu.setAttribute('aria-expanded', String(open)); });
document.addEventListener('keydown', event => { if (event.key === 'Escape' && sidebar?.classList.contains('open')) { sidebar.classList.remove('open'); menu.setAttribute('aria-expanded', 'false'); menu.focus(); } });
