'use strict';

// Notification delivery only: no page interception, offline cache or third-party code.
const destination = value => {
    try {
        const url = new URL(typeof value === 'string' ? value : '/dashboard', self.location.origin);
        if (url.origin === self.location.origin && !url.username && !url.password && /^\/(dashboard|settings|content|calendar|forms|conversations|surveys|system|license)(\/|$)/.test(url.pathname)) return url.href;
    } catch (_) {}
    return self.location.origin + '/dashboard';
};
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('push', event => {
    let data = {};
    try { data = event.data?.json() || {}; } catch (_) {}
    if (typeof data !== 'object' || Array.isArray(data)) data = {};
    const title = typeof data.title === 'string' ? data.title.slice(0, 180) : 'Sense CMS';
    const options = {
        body: typeof data.body === 'string' ? data.body.slice(0, 2000) : 'A new update is available in your workspace.',
        data: {url: destination(data.url)}
    };
    if (typeof data.tag === 'string') options.tag = data.tag.slice(0, 120);
    if(data.test===true) options.renotify=true;
    event.waitUntil((async()=>{
        let displayed=false;
        try { await self.registration.showNotification(title || 'Sense CMS', options); displayed=true; }
        finally {
            const windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});
            for(const client of windows) client.postMessage({type:'SENSECMS_PUSH_RECEIPT',displayed,at:Date.now()});
        }
    })());
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = destination(event.notification.data?.url);
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({type: 'window', includeUncontrolled: true});
        const existing = windows.find(client => client.url === url);
        if (existing) return existing.focus();
        return self.clients.openWindow(url);
    })());
});
