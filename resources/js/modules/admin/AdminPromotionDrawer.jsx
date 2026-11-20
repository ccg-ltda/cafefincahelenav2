import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { TriangleAlert, X } from 'lucide-react';
import './AdminCategories.css';
import './AdminPromotions.css';
import useModalLock from './useModalLock';
import { request } from './adminShared';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';
const MAX_PERCENTAGE = 90;
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;
const EMPTY = { product: null, variantIds: [], is_active: true, discount_type: 'percentage', discount_value: '', starts_at: '', ends_at: '' };

/** Only the live preview computes a price in the browser; the server repeats the same rules (PromotionService) on save. */
function previewRow(base, type, value) {
    const raw = type === 'percentage' ? (base * (100 - value)) / 100 : base - value;
    const final = Math.round(raw / 100) * 100;
    if (raw <= 0 || final <= 0) return { problem: 'El descuento supera el precio' };
    if (final >= base) return { problem: 'El descuento es tan pequeño que el precio no cambia' };
    return { final, saving: base - final };
}

function valueProblem(type, text) {
    const clean = String(text).trim();
    if (clean === '') return 'Escribe el valor del descuento.';
    if (!/^\d+$/.test(clean)) return 'Escribe un número entero, sin signos ni decimales.';
    const value = Number(clean);
    if (value < 1) return 'El valor debe ser de al menos 1.';
    if (type === 'percentage' && value > MAX_PERCENTAGE) return `El porcentaje no puede superar ${MAX_PERCENTAGE}.`;
    return '';
}

const presentation = variant => [variant.weight_grams ? `${variant.weight_grams} g` : null, variant.grind_type].filter(Boolean).join(' · ');

