import { Route, Routes, useLocation } from 'react-router';
import { useEffect } from 'react';
import { StorefrontProvider } from './context/StorefrontContext';
import StorefrontLayout from './layouts/StorefrontLayout';
import HomePage from './modules/storefront/HomePage';
import { BlogPage, ArticlePage, OriginPage } from './modules/content/ContentPages';

export default function App() {
    const { pathname } = useLocation();
    useEffect(() => { window.scrollTo(0, 0); }, [pathname]);
    return <StorefrontProvider><Routes><Route element={<StorefrontLayout />}>
        <Route index element={<HomePage />} />
        <Route path="sello-de-origen" element={<OriginPage />} />
        <Route path="sello-de-origen.html" element={<OriginPage />} />
        <Route path="blog" element={<BlogPage />} />
        <Route path="blog.html" element={<BlogPage />} />
        <Route path="blog/:slug" element={<ArticlePage />} />
        <Route path="*" element={<div className="not-found"><h1>Página no encontrada</h1></div>} />
    </Route></Routes></StorefrontProvider>;
}
