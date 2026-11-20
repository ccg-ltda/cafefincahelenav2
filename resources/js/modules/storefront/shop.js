// Store contact data and the WhatsApp helpers of the storefront.
// The WhatsApp number comes from the server config (SHOP_WHATSAPP_NUMBER), published by the layout in
// <meta name="shop-whatsapp">: no component writes the number by hand.

/** Digits of the store's WhatsApp number (country code included), or '' when it is missing or invalid. */
export function shopWhatsapp() {
    const number = (document.querySelector('meta[name="shop-whatsapp"]')?.content || '').replace(/\D/g, '');
    return /^[1-9][0-9]{7,14}$/.test(number) ? number : '';
}

/**
 * Whether online payments are available. The server decides (<meta name="shop-payments">, 1 only with valid Wompi keys) and
 * publishes a yes/no: no key of any kind reaches the browser. Without payments the checkout does not take orders.
 */
export const paymentsEnabled = () => document.querySelector('meta[name="shop-payments"]')?.content === '1';

/** "573027880823" -> "3027880823" (the number as people write it in Colombia). */
export const nationalNumber = number => (number.startsWith('57') && number.length === 12 ? number.slice(2) : number);

/** wa.me link with the message already written. encodeURIComponent keeps accents, ¡ ¿ and emojis intact (UTF-8). */
export function whatsappLink(message) {
    const number = shopWhatsapp();
    return number ? `https://wa.me/${number}${message ? `?text=${encodeURIComponent(message)}` : ''}` : '';
}

// ---- Reference of the order the customer just made in this browser (used by the "order support" option) ----
const LAST_ORDER_KEY = 'cfh-last-order';
const LAST_ORDER_DAYS = 7;

export function rememberLastOrder(reference) {
    try { window.localStorage.setItem(LAST_ORDER_KEY, JSON.stringify({ reference, at: Date.now() })); } catch { /* storage unavailable: the menu just says CFH-… */ }
}

export function lastOrderReference() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(LAST_ORDER_KEY) ?? 'null');
        const fresh = saved && Date.now() - saved.at < LAST_ORDER_DAYS * 86400000;
        return fresh && /^CFH-[A-Z0-9]{12}$/.test(saved.reference) ? saved.reference : null;
    } catch {
        return null;
    }
}

/** Counts a menu click (topic and page only, nothing personal). It never throws and never makes the customer wait. */
export function trackWhatsappClick(topic, path) {
    try {
        window.fetch('/api/whatsapp/clicks', {
            method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ topic, page_path: path }),
        }).catch(() => {});
    } catch { /* tracking must never block WhatsApp */ }
}
