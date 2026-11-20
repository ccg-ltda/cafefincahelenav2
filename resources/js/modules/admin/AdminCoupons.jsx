import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, CircleCheck, CircleOff, Copy, Pencil, Search, Ticket, Trash2 } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import './AdminPromotions.css';
import './AdminCoupons.css';
import AdminConfirmDialog from './AdminConfirmDialog';
import AdminCouponDrawer from './AdminCouponDrawer';
import StatusBadge from './StatusBadge';
import { pageList, request, validity } from './adminShared';
import { useAdminToast } from './AdminToast';

const STATUS_OPTIONS = [['', 'Todos'], ['current', 'Vigentes'], ['scheduled', 'Programados'], ['expired', 'Vencidos'], ['exhausted', 'Agotados'], ['inactive', 'Inactivos']];
const STATUSES = STATUS_OPTIONS.map(([value]) => value).filter(Boolean);
const USED_HINT = 'Este cupón ya se usó en pedidos; puedes desactivarlo.';
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const area = document.createElement('textarea');
        area.value = text; document.body.appendChild(area); area.select();
        try { document.execCommand('copy'); } finally { document.body.removeChild(area); }
    }
}

function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    return { search: (params.get('search') || '').slice(0, 100), status: STATUSES.includes(params.get('status')) ? params.get('status') : '', page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1 };
}

function Uses({ row }) {
    const full = row.max_uses !== null && row.uses >= row.max_uses;
    return <div className="admin-coupons-uses">
        <span>{number(row.uses)} / {row.max_uses === null ? '∞' : number(row.max_uses)}</span>
        {row.max_uses !== null && <span className="admin-coupons-bar" role="img" aria-label={`${row.uses} de ${row.max_uses} usos`}><span className={full ? 'is-full' : ''} style={{ width: `${Math.min(100, (row.uses / row.max_uses) * 100)}%` }} /></span>}
    </div>;
}