// Right-hand panel to create or edit a promotion. The footer button stays disabled while anything is invalid.
export default function AdminPromotionDrawer({ id, onClose, onSaved }) {
    const panel = useRef(null);
    const firstField = useRef(null);
    const [form, setForm] = useState(EMPTY);
    const [saved, setSaved] = useState(JSON.stringify(EMPTY));
    const [loading, setLoading] = useState(Boolean(id));
    const [loadError, setLoadError] = useState('');
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [saving, setSaving] = useState(false);
    const [closing, setClosing] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [touched, setTouched] = useState({});
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState([]);
    const [picking, setPicking] = useState(!id);
    const [listOpen, setListOpen] = useState(false);

    useModalLock();

    // Follow the visual viewport so the virtual keyboard never hides the footer buttons.
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

    // Editing: load the promotion with its product and published variants.
    useEffect(() => {
        if (!id) { firstField.current?.focus(); return undefined; }
        const controller = new AbortController();
        request(`/admin/promotions/${id}`, { signal: controller.signal }).then(({ data }) => {
            const published = new Set(data.product_option.variants.map(variant => variant.id));
            const loaded = {
                product: data.product_option, variantIds: data.variant_ids.filter(variantId => published.has(variantId)), is_active: data.is_active,
                discount_type: data.discount_type, discount_value: String(data.discount_value), starts_at: data.starts_at_local || '', ends_at: data.ends_at_local || '',
            };
            setForm(loaded); setSaved(JSON.stringify(loaded)); setLoading(false);
        }).catch(error => { if (error.name !== 'AbortError') { setLoadError(error.message); setLoading(false); } });
        return () => controller.abort();
    }, [id]);

    // Product search (published products only).
    useEffect(() => {
        if (!picking) return undefined;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            request(`/admin/promotions/products?search=${encodeURIComponent(query.trim())}`, { signal: controller.signal })
                .then(data => setOptions(data.data)).catch(() => {});
        }, 220);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [query, picking]);

    const dirty = JSON.stringify(form) !== saved;
    const patch = (changes, ...fields) => {
        setForm(current => ({ ...current, ...changes }));
        setErrors(current => { const next = { ...current }; fields.forEach(field => delete next[field]); return next; });
        setFormError('');
    };
    const touch = name => setTouched(current => ({ ...current, [name]: true }));

    function chooseProduct(product) {
        patch({ product, variantIds: product.variants.map(variant => variant.id) }, 'product_id', 'variant_ids', 'discount_value');
        setPicking(false); setListOpen(false); setQuery(''); touch('product');
    }

    const toggleVariant = variantId => patch({ variantIds: form.variantIds.includes(variantId) ? form.variantIds.filter(item => item !== variantId) : [...form.variantIds, variantId] }, 'variant_ids', 'discount_value');

    // ---- Live validation ----
    const selected = useMemo(() => (form.product ? form.product.variants.filter(variant => form.variantIds.includes(variant.id)) : []), [form.product, form.variantIds]);
    const valueError = valueProblem(form.discount_type, form.discount_value);
    const value = valueError ? null : Number(form.discount_value.trim());
    const rows = selected.map(variant => ({ variant, ...(value === null ? {} : previewRow(variant.price_cop, form.discount_type, value)) }));
    const rowProblems = rows.filter(row => row.problem);
    const dateError = form.starts_at && form.ends_at && form.ends_at <= form.starts_at ? 'La fecha de fin debe ser posterior a la de inicio.' : '';
    const client = {
        product_id: form.product ? '' : 'Selecciona un producto.',
        variant_ids: form.product && selected.length === 0 ? 'Selecciona al menos una presentación.' : '',
        discount_value: valueError,
        ends_at: dateError,
    };
    const hasErrors = Boolean(client.product_id || client.variant_ids || client.discount_value || client.ends_at || rowProblems.length);
    const shown = name => errors[name]?.[0] || (client[name] && (touched[name === 'product_id' ? 'product' : name] || (name === 'discount_value' && form.discount_value !== '') || name === 'ends_at' || name === 'variant_ids') ? client[name] : '');

    function requestClose() {
        if (saving) return;
        if (dirty && !discard) { setDiscard(true); return; }
        setClosing(true);
        window.setTimeout(onClose, 180);
    }

    async function submit(event) {
        event.preventDefault();
        if (hasErrors || saving || loading) { setTouched({ product: true, discount_value: true }); return; }
        setSaving(true); setErrors({}); setFormError('');
        const body = { product_id: form.product.id, variant_ids: form.variantIds, discount_type: form.discount_type, discount_value: value, is_active: form.is_active, starts_at: form.starts_at || null, ends_at: form.ends_at || null };
        try {
            const response = await request(id ? `/admin/promotions/${id}` : '/admin/promotions', { method: id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
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

    const err = name => shown(name) && <small className="admin-categories-error" id={`promo-${name}-error`} role="alert">{shown(name)}</small>;

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={requestClose} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer admin-promo-drawer" role="dialog" aria-modal="true" aria-labelledby="promo-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Promociones</span><h2 id="promo-drawer-title">{id ? 'Editar promoción' : 'Nueva promoción'}</h2></div>
                <button type="button" className="admin-categories-close" onClick={requestClose} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <form className="admin-categories-drawer-form" onSubmit={submit} noValidate>
                <div className="admin-categories-drawer-body">
                    {loading ? <p className="admin-categories-hint" role="status">Cargando promoción…</p> : loadError ? <p className="admin-categories-alert" role="alert">{loadError}</p> : <>
                        <div className="admin-promo-field">
                            <label htmlFor="promo-product-search"><span>Producto</span></label>
                            {form.product && !picking
                                ? <div className="admin-promo-selected"><strong>{form.product.name}</strong><button type="button" className="admin-categories-secondary" onClick={() => { setPicking(true); setListOpen(true); }}>Cambiar</button></div>
                                : <div className="admin-promo-combo">
                                    <input id="promo-product-search" ref={firstField} type="search" role="combobox" aria-expanded={listOpen} aria-controls="promo-product-list" aria-autocomplete="list" autoComplete="off" value={query} placeholder="Busca un producto publicado"
                                        onChange={event => { setQuery(event.target.value); setListOpen(true); }} onFocus={() => setListOpen(true)} onBlur={() => touch('product')} aria-invalid={!!shown('product_id')} />
                                    {listOpen && <ul id="promo-product-list" role="listbox" aria-label="Productos publicados">
                                        {options.length === 0 ? <li className="admin-promo-none" role="presentation">No hay productos publicados que coincidan.</li>
                                            : options.map(option => <li key={option.id} role="presentation"><button type="button" role="option" aria-selected={form.product?.id === option.id} onMouseDown={event => event.preventDefault()} onClick={() => chooseProduct(option)}>{option.name}<small>{option.variants.length} {option.variants.length === 1 ? 'presentación' : 'presentaciones'}</small></button></li>)}
                                    </ul>}
                                </div>}
                            {err('product_id')}
                        </div>

                        {form.product && <fieldset className="admin-promo-variants"><legend>Presentaciones</legend>
                            {form.product.variants.map(variant => <label key={variant.id} className="admin-categories-check"><input type="checkbox" checked={form.variantIds.includes(variant.id)} onChange={() => toggleVariant(variant.id)} /><span>{variant.name}{presentation(variant) && <small> · {presentation(variant)}</small>}</span><b>{money(variant.price_cop)}</b></label>)}
                            {err('variant_ids')}
                        </fieldset>}

                        <label className="admin-categories-check" htmlFor="promo-active"><input id="promo-active" type="checkbox" checked={form.is_active} onChange={event => patch({ is_active: event.target.checked }, 'is_active', 'variant_ids')} /><span>Activar promoción</span></label>
                        {errors.is_active && <small className="admin-categories-error" role="alert">{errors.is_active[0]}</small>}

                        <fieldset className="admin-promo-type"><legend>Tipo de descuento</legend>
                            <div role="radiogroup" aria-label="Tipo de descuento">{[['percentage', 'Porcentaje'], ['fixed_amount', 'Valor fijo (COP)']].map(([type, label]) => <button key={type} type="button" role="radio" aria-checked={form.discount_type === type} className={form.discount_type === type ? 'is-selected' : ''} onClick={() => patch({ discount_type: type }, 'discount_value')}>{label}</button>)}</div>
                        </fieldset>

                        <label className="admin-promo-field" htmlFor="promo-value"><span>Valor</span>
                            <span className={`admin-promo-value ${form.discount_type === 'percentage' ? 'has-suffix' : 'has-prefix'}`}>
                                {form.discount_type === 'fixed_amount' && <i aria-hidden="true">$</i>}
                                <input id="promo-value" inputMode="numeric" autoComplete="off" value={form.discount_value} onChange={event => patch({ discount_value: event.target.value }, 'discount_value')} onBlur={() => touch('discount_value')} placeholder={form.discount_type === 'percentage' ? '20' : '5000'} aria-invalid={!!shown('discount_value')} aria-describedby={shown('discount_value') ? 'promo-discount_value-error' : undefined} />
                                {form.discount_type === 'percentage' && <i aria-hidden="true">%</i>}
                            </span></label>
                        {err('discount_value')}

                        <div className="admin-promo-dates">
                            <label className="admin-promo-field" htmlFor="promo-start"><span>Fecha de inicio (opcional)</span><input id="promo-start" type="datetime-local" value={form.starts_at} onChange={event => patch({ starts_at: event.target.value }, 'starts_at', 'ends_at', 'variant_ids')} /></label>
                            <label className="admin-promo-field" htmlFor="promo-end"><span>Fecha de fin (opcional)</span><input id="promo-end" type="datetime-local" value={form.ends_at} min={form.starts_at || undefined} onChange={event => patch({ ends_at: event.target.value }, 'ends_at', 'variant_ids')} aria-invalid={!!shown('ends_at')} /></label>
                        </div>
                        {err('starts_at')}{err('ends_at')}
                        <p className="admin-categories-hint">Las fechas son hora de Colombia. Sin fecha de inicio aplica desde ya; sin fecha de fin no vence.</p>

                        <section className="admin-promo-preview" aria-label="Vista previa" aria-live="polite">
                            <h3>Vista previa</h3>
                            {rows.length === 0 ? <p className="admin-categories-hint">Elige un producto y sus presentaciones para ver los precios.</p>
                                : <div className="admin-promo-preview-scroll"><table><thead><tr><th scope="col">Presentación</th><th scope="col">Precio base</th><th scope="col">Descuento</th><th scope="col">Precio promocional</th></tr></thead>
                                    <tbody>{rows.map(row => <tr key={row.variant.id} className={row.problem ? 'is-invalid' : ''}>
                                        <td>{row.variant.name}</td><td>{money(row.variant.price_cop)}</td>
                                        {row.problem ? <td colSpan="2" className="admin-promo-problem">{row.problem}</td>
                                            : value === null ? <td colSpan="2" className="admin-promo-pending">—</td>
                                                : <><td>{form.discount_type === 'percentage' ? `-${value}%` : `-${money(value)}`}<small>(−{money(row.saving)})</small></td><td><strong>{money(row.final)}</strong></td></>}
                                    </tr>)}</tbody></table></div>}
                        </section>
                        {formError && <p className="admin-categories-alert" role="alert">{formError}</p>}
                    </>}
                </div>
                <footer className="admin-categories-drawer-foot">
                    {discard
                        ? <div className="admin-categories-prompt" role="alertdialog" aria-labelledby="promo-discard-title">
                            <TriangleAlert aria-hidden="true" />
                            <div><strong id="promo-discard-title">¿Descartar los cambios?</strong><p>Tienes cambios sin guardar. Si cierras ahora, se perderán.</p>
                                <div className="admin-categories-buttons">
                                    <button type="button" className="admin-categories-secondary" autoFocus onClick={() => setDiscard(false)}>Seguir editando</button>
                                    <button type="button" className="admin-categories-danger" onClick={() => { setDiscard(false); setClosing(true); window.setTimeout(onClose, 180); }}>Descartar cambios</button>
                                </div></div>
                        </div>
                        : <div className="admin-categories-buttons">
                            <button type="button" className="admin-categories-secondary" disabled={saving} onClick={requestClose}>Cancelar</button>
                            <button type="submit" className="admin-categories-primary" disabled={hasErrors || saving || loading || Boolean(loadError)}>{saving ? 'Guardando…' : id ? 'Guardar cambios' : 'Crear promoción'}</button>
                        </div>}
                </footer>
            </form>
        </div>
    </div>, document.body);
}
