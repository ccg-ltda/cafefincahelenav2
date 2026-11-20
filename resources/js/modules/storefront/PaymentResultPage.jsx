import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router';
import { Check, Clock3, LoaderCircle, MessageCircle, RotateCcw, X } from 'lucide-react';
import './PaymentResultPage.css';
import { useStorefront } from '../../context/StorefrontContext';
import { money } from '../catalog/ProductCard';
import { whatsappLink } from './shop';

const POLL_MS = 4000;
const MAX_TRIES = 45; // about 3 minutes of automatic checking, then a plain message

/**
 * Where Wompi sends the customer back (?id=<transaction>). The page never believes the URL: it only passes the id to the server,
 * which asks Wompi and answers approved | declined | pending. Only an approved payment empties the cart and the saved checkout.
 */
export default function PaymentResultPage() {
    const { cart, orderPaid, holdCartReconcile, releaseCartReconcile } = useStorefront();
    const id = new URLSearchParams(useLocation().search).get('id') || '';
    const [phase, setPhase] = useState(id ? 'loading' : 'unknown'); // loading | ready | unknown | unavailable
    const [result, setResult] = useState(null);
    const [tries, setTries] = useState(0);
    const [retrying, setRetrying] = useState(false);
    const [retryError, setRetryError] = useState('');
    const cleared = useRef(false);

    useEffect(() => { document.title = 'Resultado del pago | Café Finca Helena'; }, []);

    // The stock already lost the units of this order, so the cart cannot be judged against it until the payment result is known.
    // Leaving the page earlier (pending, no connection) releases the hold and the cart is checked as in any other page.
    useEffect(() => {
        holdCartReconcile();
        return () => releaseCartReconcile(true);
    }, []);

    useEffect(() => {
        if (!id) return undefined;
        const controller = new AbortController();
        let timer;
        (async () => {
            try {
                const response = await fetch(`/api/payments/wompi/result?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
                const body = await response.json().catch(() => ({}));
                if (response.status === 404) { setPhase('unknown'); return; }
                if (!response.ok) throw new Error('unavailable');
                setResult(body); setPhase('ready');
                if (body.state === 'pending' && tries < MAX_TRIES) timer = window.setTimeout(() => setTries(value => value + 1), POLL_MS);
            } catch (error) {
                if (error.name === 'AbortError') return;
                setPhase(current => (current === 'ready' ? current : 'unavailable'));
                if (tries < MAX_TRIES) timer = window.setTimeout(() => setTries(value => value + 1), POLL_MS);
            }
        })();
        return () => { controller.abort(); window.clearTimeout(timer); };
    }, [id, tries]);

    // Approved (confirmed by the server, never by the URL): empty the cart and the saved checkout progress, once.
    useEffect(() => {
        if (result?.state !== 'approved' || cleared.current) return;
        cleared.current = true;
        orderPaid(); // empties the cart, the checkout progress and the pending-order record, and lets go of the hold: no adjustment notice
        releaseCartReconcile(false);
    }, [result?.state]);

    // Not paid (or unknown link): the cart stays, and now a stock change is worth telling because the customer will try again.
    useEffect(() => {
        if (phase === 'unknown' || (phase === 'ready' && result.state === 'declined')) releaseCartReconcile(true);
    }, [phase, result?.state]);

    const retry = useCallback(async () => {
        setRetrying(true); setRetryError('');
        try {
            const response = await fetch('/api/payments/wompi/attempt', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ transaction_id: id }) });
            const body = await response.json().catch(() => ({}));
            if (response.ok && body.checkout_url) { window.location.assign(body.checkout_url); return; }
            setRetryError(body.message || 'No pudimos iniciar el pago. Inténtalo de nuevo en un momento.');
        } catch { setRetryError('No pudimos conectar con el servidor. Comprueba tu conexión e inténtalo de nuevo.'); }
        setRetrying(false);
    }, [id]);

    const state = phase === 'ready' ? result.state : phase;
    const order = result?.order;
    const timedOut = state === 'pending' && tries >= MAX_TRIES;
    const help = whatsappLink(`Hola, necesito ayuda con mi pedido ${order?.reference ?? ''}`.trim());

    return <main className="pay-result" aria-live="polite">
        <div className={`pay-card is-${state}`}>
            {(state === 'loading' || state === 'unavailable') && <>
                <div className="pay-icon"><LoaderCircle className="pay-spin" aria-hidden="true" /></div>
                <h1>{state === 'loading' ? 'Confirmando tu pago…' : 'Seguimos consultando tu pago…'}</h1>
                <p>{state === 'loading' ? 'Estamos preguntando a Wompi por el resultado. No cierres esta página.' : 'No pudimos consultar el resultado ahora. Lo intentamos de nuevo en unos segundos; no pagues dos veces.'}</p>
            </>}

            {state === 'approved' && <>
                <div className="pay-icon"><Check aria-hidden="true" /></div>
                <h1>¡Pago recibido!</h1>
                <p>Tu pedido quedó pagado y ya lo estamos preparando.</p>
                <OrderSummary order={order} />
                <div className="pay-actions"><Link className="pay-primary" to="/">Seguir comprando</Link></div>
            </>}

            {state === 'declined' && <>
                <div className="pay-icon"><X aria-hidden="true" /></div>
                <h1>Pago rechazado</h1>
                <p>{result.message ? `Wompi informó: ${result.message}.` : 'Tu pago no pudo completarse.'} No se hizo ningún cobro.</p>
                <OrderSummary order={order} />
                {result.can_retry
                    ? <p className="pay-note">Tu pedido sigue registrado: puedes intentarlo de nuevo con el mismo u otro medio de pago, sin repetir tus datos.</p>
                    : <p className="pay-note">Este pedido ya no admite un nuevo pago. Vuelve a la tienda para hacer uno nuevo.</p>}
                {retryError && <p className="pay-error" role="alert">{retryError}</p>}
                <div className="pay-actions">
                    {result.can_retry && <button type="button" className="pay-primary" onClick={retry} disabled={retrying}>{retrying ? <><LoaderCircle className="pay-spin" aria-hidden="true" /> Preparando…</> : <><RotateCcw aria-hidden="true" /> Intentar de nuevo</>}</button>}
                    <Link className="pay-secondary" to="/">Volver a la tienda</Link>
                </div>
            </>}

            {state === 'pending' && <>
                <div className="pay-icon"><Clock3 aria-hidden="true" /></div>
                <h1>Pago en proceso</h1>
                <p>{timedOut ? 'Tu pago sigue en proceso. Puedes cerrar esta página: cuando se confirme, tu pedido quedará pagado automáticamente.' : 'Estamos esperando la confirmación de tu banco o de tu billetera. No necesitas hacer nada más: esta página se actualiza sola.'}</p>
                <OrderSummary order={order} />
                {timedOut && help && <div className="pay-actions"><a className="pay-secondary" href={help} target="_blank" rel="noopener noreferrer"><MessageCircle aria-hidden="true" /> Escríbenos por WhatsApp</a></div>}
                {!timedOut && <p className="pay-note"><LoaderCircle className="pay-spin" aria-hidden="true" /> Consultando…</p>}
            </>}

            {state === 'unknown' && <>
                <div className="pay-icon"><X aria-hidden="true" /></div>
                <h1>No encontramos ese pago</h1>
                <p>El enlace no corresponde a un pago de Café Finca Helena. Si acabas de pagar, espera unos minutos: tu pedido se confirmará solo.</p>
                <div className="pay-actions"><Link className="pay-primary" to="/">Ir a la tienda</Link></div>
            </>}
        </div>
    </main>;
}

function OrderSummary({ order }) {
    if (!order) return null;
    return <dl className="pay-summary">
        <div><dt>Referencia</dt><dd className="pay-ref">{order.reference}</dd></div>
        <div><dt>Total</dt><dd>{money(order.total_cop)}</dd></div>
        <div><dt>Correo</dt><dd>{order.email}</dd></div>
        <div className="pay-items"><dt>Productos</dt><dd><ul>{order.items.map(item => <li key={item.name}><b>{item.quantity} ×</b> {item.name}</li>)}</ul></dd></div>
    </dl>;
}
