import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Copy, MessageCircle, Pencil } from 'lucide-react';
import './AdminProducts.css';
import './AdminCustomers.css';
import './AdminWhatsApp.css';
import AdminWhatsAppDrawer from './AdminWhatsAppDrawer';
import StatusBadge from './StatusBadge';
import { TOPICS, TopicPill, formatPhone, pageList, request, timeAgoText, titleCase } from './adminShared';
import { useAdminToast } from './AdminToast';

const PILLS = [['open', 'Abiertas'], ['wholesale', 'Mayorista'], ['order_support', 'Soporte'], ['buying_help', 'Ayuda'], ['all', 'Todas']];
const FILTERS = PILLS.map(([value]) => value);
const number = value => new Intl.NumberFormat('es-CO').format(value);

// URL: /admin/whatsapp[/{id}]?filter=&page=  (the id is the request open in the drawer)
const readId = () => Number(window.location.pathname.match(/^\/admin\/whatsapp\/(\d+)$/)?.[1]) || null;
function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    return { filter: FILTERS.includes(params.get('filter')) ? params.get('filter') : 'open', page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1 };
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const area = document.createElement('textarea');
        area.value = text; document.body.appendChild(area); area.select();
        try { document.execCommand('copy'); } finally { document.body.removeChild(area); }
    }
}

const EMPTY_FORM = { name: '', phone: '', topic: 'wholesale', order_reference: '', message: '' };

