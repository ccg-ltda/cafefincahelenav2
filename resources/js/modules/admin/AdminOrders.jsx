import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Check, ChevronLeft, ChevronRight, ClipboardCopy, Eye, Link2, Search } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import './AdminOrders.css';
import './PaymentAlerts.css';
import { request, titleCase } from './adminShared';
import StatusBadge, { ChannelPill, paymentState, statusLabel } from './StatusBadge';
import { useAdminToast } from './AdminToast';
import AdminOrderFulfillment from './AdminOrderFulfillment';

// Order and payment states are shown exactly as stored. The status only changes through the fulfillment buttons (OrderStatusService on the server).
// What a role sees comes from the server (it omits email, prices, coupon and payments for roles without them); `can` only decides what to draw.
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;
const dateTime = value => value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(value)) : '—';
const detailId = () => window.location.pathname.match(/^\/admin\/orders\/(\d+)$/)?.[1] || null;

const PaymentBadge = ({ order }) => <StatusBadge value={paymentState(order)} />;

// Payment method as Wompi reports it.
const METHODS = { CARD: 'Tarjeta', NEQUI: 'Nequi', PSE: 'PSE', BANCOLOMBIA_TRANSFER: 'Bancolombia', BANCOLOMBIA_COLLECT: 'Corresponsal Bancolombia', BANCOLOMBIA_QR: 'QR Bancolombia' };

// The payment attempts of an order. "Consultar estado en Wompi" asks the API (the same rules as the webhook apply); nothing here can
// mark an order as paid by hand. The red notice stays until an administrator marks it "Revisado" (who and when go to the Historial).
// A manual order's payment link: copy the one that is still valid, or (only once it has expired) generate a new one for the same order.
function ManualLinkBlock({ order, onReload }) {
    const toast = useAdminToast();
    const [busy, setBusy] = useState('');
    const [copied, setCopied] = useState(false);
    const { link, link_expires_at: expiresAt, payment_due_at: dueAt, created_by: createdBy } = order.manual;

    async function copy() {
        if (busy) return;
        setBusy('copy');
        try {
            const data = await request(`/admin/orders/${order.id}/checkout-link`);
            await navigator.clipboard.writeText(data.payment.url);
            setCopied(true); window.setTimeout(() => setCopied(false), 2500);
            toast.success('Link copiado', `Pedido ${order.reference}.`);
        } catch (failure) {
            toast.error('No se pudo copiar el link', failure.data?.message || failure.message);
            onReload();
        } finally { setBusy(''); }
    }

    async function renew() {
        if (busy) return;
        setBusy('renew');
        try {
            await request(`/admin/orders/${order.id}/checkout-link`, { method: 'POST' });
            toast.success('Nuevo link generado', 'Ya puedes copiarlo y enviarlo al cliente.');
            onReload();
        } catch (failure) {
            toast.error('No se pudo generar el link', failure.data?.message || failure.message);
            onReload();
        } finally { setBusy(''); }
    }

    return <div className="admin-order-manual">
        <p><strong>Pedido manual</strong>{createdBy ? ` registrado por ${titleCase(createdBy)}` : ''}.{link !== 'none' && dueAt ? ` Si nadie paga antes del ${dateTime(dueAt)}, se cancela solo.` : ''}</p>
        {link === 'valid' && <><p>El link de pago vigente vence el {dateTime(expiresAt)}</p>
            <div className="admin-order-manual-actions"><button type="button" className="admin-categories-secondary" disabled={Boolean(busy)} onClick={copy}>{copied ? <><Check aria-hidden="true" /> Copiado</> : <><ClipboardCopy aria-hidden="true" /> {busy === 'copy' ? 'Copiando…' : 'Copiar link'}</>}</button></div></>}
        {link === 'expired' && <><p>El link de pago venció. Genera uno nuevo para el mismo pedido (el plazo para pagar se renueva).</p>
            <div className="admin-order-manual-actions"><button type="button" className="admin-categories-primary" disabled={Boolean(busy)} onClick={renew}><Link2 aria-hidden="true" /> {busy === 'renew' ? 'Generando…' : 'Generar nuevo link de pago'}</button></div></>}
    </div>;
}

