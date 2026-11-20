import { savedCheckoutEmail } from './checkoutForm';

// The order a customer left pending when they went to pay in Wompi: only its reference, total and date are kept here (no personal data).
// The email that proves it is theirs is read from `cfh-checkout` (the form progress) when it is needed, never copied into this record.
const KEY = 'cfh-pending-order';
const REFERENCE = /^CFH-[A-Z0-9]{12}$/;

export function readPendingOrder() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(KEY) ?? 'null');
        return saved && REFERENCE.test(saved.reference ?? '') && Number.isSafeInteger(saved.total_cop) ? { reference: saved.reference, total_cop: saved.total_cop, created_at: saved.created_at ?? null } : null;
    } catch {
        return null;
    }
}

export function savePendingOrder(order) {
    try {
        window.localStorage.setItem(KEY, JSON.stringify({ reference: order.reference, total_cop: order.total_cop, created_at: new Date().toISOString() }));
    } catch {
        // Storage unavailable: the customer just does not get the reminder.
    }
}

export function clearPendingOrder() {
    try {
        window.localStorage.removeItem(KEY);
    } catch {
        // Nothing to clear.
    }
}

const post = async (url, record, email) => {
    const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ reference: record.reference, customer_email: email }) });
    return { status: response.status, ok: response.ok, body: await response.json().catch(() => ({})) };
};

/** pending | paid | cancelled | unknown; throws when the server cannot be reached (the record is kept and asked again later). */
export async function fetchPendingState(record) {
    const email = savedCheckoutEmail();
    if (!email) return { state: 'unknown' }; // without the email the server cannot confirm anything: the record is dropped
    const answer = await post('/api/orders/pending/status', record, email);
    if (!answer.ok) throw new Error('unavailable');
    return answer.body;
}

/** @returns {{state?: string, message?: string}} state `cancelled` | `paid` | `pending` (a payment is in process); otherwise a message */
export async function cancelPendingOrderRequest(record) {
    const email = savedCheckoutEmail();
    if (!email) return { state: 'unknown' };
    const answer = await post('/api/orders/pending/cancel', record, email);
    if (answer.ok) return { state: answer.body.state };
    if (answer.status === 404) return { state: 'unknown' };
    return { state: answer.body.state, message: answer.body.message || 'No pudimos cancelar el pedido. Inténtalo de nuevo en un momento.' };
}

/** A new payment attempt on the SAME order; leaves for Wompi. Returns an error message when it cannot. */
export async function payPendingOrder(record) {
    const email = savedCheckoutEmail();
    if (!email) return 'No pudimos iniciar el pago. Escríbenos por WhatsApp y te ayudamos.';
    try {
        const answer = await post('/api/payments/wompi/attempt', record, email);
        if (answer.ok && answer.body.checkout_url) { window.location.assign(answer.body.checkout_url); return null; }
        return answer.body.message || 'No pudimos iniciar el pago. Inténtalo de nuevo en un momento.';
    } catch {
        return 'No pudimos conectar con el servidor. Comprueba tu conexión e inténtalo de nuevo.';
    }
}
