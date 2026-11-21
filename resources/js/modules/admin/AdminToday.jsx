import { useCallback, useEffect, useRef, useState } from 'react';
import { House, LoaderCircle, MapPin, MessageCircle, Package, Phone, RefreshCw, Sparkles, Truck } from 'lucide-react';
import './AdminToday.css';
import AdminConfirmDialog from './AdminConfirmDialog';
import StatusBadge from './StatusBadge';
import { request, timeAgoText, titleCase, whatsappChatUrl } from './adminShared';
import { useAdminToast } from './AdminToast';
import { ShipFields, runStep, shipPayload, stepError, useShip } from './orderActions';

// The four moments of an order, in the order the work flows. Delivery only gets "shipping" from the server.
const SECTIONS = {
    new: { icon: Sparkles, title: 'Nuevos', empty: 'Nada nuevo por empezar.' },
    preparing: { icon: Package, title: 'Preparando', empty: 'Nada en preparación.' },
    ready: { icon: Truck, title: 'Por enviar', empty: 'Nada por enviar.' },
    shipping: { icon: House, title: 'Por entregar', empty: 'Nada en camino.' },
};
// Where a card goes after each step (deliver leaves Hoy).
const DESTINATION = { prepare: 'preparing', ready: 'ready', ship: 'shipping', deliver: null };
const REFRESH_MS = 30000;
const MOVE_MS = 260;
const money = value => `$ ${new Intl.NumberFormat('es-CO').format(value)}`;
const digits = value => String(value).replace(/\D/g, '');
const mapsUrl = order => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([order.delivery_address, order.delivery_city, order.delivery_department, 'Colombia'].filter(Boolean).join(', '))}`;

function greeting(name) {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone: 'America/Bogota' }).format(new Date()));
    const word = hour < 12 ? 'BUENOS DÍAS' : hour < 19 ? 'BUENAS TARDES' : 'BUENAS NOCHES';
    const first = titleCase(name || '').split(' ')[0].toLocaleUpperCase('es');
    return first ? `${word}, ${first}` : word;
}

// Minutes in the current stage: amber after 2 h, red after 6 h.
const urgency = iso => {
    const hours = (Date.now() - Date.parse(iso)) / 3600000;
    return hours > 6 ? 'is-late' : hours > 2 ? 'is-slow' : '';
};

function OrderCard({ order, section, busy, phase, onStep }) {
    const total = order.total_cop;
    const first = titleCase(order.customer_name).split(' ')[0];
    const open = event => { if (!event.target.closest('a,button')) window.location.assign(`/admin/orders/${order.id}`); };
    return <article className={`today-card${phase ? ` is-${phase}` : ''}`} data-section={section} onClick={open}>
        <div className="today-card-top">
            <code className="today-ref">{order.reference}</code>
            <span className={`today-age ${urgency(order.stage_since)}`}>{timeAgoText(order.stage_since)}</span>
        </div>
        {section === 'new' && <StatusBadge value={`order_${order.status}`} />}
        <h3><a href={`/admin/orders/${order.id}`}>{titleCase(order.customer_name)}</a></h3>
        <p className="today-address">{order.delivery_city}{order.delivery_details || order.delivery_address ? <small>{[order.delivery_address, order.delivery_details].filter(Boolean).join(' — ')}</small> : null}</p>
        <ul className="today-items">{order.items.map(item => <li key={item.id}><b>{item.quantity} ×</b> {item.name}</li>)}</ul>
        {order.order_notes && <p className="today-notes"><strong>Notas:</strong> {order.order_notes}</p>}
        {section === 'shipping' && (order.assigned_delivery || order.carrier) && <p className="today-courier">{order.assigned_delivery ? <>Repartidor: <strong>{titleCase(order.assigned_delivery.name)}</strong></> : <>Transportadora: <strong>{order.carrier}</strong>{order.tracking_number ? ` · guía ${order.tracking_number}` : ''}</>}</p>}
        {total !== undefined && <p className="today-total">{money(total)}</p>}
        {order.next && <button type="button" className="today-action" disabled={busy} aria-busy={busy} onClick={event => onStep(order, section, event.currentTarget)}>
            {busy ? <LoaderCircle className="today-spin" aria-hidden="true" /> : null}{order.next.label}</button>}
        {section === 'shipping' && <div className="today-contact">
            <a href={`tel:+57${digits(order.customer_phone)}`}><Phone aria-hidden="true" />Llamar</a>
            <a href={whatsappChatUrl(digits(order.customer_phone), `Hola ${first}, vamos en camino con tu pedido ${order.reference} de Café Finca Helena`)} target="_blank" rel="noopener noreferrer"><MessageCircle aria-hidden="true" />WhatsApp</a>
            <a href={mapsUrl(order)} target="_blank" rel="noopener noreferrer"><MapPin aria-hidden="true" />Cómo llegar</a>
        </div>}
    </article>;
}

function SectionIcon({ name }) {
    const Icon = SECTIONS[name].icon;

    return <Icon className="today-section-icon" size={22} aria-hidden="true" />;
}

export default function AdminToday({ session }) {
    const toast = useAdminToast();
    const [data, setData] = useState(null); // { sections, counts, couriers }
    const [state, setState] = useState('loading');
    const [updatedAt, setUpdatedAt] = useState(null);
    const [, tick] = useState(0);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(null); // order id
    const [phase, setPhase] = useState({}); // order id -> 'leaving' | 'entering'
    const [shipFor, setShipFor] = useState(null); // { order, section, opener }
    const [ship, setShip, resetShip] = useShip();
    const [shipError, setShipError] = useState('');
    const controller = useRef(null);
    const acting = useRef(false);

    const load = useCallback(async () => {
        if (acting.current) return; // never replace the screen under a step in progress
        controller.current?.abort();
        const current = new AbortController();
        controller.current = current;
        setLoading(true);
        try {
            const next = await request('/admin/today/data', { signal: current.signal });
            setData(next); setUpdatedAt(Date.now()); setState('success');
        } catch (failure) {
            if (failure.name === 'AbortError') return;
            setState(previous => (previous === 'success' ? previous : 'error')); // a failed background refresh keeps what is on screen
        } finally { if (controller.current === current) setLoading(false); }
    }, []);

    // First load, refresh every 30 s while the tab is visible, and an immediate refresh when coming back to it.
    useEffect(() => {
        load();
        const timer = window.setInterval(() => { if (document.visibilityState === 'visible') load(); }, REFRESH_MS);
        const visible = () => { if (document.visibilityState === 'visible') load(); };
        document.addEventListener('visibilitychange', visible);
        return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', visible); controller.current?.abort(); };
    }, [load]);

    useEffect(() => { const timer = window.setInterval(() => tick(value => value + 1), 10000); return () => window.clearInterval(timer); }, []);

    // "(3) Hoy · Finca Helena": the tab shows what is new (for delivery, what is on the road).
    const waiting = data ? (data.counts.new ?? data.counts.shipping ?? 0) : 0;
    useEffect(() => {
        const previous = document.title;
        document.title = `${waiting > 0 ? `(${waiting}) ` : ''}Hoy · Finca Helena`;
        return () => { document.title = previous; };
    }, [waiting]);

    const keys = data ? Object.keys(data.sections) : [];

    // A step of a card (the same endpoints Pedidos uses). "Enviar" first asks who takes it.
    function step(order, section, opener) {
        if (order.next.step === 'ship') { resetShip(); setShipError(''); setShipFor({ order, section, opener }); return; }
        perform(order, section);
    }

    async function perform(order, section, body = {}) {
        if (acting.current) return false;
        acting.current = true;
        setBusy(order.id);
        const doneStep = order.next.step;
        try {
            const { order: moved } = await runStep(order, doneStep, body);
            setPhase(current => ({ ...current, [order.id]: 'leaving' }));
            await new Promise(resolve => window.setTimeout(resolve, MOVE_MS));
            const target = DESTINATION[doneStep];
            setData(current => {
                const sections = Object.fromEntries(Object.entries(current.sections).map(([key, list]) => [key, list.filter(item => item.id !== order.id)]));
                if (target && sections[target]) sections[target] = [...sections[target], moved];
                return { ...current, sections, counts: Object.fromEntries(Object.entries(sections).map(([key, list]) => [key, list.length])) };
            });
            setPhase(current => ({ ...current, [order.id]: target ? 'entering' : undefined }));
            window.setTimeout(() => setPhase(current => { const { [order.id]: _gone, ...rest } = current; return rest; }), 500);
            toast.success(order.next.done, `Pedido ${order.reference}.`);
            return true;
        } catch (failure) {
            // Back in its place, with the message of the server ("{name} ya movió este pedido a …").
            toast[failure.status === 409 ? 'warning' : 'error'](failure.status === 409 ? 'El pedido ya cambió' : 'No se pudo avanzar el pedido', stepError(failure));
            if (failure.status === 409) { acting.current = false; setBusy(null); load(); }
            return false;
        } finally { acting.current = false; setBusy(null); }
    }

    async function confirmShip() {
        const payload = shipPayload(ship);
        if (payload.error) { setShipError(payload.error); return; }
        const { order, section } = shipFor;
        setShipError('');
        setShipFor(null);
        await perform(order, section, payload.body);
    }

    const scrollTo = key => document.getElementById(`today-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const seconds = updatedAt ? Math.max(0, Math.round((Date.now() - updatedAt) / 1000)) : null;

    return <section className="today">
        <header className="today-head">
            <div>
                <span className="dashboard-eyebrow">{greeting(session?.name)}</span>
                <h1>Hoy</h1>
                <p>Lo que hay que mover ahora. Se actualiza solo.</p>
            </div>
            <div className="today-refresh">
                <button type="button" onClick={load} disabled={loading} aria-label="Actualizar ahora"><RefreshCw className={loading ? 'today-spin' : ''} aria-hidden="true" /></button>
                <span role="status">{seconds === null ? 'Cargando…' : `Actualizado hace ${seconds < 60 ? `${seconds} s` : `${Math.floor(seconds / 60)} min`}`}</span>
            </div>
        </header>

        {state === 'loading' && <p className="today-message" role="status">Cargando lo de hoy…</p>}
        {state === 'error' && <p className="today-message" role="alert">No se pudo cargar. <button type="button" onClick={load}>Reintentar</button></p>}

        {state === 'success' && <>
            <div className={`today-counters${keys.length === 1 ? ' is-single' : ''}`}>
                {keys.map(key => <button key={key} type="button" className={`today-counter is-${key}`} onClick={() => scrollTo(key)}>
                    <span>{SECTIONS[key].title}</span><strong>{data.counts[key]}</strong></button>)}
            </div>

            {keys.map(key => <section key={key} id={`today-${key}`} className="today-section" aria-labelledby={`today-${key}-title`}>
                <h2 id={`today-${key}-title`}><SectionIcon name={key} />{SECTIONS[key].title}<em>{data.counts[key]}</em></h2>
                {data.sections[key].length === 0
                    ? <p className="today-empty">{SECTIONS[key].empty}</p>
                    : <div className="today-list">{data.sections[key].map(order => <OrderCard key={order.id} order={order} section={key} busy={busy === order.id} phase={phase[order.id]} onStep={step} />)}</div>}
            </section>)}
        </>}

        {shipFor && <AdminConfirmDialog title="Enviar pedido" confirmLabel="Enviar" tone="primary" error={shipError} opener={shipFor.opener} onCancel={() => { setShipFor(null); setShipError(''); }} onConfirm={confirmShip}>
            <p>Pedido <strong>{shipFor.order.reference}</strong> de {titleCase(shipFor.order.customer_name)}.</p>
            <ShipFields ship={ship} onChange={setShip} couriers={data?.couriers || []} />
        </AdminConfirmDialog>}
    </section>;
}
