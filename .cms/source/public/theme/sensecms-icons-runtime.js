(()=>{
    'use strict';

    const lucide=window.lucide;
    if(!lucide?.createIcons||lucide.createIcons.__sensecmsOptimized)return;

    const create=lucide.createIcons.bind(lucide);
    const clean=root=>root?.querySelectorAll?.('svg[data-lucide]').forEach(icon=>icon.removeAttribute('data-lucide'));
    const render=(root=document,options={})=>{
        if(!root?.querySelectorAll)return;
        clean(root);
        const result=create({...options,root});
        clean(root);
        return result;
    };
    const optimized=(options={})=>render(options?.root||document,options||{});

    optimized.__sensecmsOptimized=true;
    lucide.createIcons=optimized;
    window.SenseCMSIcons=Object.freeze({render});
})();
