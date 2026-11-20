import { useState } from 'react';
import { request, titleCase } from './adminShared';

/**
 * The status steps of an order, shared by Hoy and Pedidos: ONE call, the same endpoints (`/admin/orders/{id}/{step}`).
 * `expected_status` is what the person was looking at: if somebody else already moved the order the server answers 409
 * with "{name} ya movió este pedido a …" and saves nothing.
 */
export const runStep = (order, step, body = {}) => request(`/admin/orders/${order.id}/${step}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...body, expected_status: order.status }),
});

/** A readable message from a failed step (the first field error, or the server message). */
export const stepError = failure => Object.values(failure.fields || {})[0]?.[0] || failure.message;

export const emptyShip = { mode: 'courier', courier: '', carrier: '', tracking: '' };

/** Body of "Enviar" from the panel, or an error message to show before calling the server. */
export function shipPayload(ship) {
    if (ship.mode === 'courier') return ship.courier ? { body: { delivery_user_id: Number(ship.courier) } } : { error: 'Elige un repartidor.' };
    return ship.carrier.trim() ? { body: { carrier: ship.carrier.trim(), tracking_number: ship.tracking.trim() || null } } : { error: 'Escribe el nombre de la transportadora.' };
}

/** Fields of the "Enviar" panel: a delivery person OR a carrier (with an optional tracking number). Same form in Hoy and Pedidos. */
export function ShipFields({ ship, onChange, couriers }) {
    const set = patch => onChange({ ...ship, ...patch });
    return <div className="admin-fulfillment-ship">
        <div className="admin-fulfillment-modes" role="radiogroup" aria-label="Cómo se envía">
            <label className={ship.mode === 'courier' ? 'is-selected' : ''}><input type="radio" name="ship-mode" checked={ship.mode === 'courier'} onChange={() => set({ mode: 'courier' })} />Repartidor</label>
            <label className={ship.mode === 'carrier' ? 'is-selected' : ''}><input type="radio" name="ship-mode" checked={ship.mode === 'carrier'} onChange={() => set({ mode: 'carrier' })} />Transportadora</label></div>
        {ship.mode === 'courier'
            ? <label className="admin-categories-field" htmlFor="ship-courier"><span>Repartidor</span><select id="ship-courier" value={ship.courier} onChange={event => set({ courier: event.target.value })}><option value="">Elige un repartidor</option>{couriers.map(item => <option key={item.id} value={item.id}>{titleCase(item.name)}</option>)}</select></label>
            : <><label className="admin-categories-field" htmlFor="ship-carrier"><span>Transportadora</span><input id="ship-carrier" value={ship.carrier} maxLength={100} onChange={event => set({ carrier: event.target.value })} /></label>
                <label className="admin-categories-field" htmlFor="ship-tracking"><span>Número de guía <em>opcional</em></span><input id="ship-tracking" value={ship.tracking} maxLength={100} onChange={event => set({ tracking: event.target.value })} /></label></>}
    </div>;
}

/** State of the "Enviar" panel for a screen: `ship`, its setter and a reset. */
export function useShip() {
    const [ship, setShip] = useState(emptyShip);
    return [ship, setShip, () => setShip(emptyShip)];
}
