import { Route, Routes, useLocation } from 'react-router';
import { lazy, Suspense, useEffect, useRef } from 'react';
import { StorefrontProvider } from './context/StorefrontContext';
import StorefrontLayout from './layouts/StorefrontLayout';
import HomePage from './modules/storefront/HomePage';
import ProductDetailPage from './modules/catalog/ProductDetailPage';
import NotFoundPage from './modules/storefront/NotFoundPage';
import { isAdminPath, storefrontRoutes } from './routes';

// The admin and the content pages (blog, origin) are separate chunks: a shopper never downloads the admin panel.
const AdminPage = lazy(() => import('./modules/admin/AdminPage'));
const OriginPage = lazy(() => import('./modules/content/ContentPages').then(module => ({ default: module.OriginPage })));
const BlogPage = lazy(() => import('./modules/content/ContentPages').then(module => ({ default: module.BlogPage })));
const ArticlePage = lazy(() => import('./modules/content/ContentPages').then(module => ({ default: module.ArticlePage })));

// The `page` of each entry in routes/spa.json picks its component; paths, layouts and titles live in that file.
const PAGES = {
    home: <HomePage />,
    promotions: <HomePage promotions />,
    product: <ProductDetailPage />,
    origin: <OriginPage />,
    blog: <BlogPage />,
    article: <ArticlePage />,
};

const SHOP_TABS = ['/', '/promociones'];

export default function App() {
    const { pathname } = useLocation();
    const previous = useRef(pathname);
    useEffect(() => {
        const from = previous.current;
        previous.current = pathname;
        // Moving between / and /promociones is only a tab change inside the catalogue: do not jump to the top.
        if (from !== pathname && SHOP_TABS.includes(from) && SHOP_TABS.includes(pathname)) return;
        window.scrollTo(0, 0);
    }, [pathname]);
    if (isAdminPath(pathname)) return <Suspense fallback={null}><AdminPage /></Suspense>;
    return <StorefrontProvider><Routes><Route element={<StorefrontLayout />}>
        {storefrontRoutes.map(route => <Route key={route.path} path={route.pattern} element={<Suspense fallback={null}>{PAGES[route.page]}</Suspense>} />)}
        <Route path="*" element={<NotFoundPage />} />
    </Route></Routes></StorefrontProvider>;
}
