import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Download, History } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import './AdminCustomers.css';
import './AdminActivity.css';
import AdminActivityDrawer from './AdminActivityDrawer';
import { actorText, request, titleCase } from './adminShared';

const number = value => new Intl.NumberFormat('es-CO').format(value);
const EMPTY = { admin: '', module: '', action: '', from: '', to: '', includeAuth: false };
const DATE = /^\d{4}-\d{2}-\d{2}$/;

// URL: /admin/activity[/{id}]?admin=&module=&action=&from=&to=&include_auth=1&page=  (the id is the record open in the drawer)
const readId = () => Number(window.location.pathname.match(/^\/admin\/activity\/(\d+)$/)?.[1]) || null;
function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    const filters = {
        admin: /^\d+$/.test(params.get('admin') || '') ? params.get('admin') : '', module: /^[a-z]+$/.test(params.get('module') || '') ? params.get('module') : '',
        action: /^[a-z_]+$/.test(params.get('action') || '') ? params.get('action') : '', from: DATE.test(params.get('from') || '') ? params.get('from') : '',
        to: DATE.test(params.get('to') || '') ? params.get('to') : '', includeAuth: params.get('include_auth') === '1',
    };
    return { filters, page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1 };
}

const toParams = filters => {
    const params = new URLSearchParams();
    if (filters.admin) params.set('admin', filters.admin);
    if (filters.module) params.set('module', filters.module);
    if (filters.action) params.set('action', filters.action);
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.includeAuth) params.set('include_auth', '1');
    return params;
};

