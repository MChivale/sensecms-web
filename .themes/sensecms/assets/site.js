'use strict';
const menu = document.querySelector('.menu-button');
const nav = document.querySelector('#navigation');
function closeMenu() { menu.setAttribute('aria-expanded', 'false'); nav.classList.remove('open'); }
menu.addEventListener('click', () => {
    const open = menu.getAttribute('aria-expanded') !== 'true';
    menu.setAttribute('aria-expanded', String(open)); nav.classList.toggle('open', open);
});
document.addEventListener('keydown', event => { if (event.key === 'Escape' && nav.classList.contains('open')) { closeMenu(); menu.focus(); } });
nav.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
matchMedia('(min-width: 901px)').addEventListener('change', closeMenu);
