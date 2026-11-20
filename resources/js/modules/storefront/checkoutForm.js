// Checkout form rules and persistence. The backend (App\Http\Requests\StoreOrderRequest) enforces the same
// rules: keep both in sync.

// Keep in sync with StoreOrderRequest::DEPARTMENTS.
export const DEPARTMENTS = [
    'Amazonas', 'Antioquia', 'Arauca', 'Atlántico', 'Bogotá D.C.', 'Bolívar', 'Boyacá', 'Caldas', 'Caquetá', 'Casanare',
    'Cauca', 'Cesar', 'Chocó', 'Córdoba', 'Cundinamarca', 'Guainía', 'Guaviare', 'Huila', 'La Guajira', 'Magdalena',
    'Meta', 'Nariño', 'Norte de Santander', 'Putumayo', 'Quindío', 'Risaralda', 'San Andrés y Providencia', 'Santander',
    'Sucre', 'Tolima', 'Valle del Cauca', 'Vaupés', 'Vichada',
];

export const EMPTY_FORM = {
    customer_name: '', customer_email: '', customer_phone: '',
    delivery_department: '', delivery_city: '', delivery_address: '', delivery_details: '', order_notes: '',
};

export const EMPTY_CONSENT = { terms_accepted: false, data_consent: false, marketing_opt_in: false }; // the last one is optional and never pre-ticked

// Step of the form each field belongs to (backend 422 errors use these names).
export const stepOf = field => (field.startsWith('customer_') ? 1 : field.startsWith('delivery_') || field === 'order_notes' ? 2 : 3);

// "300 123 4567", "300-123-4567" and "+57 300 123 4567" all become "3001234567".
export const normalizePhone = value => String(value ?? '').replace(/[\s().-]/g, '').replace(/^(\+57|57)(?=3\d{9}$)/, '');

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

const FIELD_RULES = {
    customer_name: value => (!value.trim() ? 'Escribe tu nombre completo.' : value.trim().length < 3 ? 'El nombre debe tener al menos 3 caracteres.' : ''),
    customer_email: value => (!value.trim() ? 'Escribe tu correo electrónico.' : !EMAIL.test(value.trim()) ? 'Escribe un correo válido, por ejemplo nombre@correo.com.' : ''),
    customer_phone: value => (!value.trim() ? 'Escribe tu número de celular.' : !/^3\d{9}$/.test(normalizePhone(value)) ? 'Ingresa un celular colombiano de 10 dígitos que empiece por 3.' : ''),
    delivery_department: value => (DEPARTMENTS.includes(value) ? '' : 'Selecciona tu departamento.'),
    delivery_city: value => (!value.trim() ? 'Escribe tu ciudad.' : ''),
    delivery_address: value => (!value.trim() ? 'Escribe tu dirección de entrega.' : ''),
};

const STEP_FIELDS = { 1: ['customer_name', 'customer_email', 'customer_phone'], 2: ['delivery_department', 'delivery_city', 'delivery_address'] };

/** Returns { field: message } for the fields of `step` that are invalid. Step 3 checks the legal acceptance. */
export function validateStep(step, data, consent) {
    const errors = {};
    for (const field of STEP_FIELDS[step] ?? []) {
        const message = FIELD_RULES[field](data[field] ?? '');
        if (message) errors[field] = message;
    }
    if (step === 3) {
        if (!consent.terms_accepted) errors.terms_accepted = 'Debes aceptar los términos y condiciones.';
        if (!consent.data_consent) errors.data_consent = 'Debes autorizar el tratamiento de tus datos personales.';
    }
    return errors;
}

// ---- Persistence: form progress and idempotency key survive reloads and closing the modal. ----
const STORAGE_KEY = 'cfh-checkout';
const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`).replace(/[^A-Za-z0-9-]/g, '');

/** Same cart content (variant ids and quantities) gives the same signature. */
export const cartSignature = items => items.map(item => `${item.id}:${item.quantity}`).sort().join(',');

/**
 * Restores the saved progress. The idempotency key is reused only while the cart content is unchanged;
 * otherwise a new key is generated (the typed data is kept). Legal acceptance is never restored.
 */
export function loadCheckout(signature) {
    let saved = null;
    try {
        saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null');
    } catch {
        saved = null;
    }
    const valid = saved && typeof saved === 'object' && saved.v === 1 && saved.data && typeof saved.data === 'object';
    const data = { ...EMPTY_FORM };
    if (valid) for (const field of Object.keys(EMPTY_FORM)) if (typeof saved.data[field] === 'string') data[field] = saved.data[field];
    const sameCart = valid && saved.signature === signature && /^[A-Za-z0-9-]{16,64}$/.test(saved.key ?? '');
    // The applied coupon survives a reload; the summary validates it again on step 3.
    const coupon = valid && typeof saved.coupon === 'string' && /^[A-Z0-9-]{3,30}$/.test(saved.coupon) ? saved.coupon : null;
    return { data, coupon, step: sameCart && [1, 2, 3].includes(saved.step) ? saved.step : 1, key: sameCart ? saved.key : newKey() };
}

export function saveCheckout({ signature, key, step, data, coupon }) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ v: 1, signature, key, step, data, coupon: coupon ?? null }));
    } catch {
        // Browser storage unavailable: the form keeps working in memory.
    }
}

export function clearCheckout() {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to clear.
    }
}

/**
 * The order of this cart was cancelled (by the customer or by expiry): the typed data stays, but the idempotency key goes. Reusing it
 * would make the server answer with the cancelled order instead of creating a new one.
 */
export function dropCheckoutKey() {
    try {
        const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null');
        if (saved && typeof saved === 'object') window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...saved, key: null, signature: null }));
    } catch {
        // Nothing saved or storage unavailable.
    }
}

/** The email typed at checkout, kept in `cfh-checkout` until the payment is approved: it is what proves the pending order is theirs. */
export function savedCheckoutEmail() {
    try {
        const email = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null')?.data?.customer_email;
        return typeof email === 'string' && email.trim() ? email.trim() : null;
    } catch {
        return null;
    }
}

export { newKey };
