import { useEffect, useState } from 'react';
import { useLocation } from 'react-router';
import { Clock3, LoaderCircle } from 'lucide-react';
import './PendingOrderNotice.css';
import { useStorefront } from '../../context/StorefrontContext';
import { money } from '../catalog/ProductCard';

/**
 * Fixed reminder for a customer who left for Wompi and came back with an order still pending. "Pagar ahora" opens a new payment attempt
 * on the SAME order; "Cancelar pedido y editar carrito" gives the stock and the coupon back (the server decides, and refuses while a
 * payment is in process). Not drawn on /pago/resultado, which already explains the payment.
 */
export default function PendingOrderNotice({ nudge }) {
    const { pendingOrder, cancelPending, payPending } = useStorefront();
    const { pathname } = useLocation();
    const [busy, setBusy] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [message, setMessage] = useState('');
    const [pulse, setPulse] = useState(false);

    useEffect(() => {
        if (!nudge) return undefined;
        setPulse(true);
        const timer = window.setTimeout(() => setPulse(false), 1200);
        return () => window.clearTimeout(timer);
    }, [nudge]);

    if (pendingOrder?.state !== 'pending' || pathname.startsWith('/pago/')) return null;

    async function pay() {
        setBusy('pay'); setMessage('');
        const error = await payPending(); // on success the browser leaves for Wompi
        if (error) { setMessage(error); setBusy(''); }
    }

    async function cancel() {
        setBusy('cancel'); setMessage('');
        const error = await cancelPending(); // on success the notice disappears (the pending order is gone)
        if (error) { setMessage(error); setConfirming(false); }
        setBusy('');
    }

    return <div className={`pending-order-notice${pulse ? ' is-pulsing' : ''}`} role="alert">
        <Clock3 className="pending-order-icon" aria-hidden="true" />
        <div className="pending-order-body">
            <strong>Tienes un pedido pendiente de pago</strong>
            <span className="pending-order-ref">{pendingOrder.reference} · {money(pendingOrder.total_cop)}</span>
            {pendingOrder.can_cancel
                ? <p>Termina de pagarlo o cancélalo para cambiar tu carrito. Tus productos están reservados mientras tanto.</p>
                : <p>Tu pago está en proceso en Wompi. Cuando se confirme, tu pedido quedará pagado automáticamente.</p>}
            {message && <p className="pending-order-error">{message}</p>}
            {pendingOrder.can_cancel && (confirming
                ? <div className="pending-order-confirm">
                    <p>¿Cancelar el pedido {pendingOrder.reference}? Devolvemos tus productos al inventario y podrás editar tu carrito.</p>
                    <div className="pending-order-actions">
                        <button type="button" className="pending-order-danger" onClick={cancel} disabled={Boolean(busy)}>{busy === 'cancel' ? <><LoaderCircle className="pending-order-spin" aria-hidden="true" /> Cancelando…</> : 'Sí, cancelar pedido'}</button>
                        <button type="button" className="pending-order-secondary" onClick={() => setConfirming(false)} disabled={Boolean(busy)}>No, volver</button>
                    </div>
                </div>
                : <div className="pending-order-actions">
                    <button type="button" className="pending-order-primary" onClick={pay} disabled={Boolean(busy)}>{busy === 'pay' ? <><LoaderCircle className="pending-order-spin" aria-hidden="true" /> Preparando…</> : 'Pagar ahora'}</button>
                    <button type="button" className="pending-order-secondary" onClick={() => setConfirming(true)} disabled={Boolean(busy)}>Cancelar pedido y editar carrito</button>
                </div>)}
        </div>
    </div>;
}
