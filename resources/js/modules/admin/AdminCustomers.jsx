import { useEffect, useRef, useState } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, Download, MessageCircle, Search, Users } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import './AdminCustomers.css';
import AdminCustomerDrawer from './AdminCustomerDrawer';
import { dayText, daysAgoText, pageList, request, titleCase, whatsappUrl } from './adminShared';

const PILLS = [['', 'Todos', 'all'], ['recurring', 'Recurrentes', 'recurring'], ['recover', 'Por recuperar', 'recover'], ['new', 'Nuevos', 'new'], ['promos', 'Aceptan promociones', 'promos']];
const FILTERS = PILLS.map(([value]) => value).filter(Boolean);
const SORTS = ['spent', 'orders', 'last'];
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;

// URL: /admin/customers[/{id}]?search=&filter=&sort=&dir=&page=  (the id is the customer open in the drawer)
const readId = () => Number(window.location.pathname.match(/^\/admin\/customers\/(\d+)$/)?.[1]) || null;
function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    return {
        search: (params.get('search') || '').slice(0, 100), filter: FILTERS.includes(params.get('filter')) ? params.get('filter') : '',
        sort: SORTS.includes(params.get('sort')) ? params.get('sort') : 'last', dir: params.get('dir') === 'asc' ? 'asc' : 'desc',
        page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1,
    };
}

