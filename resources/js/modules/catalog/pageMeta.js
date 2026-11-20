const upsert = (selector, create) => {
    let node = document.head.querySelector(selector);
    if (!node) { node = create(); document.head.appendChild(node); }
    return node;
};

const metaTag = (attribute, name) => upsert(`meta[${attribute}="${name}"]`, () => { const node = document.createElement('meta'); node.setAttribute(attribute, name); return node; });

/**
 * Updates title, description, canonical and Open Graph tags for client-side navigation and returns
 * a function that restores the previous values. The server already renders them for direct visits:
 * when the canonical already points to this page nothing is touched.
 */
export function applyPageMeta({ title, description, path, image }) {
    const existing = document.head.querySelector('link[rel="canonical"]');
    if (existing && new URL(existing.href, window.location.origin).pathname === path) return () => {};
    const origin = existing ? new URL(existing.href).origin : window.location.origin;
    const canonical = upsert('link[rel="canonical"]', () => { const node = document.createElement('link'); node.rel = 'canonical'; return node; });
    const tags = [
        [metaTag('name', 'description'), description],
        [metaTag('property', 'og:title'), title],
        [metaTag('property', 'og:description'), description],
        [metaTag('property', 'og:url'), `${origin}${path}`],
        [metaTag('property', 'og:type'), 'product'],
        ...(image ? [[metaTag('property', 'og:image'), image.startsWith('http') ? image : `${origin}${image}`]] : []),
    ];
    const previous = { title: document.title, canonical: canonical.getAttribute('href'), tags: tags.map(([node]) => [node, node.getAttribute('content')]) };
    document.title = title;
    canonical.setAttribute('href', `${origin}${path}`);
    tags.forEach(([node, content]) => node.setAttribute('content', content));
    return () => {
        document.title = previous.title;
        if (previous.canonical === null) canonical.remove(); else canonical.setAttribute('href', previous.canonical);
        previous.tags.forEach(([node, content]) => { if (content === null) node.remove(); else node.setAttribute('content', content); });
    };
}

/**
 * Title and description of a page without canonical data (home, blog, origin, promotions) for client-side
 * navigation. The server already renders them for direct visits. Returns a function that restores the previous values.
 */
export function applyStaticMeta({ title, description }) {
    const tag = metaTag('name', 'description');
    const previous = { title: document.title, description: tag.getAttribute('content') };
    document.title = title;
    if (description) tag.setAttribute('content', description);
    return () => {
        document.title = previous.title;
        if (previous.description === null) tag.remove(); else tag.setAttribute('content', previous.description);
    };
}
