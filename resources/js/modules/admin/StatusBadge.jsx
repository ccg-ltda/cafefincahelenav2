import './StatusBadge.css';
import { orderStatusLabel, orderStatusTone } from './orderStatuses';

// Spanish label and colour tone for every payment / promotion / coupon / stock state the admin can show. Order states are
// shown with the prefix `order_` (`order_ready`): their labels come from the server (orderStatuses.js).
// The model stores free text (`orders.status`, `orders.payment_status`, `payments.status`); only 'pending' is
// written by the application today, the rest are the states the admin already recognises. Unknown values are
// shown as stored, in neutral gray.
const STATES = {
    pending: ['Pendiente', 'amber'],
    paid: ['Pagado', 'green'],
    approved: ['Aprobado', 'green'],
    unverified: ['Sin verificar', 'amber'],
    failed: ['Fallido', 'red'],
    rejected: ['Rechazado', 'red'],
    declined: ['Rechazado', 'red'],
    refunded: ['Reembolsado', 'gray'],
    voided: ['Anulado', 'gray'],
    error: ['Error', 'red'],
    // Promotion states (the label differs from the generic ones).
    promo_current: ['Vigente', 'green'],
    promo_scheduled: ['Programada', 'blue'],
    promo_expired: ['Vencida', 'gray'],
    promo_inactive: ['Inactiva', 'light'],
    // Email states (order_notifications).
    mail_sent: ['Enviado', 'green'],
    mail_queued: ['En cola', 'amber'],
    mail_failed: ['Falló', 'red'],
    // Coupon states.
    coupon_current: ['Vigente', 'green'],
    coupon_scheduled: ['Programado', 'blue'],
    coupon_expired: ['Vencido', 'gray'],
    coupon_exhausted: ['Agotado', 'amber'],
    coupon_inactive: ['Inactivo', 'light'],
    // WhatsApp request states.
    wa_new: ['Nueva', 'amber'],
    wa_in_progress: ['En atención', 'blue'],
    wa_resolved: ['Resuelta', 'green'],
    stock_ok: ['Disponible', 'green'],
    stock_low: ['Stock bajo', 'amber'],
    stock_out: ['Agotado', 'red'],
    // Catalogue publication state (products and variants).
    active: ['Activo', 'green'],
    inactive: ['Inactivo', 'gray'],
};

const info = value => (String(value).startsWith('order_') ? [orderStatusLabel(value.slice(6)), orderStatusTone(value.slice(6))] : STATES[value] ?? [value, 'gray']);

export const statusLabel = value => info(value)[0];

/** An approved/paid payment is only shown as confirmed when the backend found a verified payment for the full amount. */
export const paymentState = order => ((order.payment_status === 'approved' || order.payment_status === 'paid') && !order.payment_verified ? 'unverified' : order.payment_status);

/** Small pill that tells a manual order (registered by the team) from a storefront one. Nothing for the storefront. */
export function ChannelPill({ channel }) {
    return channel === 'manual' ? <span className="channel-pill" title="Pedido manual: lo registró el equipo">Manual</span> : null;
}

export default function StatusBadge({ value }) {
    return <span className={`status-badge is-${info(value)[1]}`}>{statusLabel(value)}</span>;
}
