import { Route, Routes, useLocation } from 'react-router';
import { useEffect, useRef } from 'react';
import { StorefrontProvider } from './context/StorefrontContext';
import StorefrontLayout from './layouts/StorefrontLayout';
import HomePage from './modules/storefront/HomePage';
import ProductDetailPage from './modules/catalog/ProductDetailPage';
import { BlogPage, ArticlePage, OriginPage } from './modules/content/ContentPages';
import AdminPage from './modules/admin/AdminPage';
import NotFoundPage from './modules/storefront/NotFoundPage';
import { isAdminPath, storefrontRoutes } from './routes';

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
    if (isAdminPath(pathname)) return <AdminPage />;
    return <StorefrontProvider><Routes><Route element={<StorefrontLayout />}>
        {storefrontRoutes.map(route => <Route key={route.path} path={route.pattern} element={PAGES[route.page]} />)}
        <Route path="*" element={<NotFoundPage />} />
    </Route></Routes></StorefrontProvider>;
}