export default function AdminActivity() {
    const [initial] = useState(initialLocation);
    const [draft, setDraft] = useState(initial.filters);
    const [applied, setApplied] = useState(initial.filters);
    const [page, setPage] = useState(initial.page);
    const [drawerId, setDrawerId] = useState(readId);
    const [result, setResult] = useState(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const [options, setOptions] = useState(null);
    const skipUrl = useRef(true);

    // Keep the URL in step with the applied filters and the open record. Opening/closing the drawer adds/removes a history entry.
    useEffect(() => {
        const params = toParams(applied);
        if (page > 1) params.set('page', String(page));
        const text = params.toString();
        const next = `${drawerId ? `/admin/activity/${drawerId}` : '/admin/activity'}${text ? `?${text}` : ''}`;
        if (next === `${window.location.pathname}${window.location.search}`) return;
        if (skipUrl.current) { skipUrl.current = false; window.history.replaceState(window.history.state, '', next); return; }
        window.history[readId() === drawerId ? 'replaceState' : 'pushState'](window.history.state, '', next);
    }, [applied, page, drawerId]);

    useEffect(() => {
        const onPop = () => setDrawerId(readId());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const params = toParams(applied);
        params.set('page', String(page));
        setState('loading');
        request(`/admin/activity/data?${params}`, { signal: controller.signal })
            .then(data => { setResult(data); setOptions(data.options); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [applied, page, retry]);

    // The action list follows the module: with no module it shows every action once.
    const actionOptions = !options ? [] : draft.module ? options.actions[draft.module] || []
        : [...new Map(Object.values(options.actions).flat().map(item => [item.value, item])).values()];

    function change(field, value) {
        setDraft(current => {
            const next = { ...current, [field]: value };
            if (field === 'module' && next.action && !(options?.actions[value] || []).some(item => item.value === next.action) && value) next.action = '';
            return next;
        });
    }
    function apply(event) {
        event.preventDefault();
        if (draft.from && draft.to && draft.to < draft.from) return;
        setApplied(draft); setPage(1);
    }
    function clear(event) { event.preventDefault(); setDraft(EMPTY); setApplied(EMPTY); setPage(1); }

    const meta = result?.meta;
    const dirty = JSON.stringify(draft) !== JSON.stringify(applied);
    const hasFilters = JSON.stringify(applied) !== JSON.stringify(EMPTY);
    const rangeError = draft.from && draft.to && draft.to < draft.from;
    const exportParams = toParams(applied);

    return <section className="admin-products admin-activity">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Gestión administrativa</span><h1>Historial</h1><p>Consulta las acciones administrativas registradas en la tienda.</p></div>
            <div className="admin-products-header-actions"><a className="admin-customers-export" href={`/admin/activity/export${exportParams.size ? `?${exportParams}` : ''}`}><Download aria-hidden="true" />Exportar CSV</a></div></header>

        <form className="admin-activity-filters dashboard-card" onSubmit={apply} aria-label="Filtros del historial">
            <label className="admin-activity-field"><span>Administrador</span>
                <select value={draft.admin} onChange={event => change('admin', event.target.value)}><option value="">Todos</option>{options?.admins.map(admin => <option key={admin.id} value={admin.id}>{titleCase(admin.name)}</option>)}</select></label>
            <label className="admin-activity-field"><span>Módulo</span>
                <select value={draft.module} onChange={event => change('module', event.target.value)}><option value="">Todos</option>{options?.modules.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
            <label className="admin-activity-field"><span>Acción</span>
                <select value={draft.action} onChange={event => change('action', event.target.value)}><option value="">Todas</option>{actionOptions.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
            <label className="admin-activity-field"><span>Desde</span><input type="date" value={draft.from} max={draft.to || undefined} onChange={event => change('from', event.target.value)} /></label>
            <label className="admin-activity-field"><span>Hasta</span><input type="date" value={draft.to} min={draft.from || undefined} aria-invalid={rangeError || undefined} onChange={event => change('to', event.target.value)} /></label>
            <label className="admin-activity-check"><input type="checkbox" checked={draft.includeAuth} onChange={event => change('includeAuth', event.target.checked)} /><span>Incluir accesos</span></label>
            <div className="admin-activity-actions"><button type="submit" className="admin-categories-primary" disabled={Boolean(rangeError)}>Aplicar filtros</button>
                <a href="/admin/activity" className="admin-activity-clear" onClick={clear} aria-disabled={!hasFilters && !dirty}>Limpiar</a></div>
            {rangeError && <p className="admin-activity-error" role="alert">La fecha final no puede ser anterior a la inicial.</p>}
        </form>

        {drawerId && <AdminActivityDrawer id={drawerId} onClose={() => setDrawerId(null)} />}

        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Registro de actividad</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'registro' : 'registros'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0 ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de actividad, desplazable horizontalmente"><table className="admin-products-table admin-activity-table">
                <thead><tr><th scope="col">Fecha</th><th scope="col">Administrador</th><th scope="col">Módulo</th><th scope="col">Acción</th><th scope="col">Recurso</th></tr></thead>
                {result.data.map(row => <tbody key={row.id} className={row.failed ? 'is-failed' : undefined}><tr className={`admin-products-row admin-activity-row${row.failed ? ' is-failed' : ''}`} tabIndex={0} onClick={() => setDrawerId(row.id)}
                    onKeyDown={event => { if (event.key === 'Enter' && event.target === event.currentTarget) setDrawerId(row.id); }} aria-label={`Ver registro: ${row.action_label}, ${row.occurred_text}`}>
                    <td data-label="Fecha" className="admin-activity-date">{row.occurred_text}</td>
                    <td data-label="Administrador"><strong>{actorText(row.actor)}</strong></td>
                    <td data-label="Módulo">{row.module_label}</td>
                    <td data-label="Acción"><span className={`admin-activity-action${row.failed ? ' is-failed' : ''}`}>{row.action_label}</span></td>
                    <td data-label="Recurso">{row.subject_label ? (row.link ? <a className="admin-activity-link" href={row.link} onClick={event => event.stopPropagation()}>{row.subject_label}</a> : row.subject_label) : '—'}</td>
                </tr></tbody>)}
            </table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando historial…' : state === 'error' ? <>No se pudo cargar el historial. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></>
                    : meta.total ? 'No hay resultados en esta página.' : <div className="admin-promotions-empty"><History aria-hidden="true" /><strong>No hay actividad con esos filtros.</strong></div>}</div>}
            {state === 'success' && meta.total > 0 && <nav className="admin-products-pagination admin-activity-pagination" aria-label="Paginación del historial">
                <button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                <span>Página {meta.current_page} de {meta.last_page}</span>
                <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></nav>}
        </div>
    </section>;
}