function PaymentSection({ order, can, onReload }) {
    const toast = useAdminToast();
    const [busy, setBusy] = useState('');
    const alertPayment = order.payments.find(payment => payment.review_required_at && !payment.reviewed_at);

    async function run(kind) {
        if (busy) return;
        setBusy(kind);
        try {
            await request(`/admin/orders/${order.id}/wompi/${kind}`, { method: 'POST' });
            toast.success(kind === 'refresh' ? 'Estado consultado en Wompi' : 'Pago marcado como revisado', `Pedido ${order.reference}.`);
            onReload();
        } catch (failure) {
            toast.error(kind === 'refresh' ? 'No se pudo consultar a Wompi' : 'No se pudo marcar como revisado',
                failure.status === 422 ? 'Wompi no está configurado en este servidor.' : failure.status === 503 ? 'Wompi no respondió. Inténtalo de nuevo en un momento.' : failure.message);
        } finally { setBusy(''); }
    }

    return <section className="dashboard-card admin-order-card admin-order-payments">
        {order.manual && <ManualLinkBlock order={order} onReload={onReload} />}
        <div className="admin-fulfillment-head"><h2>Pago</h2>
            {can('payments.refresh') && order.payments.some(payment => payment.provider === 'wompi') && <button type="button" className="admin-categories-secondary" disabled={Boolean(busy)} onClick={() => run('refresh')}>{busy === 'refresh' ? 'Consultando…' : 'Consultar estado en Wompi'}</button>}</div>
        {alertPayment && <div className="admin-payment-alert" role="alert">
            <strong>Pago recibido en pedido cancelado</strong>
            <p>{order.reference} · {money(alertPayment.amount_cop)} · revisar reembolso en Wompi.</p>
            {can('payments.review') && <button type="button" className="admin-categories-primary" disabled={Boolean(busy)} onClick={() => run('review')}>{busy === 'review' ? 'Guardando…' : 'Marcar como revisado'}</button>}
        </div>}
        {order.payments.length === 0 ? <p className="admin-order-empty">Aún no hay intentos de pago registrados para este pedido.</p> : order.payments.map(payment => <dl key={payment.id} className="admin-order-payment">
            <div><dt>Proveedor</dt><dd>{payment.provider}</dd></div>
            <div><dt>Referencia</dt><dd>{payment.reference}</dd></div>
            {payment.external_transaction_id && <div><dt>ID de transacción</dt><dd>{payment.external_transaction_id}</dd></div>}
            <div><dt>Estado registrado</dt><dd><StatusBadge value={payment.status} /></dd></div>
            {payment.payment_method_type && <div><dt>Método (según Wompi)</dt><dd>{METHODS[payment.payment_method_type] ?? payment.payment_method_type}</dd></div>}
            {payment.status_message && <div><dt>Motivo</dt><dd>{payment.status_message}</dd></div>}
            <div><dt>Monto</dt><dd>{money(payment.amount_cop)}</dd></div>
            <div><dt>Creado</dt><dd>{payment.created_at ? dateTime(payment.created_at) : '—'}</dd></div>
            <div><dt>Confirmado</dt><dd>{payment.confirmed_at ? dateTime(payment.confirmed_at) : 'No confirmado'}</dd></div>
            <div><dt>Verificado por el sistema</dt><dd>{payment.verified_at ? dateTime(payment.verified_at) : 'Sin verificar'}</dd></div>
            {payment.reviewed_at && <div><dt>Alerta revisada</dt><dd>{dateTime(payment.reviewed_at)}</dd></div>}
        </dl>)}
    </section>;
}

// The emails of the order (admin only): what was sent, to whom (masked) and when. "Reenviar" sends it again with the current data of
// the order; the server refuses it while it is still queued and records who did it in the Historial.
function EmailsSection({ order, onReload }) {
    const toast = useAdminToast();
    const [busy, setBusy] = useState(0);

    async function resend(row) {
        if (busy) return;
        setBusy(row.id);
        try {
            await request(`/admin/orders/${order.id}/notifications/${row.id}/resend`, { method: 'POST' });
            toast.success('Correo en cola', `Se reenviará «${row.label}» del pedido ${order.reference}.`);
            onReload();
        } catch (failure) {
            toast.error('No se pudo reenviar el correo', failure.message);
        } finally { setBusy(0); }
    }

    return <section className="dashboard-card admin-order-card admin-order-emails">
        <div className="admin-fulfillment-head"><h2>Correos</h2><a className="admin-categories-secondary" href={`/admin/emails/preview?order=${order.id}`} target="_blank" rel="noopener noreferrer">Vista previa</a></div>
        {order.notifications.length === 0 ? <p className="admin-order-empty">Este pedido todavía no ha generado correos.</p> : <ul className="admin-order-email-list">
            {order.notifications.map(row => <li key={row.id}>
                <div><strong>{row.label}</strong><small>{row.audience === 'admin' ? 'Administrador' : 'Cliente'} · {row.recipient}</small>{row.error && row.status !== 'sent' && <small className="admin-order-email-error">{row.error}</small>}</div>
                <div className="admin-order-email-meta"><StatusBadge value={`mail_${row.status}`} /><small>{row.sent_at ? dateTime(row.sent_at) : row.attempts > 0 ? `${row.attempts} intento${row.attempts === 1 ? '' : 's'}` : '—'}</small></div>
                <button type="button" className="admin-categories-secondary" disabled={Boolean(busy) || row.status === 'queued'} onClick={() => resend(row)}>{busy === row.id ? 'Enviando…' : 'Reenviar'}</button>
            </li>)}
        </ul>}
    </section>;
}

