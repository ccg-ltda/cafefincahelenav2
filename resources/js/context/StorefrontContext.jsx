import { createContext, useContext, useEffect, useRef, useState } from 'react';

import { buildCatalog } from '../modules/catalog/catalogData';
import { clearCheckout, dropCheckoutKey } from '../modules/storefront/checkoutForm';
import { cancelPendingOrderRequest, clearPendingOrder, fetchPendingState, payPendingOrder, readPendingOrder } from '../modules/storefront/pendingOrder';

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
    // Two things must be known before the cart is judged against the stock, because the stock already lost the units of an order
    // that is not the cart's fault: the payment result page (`pageHold`) and a pending order the customer left behind (`pendingHold`).
    // The catalogue loaded meanwhile waits in `heldCatalog` until both let go; only then it may be applied (with its notices).
    const pageHold = useRef(false);
    const pendingHold = useRef(false);
    const heldCatalog = useRef(null);
    const reloadCatalog = useRef(() => {});
    // The pending order of this browser (reference + total) and what the server says about it: checking | pending.
    const [pendingOrder, setPendingOrder] = useState(() => {
        const record = readPendingOrder();
        pendingHold.current = record !== null;
        return record ? { ...record, state: 'checking', can_cancel: false } : null;
    });
    const [hasPromotions, setHasPromotions] = useState(false);
    const [products, setProducts] = useState([]);
    const [catalog, setCatalog] = useState([]);
    const [catalogStatus, setCatalogStatus] = useState('loading');
    const [drawer, setDrawer] = useState(false);
    const [checkout, setCheckout] = useState(false);
    const [policy, setPolicy] = useState(null);
    const applyReconcile = variants => {
        const reconciled = reconcileCart(cartRef.current, variants);
        setCart(reconciled.cart);
        setCartNotice([...new Set(reconciled.messages)]);
    };
    const applyHeldCatalog = () => {
        const variants = heldCatalog.current;
        heldCatalog.current = null;
        if (variants) applyReconcile(variants);
    };
    const holdCartReconcile = () => { pageHold.current = true; };
    const releaseCartReconcile = apply => {
        pageHold.current = false;
        if (!apply) heldCatalog.current = null;
        else if (!pendingHold.current) applyHeldCatalog();
    };
    // The pending order is over (paid, cancelled, expired or unknown): let go of the hold. `apply` = judge the cart against the stock now.
    const settlePending = apply => {
        pendingHold.current = false;
        setPendingOrder(null);
        if (!apply) heldCatalog.current = null;
        else if (!pageHold.current) applyHeldCatalog();
    };
    const orderIsPaid = () => {
        clearPendingOrder(); clearCheckout();
        try { window.localStorage.removeItem(CART_STORAGE_KEY); } catch { /* storage unavailable */ }
        setCart({});
        settlePending(false); // paid: the stock they "lost" is their own purchase, no notice
    };
    const orderIsGone = () => {
        clearPendingOrder(); dropCheckoutKey(); // a new order needs a new key, or the server would answer with the cancelled one
        settlePending(true);
    };
    useEffect(() => {
        const record = readPendingOrder();
        if (!record) return undefined;
        let alive = true;
        fetchPendingState(record).then(answer => {
            if (!alive) return;
            if (answer.state === 'pending') setPendingOrder({ ...record, total_cop: answer.total_cop ?? record.total_cop, state: 'pending', can_cancel: Boolean(answer.can_cancel) });
            else if (answer.state === 'paid') orderIsPaid();
            else orderIsGone(); // cancelled, expired or not recognised
        }).catch(() => {
            // The server could not be reached: keep the record and ask again next time; do not judge the cart on stock that includes this order.
            if (alive) { pendingHold.current = false; heldCatalog.current = null; setPendingOrder(current => (current ? { ...current, state: 'unreachable' } : current)); }
        });
        return () => { alive = false; };
    }, []);
    /** "Cancelar pedido y editar carrito". Returns an error message, or null when it is done. */
    const cancelPending = async () => {
        if (!pendingOrder) return null;
        try {
            const answer = await cancelPendingOrderRequest(pendingOrder);
            if (answer.state === 'cancelled' || answer.state === 'unknown') {
                // The units are back in the stock: judge the cart against a FRESH catalogue, never the one loaded while they were reserved.
                clearPendingOrder(); dropCheckoutKey(); settlePending(false); reloadCatalog.current();
                return null;
            }
            if (answer.state === 'paid') { orderIsPaid(); return null; }
            if (answer.state === 'pending') setPendingOrder(current => ({ ...current, can_cancel: false })); // a payment is in process
            return answer.message;
        } catch {
            return 'No pudimos conectar con el servidor. Comprueba tu conexión e inténtalo de nuevo.';
        }
    };
    const payPending = () => (pendingOrder ? payPendingOrder(pendingOrder) : Promise.resolve(null));
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
                if (pageHold.current || pendingHold.current) heldCatalog.current = variants;
                else applyReconcile(variants);
                setCatalogStatus('ready');
            } catch (error) {
                if (!controller.signal.aborted) {
                    setCatalogStatus('error');
                    retryTimeout = window.setTimeout(loadCatalog, 30000);
                }
            }
        }
        reloadCatalog.current = () => { window.clearTimeout(retryTimeout); loadCatalog(); };
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
    return <StorefrontContext.Provider value={{ search, setSearch, category, selectCategory, subcategory, setSubcategory, products, catalog, catalogStatus, hasPromotions, cart, changeQuantity, cartNotice, holdCartReconcile, releaseCartReconcile, orderPaid: orderIsPaid, pendingOrder, cancelPending, payPending, dismissCartNotice: () => setCartNotice([]), items, drawer, setDrawer, checkout, setCheckout, policy, setPolicy }}>{children}</StorefrontContext.Provider>;
}
export const useStorefront = () => useContext(StorefrontContext);
