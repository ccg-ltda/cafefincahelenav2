import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Check, CircleAlert, ClipboardCopy, LoaderCircle, MessageCircle, Minus, Plus, Search, UserCheck, X } from 'lucide-react';
import './AdminProductEditor.css';
import './AdminCategories.css';
import './AdminManualOrder.css';
import { Field, request, titleCase } from './adminShared';
import { useAdminToast } from './AdminToast';
import { DEPARTMENTS } from '../storefront/checkoutForm';

// The manual order: the administrator registers an order that came by WhatsApp or phone. Nothing is calculated here: the summary is the
// server's quote (OrderPricingService), and the only way to pay is the Wompi link that the server creates together with the order.
const money = value => `$ ${new Intl.NumberFormat('es-CO').format(value)}`;
const digits = value => String(value ?? '').replace(/\D/g, '');
// "300 123 4567", "+57 300 123 4567" -> "3001234567" (same normalization as the checkout)
const normalizePhone = value => String(value ?? '').trim().replace(/[\s().-]/g, '').replace(/^(\+57|57)(?=3\d{9}$)/, '');
const PHONE = /^3\d{9}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const STEPS = ['Cliente', 'Productos', 'Envío y pago'];
const EMPTY = { customer_name: '', customer_phone: '', customer_email: '', delivery_department: '', delivery_city: '', delivery_address: '', delivery_details: '' };
const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}-manual`).replace(/[^A-Za-z0-9-]/g, '').padEnd(16, '0');

function validateCustomer(form, consent) {
    const errors = {};
    if (form.customer_name.trim().length < 3) errors.customer_name = ['Escribe el nombre completo.'];
    if (!PHONE.test(normalizePhone(form.customer_phone))) errors.customer_phone = ['Ingresa un celular colombiano de 10 dígitos que empiece por 3.'];
    if (form.customer_email.trim() && !EMAIL.test(form.customer_email.trim())) errors.customer_email = ['Escribe un correo válido o déjalo vacío.'];
    if (!DEPARTMENTS.includes(form.delivery_department)) errors.delivery_department = ['Elige el departamento.'];
    if (!form.delivery_city.trim()) errors.delivery_city = ['Escribe la ciudad.'];
    if (!form.delivery_address.trim()) errors.delivery_address = ['Escribe la dirección de entrega.'];
    if (!consent) errors.data_consent = ['Confirma que el cliente autorizó el tratamiento de sus datos personales.'];
    return errors;
}

export default function AdminManualOrder() {
    const toast = useAdminToast();
    const [step, setStep] = useState(1);
    const [form, setForm] = useState(EMPTY);
    const [consent, setConsent] = useState(false);
    const [marketing, setMarketing] = useState(false);
    const [errors, setErrors] = useState({});
    const [found, setFound] = useState(null); // customer offered by the lookup
    const dismissed = useRef(new Set());
    const [items, setItems] = useState({}); // variant id -> quantity
    const [search, setSearch] = useState('');
    const [products, setProducts] = useState({ state: 'loading', rows: [] });
    const [couponDraft, setCouponDraft] = useState('');
    const [coupon, setCoupon] = useState('');
    const [shippingMode, setShippingMode] = useState('later'); // later = "A coordinar" (0) | value
    const [shippingValue, setShippingValue] = useState('');
    const [notes, setNotes] = useState('');
    const [quote, setQuote] = useState(null);
    const [quoteState, setQuoteState] = useState('idle');
    const [saving, setSaving] = useState(false);
    const [formMessage, setFormMessage] = useState('');
    const [result, setResult] = useState(null);
    const [key, setKey] = useState(newKey);

    const set = name => event => { setForm(current => ({ ...current, [name]: event.target.value })); setErrors(current => ({ ...current, [name]: undefined })); };
    const lines = useMemo(() => Object.entries(items).filter(([, quantity]) => quantity > 0).map(([id, quantity]) => ({ variant_id: Number(id), quantity })), [items]);
    const shippingNumber = shippingMode === 'value' ? (shippingValue.trim() === '' ? null : Number(shippingValue)) : null;
    const shippingInvalid = shippingMode === 'value' && (shippingValue.trim() === '' || !/^\d{1,8}$/.test(shippingValue.trim()));
    const email = form.customer_email.trim();

    // ---- customer lookup (by phone or email, as they are typed) ----
    useEffect(() => {
        const phone = normalizePhone(form.customer_phone);
        const mail = EMAIL.test(email) ? email : '';
        if (!PHONE.test(phone) && !mail) { setFound(null); return undefined; }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            request(`/admin/orders/manual/customer?${new URLSearchParams({ ...(PHONE.test(phone) ? { phone } : {}), ...(mail ? { email: mail } : {}) })}`, { signal: controller.signal })
                .then(data => setFound(data.customer && !dismissed.current.has(data.customer.id) ? data.customer : null))
                .catch(() => {});
        }, 450);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [form.customer_phone, email]);

    function applyCustomer(customer) {
        setForm({ customer_name: titleCase(customer.name), customer_phone: customer.phone || '', customer_email: customer.email || '', delivery_department: customer.department || '', delivery_city: customer.city || '', delivery_address: customer.last_address || '', delivery_details: customer.last_address_details || '' });
        setErrors({});
        dismissed.current.add(customer.id);
        setFound(null);
        toast.info('Datos cargados', `Se usaron los datos de ${titleCase(customer.name)}. Puedes editarlos.`);
    }

    // ---- products ----
    useEffect(() => {
        const controller = new AbortController();
        setProducts(current => ({ ...current, state: 'loading' }));
        const timer = window.setTimeout(() => {
            request(`/admin/orders/manual/products${search.trim() ? `?${new URLSearchParams({ search: search.trim() })}` : ''}`, { signal: controller.signal })
                .then(data => setProducts({ state: 'ready', rows: data.data }))
                .catch(error => { if (error.name !== 'AbortError') setProducts({ state: 'error', rows: [] }); });
        }, 250);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [search]);

    const change = (row, delta) => setItems(current => {
        const next = Math.max(0, Math.min(row.available_quantity, (current[row.id] || 0) + delta));
        return { ...current, [row.id]: next };
    });

    // ---- the summary is always the server's quote ----
    useEffect(() => {
        if (lines.length === 0) { setQuote(null); setQuoteState('idle'); return undefined; }
        const controller = new AbortController();
        setQuoteState('loading');
        const timer = window.setTimeout(() => {
            request('/admin/orders/manual/quote', {
                method: 'POST', signal: controller.signal, headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: lines, coupon_code: coupon || null, customer_email: EMAIL.test(email) ? email : null, shipping_cop: shippingMode === 'value' && !shippingInvalid ? shippingNumber : null }),
            }).then(data => { setQuote(data.data); setQuoteState('ready'); })
                .catch(error => { if (error.name !== 'AbortError') setQuoteState('error'); });
        }, 300);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [lines, coupon, email, shippingMode, shippingNumber, shippingInvalid]);

    const insufficient = quote?.items.some(line => line.insufficient) || (quote?.unavailable?.length > 0);
    const canSave = consent && lines.length > 0 && quoteState === 'ready' && !insufficient && !shippingInvalid && !saving;

    function next(target) {
        if (target === 2) {
            const problems = validateCustomer(form, consent);
            setErrors(problems);
            if (Object.keys(problems).length) { toast.warning('Revisa los datos del cliente', 'Hay campos por completar.'); return; }
        }
        setStep(target);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function save() {
        const problems = validateCustomer(form, consent);
        if (Object.keys(problems).length) { setErrors(problems); setStep(1); return; }
        setSaving(true); setFormMessage('');
        try {
            const data = await request('/admin/orders/manual', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    idempotency_key: key, ...Object.fromEntries(Object.entries(form).map(([name, value]) => [name, name === 'customer_phone' ? normalizePhone(value) : value.trim()])),
                    customer_email: email || null, data_consent: true, marketing_opt_in: marketing, items: lines, coupon_code: coupon || null,
                    shipping_cop: shippingMode === 'value' ? shippingNumber : null, order_notes: notes.trim() || null, expected_total_cop: quote.total_cop,
                }),
            });
            setResult(data);
            window.scrollTo({ top: 0 });
        } catch (error) {
            const fields = error.fields || {};
            setErrors(fields);
            setFormMessage(error.data?.message || (error.status === 422 ? 'Revisa los campos señalados.' : error.message));
            if (Object.keys(fields).some(name => name in EMPTY || name === 'data_consent')) setStep(1);
            else if (fields.items) setStep(2);
            toast.error('No se guardó el pedido', error.data?.message || fields.items?.[0] || error.message);
        } finally { setSaving(false); }
    }

    function reset() {
        setForm(EMPTY); setConsent(false); setMarketing(false); setErrors({}); setFound(null); dismissed.current = new Set(); setItems({}); setSearch(''); setCoupon(''); setCouponDraft('');
        setShippingMode('later'); setShippingValue(''); setNotes(''); setQuote(null); setFormMessage(''); setResult(null); setStep(1); setKey(newKey());
        window.scrollTo({ top: 0 });
    }

    if (result) return <Confirmation result={result} onAnother={reset} />;

    return <section className="admin-manual">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Ventas</span><h1>Pedido manual</h1><p>Registra un pedido que llegó por WhatsApp o por teléfono. El cliente lo paga con un link de Wompi.</p></div></header>
        <ol className="admin-manual-steps" aria-label="Pasos del pedido">{STEPS.map((label, index) => <li key={label} className={step === index + 1 ? 'is-current' : step > index + 1 ? 'is-done' : ''}>
            <button type="button" onClick={() => (index + 1 < step ? setStep(index + 1) : undefined)} disabled={index + 1 > step} aria-current={step === index + 1 ? 'step' : undefined}><b>{step > index + 1 ? <Check aria-hidden="true" /> : index + 1}</b><span>{label}</span></button></li>)}</ol>

        <div className="admin-manual-grid">
            <div className="dashboard-card admin-manual-panel">
                {step === 1 && <div className="admin-manual-form">
                    <h2>¿Quién compra?</h2>
                    <div className="admin-manual-fields">
                        <Field prefix="mo" label="Nombre *" name="customer_name" value={form.customer_name} onChange={set('customer_name')} errors={errors} maxLength={255} autoComplete="off" />
                        <Field prefix="mo" label="Celular *" name="customer_phone" value={form.customer_phone} onChange={set('customer_phone')} errors={errors} inputMode="tel" placeholder="300 123 4567" autoComplete="off" />
                        <Field prefix="mo" label="Correo (opcional)" name="customer_email" value={form.customer_email} onChange={set('customer_email')} errors={errors} type="email" placeholder="correo@ejemplo.com" autoComplete="off" />
                    </div>
                    {found && <div className="admin-manual-found" role="status"><UserCheck aria-hidden="true" /><div><strong>Este cliente ya existe</strong><span>{titleCase(found.name)}{found.city ? ` · ${found.city}` : ''}{found.last_address ? ` · ${found.last_address}` : ''}</span></div>
                        <button type="button" className="admin-categories-secondary" onClick={() => applyCustomer(found)}>Usar datos de {titleCase(found.name).split(' ')[0]}</button>
                        <button type="button" className="admin-manual-dismiss" aria-label="Ocultar" onClick={() => { dismissed.current.add(found.id); setFound(null); }}><X aria-hidden="true" /></button></div>}
                    <div className="admin-manual-fields">
                        <Field prefix="mo" label="Departamento *" name="delivery_department" errors={errors}><select id="mo-delivery_department" value={form.delivery_department} onChange={set('delivery_department')} aria-invalid={!!errors.delivery_department}><option value="">Elige…</option>{DEPARTMENTS.map(name => <option key={name} value={name}>{name}</option>)}</select></Field>
                        <Field prefix="mo" label="Ciudad *" name="delivery_city" value={form.delivery_city} onChange={set('delivery_city')} errors={errors} maxLength={255} autoComplete="off" />
                        <Field prefix="mo" label="Dirección de entrega *" name="delivery_address" value={form.delivery_address} onChange={set('delivery_address')} errors={errors} maxLength={500} autoComplete="off" />
                        <Field prefix="mo" label="Barrio o indicaciones (opcional)" name="delivery_details" value={form.delivery_details} onChange={set('delivery_details')} errors={errors} maxLength={500} autoComplete="off" />
                    </div>
                    <div className="admin-manual-consents">
                        <label className={errors.data_consent ? 'has-error' : ''}><input type="checkbox" checked={consent} onChange={event => { setConsent(event.target.checked); setErrors(current => ({ ...current, data_consent: undefined })); }} aria-invalid={!!errors.data_consent} /><span>El cliente autorizó el tratamiento de sus datos personales (Ley 1581). <b>*</b></span></label>
                        {errors.data_consent && <small role="alert">{errors.data_consent[0]}</small>}
                        <label><input type="checkbox" checked={marketing} onChange={event => setMarketing(event.target.checked)} /><span>Quiere recibir promociones y novedades.</span></label>
                    </div>
                    <div className="admin-manual-actions"><button type="button" className="admin-categories-primary" onClick={() => next(2)}>Continuar a productos</button></div>
                </div>}

                {step === 2 && <div className="admin-manual-form">
                    <h2>¿Qué lleva?</h2>
                    <label className="admin-products-search admin-manual-search"><span className="sr-only">Buscar producto</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={search} maxLength={80} onChange={event => setSearch(event.target.value)} placeholder="Buscar producto o SKU" /></span></label>
                    <ul className="admin-manual-products" tabIndex="0" aria-label="Productos disponibles, desplazable">
                        {products.state === 'loading' && products.rows.length === 0 && <li className="admin-manual-empty" role="status">Cargando productos…</li>}
                        {products.state === 'error' && <li className="admin-manual-empty" role="alert">No se pudieron cargar los productos.</li>}
                        {products.state === 'ready' && products.rows.length === 0 && <li className="admin-manual-empty">No hay productos que coincidan.</li>}
                        {products.rows.map(row => {
                            const quantity = items[row.id] || 0;
                            const out = row.available_quantity <= 0;
                            return <li key={row.id} className={`admin-manual-product${out ? ' is-out' : ''}${quantity ? ' is-picked' : ''}`}>
                                <div className="admin-manual-product-info">
                                    <strong>{row.name}</strong>
                                    <small>{[row.product_name !== row.name ? row.product_name : null, row.weight_grams ? `${row.weight_grams} g` : null, row.grind_type, row.sku].filter(Boolean).join(' · ')}</small>
                                    <span className="admin-manual-price">{row.original_price_cop && <s>{money(row.original_price_cop)}</s>}<b>{money(row.price_cop)}</b>{row.discount_label && <em>{row.discount_label}</em>}</span>
                                    <span className={`admin-manual-stock${out ? ' is-out' : row.available_quantity <= 5 ? ' is-low' : ''}`}>{out ? 'Agotado' : `Disponibles: ${row.available_quantity}`}</span>
                                </div>
                                <div className="admin-manual-qty" role="group" aria-label={`Cantidad de ${row.name}`}>
                                    <button type="button" onClick={() => change(row, -1)} disabled={out || quantity === 0} aria-label="Quitar uno"><Minus aria-hidden="true" /></button>
                                    <output aria-live="polite">{quantity}</output>
                                    <button type="button" onClick={() => change(row, 1)} disabled={out || quantity >= row.available_quantity} aria-label="Agregar uno"><Plus aria-hidden="true" /></button>
                                </div>
                            </li>;
                        })}
                    </ul>
                    {errors.items && <p className="admin-manual-error" role="alert">{errors.items[0]}</p>}
                    <div className="admin-manual-actions"><button type="button" className="admin-categories-secondary" onClick={() => setStep(1)}>Atrás</button><button type="button" className="admin-categories-primary" disabled={lines.length === 0 || quoteState === 'loading'} onClick={() => next(3)}>Continuar a envío y pago</button></div>
                </div>}

                {step === 3 && <div className="admin-manual-form">
                    <h2>Envío, notas y pago</h2>
                    <fieldset className="admin-manual-shipping"><legend>Envío</legend>
                        <label><input type="radio" name="mo-shipping" checked={shippingMode === 'later'} onChange={() => setShippingMode('later')} /><span><b>A coordinar</b> ($ 0 por ahora)</span></label>
                        <label><input type="radio" name="mo-shipping" checked={shippingMode === 'value'} onChange={() => setShippingMode('value')} /><span><b>Escribir un valor</b> en COP</span></label>
                        {shippingMode === 'value' && <Field prefix="mo" label="Valor del envío (COP)" name="shipping_cop" value={shippingValue} onChange={event => setShippingValue(event.target.value.replace(/\D/g, '').slice(0, 8))} errors={{ ...errors, shipping_cop: errors.shipping_cop || (shippingValue.trim() !== '' && shippingInvalid ? ['Escribe un número entero.'] : undefined) }} inputMode="numeric" placeholder="Ej.: 12000" />}
                        <p className="admin-manual-hint">El total del link de pago incluye el envío que escribas ahora. Si el envío se acuerda después, no se puede sumar a un link ya generado: define el valor antes de guardar.</p>
                    </fieldset>
                    <label className="admin-editor-field" htmlFor="mo-notes"><span>Nota para quien despacha (opcional)</span><textarea id="mo-notes" rows={3} maxLength={500} value={notes} onChange={event => setNotes(event.target.value)} placeholder="Ej.: es un regalo, llamar antes de entregar" /></label>
                    <div className="admin-manual-pay"><CircleAlert aria-hidden="true" /><p><b>Pago solo por Wompi.</b> Al guardar se crea el pedido (pendiente de pago) y un link de pago con plazo. El pedido pasa a Pagado únicamente cuando Wompi apruebe el pago.</p></div>
                    {formMessage && <p className="admin-manual-error" role="alert">{formMessage}</p>}
                    <div className="admin-manual-actions"><button type="button" className="admin-categories-secondary" onClick={() => setStep(2)} disabled={saving}>Atrás</button>
                        <button type="button" className="admin-manual-save" disabled={!canSave} onClick={save}>{saving ? <><LoaderCircle className="admin-manual-spin" aria-hidden="true" /> Guardando…</> : 'Guardar y generar link de pago'}</button></div>
                </div>}
            </div>

            <aside className="dashboard-card admin-manual-summary" aria-label="Resumen del pedido">
                <h2>Resumen</h2>
                {lines.length === 0 ? <p className="admin-manual-empty">Aún no hay productos.</p> : !quote ? <p className="admin-manual-empty" role="status">Calculando…</p> : <>
                    <ul className="admin-manual-lines">{quote.items.map(line => <li key={line.variant_id} className={line.insufficient ? 'is-bad' : ''}><span><b>{line.quantity} ×</b> {line.name}{line.insufficient && <small>Solo hay {line.available_quantity}</small>}</span><span>{line.original_unit_price_cop && <s>{money(line.original_unit_price_cop * line.quantity)}</s>} {money(line.subtotal_cop)}</span></li>)}</ul>
                    <dl className="admin-manual-totals" aria-live="polite">
                        <div><dt>Subtotal</dt><dd>{money(quote.subtotal_cop)}</dd></div>
                        {quote.savings_cop > 0 && <div className="is-good"><dt>Descuento por promociones</dt><dd>−{money(quote.savings_cop)}</dd></div>}
                        {quote.coupon && <div className="is-good"><dt>Cupón {quote.coupon.code}</dt><dd>−{money(quote.coupon.discount_cop)}</dd></div>}
                        <div><dt>Envío</dt><dd>{quote.shipping_pending ? 'A coordinar' : money(quote.shipping_cop)}</dd></div>
                        <div className="is-total"><dt>Total</dt><dd>{money(quote.total_cop)}</dd></div>
                    </dl>
                    {quote.unavailable?.length > 0 && <p className="admin-manual-error" role="alert">Algún producto ya no está disponible. Quítalo para continuar.</p>}
                </>}
                <form className="admin-manual-coupon" onSubmit={event => { event.preventDefault(); setCoupon(couponDraft.trim().toUpperCase()); }}>
                    <label htmlFor="mo-coupon">Cupón (opcional)</label>
                    <div><input id="mo-coupon" value={couponDraft} maxLength={40} onChange={event => setCouponDraft(event.target.value)} placeholder="Ej.: HELENA10" autoComplete="off" /><button type="submit" className="admin-categories-secondary" disabled={!couponDraft.trim() || lines.length === 0}>Aplicar</button>{coupon && <button type="button" className="admin-manual-dismiss" aria-label="Quitar cupón" onClick={() => { setCoupon(''); setCouponDraft(''); }}><X aria-hidden="true" /></button>}</div>
                    {quote?.coupon_error && coupon && <small role="alert">{quote.coupon_error.message}</small>}
                </form>
                {quoteState === 'error' && <p className="admin-manual-error" role="alert">No se pudo calcular el resumen. Inténtalo de nuevo.</p>}
            </aside>
        </div>
    </section>;
}

function Confirmation({ result, onAnother }) {
    const toast = useAdminToast();
    const [copied, setCopied] = useState(false);
    const order = result.order;
    const payment = result.payment;
    const copy = useCallback(async () => {
        try { await navigator.clipboard.writeText(payment.url); setCopied(true); toast.success('Link copiado', 'Ya puedes pegarlo donde quieras.'); window.setTimeout(() => setCopied(false), 2500); }
        catch { toast.error('No se pudo copiar', 'Selecciona el link y cópialo a mano.'); }
    }, [payment]);

    return <section className="admin-manual">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Ventas</span><h1>Pedido manual</h1></div></header>
        <div className="dashboard-card admin-manual-done">
            <span className="admin-manual-done-icon"><Check aria-hidden="true" /></span>
            <h2>Pedido registrado</h2>
            <p className="admin-manual-done-ref"><code>{order.reference}</code> · <strong>{money(order.total_cop)}</strong></p>
            {payment ? <>
                <p>Envía este link al cliente para que pague. {payment.expires_label && <>Tiene hasta el <b>{payment.expires_label}</b> para pagarlo; después el pedido se cancela solo.</>}</p>
                <div className="admin-manual-link"><input readOnly value={payment.url} aria-label="Link de pago" onFocus={event => event.target.select()} /><button type="button" className="admin-categories-secondary" onClick={copy}>{copied ? <><Check aria-hidden="true" /> Copiado</> : <><ClipboardCopy aria-hidden="true" /> Copiar link</>}</button></div>
                <div className="admin-manual-done-actions">
                    <a className="admin-manual-whatsapp" href={result.whatsapp.url} target="_blank" rel="noopener noreferrer"><MessageCircle aria-hidden="true" /> Enviar por WhatsApp</a>
                    <a className="admin-categories-secondary" href={`/admin/orders/${order.id}`}>Ver pedido</a>
                    <button type="button" className="admin-categories-secondary" onClick={onAnother}>Crear otro pedido</button>
                </div>
            </> : <>
                <p>Este pedido ya estaba registrado y ya no tiene un link de pago vigente. Ábrelo para generar uno nuevo.</p>
                <div className="admin-manual-done-actions"><a className="admin-categories-primary" href={`/admin/orders/${order.id}`}>Ver pedido</a><button type="button" className="admin-categories-secondary" onClick={onAnother}>Crear otro pedido</button></div>
            </>}
        </div>
    </section>;
}
