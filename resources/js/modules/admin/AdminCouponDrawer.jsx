import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Dices, TriangleAlert, X } from 'lucide-react';
import './AdminCategories.css';
import './AdminPromotions.css';
import './AdminCoupons.css';
import useModalLock from './useModalLock';
import { request } from './adminShared';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';
const MAX_PERCENTAGE = 90;
const CODE_REGEX = /^[A-Z0-9-]{3,30}$/;
const SAMPLE_PURCHASE = 100000;
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$${number(value)}`;
const EMPTY = {
    code: '', description: '', is_active: true, discount_type: 'percentage', discount_value: '', max_discount_cop: '', min_subtotal_cop: '',
    max_uses: '', exclude_promoted_items: false, starts_at: '', ends_at: '',
};

/** A readable random code like FH-7K2M (no 0/O/1/I). The server still checks that it is unique. */
function generateCode() {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint8Array(4));
    return `FH-${[...bytes].map(byte => alphabet[byte % alphabet.length]).join('')}`;
}

// Whole number problem of an optional/required text field, or ''.
function integerProblem(text, { min, max, required = false, label }) {
    const clean = String(text).trim();
    if (clean === '') return required ? `Escribe ${label}.` : '';
    if (!/^\d+$/.test(clean)) return 'Escribe un número entero, sin signos ni decimales.';
    const value = Number(clean);
    if (value < min) return `El valor debe ser de al menos ${number(min)}.`;
    if (max !== undefined && value > max) return `No puede superar ${number(max)}.`;
    return '';
}

/** Only the live preview computes a discount in the browser; the server repeats the same rules (CouponService). */
function previewDiscount(form, purchase) {
    const value = Number(form.discount_value);
    const raw = form.discount_type === 'percentage' ? (purchase * value) / 100 : value;
    let discount = Math.round(raw / 100) * 100;
    if (form.discount_type === 'percentage' && form.max_discount_cop.trim() !== '') discount = Math.min(discount, Number(form.max_discount_cop));
    return Math.max(0, Math.min(discount, purchase));
}

// Right-hand panel to create or edit a coupon. The footer button stays disabled while anything is invalid.
export default function AdminCouponDrawer({ id, onClose, onSaved }) {
    const panel = useRef(null);
    const firstField = useRef(null);
    const [form, setForm] = useState(EMPTY);
    const [saved, setSaved] = useState(JSON.stringify(EMPTY));
    const [uses, setUses] = useState(0);
    const [loading, setLoading] = useState(Boolean(id));
    const [loadError, setLoadError] = useState('');
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [saving, setSaving] = useState(false);
    const [closing, setClosing] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [touched, setTouched] = useState({});
    const [taken, setTaken] = useState('');

    useModalLock();

    useEffect(() => {
        const viewport = window.visualViewport;
        if (!viewport) return undefined;
        const sync = () => panel.current?.style.setProperty('--drawer-height', `${viewport.height}px`);
        sync(); viewport.addEventListener('resize', sync);
        return () => viewport.removeEventListener('resize', sync);
    }, []);

    useEffect(() => {
        const keepFocus = event => { if (panel.current && !panel.current.contains(event.target)) firstField.current?.focus(); };
        document.addEventListener('focusin', keepFocus);
        return () => document.removeEventListener('focusin', keepFocus);
    }, []);

    useEffect(() => {
        if (!id) { firstField.current?.focus(); return undefined; }
        const controller = new AbortController();
        request(`/admin/coupons/${id}`, { signal: controller.signal }).then(({ data }) => {
            const loaded = {
                code: data.code, description: data.description || '', is_active: data.is_active, discount_type: data.discount_type, discount_value: String(data.discount_value),
                max_discount_cop: data.max_discount_cop === null ? '' : String(data.max_discount_cop), min_subtotal_cop: data.min_subtotal_cop ? String(data.min_subtotal_cop) : '',
                max_uses: data.max_uses === null ? '' : String(data.max_uses),
                exclude_promoted_items: data.exclude_promoted_items, starts_at: data.starts_at_local || '', ends_at: data.ends_at_local || '',
            };
            setForm(loaded); setSaved(JSON.stringify(loaded)); setUses(data.uses); setLoading(false);
        }).catch(error => { if (error.name !== 'AbortError') { setLoadError(error.message); setLoading(false); } });
        return () => controller.abort();
    }, [id]);

    const dirty = JSON.stringify(form) !== saved;
    const patch = (changes, ...fields) => {
        setForm(current => ({ ...current, ...changes }));
        setErrors(current => { const next = { ...current }; fields.forEach(field => delete next[field]); return next; });
        setFormError('');
    };
    const touch = name => setTouched(current => ({ ...current, [name]: true }));
    const percentage = form.discount_type === 'percentage';

    // ---- Live validation ----
    const code = form.code.trim();
    const client = {
        code: code === '' ? 'Escribe o genera un código.' : !CODE_REGEX.test(code) ? 'Usa solo letras, números y guiones, de 3 a 30 caracteres.' : taken === code ? 'Ya existe un cupón con este código.' : '',
        discount_value: integerProblem(form.discount_value, { min: 1, max: percentage ? MAX_PERCENTAGE : 100000000, required: true, label: 'el valor del descuento' }),
        max_discount_cop: percentage ? integerProblem(form.max_discount_cop, { min: 100, max: 100000000 }) : '',
        min_subtotal_cop: integerProblem(form.min_subtotal_cop, { min: 0, max: 100000000 }),
        max_uses: integerProblem(form.max_uses, { min: 1, max: 10000000 }),
        ends_at: form.starts_at && form.ends_at && form.ends_at <= form.starts_at ? 'La fecha de fin debe ser posterior a la de inicio.' : '',
    };
    const hasErrors = Object.values(client).some(Boolean);
    const shown = name => errors[name]?.[0] || (client[name] && (touched[name] || (name !== 'code' && form[name] !== '') || name === 'ends_at') ? client[name] : '');

    // Uniqueness while typing (the server checks again on save).
    function checkCode() {
        touch('code');
        if (!CODE_REGEX.test(code)) return;
        request(`/admin/coupons/data?search=${encodeURIComponent(code)}`).then(({ data }) => {
            setTaken(data.some(row => row.code === code && row.id !== id) ? code : '');
        }).catch(() => {});
    }

    // Live example: a purchase of $100.000 (or the minimum, when that is higher).
    const minimum = client.min_subtotal_cop || form.min_subtotal_cop.trim() === '' ? 0 : Number(form.min_subtotal_cop);
    const purchase = Math.max(SAMPLE_PURCHASE, minimum);
    const previewable = !client.discount_value && !client.max_discount_cop;
    const discount = previewable ? previewDiscount(form, purchase) : null;

    function requestClose() {
        if (saving) return;
        if (dirty && !discard) { setDiscard(true); return; }
        setClosing(true);
        window.setTimeout(onClose, 180);
    }

    async function submit(event) {
        event.preventDefault();
        if (hasErrors || saving || loading) { setTouched({ code: true, discount_value: true }); return; }
        setSaving(true); setErrors({}); setFormError('');
        const optional = text => (text.trim() === '' ? null : Number(text.trim()));
        const body = {
            code: code.toUpperCase(), description: form.description.trim() || null, discount_type: form.discount_type, discount_value: Number(form.discount_value.trim()),
            max_discount_cop: percentage ? optional(form.max_discount_cop) : null, min_subtotal_cop: optional(form.min_subtotal_cop) ?? 0,
            max_uses: optional(form.max_uses), exclude_promoted_items: form.exclude_promoted_items,
            is_active: form.is_active, starts_at: form.starts_at || null, ends_at: form.ends_at || null,
        };
        try {
            const response = await request(id ? `/admin/coupons/${id}` : '/admin/coupons', { method: id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            setSaving(false); onSaved(!id, response);
            setClosing(true); window.setTimeout(onClose, 180);
        } catch (error) {
            setSaving(false);
            const fields = error.fields || {};
            setErrors(fields);
            setFormError(Object.keys(fields).length ? '' : error.message);
        }
    }

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (discard) setDiscard(false); else requestClose(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    const err = name => shown(name) && <small className="admin-categories-error" id={`coupon-${name}-error`} role="alert">{shown(name)}</small>;
    const numberField = (name, label, placeholder, { prefix } = {}) => <>
        <label className="admin-promo-field" htmlFor={`coupon-${name}`}><span>{label}</span>
            <span className={`admin-promo-value${prefix ? ' has-prefix' : ''}`}>
                {prefix && <i aria-hidden="true">$</i>}
                <input id={`coupon-${name}`} inputMode="numeric" autoComplete="off" value={form[name]} placeholder={placeholder} onChange={event => patch({ [name]: event.target.value }, name)} onBlur={() => touch(name)} aria-invalid={!!shown(name)} aria-describedby={shown(name) ? `coupon-${name}-error` : undefined} />
            </span></label>
        {err(name)}
    </>;

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={requestClose} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer admin-coupon-drawer" role="dialog" aria-modal="true" aria-labelledby="coupon-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Cupones</span><h2 id="coupon-drawer-title">{id ? 'Editar cupón' : 'Crear cupón'}</h2></div>
                <button type="button" className="admin-categories-close" onClick={requestClose} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <form className="admin-categories-drawer-form" onSubmit={submit} noValidate>
                <div className="admin-categories-drawer-body">
                    {loading ? <p className="admin-categories-hint" role="status">Cargando cupón…</p> : loadError ? <p className="admin-categories-alert" role="alert">{loadError}</p> : <>
                        {uses > 0 && <div className="admin-coupon-notice" role="note"><TriangleAlert aria-hidden="true" /><span>Este cupón ya se usó {uses === 1 ? '1 vez' : `${number(uses)} veces`}. Los cambios no afectan los pedidos ya hechos.</span></div>}

                        <div className="admin-promo-field">
                            <label htmlFor="coupon-code"><span>Código</span></label>
                            <div className="admin-coupon-code-row">
                                <input id="coupon-code" ref={firstField} value={form.code} maxLength={30} autoComplete="off" autoCapitalize="characters" spellCheck="false" placeholder="Ej: HELENA10"
                                    onChange={event => { patch({ code: event.target.value.toUpperCase() }, 'code'); setTaken(''); }} onBlur={checkCode} aria-invalid={!!shown('code')} aria-describedby={shown('code') ? 'coupon-code-error' : undefined} />
                                <button type="button" className="admin-categories-secondary" onClick={() => { const generated = generateCode(); patch({ code: generated }, 'code'); setTaken(''); touch('code'); }}><Dices aria-hidden="true" />Generar</button>
                            </div>
                            {err('code')}
                        </div>

                        <label className="admin-promo-field admin-coupon-field" htmlFor="coupon-description"><span>Descripción (uso interno)</span>
                            <textarea id="coupon-description" maxLength={255} value={form.description} placeholder="Ej: Campaña de bienvenida" onChange={event => patch({ description: event.target.value }, 'description')} /></label>
                        {err('description')}

                        <label className="admin-categories-check" htmlFor="coupon-active"><input id="coupon-active" type="checkbox" checked={form.is_active} onChange={event => patch({ is_active: event.target.checked }, 'is_active')} /><span>Activar cupón</span></label>

                        <fieldset className="admin-promo-type"><legend>Tipo de descuento</legend>
                            <div role="radiogroup" aria-label="Tipo de descuento">{[['percentage', 'Porcentaje'], ['fixed_amount', 'Valor fijo (COP)']].map(([type, label]) => <button key={type} type="button" role="radio" aria-checked={form.discount_type === type} className={form.discount_type === type ? 'is-selected' : ''} onClick={() => patch({ discount_type: type }, 'discount_value', 'max_discount_cop')}>{label}</button>)}</div>
                        </fieldset>

                        <label className="admin-promo-field" htmlFor="coupon-discount_value"><span>Valor</span>
                            <span className={`admin-promo-value ${percentage ? 'has-suffix' : 'has-prefix'}`}>
                                {!percentage && <i aria-hidden="true">$</i>}
                                <input id="coupon-discount_value" inputMode="numeric" autoComplete="off" value={form.discount_value} placeholder={percentage ? '10' : '5000'} onChange={event => patch({ discount_value: event.target.value }, 'discount_value')} onBlur={() => touch('discount_value')} aria-invalid={!!shown('discount_value')} aria-describedby={shown('discount_value') ? 'coupon-discount_value-error' : undefined} />
                                {percentage && <i aria-hidden="true">%</i>}
                            </span></label>
                        {err('discount_value')}

                        {percentage && numberField('max_discount_cop', 'Descuento máximo (opcional)', 'Sin tope', { prefix: true })}
                        {numberField('min_subtotal_cop', 'Compra mínima (opcional)', 'Sin mínimo', { prefix: true })}
                        {numberField('max_uses', 'Máximo de usos total', 'Vacío = ilimitado')}

                        <label className="admin-categories-check" htmlFor="coupon-exclude"><input id="coupon-exclude" type="checkbox" checked={form.exclude_promoted_items} onChange={event => patch({ exclude_promoted_items: event.target.checked }, 'exclude_promoted_items')} /><span>No aplica a productos en promoción</span></label>

                        <div className="admin-promo-dates">
                            <label className="admin-promo-field" htmlFor="coupon-start"><span>Inicio (opcional)</span><input id="coupon-start" type="datetime-local" value={form.starts_at} onChange={event => patch({ starts_at: event.target.value }, 'starts_at', 'ends_at')} /></label>
                            <label className="admin-promo-field" htmlFor="coupon-end"><span>Fin (opcional)</span><input id="coupon-end" type="datetime-local" value={form.ends_at} min={form.starts_at || undefined} onChange={event => patch({ ends_at: event.target.value }, 'ends_at')} aria-invalid={!!shown('ends_at')} /></label>
                        </div>
                        {err('starts_at')}{err('ends_at')}
                        <p className="admin-categories-hint">Las fechas son hora de Colombia. Sin inicio aplica desde ya; sin fin no vence.</p>

                        <section className="admin-coupon-preview" aria-label="Vista previa" aria-live="polite">
                            <h3>Vista previa</h3>
                            {discount === null ? <p className="admin-categories-hint">Escribe un valor válido para ver el descuento.</p>
                                : discount <= 0 ? <p>Con una compra de <strong>{money(purchase)}</strong> este cupón no genera descuento.</p>
                                    : <p>Con una compra de <strong>{money(purchase)}</strong>, el cliente paga <strong>{money(purchase - discount)}</strong> (ahorra {money(discount)}).</p>}
                        </section>
                        {formError && <p className="admin-categories-alert" role="alert">{formError}</p>}
                    </>}
                </div>
                <footer className="admin-categories-drawer-foot">
                    {discard
                        ? <div className="admin-categories-prompt" role="alertdialog" aria-labelledby="coupon-discard-title">
                            <TriangleAlert aria-hidden="true" />
                            <div><strong id="coupon-discard-title">¿Descartar los cambios?</strong><p>Tienes cambios sin guardar. Si cierras ahora, se perderán.</p>
                                <div className="admin-categories-buttons">
                                    <button type="button" className="admin-categories-secondary" autoFocus onClick={() => setDiscard(false)}>Seguir editando</button>
                                    <button type="button" className="admin-categories-danger" onClick={() => { setDiscard(false); setClosing(true); window.setTimeout(onClose, 180); }}>Descartar cambios</button>
                                </div></div>
                        </div>
                        : <div className="admin-categories-buttons">
                            <button type="button" className="admin-categories-secondary" disabled={saving} onClick={requestClose}>Cancelar</button>
                            <button type="submit" className="admin-categories-primary" disabled={hasErrors || saving || loading || Boolean(loadError)}>{saving ? 'Guardando…' : 'Guardar cupón'}</button>
                        </div>}
                </footer>
            </form>
        </div>
    </div>, document.body);
}
