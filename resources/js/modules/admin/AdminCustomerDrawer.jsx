import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { MessageCircle, X } from 'lucide-react';
import './AdminCategories.css';
import './AdminCustomers.css';
import AdminConfirmDialog from './AdminConfirmDialog';
import StatusBadge from './StatusBadge';
import useModalLock from './useModalLock';
import { dayText, daysAgoText, request, titleCase, whatsappUrl } from './adminShared';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;
const dateTime = value => (value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(value)) : '—');
const day = value => (value ? dayText(value, true) : '—');

// Read-only detail of a customer in a right-hand panel. The URL reflects the open customer (/admin/customers/{id}).
export default function AdminCustomerDrawer({ id, onClose, onChanged }) {
    const panel = useRef(null);
    const closeButton = useRef(null);
    const [customer, setCustomer] = useState(null);
    const [state, setState] = useState('loading');
    const [closing, setClosing] = useState(false);
    const [confirming, setConfirming] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useModalLock();

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        request(`/admin/customers/${id}/detail`, { signal: controller.signal })
            .then(({ data }) => { setCustomer(data); setState('success'); })
            .catch(failure => { if (failure.name !== 'AbortError') setState(failure.status === 404 || failure.confirmedRejected ? 'missing' : 'error'); });
        return () => controller.abort();
    }, [id]);

    useEffect(() => { closeButton.current?.focus(); }, []);

    function close() {
        setClosing(true);
        window.setTimeout(onClose, 180);
    }

    async function optOut() {
        if (busy) return;
        setBusy(true); setError('');
        try {
            const response = await request(`/admin/customers/${id}/marketing-opt-out`, { method: 'PATCH' });
            setCustomer(response.data); setConfirming(null); onChanged();
        } catch (failure) { setError(failure.message); } finally { setBusy(false); }
    }

    function keyDown(event) {
        if (confirming) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    const consent = customer?.consents;
    const promoText = !customer ? '' : customer.accepts_marketing ? `Acepta desde ${day(consent.marketing_opt_in_at)}`
        : consent.marketing_opt_out_at ? `Pidió no recibir promociones el ${day(consent.marketing_opt_out_at)}` : 'No ha aceptado recibir promociones';

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={close} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer admin-customer-drawer" role="dialog" aria-modal="true" aria-labelledby="customer-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Cliente</span><h2 id="customer-drawer-title">{customer ? titleCase(customer.name) : 'Cliente'}</h2></div>
                <button type="button" ref={closeButton} className="admin-categories-close" onClick={close} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <div className="admin-categories-drawer-form">
                <div className="admin-categories-drawer-body">
                    {state === 'loading' && <p className="admin-categories-hint" role="status">Cargando cliente…</p>}
                    {state === 'missing' && <p className="admin-categories-alert" role="alert">Este cliente no existe o no tiene compras válidas.</p>}
                    {state === 'error' && <p className="admin-categories-alert" role="alert">No se pudo cargar el cliente.</p>}
                    {state === 'success' && <>
                        <section className="admin-customer-section"><h3>Contacto</h3><dl>
                            <div><dt>Correo</dt><dd>{customer.email || 'Sin correo'}</dd></div>
                            <div><dt>Celular</dt><dd>{customer.phone.replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3')}</dd></div>
                            <div><dt>Ciudad</dt><dd>{customer.city}{customer.department ? `, ${customer.department}` : ''}</dd></div>
                            <div><dt>Última dirección</dt><dd>{customer.last_address || '—'}{customer.last_address_details ? ` — ${customer.last_address_details}` : ''}</dd></div>
                        </dl></section>

                        <section className="admin-customer-section"><h3>Consentimientos</h3><dl>
                            <div><dt>Tratamiento de datos</dt><dd>{consent.data_consent_at ? `Autorizó el ${day(consent.data_consent_at)}` : '—'}</dd></div>
                            <div><dt>Promociones</dt><dd><span className={customer.accepts_marketing ? 'admin-customers-accepts' : 'admin-customer-no-promo'}>{customer.accepts_marketing ? 'Acepta' : 'No acepta'}</span><small>{promoText}</small></dd></div>
                        </dl></section>

                        <section className="admin-customer-section"><h3>Métricas</h3>
                            <div className="admin-customer-metrics">
                                <div><span>Pedidos válidos</span><strong>{number(customer.metrics.orders)}</strong></div>
                                <div><span>Total gastado</span><strong className="admin-customers-money">{money(customer.metrics.spent_cop)}</strong></div>
                                <div><span>Ticket promedio</span><strong className="admin-customers-money">{money(customer.metrics.average_ticket_cop)}</strong></div>
                                <div><span>Primera compra</span><strong>{day(customer.first_order_at)}</strong></div>
                                <div><span>Última compra</span><strong>{day(customer.last_order_at)}<small>{daysAgoText(customer.last_order_at)}</small></strong></div>
                            </div></section>

                        <section className="admin-customer-section"><h3>Productos más comprados</h3>
                            {customer.top_products.length ? <ol className="admin-customer-top">{customer.top_products.map(product => <li key={product.name}><span>{product.name}</span><b>{number(product.units)} {product.units === 1 ? 'unidad' : 'unidades'}</b></li>)}</ol> : <p className="admin-categories-hint">Aún sin productos registrados.</p>}</section>

                        <section className="admin-customer-section"><h3>Pedidos</h3>
                            <ul className="admin-customer-orders">{customer.orders.map(order => <li key={order.id} className={order.counts ? '' : 'is-cancelled'}>
                                <div><a href={`/admin/orders/${order.id}`}>{order.reference}</a><small>{dateTime(order.created_at)}{order.coupon_code ? ` · cupón ${order.coupon_code}` : ''}</small></div>
                                <div className="admin-customer-order-end"><strong>{money(order.total_cop)}</strong><StatusBadge value={`order_${order.status}`} />{!order.counts && <small>No cuenta en las métricas</small>}</div>
                            </li>)}</ul></section>
                        {error && <p className="admin-categories-alert" role="alert">{error}</p>}
                    </>}
                </div>
                <footer className="admin-categories-drawer-foot"><div className="admin-categories-buttons admin-customer-foot">
                    {state === 'success' && <a className="admin-categories-secondary admin-customer-whatsapp-link" href={whatsappUrl(customer)} target="_blank" rel="noopener noreferrer"><MessageCircle aria-hidden="true" />WhatsApp</a>}
                    {state === 'success' && <button type="button" className="admin-categories-danger" disabled={!customer.accepts_marketing} onClick={event => { setError(''); setConfirming(event.currentTarget); }}>Marcar que no quiere promociones</button>}
                    {state !== 'success' && <button type="button" className="admin-categories-secondary" onClick={close}>Cerrar</button>}
                </div></footer>
            </div>
        </div>
        {confirming && <AdminConfirmDialog opener={confirming} busy={busy} error={error} tone="danger" title="¿Marcar que no quiere promociones?" confirmLabel="Marcar" onCancel={() => { setConfirming(null); setError(''); }} onConfirm={optOut}>
            <p>{titleCase(customer.name)} dejará de aparecer como cliente que acepta promociones.</p>
            <p>No se puede deshacer desde aquí: solo una compra nueva con la casilla de promociones marcada lo reactiva.</p>
        </AdminConfirmDialog>}
    </div>, document.body);
}