export default function AdminCoupons() {
    const toast = useAdminToast();
    const [initial] = useState(initialLocation);
    const [draft, setDraft] = useState(initial.search);
    const [search, setSearch] = useState(initial.search);
    const [status, setStatus] = useState(initial.status);
    const [page, setPage] = useState(initial.page);
    const [result, setResult] = useState(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const [drawer, setDrawer] = useState(null); // { id: null } to create, { id } to edit
    const [removing, setRemoving] = useState(null);
    const [dialogError, setDialogError] = useState('');
    const [busyId, setBusyId] = useState(null);
    const busy = useRef(false);

    useEffect(() => {
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (page > 1) params.set('page', String(page));
        const query = params.toString();
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
    }, [search, status, page]);

    useEffect(() => {
        if (draft.trim() === search) return undefined;
        const timer = window.setTimeout(() => { setSearch(draft.trim()); setPage(1); }, 300);
        return () => window.clearTimeout(timer);
    }, [draft, search]);

    useEffect(() => {
        const controller = new AbortController();
        const params = new URLSearchParams({ page: String(page) });
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        setState('loading');
        request(`/admin/coupons/data?${params}`, { signal: controller.signal })
            .then(data => { setResult(data); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [search, status, page, retry]);

    async function toggle(row) {
        if (busy.current) return;
        busy.current = true; setBusyId(row.id);
        try {
            const response = await request(`/admin/coupons/${row.id}/status`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ is_active: !row.is_active }) });
            toast.success(row.is_active ? 'Cupón desactivado' : 'Cupón activado', `El cupón ${row.code} ${row.is_active ? 'dejó de aplicarse' : 'quedó activo'}.`);
            if (status) setRetry(value => value + 1);
            else setResult(current => ({ ...current, summary: response.summary, data: current.data.map(item => (item.id === row.id ? response.data : item)) }));
        } catch (error) {
            toast.error('No se pudo cambiar el estado', error.message);
        } finally { busy.current = false; setBusyId(null); }
    }

    async function remove() {
        if (busy.current || !removing) return;
        busy.current = true; setBusyId(removing.row.id); setDialogError('');
        try {
            await request(`/admin/coupons/${removing.row.id}`, { method: 'DELETE' });
            toast.success('Cupón eliminado', `Se eliminó el cupón ${removing.row.code}.`);
            setRemoving(null);
            setRetry(value => value + 1);
        } catch (error) { setDialogError(error.message); }
        finally { busy.current = false; setBusyId(null); }
    }

    async function copy(row) {
        await copyText(row.code);
        toast.success('Código copiado', `${row.code} está en tu portapapeles.`);
    }

    const summary = result?.summary;
    const meta = result?.meta;
    const noCoupons = state === 'success' && meta.total === 0 && !search && !status;

    return <section className="admin-products admin-promotions admin-coupons">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Catálogo</span><h1>Cupones</h1><p>Gestiona descuentos generales y su vigencia.</p></div>
            <div className="admin-products-header-actions"><button className="admin-products-new" type="button" onClick={() => setDrawer({ id: null })}>+ Nuevo cupón</button></div></header>
        {summary && <div className="admin-products-summary">
            <div className="dashboard-card"><span>Vigentes</span><strong>{number(summary.current)}</strong></div>
            <div className="dashboard-card"><span>Programados</span><strong>{number(summary.scheduled)}</strong></div>
            <div className="dashboard-card"><span>Vencidos o agotados</span><strong>{number(summary.expired + summary.exhausted)}</strong></div>
            <div className="dashboard-card"><span>Inactivos</span><strong>{number(summary.inactive)}</strong></div>
        </div>}
        <div className="admin-products-filters admin-products-filters--caps admin-promotions-filters dashboard-card">
            <label className="admin-products-search"><span>Buscar cupón</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={draft} maxLength={100} onChange={event => setDraft(event.target.value)} placeholder="Código o descripción" /></span></label>
            <label><span>Estado</span><select value={status} onChange={event => { setStatus(event.target.value); setPage(1); }}>{STATUS_OPTIONS.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>
        </div>

        {removing && <AdminConfirmDialog opener={removing.opener} busy={busyId !== null} error={dialogError} tone="danger" title="¿Eliminar cupón?" confirmLabel="Eliminar cupón"
            onCancel={() => { setRemoving(null); setDialogError(''); }} onConfirm={remove}>
            <p>Se eliminará el cupón <code>{removing.row.code}</code> ({removing.row.discount_label}). Esta acción no se puede deshacer.</p>
            <p>No cambia ningún pedido.</p>
        </AdminConfirmDialog>}
        {drawer && <AdminCouponDrawer id={drawer.id} onClose={() => setDrawer(null)} onSaved={(created, response) => {
            toast.success(created ? 'Cupón creado' : 'Cupón actualizado', `El cupón ${response.data.code} se guardó correctamente.`);
            if (created) setPage(1);
            setRetry(value => value + 1);
        }} />}

        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Listado de cupones</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'resultado' : 'resultados'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0 ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de cupones, desplazable horizontalmente"><table className="admin-products-table admin-coupons-table">
                <thead><tr><th scope="col">Código</th><th scope="col">Descripción</th><th scope="col">Tipo</th><th scope="col">Descuento</th><th scope="col">Mínimo</th><th scope="col">Usos</th><th scope="col">Vigencia</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                {result.data.map(row => <tbody key={row.id}><tr className="admin-products-row">
                    <td data-label="Código"><span className="admin-coupons-code"><code>{row.code}</code><button type="button" onClick={() => copy(row)} aria-label={`Copiar el código ${row.code}`} title="Copiar código"><Copy aria-hidden="true" /></button></span></td>
                    <td data-label="Descripción" className="admin-coupons-description"><div>{row.description || <span className="admin-coupons-none">—</span>}{row.exclude_promoted_items && <small>No aplica a productos en promoción</small>}</div></td>
                    <td data-label="Tipo">{row.discount_type === 'percentage' ? 'Porcentaje' : 'Valor fijo'}</td>
                    <td data-label="Descuento"><div><strong className="admin-promotions-discount">{row.discount_label}</strong>{row.max_discount_cop !== null && <small className="admin-coupons-cap">tope {money(row.max_discount_cop)}</small>}</div></td>
                    <td data-label="Mínimo" className="admin-products-price">{row.min_subtotal_cop > 0 ? money(row.min_subtotal_cop) : <span className="admin-coupons-none">Sin mínimo</span>}</td>
                    <td data-label="Usos"><div><Uses row={row} /></div></td>
                    <td data-label="Vigencia">{validity(row)}</td>
                    <td data-label="Estado"><StatusBadge value={`coupon_${row.status}`} /></td>
                    <td data-label="Acciones"><div className="admin-products-row-actions">
                        <button className="admin-action admin-action--neutral" type="button" onClick={() => setDrawer({ id: row.id })} aria-label={`Editar cupón ${row.code}`}><Pencil aria-hidden="true" />Editar</button>
                        <button className={`admin-action ${row.is_active ? 'admin-action--deactivate' : 'admin-action--activate'}`} type="button" disabled={busyId !== null} onClick={() => toggle(row)} aria-label={`${row.is_active ? 'Desactivar' : 'Activar'} cupón ${row.code}`}>{row.is_active ? <CircleOff aria-hidden="true" /> : <CircleCheck aria-hidden="true" />}{busyId === row.id ? 'Guardando…' : row.is_active ? 'Desactivar' : 'Activar'}</button>
                        {row.used_in_orders
                            ? <span className="admin-promotions-used" title={USED_HINT} tabIndex="0" role="note" aria-label={`Eliminar no disponible. ${USED_HINT}`}>Ya se usó en pedidos</span>
                            : <button className="admin-action admin-action--deactivate" type="button" disabled={busyId !== null} onClick={event => { setDialogError(''); setRemoving({ row, opener: event.currentTarget }); }} aria-label={`Eliminar cupón ${row.code}`}><Trash2 aria-hidden="true" />Eliminar</button>}
                    </div></td>
                </tr></tbody>)}
            </table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando cupones…' : state === 'error' ? <>No se pudieron cargar los cupones. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></>
                    : noCoupons ? <div className="admin-promotions-empty"><Ticket aria-hidden="true" /><strong>No hay cupones creados.</strong><p>Crea un cupón para ofrecer un descuento general que tus clientes escriben al comprar; puedes limitar sus usos y su vigencia.</p><button className="admin-products-new" type="button" onClick={() => setDrawer({ id: null })}>+ Nuevo cupón</button></div>
                    : meta.total ? 'No hay resultados en esta página.' : 'No se encontraron cupones con estos filtros.'}</div>}
            {state === 'success' && meta.total > 0 && <nav className="admin-products-pagination" aria-label="Paginación de cupones"><span>{meta.from ? `Mostrando ${meta.from}-${meta.to} de ${number(meta.total)} ${meta.total === 1 ? 'cupón' : 'cupones'}` : `Página ${meta.current_page} sin resultados`}</span>
                <div className="admin-products-pages"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                    {pageList(meta.current_page, meta.last_page).map(value => typeof value === 'string' ? <span key={value} className="admin-products-gap" aria-hidden="true">…</span> : <button key={value} type="button" className={value === meta.current_page ? 'is-current' : ''} aria-current={value === meta.current_page ? 'page' : undefined} aria-label={`Página ${value}`} onClick={() => setPage(value)}>{value}</button>)}
                    <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></nav>}
        </div>
    </section>;
}