function OrderDetail({ id, can }) {
    const toast = useAdminToast();
    const [state, setState] = useState('loading');
    const [order, setOrder] = useState(null);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        request(`/admin/orders/${id}/detail`, { signal: controller.signal })
            .then(data => { setOrder(data.order); setState('success'); })
            .catch(error => {
                if (error.name === 'AbortError') return;
                if (error.confirmedRejected) setState('missing');
                else { setState('error'); toast.error('No se pudo consultar el pedido', 'Comprueba tu conexión e inténtalo nuevamente.'); }
            });
        return () => controller.abort();
    }, [id, retry]);

    const back = <a className="admin-categories-back" href="/admin/orders"><ArrowLeft aria-hidden="true" /> Volver a pedidos</a>;
    if (state !== 'success') return <section className="admin-products">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Ventas</span><h1>Pedido</h1></div><div className="admin-products-header-actions">{back}</div></header>
        <div className="dashboard-card admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando pedido…' : state === 'missing' ? 'Pedido no encontrado.' : <>No se pudo cargar el pedido. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></>}</div>
    </section>;

    const items = order.items;
    return <section className="admin-products admin-order">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Ventas</span><h1>Pedido {order.reference}<ChannelPill channel={order.channel} /></h1><p>Registrado el {dateTime(order.created_at)}</p></div><div className="admin-products-header-actions">{back}</div></header>
        <div className="admin-order-grid">
            <section className="dashboard-card admin-order-card"><h2>Datos generales</h2><dl>
                <div><dt>Número</dt><dd>#{order.id}</dd></div>
                <div><dt>Referencia</dt><dd>{order.reference}</dd></div>
                <div><dt>Fecha de creación</dt><dd>{dateTime(order.created_at)}</dd></div>
                <div><dt>Estado del pedido</dt><dd><StatusBadge value={`order_${order.status}`} /></dd></div>
                {can('orders.view_financials') && <div><dt>Estado del pago</dt><dd><PaymentBadge order={order} /></dd></div>}
            </dl></section>
            <section className="dashboard-card admin-order-card"><h2>Cliente</h2><dl>
                <div><dt>Nombre</dt><dd>{order.customer_name}</dd></div>
                {order.customer_email !== undefined && <div><dt>Correo electrónico</dt><dd>{order.customer_email || 'Sin correo'}</dd></div>}
                <div><dt>Teléfono</dt><dd>{order.customer_phone}</dd></div>
                <div><dt>Ciudad</dt><dd>{order.delivery_city}</dd></div>
                <div><dt>Dirección de entrega</dt><dd>{order.delivery_address}{order.delivery_details ? ` — ${order.delivery_details}` : ''}</dd></div>
                {order.delivery_department && <div><dt>Departamento</dt><dd>{order.delivery_department}</dd></div>}
                {order.order_notes && <div><dt>Notas del pedido</dt><dd>{order.order_notes}</dd></div>}
            </dl></section>
        </div>
        <AdminOrderFulfillment order={order} can={can} onChange={setOrder} onStale={() => setRetry(value => value + 1)} />
        <section className="dashboard-card admin-products-list admin-order-block"><div className="admin-products-list-head"><h2>Productos</h2><span>{items.length} {items.length === 1 ? 'línea' : 'líneas'}</span></div>
            {items.length > 0
                ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Productos del pedido, desplazable horizontalmente"><table className={can('orders.view_financials') ? undefined : 'admin-order-noprice'}><thead><tr><th scope="col">Producto</th><th scope="col">SKU</th><th scope="col">Cantidad</th>{can('orders.view_financials') && <><th scope="col">Precio unitario</th><th scope="col">Subtotal</th></>}</tr></thead>
                    <tbody>{items.map(item => <tr key={item.id}><td><strong>{item.name}</strong></td><td><code>{item.sku}</code></td><td>{number(item.quantity)}</td>{can('orders.view_financials') && <><td>{money(item.unit_price_cop)}</td><td>{money(item.subtotal_cop)}</td></>}</tr>)}</tbody></table></div>
                : <div className="admin-products-message">Este pedido no tiene productos registrados.</div>}
        </section>
        {can('orders.view_financials') && <div className="admin-order-grid">
            <section className="dashboard-card admin-order-card"><h2>Resumen económico</h2><dl className="admin-order-totals">
                <div><dt>Subtotal</dt><dd>{money(order.subtotal_cop)}</dd></div>
                <div><dt>Envío</dt><dd>{order.shipping_cop > 0 ? money(order.shipping_cop) : 'Sin cargo'}</dd></div>
                {order.discount_cop > 0 && <div><dt>Descuento{order.coupon_code ? <> (cupón <code>{order.coupon_code}</code>)</> : ''}</dt><dd>− {money(order.discount_cop)}</dd></div>}
                <div className="is-total"><dt>Total ({order.currency})</dt><dd>{money(order.total_cop)}</dd></div>
            </dl></section>
            <PaymentSection order={order} can={can} onReload={() => setRetry(value => value + 1)} />
        </div>}
        {order.notifications && <EmailsSection order={order} onReload={() => setRetry(value => value + 1)} />}
    </section>;
}