export default function AdminCustomers() {
    const [initial] = useState(initialLocation);
    const [draft, setDraft] = useState(initial.search);
    const [search, setSearch] = useState(initial.search);
    const [filter, setFilter] = useState(initial.filter);
    const [sort, setSort] = useState(initial.sort);
    const [dir, setDir] = useState(initial.dir);
    const [page, setPage] = useState(initial.page);
    const [drawerId, setDrawerId] = useState(readId);
    const [result, setResult] = useState(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const skipUrl = useRef(true);

    const queryString = () => {
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (filter) params.set('filter', filter);
        if (sort !== 'last' || dir !== 'desc') { params.set('sort', sort); params.set('dir', dir); }
        return params;
    };

    // Keep the URL in step with the filters and the open customer. Opening/closing the drawer adds/removes a history entry.
    useEffect(() => {
        const params = queryString();
        if (page > 1) params.set('page', String(page));
        const text = params.toString();
        const path = drawerId ? `/admin/customers/${drawerId}` : '/admin/customers';
        const next = `${path}${text ? `?${text}` : ''}`;
        if (next === `${window.location.pathname}${window.location.search}`) return;
        if (skipUrl.current) { skipUrl.current = false; window.history.replaceState(window.history.state, '', next); return; }
        window.history[readId() === drawerId ? 'replaceState' : 'pushState'](window.history.state, '', next);
    }, [search, filter, sort, dir, page, drawerId]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const onPop = () => setDrawerId(readId());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    useEffect(() => {
        if (draft.trim() === search) return undefined;
        const timer = window.setTimeout(() => { setSearch(draft.trim()); setPage(1); }, 300);
        return () => window.clearTimeout(timer);
    }, [draft, search]);

    useEffect(() => {
        const controller = new AbortController();
        const params = queryString();
        params.set('page', String(page));
        setState('loading');
        request(`/admin/customers/data?${params}`, { signal: controller.signal })
            .then(data => { setResult(data); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [search, filter, sort, dir, page, retry]); // eslint-disable-line react-hooks/exhaustive-deps

    function sortBy(column) {
        if (sort === column) setDir(current => (current === 'desc' ? 'asc' : 'desc'));
        else { setSort(column); setDir('desc'); }
        setPage(1);
    }

    const sortHead = (column, label) => {
        const active = sort === column;
        const Icon = !active ? ArrowUpDown : dir === 'asc' ? ArrowUp : ArrowDown;
        return <th scope="col" className={`admin-customers-sortable${column === 'spent' ? ' is-money' : ''}`} aria-sort={active ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button type="button" onClick={() => sortBy(column)}>{label}<Icon aria-hidden="true" /></button></th>;
    };

    const counters = result?.counters;
    const meta = result?.meta;
    const noCustomers = state === 'success' && meta.total === 0 && !search && !filter;
    const exportParams = queryString();

    return <section className="admin-products admin-customers">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Relación con clientes</span><h1>Clientes</h1><p>Quién compra, cuánto y hace cuánto.</p></div>
            <div className="admin-products-header-actions"><a className="admin-customers-export" href={`/admin/customers/export${exportParams.size ? `?${exportParams}` : ''}`}><Download aria-hidden="true" />Exportar CSV</a></div></header>

        <div className="admin-customers-filters dashboard-card">
            <label className="admin-products-search"><span>Buscar por nombre, correo, ciudad o teléfono</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={draft} maxLength={100} onChange={event => setDraft(event.target.value)} placeholder="Nombre, correo, ciudad o teléfono" /></span></label>
            <div className="admin-customers-pills" role="group" aria-label="Segmentos de clientes">
                {PILLS.map(([value, label, key]) => <button key={key} type="button" className={`admin-customers-pill${filter === value ? ' is-active' : ''}`} aria-pressed={filter === value} onClick={() => { setFilter(value); setPage(1); }}>{label}<b>{counters ? number(counters[key]) : '–'}</b></button>)}
            </div>
        </div>

        {drawerId && <AdminCustomerDrawer id={drawerId} onClose={() => setDrawerId(null)} onChanged={() => setRetry(value => value + 1)} />}

        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Listado de clientes</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'resultado' : 'resultados'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0 ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de clientes, desplazable horizontalmente"><table className="admin-products-table admin-customers-table">
                <thead><tr><th scope="col">Cliente</th><th scope="col">Ciudad</th>{sortHead('orders', 'Pedidos')}{sortHead('spent', 'Gastado')}{sortHead('last', 'Última compra')}<th scope="col">Promociones</th><th scope="col"><span className="admin-customers-sr">Acciones</span></th></tr></thead>
                {result.data.map(row => <tbody key={row.id}><tr className="admin-products-row admin-customers-row" onClick={() => setDrawerId(row.id)}>
                    <td data-label="Cliente"><div><button type="button" className="admin-customers-name" onClick={event => { event.stopPropagation(); setDrawerId(row.id); }} aria-label={`Ver detalle de ${titleCase(row.name)}`}>{titleCase(row.name)}</button><small>{row.email}</small></div></td>
                    <td data-label="Ciudad">{row.city}</td>
                    <td data-label="Pedidos" className="admin-customers-num">{number(row.orders_count)}</td>
                    <td data-label="Gastado" className="admin-customers-money">{money(row.spent_cop)}</td>
                    <td data-label="Última compra"><div>{dayText(row.last_order_at)}<small>{daysAgoText(row.last_order_at)}</small></div></td>
                    <td data-label="Promociones">{row.accepts_marketing ? <span className="admin-customers-accepts">Acepta</span> : <span className="admin-customers-none" aria-label="No acepta">—</span>}</td>
                    <td data-label="Acciones"><a className="admin-customers-whatsapp" href={whatsappUrl(row)} target="_blank" rel="noopener noreferrer" onClick={event => event.stopPropagation()} aria-label={`Escribir por WhatsApp a ${titleCase(row.name)} (se abre en una pestaña nueva)`}><MessageCircle aria-hidden="true" />WhatsApp</a></td>
                </tr></tbody>)}
            </table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando clientes…' : state === 'error' ? <>No se pudieron cargar los clientes. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></>
                    : noCustomers ? <div className="admin-promotions-empty"><Users aria-hidden="true" /><strong>Aún no hay clientes con compras.</strong><p>Cuando alguien compre en la tienda aparecerá aquí con sus pedidos y lo que ha gastado.</p></div>
                    : meta.total ? 'No hay resultados en esta página.' : 'No se encontraron clientes con estos filtros.'}</div>}
            {state === 'success' && meta.total > 0 && <nav className="admin-products-pagination" aria-label="Paginación de clientes"><span>{meta.from ? `Mostrando ${meta.from}-${meta.to} de ${number(meta.total)} ${meta.total === 1 ? 'cliente' : 'clientes'}` : `Página ${meta.current_page} sin resultados`}</span>
                <div className="admin-products-pages"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                    {pageList(meta.current_page, meta.last_page).map(value => typeof value === 'string' ? <span key={value} className="admin-products-gap" aria-hidden="true">…</span> : <button key={value} type="button" className={value === meta.current_page ? 'is-current' : ''} aria-current={value === meta.current_page ? 'page' : undefined} aria-label={`Página ${value}`} onClick={() => setPage(value)}>{value}</button>)}
                    <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></nav>}
        </div>
    </section>;
}