function RequestForm({ onCreated }) {
    const toast = useAdminToast();
    const [form, setForm] = useState(EMPTY_FORM);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const patch = (name, value) => { setForm(current => ({ ...current, [name]: value })); setErrors(current => ({ ...current, [name]: undefined })); };

    async function submit(event) {
        event.preventDefault();
        if (saving) return;
        setSaving(true); setErrors({});
        try {
            const response = await request('/admin/whatsapp/requests', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...form, order_reference: form.order_reference.trim() || null }) });
            toast.success('Solicitud registrada', `La solicitud de ${response.data.name} quedó como nueva.`);
            setForm(EMPTY_FORM); onCreated(response);
        } catch (error) {
            setErrors(error.fields || {});
            if (!Object.keys(error.fields || {}).length) toast.error('No se pudo registrar', error.message);
        } finally { setSaving(false); }
    }

    const err = name => errors[name]?.[0] && <small className="admin-categories-error" id={`wa-${name}-error`} role="alert">{errors[name][0]}</small>;
    const aria = name => ({ 'aria-invalid': !!errors[name], 'aria-describedby': errors[name] ? `wa-${name}-error` : undefined });

    return <form className="admin-wa-form" onSubmit={submit} noValidate>
        <label className="admin-wa-field" htmlFor="wa-name"><span>Nombre o negocio</span><input id="wa-name" value={form.name} maxLength={255} autoComplete="off" onChange={event => patch('name', event.target.value)} {...aria('name')} /></label>{err('name')}
        <label className="admin-wa-field" htmlFor="wa-phone"><span>Teléfono (celular)</span><input id="wa-phone" type="tel" inputMode="numeric" value={form.phone} maxLength={20} placeholder="300 123 4567" autoComplete="off" onChange={event => patch('phone', event.target.value)} {...aria('phone')} /></label>{err('phone')}
        <label className="admin-wa-field" htmlFor="wa-topic"><span>Tema</span><select id="wa-topic" value={form.topic} onChange={event => patch('topic', event.target.value)} {...aria('topic')}>{Object.entries(TOPICS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>{err('topic')}
        <label className="admin-wa-field" htmlFor="wa-order"><span>Pedido (CFH-…) <em>opcional</em></span><input id="wa-order" value={form.order_reference} maxLength={40} placeholder="CFH-XXXXXXXXXXXX" autoComplete="off" onChange={event => patch('order_reference', event.target.value.toUpperCase())} {...aria('order_reference')} /></label>{err('order_reference')}
        <label className="admin-wa-field" htmlFor="wa-message"><span>Qué necesita</span><textarea id="wa-message" rows={4} maxLength={2000} value={form.message} onChange={event => patch('message', event.target.value)} {...aria('message')} /></label>{err('message')}
        <button type="submit" className="admin-categories-primary admin-wa-submit" disabled={saving}>{saving ? 'Registrando…' : 'Registrar'}</button>
    </form>;
}

function QuickReplies({ replies, onSaved }) {
    const toast = useAdminToast();
    const [editing, setEditing] = useState(false);
    const [drafts, setDrafts] = useState({});
    const [copied, setCopied] = useState(null);
    const [errors, setErrors] = useState({});
    const [savingId, setSavingId] = useState(null);

    const draft = reply => drafts[reply.id] ?? { title: reply.title, body: reply.body };
    const change = (reply, field, value) => setDrafts(current => ({ ...current, [reply.id]: { ...draft(reply), [field]: value } }));

    async function copy(reply) {
        await copyText(reply.body);
        setCopied(reply.id); window.setTimeout(() => setCopied(current => (current === reply.id ? null : current)), 2000);
    }

    async function save(reply) {
        setSavingId(reply.id); setErrors(current => ({ ...current, [reply.id]: undefined }));
        try {
            const response = await request(`/admin/whatsapp/quick-replies/${reply.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(draft(reply)) });
            onSaved(response.data); setDrafts(current => { const next = { ...current }; delete next[reply.id]; return next; });
            toast.success('Respuesta guardada', `La respuesta «${draft(reply).title}» se actualizó.`);
        } catch (error) { setErrors(current => ({ ...current, [reply.id]: error.fields || { title: [error.message] } })); }
        finally { setSavingId(null); }
    }

    return <section className="dashboard-card admin-wa-card" aria-labelledby="wa-replies-title">
        <div className="admin-wa-card-head"><h2 id="wa-replies-title">Respuestas rápidas</h2>
            <button type="button" className="admin-action admin-action--neutral" onClick={() => { setEditing(value => !value); setDrafts({}); setErrors({}); }}><Pencil aria-hidden="true" />{editing ? 'Listo' : 'Editar respuestas'}</button></div>
        <div className="admin-wa-replies">{replies.map(reply => {
            const current = draft(reply);
            const problems = errors[reply.id] || {};
            return <article key={reply.id} className="admin-wa-reply">
                <header><TopicPill topic={reply.topic} />{!editing && <strong>{reply.title}</strong>}</header>
                {editing ? <>
                    <label className="admin-wa-field" htmlFor={`wa-reply-title-${reply.id}`}><span>Título</span><input id={`wa-reply-title-${reply.id}`} value={current.title} maxLength={120} onChange={event => change(reply, 'title', event.target.value)} aria-invalid={!!problems.title} /></label>
                    {problems.title && <small className="admin-categories-error" role="alert">{problems.title[0]}</small>}
                    <label className="admin-wa-field" htmlFor={`wa-reply-body-${reply.id}`}><span>Texto</span><textarea id={`wa-reply-body-${reply.id}`} rows={5} maxLength={1000} value={current.body} onChange={event => change(reply, 'body', event.target.value)} aria-invalid={!!problems.body} /></label>
                    {problems.body && <small className="admin-categories-error" role="alert">{problems.body[0]}</small>}
                    <div className="admin-wa-preview" aria-label="Vista previa en WhatsApp"><span>Así llega a WhatsApp</span><p>{current.body}</p></div>
                    <button type="button" className="admin-categories-primary" disabled={savingId === reply.id || (current.title === reply.title && current.body === reply.body)} onClick={() => save(reply)}>{savingId === reply.id ? 'Guardando…' : 'Guardar respuesta'}</button>
                </> : <>
                    <p className="admin-wa-reply-body">{reply.body}</p>
                    <button type="button" className={`admin-wa-copy${copied === reply.id ? ' is-copied' : ''}`} onClick={() => copy(reply)} aria-live="polite">{copied === reply.id ? 'Copiado ✓' : <><Copy aria-hidden="true" />Copiar</>}</button>
                </>}
            </article>;
        })}</div>
    </section>;
}

export default function AdminWhatsApp() {
    const [initial] = useState(initialLocation);
    const [filter, setFilter] = useState(initial.filter);
    const [page, setPage] = useState(initial.page);
    const [drawerId, setDrawerId] = useState(readId);
    const [result, setResult] = useState(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const skipUrl = useRef(true);

    useEffect(() => {
        const params = new URLSearchParams();
        if (filter !== 'open') params.set('filter', filter);
        if (page > 1) params.set('page', String(page));
        const text = params.toString();
        const next = `${drawerId ? `/admin/whatsapp/${drawerId}` : '/admin/whatsapp'}${text ? `?${text}` : ''}`;
        if (next === `${window.location.pathname}${window.location.search}`) return;
        if (skipUrl.current) { skipUrl.current = false; window.history.replaceState(window.history.state, '', next); return; }
        window.history[readId() === drawerId ? 'replaceState' : 'pushState'](window.history.state, '', next);
    }, [filter, page, drawerId]);

    useEffect(() => {
        const onPop = () => setDrawerId(readId());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        request(`/admin/whatsapp/data?filter=${filter}&page=${page}`, { signal: controller.signal })
            .then(data => { setResult(data); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [filter, page, retry]);

    const summary = result?.summary;
    const meta = result?.meta;
    const refresh = () => setRetry(value => value + 1);

    return <section className="admin-products admin-wa">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Menú de WhatsApp de la tienda</span><h1>WhatsApp</h1><p>Solicitudes de mayoristas, soporte y ayuda para comprar: quién las atiende y en qué van.</p></div></header>

        {summary && <div className="admin-wa-summary">
            <div className="dashboard-card"><span>Nuevas</span><strong>{number(summary.new)}</strong><small>Sin asignar o por responder</small></div>
            <div className="dashboard-card"><span>En atención</span><strong>{number(summary.in_progress)}</strong><small>Con responsable</small></div>
            <div className="dashboard-card"><span>Resueltas</span><strong>{number(summary.resolved_30d)}</strong><small>Cerradas en los últimos 30 días</small></div>
            <div className="dashboard-card"><span>Clics en el menú (30 días)</span><strong>{number(summary.clicks_30d)}</strong><small>Mayorista: {number(summary.clicks_by_topic.wholesale)} · Soporte: {number(summary.clicks_by_topic.order_support)} · Ayuda para comprar: {number(summary.clicks_by_topic.buying_help)}</small></div>
        </div>}

        {drawerId && <AdminWhatsAppDrawer id={drawerId} admins={result?.admins || []} onClose={() => setDrawerId(null)} onChanged={refresh} />}

        <div className="admin-wa-layout">
            <section className="dashboard-card admin-wa-card" aria-labelledby="wa-list-title">
                <div className="admin-wa-card-head"><h2 id="wa-list-title">Solicitudes</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'resultado' : 'resultados'}` : state === 'loading' ? 'Cargando…' : ''}</span></div>
                <div className="admin-wa-pills" role="group" aria-label="Filtrar solicitudes">{PILLS.map(([value, label]) => <button key={value} type="button" className={`admin-customers-pill${filter === value ? ' is-active' : ''}`} aria-pressed={filter === value} onClick={() => { setFilter(value); setPage(1); }}>{label}</button>)}</div>
                {state === 'success' && result.data.length > 0
                    ? <ul className="admin-wa-list">{result.data.map(row => <li key={row.id}><button type="button" className="admin-wa-item" onClick={() => setDrawerId(row.id)}>
                        <div className="admin-wa-item-top"><strong>{titleCase(row.name)}</strong><span className="admin-wa-phone">{formatPhone(row.phone)}</span></div>
                        <div className="admin-wa-item-tags"><TopicPill topic={row.topic} /><StatusBadge value={`wa_${row.status}`} /></div>
                        <p>{row.excerpt}</p>
                        <div className="admin-wa-item-foot"><span>{row.assignee ? `Atiende: ${row.assignee.name}` : 'Sin responsable'}</span><span>{timeAgoText(row.created_at)}</span></div>
                    </button></li>)}</ul>
                    : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando solicitudes…' : state === 'error' ? <>No se pudieron cargar las solicitudes. <button type="button" onClick={refresh}>Reintentar</button></> : <span className="admin-wa-empty"><MessageCircle aria-hidden="true" />No hay solicitudes con ese filtro.</span>}</div>}
                {state === 'success' && meta.last_page > 1 && <nav className="admin-products-pagination" aria-label="Paginación de solicitudes"><span>{`Mostrando ${meta.from}-${meta.to} de ${number(meta.total)}`}</span>
                    <div className="admin-products-pages"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                        {pageList(meta.current_page, meta.last_page).map(value => typeof value === 'string' ? <span key={value} className="admin-products-gap" aria-hidden="true">…</span> : <button key={value} type="button" className={value === meta.current_page ? 'is-current' : ''} aria-current={value === meta.current_page ? 'page' : undefined} onClick={() => setPage(value)}>{value}</button>)}
                        <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></nav>}
            </section>

            <div className="admin-wa-side">
                <section className="dashboard-card admin-wa-card" aria-labelledby="wa-form-title"><div className="admin-wa-card-head"><h2 id="wa-form-title">Registrar solicitud</h2></div>
                    <RequestForm onCreated={() => { setFilter('open'); setPage(1); refresh(); }} /></section>
                {result && <QuickReplies replies={result.quick_replies} onSaved={replies => setResult(current => ({ ...current, quick_replies: replies }))} />}
            </div>
        </div>
    </section>;
}
