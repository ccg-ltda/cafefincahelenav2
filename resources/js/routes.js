import { matchPath } from 'react-router';
import spa from '../../routes/spa.json';

// The pages of the app come from routes/spa.json, the same file Laravel reads (App\Support\SpaRoutes):
// add or rename a page there and PHP and React stay in sync.
const toPattern = path => path.replace(/\{(\w+)\}/g, ':$1');
const match = (pattern, pathname) => matchPath({ path: pattern, end: true }, pathname);

export const site = spa.site;

export const storefrontRoutes = spa.storefront.map(route => ({ ...route, pattern: toPattern(route.path) }));

const adminPatterns = [spa.admin.login, ...(spa.admin.public ?? []), ...spa.admin.pages].map(page => toPattern(page.path));

// Admin parameters in the list are record ids: a non-numeric one is not an admin page.
export const isAdminPath = pathname => adminPatterns.some(pattern => {
    const found = match(pattern, pathname);
    return found !== null && Object.values(found.params).every(value => /^\d+$/.test(value));
});

const storefrontMatch = pathname => {
    for (const route of storefrontRoutes) {
        const found = match(route.pattern, pathname);
        if (found) return { route, params: found.params };
    }
    return null;
};

/**
 * Layout of a storefront page: shop (home, promotions, product), origin, blog or article.
 * Every path that is not an own page (the 404) uses the shop layout, so no storefront URL loses its styles.
 */
export const layoutFor = pathname => storefrontMatch(pathname)?.route.layout ?? 'shop';

/** Title and description to show for a path (the product page sets its own). */
export function metaFor(pathname) {
    const found = storefrontMatch(pathname);
    if (!found) return { title: `Página no encontrada | ${site}` };
    if (found.route.page === 'article') {
        const article = spa.articles.find(item => item.slug === found.params.slug.replace(/\.html$/, ''));
        return article ? { title: `${article.title} | ${site}`, description: article.description } : { title: `Página no encontrada | ${site}` };
    }
    return found.route.title ? { title: found.route.title, description: found.route.description } : null;
}

// Admin page permissions (the `permission` of each page in routes/spa.json; none = any signed-in person).
// The server enforces them (HTTP 403); this only lets React show "No tienes acceso" and hide menu entries.
const adminPermissionOf = pathname => {
    for (const page of spa.admin.pages) {
        const found = match(toPattern(page.path), pathname);
        if (found && Object.values(found.params).every(value => /^\d+$/.test(value))) return page.permission || null;
    }
    return null;
};
export const adminPagePermission = adminPermissionOf;
