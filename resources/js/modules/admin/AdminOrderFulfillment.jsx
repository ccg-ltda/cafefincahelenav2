import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Printer } from 'lucide-react';
import './AdminFulfillment.css';
import AdminConfirmDialog from './AdminConfirmDialog';
import StatusBadge from './StatusBadge';
import { request, titleCase } from './adminShared';
import { useAdminToast } from './AdminToast';
import { nationalNumber, shopWhatsapp } from '../storefront/shop';
import { ShipFields, runStep, shipPayload, stepError, useShip } from './orderActions';

const dateTime = value => (value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(value)) : '—');
const json = body => ({ headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

// Printable dispatch sheet: it goes INSIDE the package and the customer reads it, so it carries the brand. No prices, no totals, no email.
// It is drawn in a portal on <body> (outside #root) so the print stylesheet can hide the whole admin and print only this.
const longDate = value => (value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'long', timeZone: 'America/Bogota' }).format(new Date(value)) : '');
// "Café Premium 125 g Molido" -> "Café Premium · 125 g · Molido" (cosmetic only: any other shape of name is left as it is)
const itemLabel = name => { const m = name.match(/^([^\d×+]+?)\s+(\d[\d.,]*\s?(?:g|gr|kg))\s+(\p{L}[\p{L} ]*)$/u); return m ? `${m[1]} · ${m[2]} · ${m[3]}` : name; };

function DispatchSheet({ order }) {
    const whatsapp = shopWhatsapp();
    const shipping = order.assigned_delivery ? `Repartidor: ${titleCase(order.assigned_delivery.name)}` : order.carrier ? `Transportadora: ${order.carrier}${order.tracking_number ? ` · Guía ${order.tracking_number}` : ''}` : '';
    const place = [order.delivery_city, order.delivery_department].filter(Boolean).join(', ');
    return createPortal(<div className="dispatch-sheet" aria-hidden="true">
        <table className="ds-frame"><thead><tr><td><div className="ds-margin" /></td></tr></thead>
            <tfoot><tr><td><div className="ds-margin" /></td></tr></tfoot>
            <tbody><tr><td className="ds-page">
                <header className="ds-head">
                    <img src="/media/email/logo.png" alt="" width="64" height="64" />
                    <div className="ds-brand"><strong>Café Finca Helena</strong><span>Café de Norte de Santander</span></div>
                    <div className="ds-doc"><span>Hoja de despacho</span><b>{order.reference}</b><small>{longDate(order.created_at)}</small></div>
                </header>

                <section className="ds-to">
                    <h2>Para</h2>
                    <p className="ds-name">{titleCase(order.customer_name)}</p>
                    <p>Cel. {order.customer_phone}</p>
                    <p className="ds-address">{order.delivery_address}</p>
                    {order.delivery_details && <p>{order.delivery_details}</p>}
                    <p>{place}</p>
                </section>

                {order.order_notes && <section className="ds-notes"><h2>Notas del pedido</h2><p>{order.order_notes}</p></section>}

                <section>
                    <h2>Tu pedido</h2>
                    <table className="ds-items"><thead><tr><th className="ds-check" /><th className="ds-qty">Cant.</th><th>Producto</th><th className="ds-sku">SKU</th></tr></thead>
                        <tbody>{order.items.map(item => <tr key={item.id}><td className="ds-check"><span className="ds-box" /></td><td className="ds-qty">{item.quantity}</td><td>{itemLabel(item.name)}</td><td className="ds-sku">{item.sku}</td></tr>)}</tbody></table>
                </section>

                {shipping && <p className="ds-ship">{shipping}</p>}

                <footer className="ds-foot">
                    <p className="ds-thanks">Gracias por elegir Café Finca Helena. Este café fue seleccionado y tostado en pequeños lotes para ti.</p>
                    <p className="ds-help">¿Necesitas ayuda con tu pedido? {whatsapp && <>WhatsApp {nationalNumber(whatsapp)}</>}</p>
                    <p className="ds-internal">Preparado por: ______________________ &nbsp;&nbsp; Fecha: ______________</p>
                </footer>
            </td></tr></tbody></table>
    </div>, document.body);
}

