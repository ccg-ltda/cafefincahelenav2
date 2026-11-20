import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { MessageCircle, X } from 'lucide-react';
import './AdminCategories.css';
import './AdminWhatsApp.css';
import StatusBadge from './StatusBadge';
import useModalLock from './useModalLock';
import { TopicPill, formatPhone, request, timeAgoText, titleCase, whatsappChatUrl } from './adminShared';
import { useAdminToast } from './AdminToast';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';
const dateTime = value => (value ? new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(value)) : '—');
const json = body => ({ headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

// Detail and actions of a request in a right-hand panel. The URL reflects the open request (/admin/whatsapp/{id}).
export default function AdminWhatsAppDrawer({ id, admins, onClose, onChanged }) {
    const toast = useAdminToast();
    const panel = useRef(null);
    const closeButton = useRef(null);
    const [item, setItem] = useState(null);
    const [state, setState] = useState('loading');
    const [closing, setClosing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [resolving, setResolving] = useState(false);
    const [note, setNote] = useState('');
    const [notes, setNotes] = useState('');
    const [assignTo, setAssignTo] = useState('');

    useModalLock();

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        request(`/admin/whatsapp/requests/${id}`, { signal: controller.signal })
            .then(({ data }) => { setItem(data); setNotes(data.internal_notes || ''); setAssignTo(data.assignee ? String(data.assignee.id) : ''); setState('success'); })
            .catch(failure => { if (failure.name !== 'AbortError') setState(failure.status === 404 || failure.confirmedRejected ? 'missing' : 'error'); });
        return () => controller.abort();
    }, [id]);

    useEffect(() => { closeButton.current?.focus(); }, []);

    function close() { setClosing(true); window.setTimeout(onClose, 180); }

    async function act(path, body, message) {
        if (busy) return;
        setBusy(true); setError('');
        try {
            const response = await request(`/admin/whatsapp/requests/${id}/${path}`, { method: 'PATCH', ...(body ? json(body) : {}) });
            setItem(response.data); setNotes(response.data.internal_notes || ''); setAssignTo(response.data.assignee ? String(response.data.assignee.id) : '');
            setResolving(false); setNote(''); onChanged();
            if (message) toast.success(message[0], message[1]);
        } catch (failure) { setError(Object.values(failure.fields || {})[0]?.[0] || failure.message); } finally { setBusy(false); }
    }

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    // First message: the quick reply of the topic; "CFH-…" becomes the real reference when the request has one.
    const firstMessage = item?.quick_reply ? item.quick_reply.body.replace('CFH-…', item.order_reference || 'CFH-…') : `Hola ${titleCase(item?.name || '').split(' ')[0]}, te escribimos de Café Finca Helena.`;
    const resolved = item?.status === 'resolved';

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={close} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer admin-wa-drawer" role="dialog" aria-modal="true" aria-labelledby="wa-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Solicitud de WhatsApp</span><h2 id="wa-drawer-title">{item ? titleCase(item.name) : 'Solicitud'}</h2></div>
                <button type="button" ref={closeButton} className="admin-categories-close" onClick={close} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <div className="admin-categories-drawer-form">
                <div className="admin-categories-drawer-body">
                    {state === 'loading' && <p className="admin-categories-hint" role="status">Cargando solicitud…</p>}
                    {state === 'missing' && <p className="admin-categories-alert" role="alert">Esta solicitud no existe.</p>}
                    {state === 'error' && <p className="admin-categories-alert" role="alert">No se pudo cargar la solicitud.</p>}
                    {state === 'success' && <>
                        <section className="admin-customer-section"><h3>Solicitud</h3><dl>
                            <div><dt>Tema</dt><dd><TopicPill topic={item.topic} /></dd></div>
                            <div><dt>Estado</dt><dd><StatusBadge value={`wa_${item.status}`} /></dd></div>
                            <div><dt>Celular</dt><dd>{formatPhone(item.phone)}</dd></div>
                            <div><dt>Registrada</dt><dd>{dateTime(item.created_at)}<small>{timeAgoText(item.created_at)}{item.created_by ? ` · por ${item.created_by}` : ''}</small></dd></div>
                            <div><dt>Responsable</dt><dd>{item.assignee ? item.assignee.name : 'Sin responsable'}</dd></div>
                            {resolved && <div><dt>Resuelta</dt><dd>{dateTime(item.resolved_at)}{item.resolution_note && <small>{item.resolution_note}</small>}</dd></div>}
                        </dl></section>

                        <section className="admin-customer-section"><h3>Qué necesita</h3><p className="admin-wa-message">{item.message}</p></section>

                        <section className="admin-customer-section"><h3>Cliente y pedido</h3><dl>
                            <div><dt>Cliente</dt><dd>{item.customer ? (item.customer.linkable ? <a className="admin-wa-link" href={`/admin/customers/${item.customer.id}`}>{titleCase(item.customer.name)}</a> : <>{titleCase(item.customer.name)}<small>Sin compras válidas todavía</small></>) : 'Ningún cliente con este celular'}</dd></div>
                            <div><dt>Pedido</dt><dd>{item.order ? <a className="admin-wa-link" href={`/admin/orders/${item.order.id}`}>{item.order.reference}</a> : item.order_reference ? <>{item.order_reference} <small className="admin-wa-notfound">(no encontrado)</small></> : '—'}</dd></div>
                        </dl></section>

                        <section className="admin-customer-section"><h3>Acciones</h3>
                            <div className="admin-wa-actions">
                                {!resolved && <button type="button" className="admin-categories-primary" disabled={busy} onClick={() => act('take', null, ['Solicitud tomada', 'Quedó en atención contigo.'])}>Tomar solicitud</button>}
                                {resolved ? <button type="button" className="admin-categories-secondary" disabled={busy} onClick={() => act('reopen', null, ['Solicitud reabierta', 'Volvió a las abiertas.'])}>Reabrir</button>
                                    : <button type="button" className="admin-categories-secondary" disabled={busy} onClick={() => setResolving(value => !value)} aria-expanded={resolving}>Marcar como resuelta</button>}
                            </div>
                            {resolving && !resolved && <div className="admin-wa-resolve"><label className="admin-wa-field" htmlFor="wa-resolution-note"><span>Nota de resolución <em>opcional</em></span><textarea id="wa-resolution-note" rows={2} maxLength={500} value={note} onChange={event => setNote(event.target.value)} /></label>
                                <div className="admin-categories-buttons"><button type="button" className="admin-categories-secondary" onClick={() => setResolving(false)}>Cancelar</button><button type="button" className="admin-categories-primary" disabled={busy} onClick={() => act('resolve', { note }, ['Solicitud resuelta', 'Se cerró la solicitud.'])}>Confirmar</button></div></div>}
                            <div className="admin-wa-assign"><label className="admin-wa-field" htmlFor="wa-assign"><span>Cambiar responsable</span>
                                <select id="wa-assign" value={assignTo} onChange={event => setAssignTo(event.target.value)}><option value="">Elige un administrador</option>{admins.map(admin => <option key={admin.id} value={admin.id}>{admin.name}</option>)}</select></label>
                                <button type="button" className="admin-categories-secondary" disabled={busy || !assignTo || Number(assignTo) === item.assignee?.id} onClick={() => act('assign', { user_id: Number(assignTo) }, ['Responsable cambiado', 'La solicitud tiene nuevo responsable.'])}>Asignar</button></div>
                        </section>

                        <section className="admin-customer-section"><h3>Notas internas</h3>
                            <label className="admin-wa-field" htmlFor="wa-notes"><span className="admin-customers-sr">Notas internas</span><textarea id="wa-notes" rows={4} maxLength={5000} value={notes} placeholder="Solo las ve el equipo" onChange={event => setNotes(event.target.value)} /></label>
                            <button type="button" className="admin-categories-secondary admin-wa-save-notes" disabled={busy || notes === (item.internal_notes || '')} onClick={() => act('notes', { internal_notes: notes }, ['Notas guardadas', 'Las notas internas se actualizaron.'])}>Guardar notas</button></section>
                        {error && <p className="admin-categories-alert" role="alert">{error}</p>}
                    </>}
                </div>
                <footer className="admin-categories-drawer-foot"><div className="admin-categories-buttons admin-customer-foot">
                    {state === 'success' && <a className="admin-wa-open" href={whatsappChatUrl(item.phone, firstMessage)} target="_blank" rel="noopener noreferrer"><MessageCircle aria-hidden="true" />Abrir WhatsApp</a>}
                    <button type="button" className="admin-categories-secondary" onClick={close}>Cerrar</button>
                </div></footer>
            </div>
        </div>
    </div>, document.body);
}
