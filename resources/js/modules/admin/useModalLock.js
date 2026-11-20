import { useEffect } from 'react';

// Shared page lock for modal dialogs and drawers (reference counted, so nested dialogs are safe).
//
// Only <html> is touched. Setting overflow:hidden on <body> as well turns <body> into a scroll
// container: the sticky sidebar (position:sticky inside <body>) then loses its scrolling ancestor
// and jumps back to its static position. The scrollbar width stays reserved (scrollbar-gutter,
// with padding as a fallback) so removing the scrollbar does not reflow the page, the scroll
// offset is untouched, and #root is made inert so the background cannot be reached.
let locks = 0;
let saved = null;

function lock() {
    if (locks++ > 0) return;
    const html = document.documentElement;
    const root = document.getElementById('root');
    const gap = window.innerWidth - html.clientWidth;
    saved = { overflow: html.style.overflow, gutter: html.style.scrollbarGutter, padding: html.style.paddingRight, inert: root?.hasAttribute('inert') ?? false };
    if (window.CSS?.supports?.('scrollbar-gutter', 'stable')) html.style.scrollbarGutter = 'stable';
    else if (gap > 0) html.style.paddingRight = `${gap}px`;
    html.style.overflow = 'hidden';
    root?.setAttribute('inert', '');
}

function unlock() {
    if (--locks > 0) return;
    const html = document.documentElement;
    const root = document.getElementById('root');
    html.style.overflow = saved.overflow;
    html.style.scrollbarGutter = saved.gutter;
    html.style.paddingRight = saved.padding;
    if (!saved.inert) root?.removeAttribute('inert');
    saved = null;
}

export default function useModalLock() {
    useEffect(() => { lock(); return unlock; }, []);
}