// `order.next` comes from the server: the next step for THIS person and status (null when there is none). It is the same
// button, the same endpoints and the same rules as Hoy (orderActions.jsx); the server checks the permission again.
export default function AdminOrderFulfillment({ order, can, onChange, onStale }) {
    const toast = useAdminToast();
    const next = order.next;
    const [dialog, setDialog] = useState(null); // { opener }
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [note, setNote] = useState('');
    const [ship, setShip, resetShip] = useShip();
    const [reassigning, setReassigning] = useState(false);
    const [newCourier, setNewCourier] = useState('');

    async function run(call, message) {
        if (busy) return;
        setBusy(true); setError('');
        try {
            const response = await call();
            onChange(response.order); setDialog(null); setReassigning(false); setNote(''); resetShip();
            toast.success(message, `Pedido ${order.reference}.`);
        } catch (failure) {
            setError(stepError(failure));
            if (failure.status === 409 && failure.message.includes('ya movió')) onStale?.(); // somebody else moved it: reload the order
        } finally { setBusy(false); }
    }

    function open(event) { setError(''); setDialog({ opener: event.currentTarget }); }
    function confirm() {
        const extra = { note: note.trim() || null };
        if (next.step === 'ship') {
            const payload = shipPayload(ship);
            if (payload.error) { setError(payload.error); return; }
            run(() => runStep(order, 'ship', { ...payload.body, ...extra }), next.done);
        } else run(() => runStep(order, next.step, extra), next.done);
    }

    const couriers = order.couriers || [];
    return <section className="dashboard-card admin-order-card admin-fulfillment">
        <div className="admin-fulfillment-head"><h2>Entrega y seguimiento</h2><StatusBadge value={`order_${order.status}`} /></div>
        <dl>
            {order.assigned_delivery && <div><dt>Repartidor</dt><dd>{titleCase(order.assigned_delivery.name)}</dd></div>}
            {order.carrier && <div><dt>Transportadora</dt><dd>{order.carrier}{order.tracking_number ? <small>Guía {order.tracking_number}</small> : null}</dd></div>}
            {order.confirmed_at && <div><dt>Pagado</dt><dd>{dateTime(order.confirmed_at)}</dd></div>}
            {order.shipped_at && <div><dt>En camino</dt><dd>{dateTime(order.shipped_at)}</dd></div>}
            {order.delivered_at && <div><dt>Entregado</dt><dd>{dateTime(order.delivered_at)}{order.delivery_note && <small>{order.delivery_note}</small>}</dd></div>}
        </dl>

        <div className="admin-fulfillment-actions">
            {next && <button type="button" className="admin-categories-primary" onClick={open}>{next.label}</button>}
            {order.status === 'shipped' && order.assigned_delivery && couriers.length > 1 && can('orders.ship') && <button type="button" className="admin-categories-secondary" onClick={() => { setReassigning(value => !value); setNewCourier(''); setError(''); }} aria-expanded={reassigning}>Reasignar repartidor</button>}
            {can('orders.print') && <button type="button" className="admin-categories-secondary" onClick={() => window.print()}><Printer aria-hidden="true" />Imprimir hoja de despacho</button>}
            {!next && order.status === 'pending' && <p className="admin-fulfillment-hint">El pedido se habilita cuando se apruebe el pago.</p>}
            {!next && order.status !== 'pending' && order.status !== 'delivered' && order.status !== 'cancelled' && !can('orders.print') && <p className="admin-fulfillment-hint">No tienes acciones disponibles para este estado.</p>}
        </div>

        {reassigning && <div className="admin-fulfillment-reassign"><label className="admin-categories-field" htmlFor="reassign-courier"><span>Nuevo repartidor</span>
            <select id="reassign-courier" value={newCourier} onChange={event => setNewCourier(event.target.value)}><option value="">Elige un repartidor</option>{couriers.filter(item => item.id !== order.assigned_delivery.id).map(item => <option key={item.id} value={item.id}>{titleCase(item.name)}</option>)}</select></label>
            <button type="button" className="admin-categories-secondary" disabled={!newCourier || busy} onClick={() => run(() => request(`/admin/orders/${order.id}/reassign`, { method: 'PATCH', ...json({ delivery_user_id: Number(newCourier) }) }), 'Repartidor cambiado')}>Reasignar</button>
            {error && <p className="admin-categories-alert" role="alert">{error}</p>}</div>}

        {order.history.length > 0 && <div className="admin-fulfillment-history"><h3>Historial del pedido</h3>
            <ol>{order.history.map(step => <li key={step.id}><span className="admin-fulfillment-dot" aria-hidden="true" />
                <div><strong>{step.from ? <><StatusBadge value={`order_${step.from}`} /> → </> : null}<StatusBadge value={`order_${step.to}`} /></strong>
                    <small>{dateTime(step.at)}{step.user ? ` · ${titleCase(step.user)}` : ' · Sistema'}</small>{step.note && <p>{step.note}</p>}</div></li>)}</ol></div>}

        {dialog && next && <AdminConfirmDialog title={next.label} confirmLabel={next.step === 'deliver' ? 'Marcar como entregado' : next.label} tone="primary" busy={busy} error={error} opener={dialog.opener} onCancel={() => { setDialog(null); setError(''); }} onConfirm={confirm}>
            {next.step === 'ship' && <ShipFields ship={ship} onChange={setShip} couriers={couriers} />}
            {next.step === 'deliver' && <p>Confirma que el pedido <strong>{order.reference}</strong> ya llegó a su destino.</p>}
            {next.step !== 'ship' && next.step !== 'deliver' && <p>El pedido <strong>{order.reference}</strong> pasará al siguiente estado.</p>}
            <label className="admin-categories-field" htmlFor="order-note"><span>Nota <em>opcional</em></span>
                <textarea id="order-note" rows={2} maxLength={500} value={note} placeholder={next.step === 'deliver' ? 'Ej.: recibió el portero' : ''} onChange={event => setNote(event.target.value)} /></label>
        </AdminConfirmDialog>}

        {can('orders.print') && <DispatchSheet order={order} />}
    </section>;
}
