import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ArrowLeft, ArrowRight, Check, Coffee, Copy, Tags, X } from 'lucide-react';
import { money } from '../catalog/ProductCard';
import { useStorefront } from '../../context/StorefrontContext';
import { rememberLastOrder } from './shop';
import { DEPARTMENTS, EMPTY_CONSENT, cartSignature, clearCheckout, loadCheckout, newKey, normalizePhone, saveCheckout, stepOf, validateStep } from './checkoutForm';

// TODO(legal): the terms and the personal-data policy (Ley 1581 de 2012) shown by `setPolicy` are reference
// text pending commercial validation. They MUST be written and approved before going to production.

// Registers the order in the backend. This is NOT a payment: the order is stored as pending and the
// payment is not processed here, so nothing in this flow says the purchase was paid.
const LABELS = ['Datos', 'Entrega', 'Resumen', 'Listo'];
// Coupon block of step 3. The rules (and the amount) are the backend's: the quote answers with `coupon` or `coupon_error`.
const PROMO_CODES_ENABLED = true;
const formatPhone = phone => (/^\d{10}$/.test(phone) ? `${phone.slice(0, 3)} ${phone.slice(3, 6)} ${phone.slice(6)}` : phone);

export default function CheckoutModal({ items, onClose, onRegistered, onFix }) {
    const { setPolicy } = useStorefront();
    const signature = cartSignature(items);
    const [initial] = useState(() => loadCheckout(signature));
    const [step, setStep] = useState(initial.step);
    const [data, setData] = useState(initial.data);
    const [coupon, setCoupon] = useState(initial.coupon); // code sent with the quote (null = none)
    const [couponInput, setCouponInput] = useState('');
    const [couponMessage, setCouponMessage] = useState('');
    const keepQuote = useRef(false); // set when a coupon error already came with a usable quote
    const [consent, setConsent] = useState(EMPTY_CONSENT);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [busy, setBusy] = useState(false);
    const [order, setOrder] = useState(null);
    const [quote, setQuote] = useState({ status: 'idle', data: null });
    const [retry, setRetry] = useState(0);
    // Keys of the cart problems the customer already reviewed (unavailable, price changed, short stock).
    const [reviewed, setReviewed] = useState(() => new Set());
    const [copied, setCopied] = useState(false);
    const sending = useRef(false);
    const key = useRef(initial.key);
    const keySignature = useRef(signature);

    // The page behind the modal must not scroll while it is open.
    useEffect(() => {
        const { overflow, paddingRight } = document.body.style;
        const scrollbar = window.innerWidth - document.documentElement.clientWidth;
        document.body.style.overflow = 'hidden';
        if (scrollbar > 0) document.body.style.paddingRight = `${scrollbar}px`;
        return () => { document.body.style.overflow = overflow; document.body.style.paddingRight = paddingRight; };
    }, []);

    // The idempotency key belongs to one cart content: a different cart is a different order.
    useEffect(() => {
        if (keySignature.current !== signature) { keySignature.current = signature; key.current = newKey(); }
    }, [signature]);

    // Save the progress (never the legal acceptance) so a reload or closing the modal loses nothing.
    useEffect(() => {
        if (step < 4) saveCheckout({ signature, key: key.current, step, data, coupon });
    }, [signature, step, data, coupon]);

    // Summary from the database: prices, subtotal, shipping and total are never computed here.
    useEffect(() => {
        if (step !== 3 || !items.length) return undefined;
        if (keepQuote.current) { keepQuote.current = false; return undefined; }
        const controller = new AbortController();
        setQuote({ status: 'loading', data: null });
        fetch('/api/orders/quote', {
            method: 'POST', signal: controller.signal, headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ items: items.map(item => ({ variant_id: item.id, quantity: item.quantity })), coupon_code: coupon, customer_email: data.customer_email.trim() || null }),
        }).then(async response => {
            const body = await response.json().catch(() => ({}));
            if (response.status === 429 && coupon) {
                // Too many coupon attempts: drop the code (the quote without it is not limited) and say why.
                setCouponMessage('Hiciste muchos intentos con cupones. Espera un minuto e inténtalo de nuevo.'); setCoupon(null);
                return;
            }
            if (!response.ok || !body.data) throw new Error('quote');
            // A coupon that does not apply comes back with the quote WITHOUT it: show the reason and forget the code.
            if (body.data.coupon_error) { setCouponMessage(body.data.coupon_error.message); keepQuote.current = true; setCoupon(null); }
            else if (body.data.coupon) setCouponMessage('');
            setQuote({ status: 'ready', data: body.data });
        }).catch(error => { if (error.name !== 'AbortError') setQuote({ status: 'error', data: null }); });
        return () => controller.abort();
    }, [step, signature, retry, coupon]); // eslint-disable-line react-hooks/exhaustive-deps

    const focusFirst = names => window.requestAnimationFrame(() => document.getElementById(`co-${names[0]}`)?.focus());
    const change = event => {
        const { name, value } = event.target;
        setData(current => ({ ...current, [name]: value }));
        setErrors(current => ({ ...current, [name]: undefined }));
    };
    const changeConsent = event => {
        const { name, checked } = event.target;
        setConsent(current => ({ ...current, [name]: checked }));
        setErrors(current => ({ ...current, [name]: undefined }));
    };
    const error = name => (Array.isArray(errors[name]) ? errors[name][0] : errors[name]);
    const field = (name, labelText, props = {}, Tag = 'input', children = undefined) => (
        <div className="co-field">
            <label htmlFor={`co-${name}`}>{labelText}</label>
            <Tag id={`co-${name}`} name={name} value={data[name]} onChange={change} aria-invalid={!!error(name)} aria-describedby={error(name) ? `co-${name}-error` : undefined} {...props}>{children}</Tag>
            {error(name) && <small className="co-error" id={`co-${name}-error`} role="alert">{error(name)}</small>}
        </div>
    );

    function applyCoupon() {
        const code = couponInput.trim().toUpperCase();
        if (!code) { setCouponMessage('Escribe tu código.'); return; }
        if (!/^[A-Z0-9-]{3,30}$/.test(code)) { setCouponMessage('Cupón no válido o vencido'); return; }
        setCouponMessage(''); setCoupon(code);
    }
    function removeCoupon() { setCouponMessage(''); setCouponInput(''); setCoupon(null); }

    function next() {
        const found = validateStep(step, data, consent);
        if (Object.keys(found).length) { setErrors(found); focusFirst(Object.keys(found)); return; }
        setErrors({}); setFormError(''); setStep(step + 1);
    }

    // Differences between what the customer saw in the cart and what the database says now.
    const quoted = quote.data?.items ?? [];
    const byId = new Map(quoted.map(line => [line.variant_id, line]));
    const unavailable = quote.status === 'ready' ? items.filter(item => !byId.has(item.id)) : [];
    const changed = quote.status === 'ready' ? items.filter(item => byId.has(item.id) && byId.get(item.id).unit_price_cop !== Math.round(item.price)) : [];
    const short = quote.status === 'ready' ? quoted.filter(line => line.insufficient) : [];
    const issueKeys = [...unavailable.map(item => `u:${item.id}`), ...changed.map(item => `c:${item.id}:${byId.get(item.id).unit_price_cop}`), ...short.map(line => `s:${line.variant_id}:${line.available_quantity}:${line.quantity}`)];
    const needsReview = issueKeys.some(issueKey => !reviewed.has(issueKey));
    const canConfirm = !busy && items.length > 0 && quote.status === 'ready' && !needsReview;

    function acceptChanges() {
        setReviewed(current => new Set([...current, ...issueKeys]));
        // Take the cart to what can actually be bought: remove what is gone, lower what exceeds the stock.
        const fixes = [...unavailable.map(item => ({ id: item.id, quantity: 0 })), ...short.map(line => ({ id: line.variant_id, quantity: line.available_quantity }))];
        if (fixes.length) onFix(fixes);
    }

    async function submit() {
        if (sending.current || !canConfirm) return;
        const found = validateStep(3, data, consent);
        if (Object.keys(found).length) { setErrors(found); focusFirst(Object.keys(found)); return; }
        sending.current = true; setBusy(true); setFormError(''); setErrors({});
        try {
            const response = await fetch('/api/orders', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    idempotency_key: key.current,
                    customer_name: data.customer_name.trim(), customer_email: data.customer_email.trim(), customer_phone: normalizePhone(data.customer_phone),
                    delivery_department: data.delivery_department, delivery_city: data.delivery_city.trim(), delivery_address: data.delivery_address.trim(),
                    delivery_details: data.delivery_details.trim() || null, order_notes: data.order_notes.trim() || null,
                    terms_accepted: consent.terms_accepted, data_consent: consent.data_consent, marketing_opt_in: consent.marketing_opt_in,
                    expected_total_cop: quote.data.total_cop, coupon_code: quote.data.coupon?.code ?? null,
                    items: items.map(item => ({ variant_id: item.id, quantity: item.quantity })),
                }),
            });
            const body = await response.json().catch(() => ({}));
            if (response.ok && body.order) {
                clearCheckout(); // Confirmed: drop the saved progress and the key.
                rememberLastOrder(body.order.reference); // the WhatsApp menu uses it for "Soporte de mi pedido" (7 days)
                setOrder(body.order); setStep(4); onRegistered();
                return;
            }
            if (response.status === 422 && body.errors) {
                const fieldErrors = { ...body.errors };
                if (fieldErrors.coupon_code) {
                    // The coupon stopped being valid between the summary and the order: nothing was created; show the new total.
                    setCouponMessage(fieldErrors.coupon_code[0]); setCoupon(null); setFormError('Tu cupón ya no se puede aplicar. Revisa el nuevo total antes de confirmar.'); setStep(3);
                    delete fieldErrors.coupon_code;
                }
                const names = Object.keys(fieldErrors).filter(name => !name.startsWith('items'));
                const cartErrors = Object.keys(fieldErrors).some(name => name.startsWith('items'));
                if (cartErrors) {
                    // Unavailable products or changed prices: the summary must be reviewed again.
                    setFormError(Object.entries(fieldErrors).find(([name]) => name.startsWith('items'))[1][0]);
                    setReviewed(new Set()); setRetry(value => value + 1); setStep(3);
                }
                if (names.length) {
                    setErrors(fieldErrors);
                    const first = Math.min(...names.map(stepOf));
                    setStep(first); focusFirst(names.filter(name => stepOf(name) === first));
                    if (!cartErrors) setFormError('Revisa los campos señalados.');
                }
            } else if (response.status === 409) {
                key.current = newKey(); // The old key belongs to another customer: start a new request.
                setFormError(body.message || 'Esta solicitud ya fue procesada con otros datos. Inténtalo de nuevo.');
            } else if (response.status === 429) setFormError('Hiciste muchos intentos seguidos. Espera un momento e inténtalo de nuevo.');
            else setFormError('No pudimos registrar tu pedido. Inténtalo nuevamente en unos minutos.');
        } catch {
            // The key is kept: retrying cannot create a second order.
            setFormError('No pudimos conectar con el servidor. Comprueba tu conexión e inténtalo nuevamente.');
        } finally { sending.current = false; setBusy(false); }
    }

    async function copyReference() {
        try {
            await navigator.clipboard.writeText(order.reference);
        } catch {
            const area = document.createElement('textarea');
            area.value = order.reference; document.body.appendChild(area); area.select();
            try { document.execCommand('copy'); } finally { document.body.removeChild(area); }
        }
        setCopied(true); window.setTimeout(() => setCopied(false), 2000);
    }

    // Presentation shown under each product: "250 g · Molido" (the weight is omitted if the name already has it).
    const presentation = item => [item?.weight && !item.name.includes(`${item.weight} g`) ? `${item.weight} g` : null, item?.grind].filter(Boolean).join(' · ');

    const legal = (name, id, label, policy) => (
        <div className="co-field co-field-check">
            <label htmlFor={`co-${name}`}>
                <input id={`co-${name}`} type="checkbox" name={name} checked={consent[name]} onChange={changeConsent} aria-invalid={!!error(name)} aria-describedby={error(name) ? `co-${name}-error` : undefined} />
                <span>{label} <button type="button" className="co-link" onClick={() => setPolicy(policy)}>{id}</button>. *</span>
            </label>
            {error(name) && <small className="co-error" id={`co-${name}-error`} role="alert">{error(name)}</small>}
        </div>
    );

    return createPortal(<div className="demo-home co-portal"><div className="co-overlay open" onClick={event => { if (event.target === event.currentTarget && !busy) onClose(); }}><div className="co-modal" role="dialog" aria-modal="true" aria-labelledby="checkout-title">
        <div className="co-header"><h3 id="checkout-title">Completar pedido</h3><button className="co-close" onClick={onClose} disabled={busy} aria-label="Cerrar"><X className="ui-icon" aria-hidden="true" /></button></div>
        <div className="co-steps">{LABELS.map((text, index) => <div key={text} className={`co-step ${step === index + 1 ? 'active' : ''} ${step > index + 1 ? 'done' : ''}`} aria-current={step === index + 1 ? 'step' : undefined}><span className="co-step-num" aria-hidden="true">{step > index + 1 ? '✓' : index + 1}</span><span className="co-step-text">{text}</span></div>)}</div>
        <div className="co-step-caption">Paso {step} de 4 · {LABELS[step - 1]}</div>
        <div className="co-body">
            {step === 1 && <>
                {field('customer_name', 'Nombre completo *', { placeholder: 'Ej: María González', maxLength: 255, autoComplete: 'name' })}
                {field('customer_email', 'Correo electrónico *', { type: 'email', placeholder: 'correo@ejemplo.com', maxLength: 255, autoComplete: 'email' })}
                {field('customer_phone', 'Celular *', { type: 'tel', inputMode: 'numeric', placeholder: '3001234567', maxLength: 20, autoComplete: 'tel-national' })}
            </>}
            {step === 2 && <>
                {field('delivery_department', 'Departamento *', { autoComplete: 'address-level1' }, 'select', <><option value="">Selecciona tu departamento</option>{DEPARTMENTS.map(name => <option key={name} value={name}>{name}</option>)}</>)}
                {field('delivery_city', 'Ciudad *', { placeholder: 'Bogotá', maxLength: 255, autoComplete: 'address-level2' })}
                {field('delivery_address', 'Dirección de entrega *', { placeholder: 'Calle 45 # 12-34', maxLength: 500, autoComplete: 'street-address' })}
                {field('delivery_details', 'Detalles de entrega', { placeholder: 'Apto, torre, barrio, indicaciones…', maxLength: 500 })}
                {field('order_notes', 'Notas del pedido (opcional)', { placeholder: 'Algo que debamos saber sobre tu pedido', maxLength: 500, rows: 3 }, 'textarea')}
            </>}
            {step === 3 && <div className="co-stack">
                <div className="co-order-summary" aria-live="polite">
                    <h4><Coffee className="ui-icon" aria-hidden="true" /> Resumen del pedido</h4>
                    {!items.length && <p>Tu carrito está vacío.</p>}
                    {items.length > 0 && quote.status === 'loading' && <p>Calculando tu pedido…</p>}
                    {quote.status === 'error' && <><p className="co-error" role="alert">No pudimos calcular tu pedido.</p><button type="button" className="co-btn-back" onClick={() => setRetry(value => value + 1)}>Reintentar</button></>}
                    {quote.status === 'ready' && <>
                        {quoted.map(line => {
                            const before = items.find(item => item.id === line.variant_id);
                            const priceChanged = before && Math.round(before.price) !== line.unit_price_cop;
                            const detail = presentation(before);
                            return <div className="co-order-line" key={line.variant_id}>
                                <div className="co-line-main"><span>{line.name}</span><span>{money(line.subtotal_cop)}</span></div>
                                <small className="co-line-sub">{detail && `${detail} · `}Cantidad: {line.quantity} · {line.original_unit_price_cop != null && <><s className="price-old">{money(line.original_unit_price_cop)}</s> </>}{money(line.unit_price_cop)} c/u</small>
                                {line.insufficient && <small className="co-error">{line.available_quantity > 0 ? `Solo quedan ${line.available_quantity}` : 'Agotado'}</small>}
                                {priceChanged && <small className="co-error">Precio actualizado: antes {money(before.price)}, ahora {money(line.unit_price_cop)}</small>}
                            </div>;
                        })}
                        {unavailable.map(item => <div className="co-order-line" key={item.id}><div className="co-line-main"><span>{item.name}</span><span>—</span></div><small className="co-error">Ya no está disponible</small></div>)}
                        <div className="co-order-row"><span>Subtotal</span><span>{money(quote.data.subtotal_cop)}</span></div>
                        {quote.data.savings_cop > 0 && <div className="co-order-row co-savings"><span>Ahorras</span><span>{money(quote.data.savings_cop)}</span></div>}
                        {quote.data.coupon && <div className="co-order-row co-coupon-line"><span>Descuento ({quote.data.coupon.code})</span><span>-{money(quote.data.discount_cop)}</span></div>}
                        <div className="co-order-row"><span>Envío</span><span>{quote.data.shipping_pending ? 'a coordinar' : money(quote.data.shipping_cop)}</span></div>
                        <div className="co-order-total"><span>Total</span><span>{money(quote.data.total_cop)}</span></div>
                    </>}
                </div>
                {PROMO_CODES_ENABLED && <div className="co-guide-box"><label htmlFor="co-coupon"><Tags className="ui-icon" aria-hidden="true" /> Código promocional</label>
                    {quote.data?.coupon
                        ? <div className="co-coupon-chip"><span><strong>{quote.data.coupon.code}</strong> <Check className="ui-icon" aria-hidden="true" /><small>{quote.data.coupon.label}</small></span><button type="button" onClick={removeCoupon} aria-label={`Quitar el cupón ${quote.data.coupon.code}`}><X className="ui-icon" aria-hidden="true" /></button></div>
                        : <form className="guide-row" onSubmit={event => { event.preventDefault(); applyCoupon(); }}><input id="co-coupon" placeholder="Ej: HELENA10" value={couponInput} maxLength={30} autoComplete="off" autoCapitalize="characters" spellCheck="false" onChange={event => { setCouponInput(event.target.value.toUpperCase()); setCouponMessage(''); }} aria-invalid={!!couponMessage} aria-describedby={couponMessage ? 'co-coupon-message' : undefined} /><button type="submit" disabled={quote.status === 'loading' || !couponInput.trim()}>{quote.status === 'loading' && coupon ? 'Aplicando…' : 'Aplicar'}</button></form>}
                    {couponMessage && <small className="co-error" id="co-coupon-message" role="alert">{couponMessage}</small>}
                </div>}
                <div className="co-consent">
                    {legal('terms_accepted', 'términos y condiciones', 'Acepto los', 'terminos')}
                    {legal('data_consent', 'política de tratamiento de datos personales', 'Autorizo el tratamiento de mis datos según la', 'privacidad')}
                    <div className="co-field co-field-check">
                        <label htmlFor="co-marketing_opt_in"><input id="co-marketing_opt_in" type="checkbox" name="marketing_opt_in" checked={consent.marketing_opt_in} onChange={changeConsent} /><span>Quiero recibir promociones y novedades de Finca Helena por WhatsApp y correo. (Opcional)</span></label>
                    </div>
                </div>
                {needsReview && <div className="co-notice" role="alert">{unavailable.length > 0 ? 'Algunos productos ya no están disponibles. ' : ''}{short.length > 0 ? 'Algunos productos tienen menos unidades de las que pediste. ' : ''}{changed.length > 0 ? 'Algunos precios cambiaron. ' : ''}Revisa el resumen antes de confirmar.<div className="co-btn-row"><button type="button" className="co-btn-next" onClick={acceptChanges}>Revisé los cambios{unavailable.length > 0 || short.length > 0 ? ' y ajustar mi carrito' : ''}</button></div></div>}
                <p className="preview-note">Al confirmar registramos tu pedido. El pago en línea aún no está disponible: nos pondremos en contacto contigo para coordinarlo y el envío.</p>
            </div>}
            {step === 4 && order && <div className="co-done" role="status">
                <div className="co-done-icon"><Check aria-hidden="true" /></div>
                <h3>¡Pedido registrado!</h3>
                <div className="co-ref-box">
                    <small>Tu referencia</small>
                    <strong>{order.reference}</strong>
                    <button type="button" className="co-copy" onClick={copyReference}><Copy className="ui-icon" aria-hidden="true" /> Copiar referencia</button>
                    <span className="co-copied" aria-live="polite">{copied ? 'Copiada' : ''}</span>
                </div>
                <dl className="co-kv">
                    {order.discount_cop > 0 && <div className="co-coupon-line"><dt>Descuento ({order.coupon_code})</dt><dd>-{money(order.discount_cop)}</dd></div>}
                    <div><dt>Total</dt><dd>{money(order.total_cop)}</dd></div>
                    <div><dt>Email</dt><dd>{data.customer_email.trim()}</dd></div>
                    <div><dt>Celular</dt><dd>{formatPhone(normalizePhone(data.customer_phone))}</dd></div>
                </dl>
                <div className="co-next">
                    <h4>¿Qué sigue?</h4>
                    <ol>
                        <li>Te contactaremos al celular {formatPhone(normalizePhone(data.customer_phone))} para coordinar el pago y el envío.</li>
                        <li>Guarda tu referencia para cualquier consulta.</li>
                        <li>Tu pedido queda pendiente: todavía no se ha realizado ningún pago.</li>
                    </ol>
                </div>
                <div className="co-btn-row"><button className="co-btn-next" onClick={onClose}>Seguir comprando</button></div>
            </div>}
            {formError && step !== 4 && <p className="co-error" role="alert">{formError}</p>}
            {step < 4 && <div className="co-btn-row">
                    {step > 1 && <button className="co-btn-back" onClick={() => { setErrors({}); setStep(step - 1); }} disabled={busy}><ArrowLeft className="ui-icon" aria-hidden="true" /> Volver</button>}
                    {step < 3 ? <button className="co-btn-next" onClick={next}>Continuar <ArrowRight className="ui-icon" aria-hidden="true" /></button>
                        : <button className="co-btn-next" onClick={submit} disabled={!canConfirm}>{busy ? 'Registrando…' : 'Confirmar pedido'}</button>}
            </div>}
        </div>
    </div></div></div>, document.body);
}
