import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Boxes, Minus, Plus, X } from 'lucide-react';
import useModalLock from './useModalLock';
import { request } from './adminShared';
import './AdminStockDialog.css';

const MAX_STOCK = 1000000;
const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';
const number = value => new Intl.NumberFormat('es-CO').format(value);
const when = value => value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(value)) : '—';
const signed = value => `${value > 0 ? '+' : value < 0 ? '−' : ''}${number(Math.abs(value))}`;

// Small modal to set the exact available stock of a variant (the difference is what the history records).
// The server is the source of truth: it answers 409 if the stock changed while the modal was open.
export default function AdminStockDialog({ product, variant, opener, onClose, onSaved }) {
    useModalLock();
    const panel = useRef(null);
    const input = useRef(null);
    const [stock, setStock] = useState(variant.stock_quantity);
    const [value, setValue] = useState(String(variant.stock_quantity));
    const [movements, setMovements] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState({});
    const [saved, setSaved] = useState('');
    const base = `/admin/products/${product.product_id}/variants/${variant.id}`;

    useEffect(() => {
        input.current?.focus();
        input.current?.select();
        const keepFocus = event => { if (panel.current && !panel.current.contains(event.target)) input.current?.focus(); };
        document.addEventListener('focusin', keepFocus);
        return () => {
            document.removeEventListener('focusin', keepFocus);
            window.setTimeout(() => { if (opener?.isConnected) opener.focus({ preventScroll: true }); }, 0);
        };
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const controller = new AbortController();
        request(`${base}/stock-movements`, { signal: controller.signal })
            .then(data => { setStock(data.stock_quantity); setValue(current => (current === String(variant.stock_quantity) ? String(data.stock_quantity) : current)); setMovements(data.data); })
            .catch(failure => { if (failure.name !== 'AbortError') setMovements([]); });
        return () => controller.abort();
    }, [base]); // eslint-disable-line react-hooks/exhaustive-deps

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (!busy) onClose(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const firstNode = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === firstNode || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); firstNode.focus(); }
    }

    const valid = /^\d{1,7}$/.test(value.trim()) && Number(value.trim()) <= MAX_STOCK;
    const target = valid ? Number(value.trim()) : null;
    const difference = target === null ? 0 : target - stock;
    const problem = value.trim() !== '' && !valid ? 'Escribe un número entero mayor o igual a 0.' : '';
    const canSave = !busy && valid && difference !== 0;
    const step = delta => { setValue(String(Math.min(MAX_STOCK, Math.max(0, (valid ? target : stock) + delta)))); setErrors({}); setError(''); setSaved(''); };

    async function submit(event) {
        event.preventDefault();
        if (!canSave) return;
        setBusy(true); setError(''); setErrors({}); setSaved('');
        try {
            const response = await request(`${base}/stock`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ stock_quantity: target, expected_stock: stock }) });
            setStock(response.stock_quantity); setValue(String(response.stock_quantity)); setMovements(response.movements);
            setSaved(`Stock actualizado: ${number(response.stock_quantity)}.`);
            onSaved(response);
        } catch (failure) {
            if (failure.status === 409 && failure.currentStock != null) { setStock(failure.currentStock); setError(failure.message); }
            else { setErrors(failure.fields || {}); setError(failure.fields && Object.keys(failure.fields).length ? '' : failure.message); }
        } finally { setBusy(false); }
    }

    const fieldError = errors.stock_quantity?.[0];
    const detail = [variant.weight_grams ? `${variant.weight_grams} g` : null, variant.grind_type].filter(Boolean).join(' · ');

    return createPortal(<div className="admin-confirm-layer">
        <div className="admin-confirm-overlay" onClick={() => { if (!busy) onClose(); }} aria-hidden="true" />
        <div ref={panel} className="admin-confirm-dialog admin-stock-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-stock-title" onKeyDown={keyDown}>
            <div className="admin-stock-head">
                <Boxes aria-hidden="true" />
                <div><h2 id="admin-stock-title">Ajustar stock</h2><p>{product.product_name} · {variant.name}{detail && ` (${detail})`} · <code>{variant.sku}</code></p></div>
                <button type="button" className="admin-stock-close" onClick={onClose} disabled={busy} aria-label="Cerrar"><X aria-hidden="true" /></button>
            </div>

            <form onSubmit={submit} noValidate>
                <label className="admin-stock-field" htmlFor="stock-quantity"><span>Stock disponible</span>
                    <span className="admin-stock-stepper">
                        <button type="button" aria-label="Restar 1" disabled={busy || (valid ? target : stock) <= 0} onClick={() => step(-1)}><Minus aria-hidden="true" /></button>
                        <input ref={input} id="stock-quantity" inputMode="numeric" autoComplete="off" value={value} onChange={event => { setValue(event.target.value); setErrors({}); setError(''); setSaved(''); }} aria-invalid={!!(problem || fieldError)} aria-describedby="stock-quantity-help" disabled={busy} />
                        <button type="button" aria-label="Sumar 1" disabled={busy || (valid ? target : stock) >= MAX_STOCK} onClick={() => step(1)}><Plus aria-hidden="true" /></button>
                    </span>
                </label>
                <p id="stock-quantity-help" className={`admin-stock-preview${problem || fieldError ? ' is-error' : ''}`} role="status">
                    {problem || fieldError || (difference !== 0 ? <>Cambio: de <strong>{number(stock)}</strong> a <strong>{number(target)}</strong> ({signed(difference)})</> : <>Stock actual: <strong>{number(stock)}</strong>. Cambia la cantidad para guardar.</>)}
                </p>
                {error && <p className="admin-confirm-error" role="alert">{error}</p>}
                {saved && <p className="admin-stock-saved" role="status">{saved}</p>}

                <div className="admin-confirm-buttons">
                    <button type="button" className="admin-confirm-cancel" onClick={onClose} disabled={busy}>Cerrar</button>
                    <button type="submit" className="admin-confirm-primary" disabled={!canSave}>{busy ? 'Guardando…' : 'Guardar'}</button>
                </div>
            </form>

            <section className="admin-stock-history" aria-label="Últimos movimientos">
                <h3>Últimos movimientos</h3>
                {movements === null ? <p className="admin-stock-hint">Cargando movimientos…</p> : movements.length === 0 ? <p className="admin-stock-hint">Esta variante aún no tiene movimientos.</p>
                    : <div className="admin-stock-scroll" tabIndex="0" role="region" aria-label="Movimientos, desplazable horizontalmente"><table>
                        <thead><tr><th scope="col">Fecha</th><th scope="col">Cambio</th><th scope="col">Usuario</th><th scope="col">Origen</th></tr></thead>
                        <tbody>{movements.map(item => <tr key={item.id}><td>{when(item.created_at)}</td><td>de {number(item.from)} a {number(item.stock_after)} <strong className={item.quantity > 0 ? 'is-plus' : 'is-minus'}>({signed(item.quantity)})</strong></td><td>{item.user_name || '—'}</td><td>{item.origin}</td></tr>)}</tbody>
                    </table></div>}
            </section>
        </div>
    </div>, document.body);
}
