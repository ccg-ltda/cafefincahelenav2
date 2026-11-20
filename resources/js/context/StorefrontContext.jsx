import { createContext, useContext, useEffect, useRef, useState } from 'react';

import { buildCatalog } from '../modules/catalog/catalogData';

export const StorefrontContext = createContext(null);
const CART_STORAGE_KEY = 'cfh-cart';
const isPositiveInteger = value => Number.isSafeInteger(value) && value > 0;

function readStoredCart() {
    try {
        const stored = JSON.parse(window.localStorage.getItem(CART_STORAGE_KEY) ?? '[]');
        if (!Array.isArray(stored)) return {};

        return stored.reduce((cart, line) => {
            if (line && isPositiveInteger(line.id) && isPositiveInteger(line.quantity) && !Object.hasOwn(cart, line.id)) {
                cart[line.id] = line.quantity;
            }
            return cart;
        }, {});
    } catch {
        return {};
    }
}

const units = count => `${count} ${count === 1 ? 'unidad' : 'unidades'}`;

/** Adjusts a stored cart to the catalogue just loaded: unknown or sold-out variants are removed and quantities above the stock are lowered. */
function reconcileCart(cart, variants) {
    const byId = new Map(variants.map(variant => [variant.id, variant]));
    const next = {};
    const messages = [];
    for (const [id, quantity] of Object.entries(cart)) {
        const variant = byId.get(Number(id));
        if (!variant) messages.push('Quitamos de tu carrito un producto que ya no está disponible.');
        else if (variant.available <= 0) messages.push(`Quitamos «${variant.name}» de tu carrito porque se agotó.`);
        else if (quantity > variant.available) { next[id] = variant.available; messages.push(`Ajustamos «${variant.name}» a ${units(variant.available)}: es lo que queda disponible.`); }
        else next[id] = quantity;
    }
    return { cart: next, messages };
}

export function StorefrontProvider({ children }) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('Café');
    const [subcategory, setSubcategory] = useState('Todas');
    const [cart, setCart] = useState(readStoredCart);
    const cartRef = useRef(cart);
    cartRef.current = cart;
    const [cartNotice, setCartNotice] = useState([]);
    const [hasPromotions, setHasPromotions] = useState(false);
    const [products, setProducts] = useState([]);
    const [catalog, setCatalog] = useState([]);
    const [catalogStatus, setCatalogStatus] = useState('loading');
    const [drawer, setDrawer] = useState(false);
    const [checkout, setCheckout] = useState(false);
    const [policy, setPolicy] = useState(null);
    useEffect(() => {
        const controller = new AbortController();
        let retryTimeout;
        async function loadCatalog() {
            try {
                const response = await fetch('/api/products', { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('No se pudo cargar el catálogo');
                const result = await response.json();
                if (!Array.isArray(result.data)) throw new Error('Respuesta de catálogo inválida');
                const { variants, grouped } = buildCatalog(result.data);
                if (controller.signal.aborted) return;
                setProducts(variants);
                setCatalog(grouped);
                setHasPromotions(Boolean(result.meta?.has_promotions));
                const reconciled = reconcileCart(cartRef.current, variants);
                setCart(reconciled.cart);
                setCartNotice([...new Set(reconciled.messages)]);
                setCatalogStatus('ready');
            } catch (error) {
                if (!controller.signal.aborted) {
                    setCatalogStatus('error');
                    retryTimeout = window.setTimeout(loadCatalog, 30000);
                }
            }
        }
        loadCatalog();
        return () => { controller.abort(); window.clearTimeout(retryTimeout); };
    }, []);
    useEffect(() => {
        if (catalogStatus !== 'ready') return;
        const availableIds = new Set(products.map(product => product.id));
        const lines = Object.entries(cart)
            .map(([id, quantity]) => ({ id: Number(id), quantity }))
            .filter(line => availableIds.has(line.id) && isPositiveInteger(line.id) && isPositiveInteger(line.quantity));
        try {
            window.localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(lines));
        } catch {
            // The cart remains available in memory if browser storage is unavailable.
        }
    }, [cart, catalogStatus, products]);
    const changeQuantity = (id, quantity) => {
        if (!isPositiveInteger(id) || !Number.isSafeInteger(quantity)) return;
        // Never above the stock the catalogue reports; a sold-out variant cannot be added.
        const available = products.find(product => product.id === id)?.available;
        if (available !== undefined && quantity > 0) {
            if (available <= 0) return;
            quantity = Math.min(quantity, available);
        }
        setCart(current => {
            const next = { ...current };
            if (quantity > 0) next[id] = quantity;
            else delete next[id];
            return next;
        });
    };
    const items = products.filter(p => cart[p.id] > 0).map(p => ({ ...p, quantity: cart[p.id] }));
    const selectCategory = value => { setCategory(value); setSubcategory('Todas'); setSearch(''); };
    return <StorefrontContext.Provider value={{ search, setSearch, category, selectCategory, subcategory, setSubcategory, products, catalog, catalogStatus, hasPromotions, cart, changeQuantity, cartNotice, dismissCartNotice: () => setCartNotice([]), items, drawer, setDrawer, checkout, setCheckout, policy, setPolicy }}>{children}</StorefrontContext.Provider>;
}
export const useStorefront = () => useContext(StorefrontContext);