function OrderList({ can }) {
    const toast = useAdminToast();
    const initial = useRef(new URLSearchParams(window.location.search));
    const [draft, setDraft] = useState(initial.current.get('search') || '');
    const [search, setSearch] = useState(draft.trim());
    const [status, setStatus] = useState(initial.current.get('status') || '');
    const [paymentStatus, setPaymentStatus] = useState(initial.current.get('payment_status') || '');
    const [coupon, setCoupon] = useState(['with', 'without'].includes(initial.current.get('coupon')) ? initial.current.get('coupon') : '');
    const [channel, setChannel] = useState(['web', 'manual'].includes(initial.current.get('channel')) ? initial.current.get('channel') : '');
    const [page, setPage] = useState(Math.max(1, Number(initial.current.get('page')) || 1));
    const [state, setState] = useState('loading');
    const [result, setResult] = useState(null);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        const timer = window.setTimeout(() => { const next = draft.trim(); if (next !== search) { setSearch(next); setPage(1); } }, 350);
        return () => window.clearTimeout(timer);
    }, [draft, search]);

    useEffect(() => {
        const controller = new AbortController();
        const params = new URLSearchParams();
        if (page > 1) params.set('page', String(page));
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (paymentStatus) params.set('payment_status', paymentStatus);
        if (coupon) params.set('coupon', coupon);
        if (channel) params.set('channel', channel);
        window.history.replaceState(null, '', `/admin/orders${params.size ? `?${params}` : ''}`);
        setState('loading');
        request(`/admin/orders/data?${params}`, { signal: controller.signal })
            .then(data => { setResult(data); setState('success'); })
            .catch(error => {
                if (error.name === 'AbortError') return;
                setState('error');
                toast.error('No se pudieron cargar los pedidos', 'Comprueba tu conexión e inténtalo nuevamente.');
            });
        return () => controller.abort();
    }, [search, status, paymentStatus, coupon, channel, page, retry]);

    const summary = result?.summary;
    const filtered = Boolean(search || status || paymentStatus || coupon || channel);
    const meta = result?.meta;
    return <section className="admin-products">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Ventas</span><h1>Pedidos</h1><p>{can('orders.view_financials') ? 'Consulta las compras registradas y avanza su preparación y entrega.' : can('orders.view_all') ? 'Pedidos activos para preparar, enviar y entregar.' : 'Tus pedidos asignados, incluidos los ya entregados.'}</p></div></header>
        {summary && <div className="admin-orders-summary">
            <div className="dashboard-card"><span>Total de pedidos</span><strong>{number(summary.total)}</strong></div>
            <div className="dashboard-card"><span>Pendientes de pago</span><strong>{number(summary.pending)}</strong></div>
            <div className="dashboard-card"><span>Entregados</span><strong>{number(summary.completed)}</strong></div>
        </div>}
        <div className="admin-products-filters admin-orders-filters dashboard-card">
            <label className="admin-products-search"><span>Buscar</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={draft} maxLength={100} onChange={event => setDraft(event.target.value)} placeholder={can('orders.view_customer_email') ? 'Número, referencia, cliente o correo' : 'Número, referencia o cliente'} /></span></label>
            <label><span>Estado del pedido</span><select value={status} onChange={event => { setStatus(event.target.value); setPage(1); }}><option value="">Todos los estados</option>{(result?.options.statuses || (status ? [status] : [])).map(value => <option key={value} value={value}>{statusLabel(`order_${value}`)}</option>)}</select></label>
            {can('orders.view_financials') && <label><span>Estado del pago</span><select value={paymentStatus} onChange={event => { setPaymentStatus(event.target.value); setPage(1); }}><option value="">Todos los pagos</option>{(result?.options.payment_statuses || (paymentStatus ? [paymentStatus] : [])).map(value => <option key={value} value={value}>{statusLabel(value)}</option>)}</select></label>}
            {can('orders.view_financials') && <label><span>Cupón</span><select value={coupon} onChange={event => { setCoupon(event.target.value); setPage(1); }}><option value="">Con y sin cupón</option><option value="with">Con cupón</option><option value="without">Sin cupón</option></select></label>}
            <label><span>Canal</span><select value={channel} onChange={event => { setChannel(event.target.value); setPage(1); }}><option value="">Tienda y manual</option><option value="web">Tienda</option><option value="manual">Manual</option></select></label>
        </div>
        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Listado de pedidos</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'resultado' : 'resultados'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0
                ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de pedidos, desplazable horizontalmente"><table className="admin-orders-table"><thead><tr><th scope="col">Pedido</th><th scope="col">Fecha</th><th scope="col">Cliente</th>{can('orders.view_financials') && <><th scope="col" className="is-total">Total</th><th scope="col" className="is-coupon">Cupón</th></>}<th scope="col">Estado</th>{can('orders.view_financials') && <th scope="col">Pago</th>}<th scope="col">Acciones</th></tr></thead>
                    <tbody>{result.data.map(order => <tr key={order.id} className={order.payment_alert ? 'has-payment-alert' : undefined}>
                        <td data-label="Pedido"><strong>{order.reference}</strong><ChannelPill channel={order.channel} /><small className="admin-orders-id">#{order.id}</small></td>
                        <td data-label="Fecha">{dateTime(order.created_at)}</td>
                        <td data-label="Cliente"><strong className="admin-orders-customer">{titleCase(order.customer_name)}</strong><small className="admin-orders-email">{order.customer_email ?? `${order.customer_phone} · ${order.delivery_city}`}</small></td>
                        {can('orders.view_financials') && <><td data-label="Total" className="is-total">{money(order.total_cop)}</td>
                        <td data-label="Cupón" className={`is-coupon${order.coupon_code ? '' : ' is-empty'}`}>{order.coupon_code
                            ? <div className="admin-orders-coupon-cell"><code className="admin-orders-coupon">{order.coupon_code}</code>{order.discount_cop > 0 && <small>−${number(order.discount_cop)}</small>}</div>
                            : <span className="admin-orders-no-coupon" aria-label="Sin cupón">—</span>}</td></>}
                        <td data-label="Estado"><StatusBadge value={`order_${order.status}`} />{order.payment_alert && <span className="admin-orders-alert">Pago recibido · revisar</span>}</td>
                        {can('orders.view_financials') && <td data-label="Pago"><PaymentBadge order={order} /></td>}
                        <td data-label="Acciones"><div className="admin-products-row-actions"><a className="admin-action admin-action--neutral" href={`/admin/orders/${order.id}`} aria-label={`Ver detalle del pedido ${order.reference}`}><Eye aria-hidden="true" /> Ver detalle</a></div></td>
                    </tr>)}</tbody></table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando pedidos…' : state === 'error' ? <>No se pudieron cargar los pedidos. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></> : filtered ? 'No hay pedidos que coincidan con la búsqueda o los filtros.' : meta.total ? 'No hay pedidos en esta página.' : 'Aún no hay pedidos registrados.'}</div>}
            {state === 'success' && meta.total > 0 && <div className="admin-products-pagination"><span>{meta.from ? `${meta.from}–${meta.to} de ${meta.total}` : `Página ${meta.current_page} sin resultados`}</span><div><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button><span>Página {meta.current_page} de {meta.last_page}</span><button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></div>}
        </div>
    </section>;
}

export default function AdminOrders({ session }) {
    const id = detailId();
    const can = permission => Boolean(session?.permissions?.includes(permission));
    return id ? <OrderDetail id={id} can={can} /> : <OrderList can={can} />;
}
