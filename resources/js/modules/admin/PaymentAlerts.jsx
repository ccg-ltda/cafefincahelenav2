import { useState } from 'react';
import { TriangleAlert } from 'lucide-react';
import './PaymentAlerts.css';
import { request } from './adminShared';
import { useAdminToast } from './AdminToast';

const money = value => `$ ${new Intl.NumberFormat('es-CO').format(value)}`;

/**
 * Approved payments that landed on a CANCELLED order (a slow PSE, for example). Money was received for an order that is no longer
 * going to be served: it stays on screen in Hoy and in the Dashboard until an administrator marks it "Revisado" (the Historial
 * keeps who and when). The server only sends this to people who can review it (admin).
 */
export default function PaymentAlerts({ alerts, onReviewed }) {
    const toast = useAdminToast();
    const [busy, setBusy] = useState(null);
    if (!alerts?.length) return null;

    async function review(alert) {
        if (busy) return;
        setBusy(alert.order_id);
        try {
            await request(`/admin/orders/${alert.order_id}/wompi/review`, { method: 'POST' });
            toast.success('Pago marcado como revisado', `Pedido ${alert.reference}.`);
            onReviewed(alert.order_id);
        } catch (failure) { toast.error('No se pudo marcar como revisado', failure.message); } finally { setBusy(null); }
    }

    return <section className="payment-alerts" role="alert" aria-label="Pagos recibidos en pedidos cancelados">
        {alerts.map(alert => <div className="payment-alert" key={alert.order_id}>
            <TriangleAlert aria-hidden="true" />
            <p><strong>Pago recibido en pedido cancelado:</strong> <code>{alert.reference}</code> · {money(alert.amount_cop)} · revisar reembolso en Wompi</p>
            <div className="payment-alert-actions">
                <a href={`/admin/orders/${alert.order_id}`}>Ver pedido</a>
                <button type="button" onClick={() => review(alert)} disabled={busy === alert.order_id}>{busy === alert.order_id ? 'Guardando…' : 'Revisado'}</button>
            </div>
        </div>)}
    </section>;
}
