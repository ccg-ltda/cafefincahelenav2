// Scrolls so the catalogue section starts right under the sticky header (its category tabs and products stay visible).
export function scrollToCatalog(behavior = 'smooth') {
    const section = document.querySelector('.catalog-section');
    if (!section) return;
    const header = document.querySelector('.demo-home header');
    const offset = header ? header.getBoundingClientRect().height : 0;
    window.scrollTo({ top: Math.max(0, section.getBoundingClientRect().top + window.scrollY - offset), behavior });
}
