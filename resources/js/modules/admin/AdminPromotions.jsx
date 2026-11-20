import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, CircleCheck, CircleOff, Package, Pencil, Search, Tag, Trash2 } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import './AdminPromotions.css';
import AdminConfirmDialog from './AdminConfirmDialog';
import AdminPromotionDrawer from './AdminPromotionDrawer';
import StatusBadge from './StatusBadge';
import { pageList, request, validity } from './adminShared';
import { useAdminToast } from './AdminToast';

const STATUS_OPTIONS = [['', 'Todas'], ['current', 'Vigentes'], ['scheduled', 'Programadas'], ['expired', 'Vencidas'], ['inactive', 'Inactivas']];
const STATUSES = STATUS_OPTIONS.map(([value]) => value).filter(Boolean);
const NOTES = { product_inactive: 'producto inactivo', category_inactive: 'categoría inactiva', variants_inactive: 'sin presentaciones activas' };
const USED_HINT = 'Esta promoción ya se usó en pedidos; puedes desactivarla.';
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;
const range = (min, max) => (min === max ? money(min) : `${money(min)} – ${money(max)}`);

function ProductImage({ src }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [src]);
    return <span className="admin-product-image">{src && !failed ? <img src={src} alt="" loading="lazy" onError={() => setFailed(true)} /> : <Package aria-hidden="true" />}</span>;
}

function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    return { search: (params.get('search') || '').slice(0, 100), status: STATUSES.includes(params.get('status')) ? params.get('status') : '', page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1 };
}

export default function AdminPromotions() {
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
        request(`/admin/promotions/data?${params}`, { signal: controller.signal })
            .then(data => { setResult(data); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [search, status, page, retry]);

    async function toggle(row) {
        if (busy.current) return;
        busy.current = true; setBusyId(row.id);
        try {
            const response = await request(`/admin/promotions/${row.id}/status`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ is_active: !row.is_active }) });
            toast.success(row.is_active ? 'Promoción desactivada' : 'Promoción activada', `La promoción de ${row.product.name} ${row.is_active ? 'dejó de aplicarse' : 'quedó activa'}.`);
            if (status) setRetry(value => value + 1);
            else setResult(current => ({ ...current, summary: response.summary, data: current.data.map(item => (item.id === row.id ? response.data : item)) }));
        } catch (error) {
            const first = Object.values(error.fields || {})[0]?.[0];
            toast.error('No se pudo cambiar el estado', first || error.message);
        } finally { busy.current = false; setBusyId(null); }
    }

    async function remove() {
        if (busy.current || !removing) return;
        busy.current = true; setBusyId(removing.row.id); setDialogError('');
        try {
            await request(`/admin/promotions/${removing.row.id}`, { method: 'DELETE' });
            toast.success('Promoción eliminada', `Se eliminó la promoción de ${removing.row.product.name}.`);
            setRemoving(null);
            setRetry(value => value + 1);
        } catch (error) { setDialogError(error.message); }
        finally { busy.current = false; setBusyId(null); }
    }

    const summary = result?.summary;
    const meta = result?.meta;
    const noPromotions = state === 'success' && meta.total === 0 && !search && !status;

    return <section className="admin-products admin-promotions">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Catálogo</span><h1>Promociones</h1><p>Gestiona los descuentos activos y programados del catálogo.</p></div>
            <div className="admin-products-header-actions"><button className="admin-products-new" type="button" onClick={() => setDrawer({ id: null })}>+ Nueva promoción</button></div></header>
        {summary && <div className="admin-products-summary">
            <div className="dashboard-card"><span>Vigentes</span><strong>{number(summary.current)}</strong></div>
            <div className="dashboard-card"><span>Programadas</span><strong>{number(summary.scheduled)}</strong></div>
            <div className="dashboard-card"><span>Vencidas</span><strong>{number(summary.expired)}</strong></div>
            <div className="dashboard-card"><span>Inactivas</span><strong>{number(summary.inactive)}</strong></div>
        </div>}
        <div className="admin-products-filters admin-products-filters--caps admin-promotions-filters dashboard-card">
            <label className="admin-products-search"><span>Buscar producto</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={draft} maxLength={100} onChange={event => setDraft(event.target.value)} placeholder="Nombre del producto" /></span></label>
            <label><span>Estado</span><select value={status} onChange={event => { setStatus(event.target.value); setPage(1); }}>{STATUS_OPTIONS.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>
        </div>

        {removing && <AdminConfirmDialog opener={removing.opener} busy={busyId !== null} error={dialogError} tone="danger" title="¿Eliminar promoción?" confirmLabel="Eliminar promoción"
            onCancel={() => { setRemoving(null); setDialogError(''); }} onConfirm={remove}>
            <p>Se eliminará la promoción de «{removing.row.product.name}» ({removing.row.discount_label}). Esta acción no se puede deshacer.</p>
            <p>No cambia los precios base ni ningún pedido.</p>
        </AdminConfirmDialog>}
        {drawer && <AdminPromotionDrawer id={drawer.id} onClose={() => setDrawer(null)} onSaved={(created, response) => {
            toast.success(created ? 'Promoción creada' : 'Promoción actualizada', `La promoción de ${response.data.product.name} (${response.data.discount_label}) se guardó correctamente.`);
            if (created) setPage(1);
            setRetry(value => value + 1);
        }} />}

        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Listado de promociones</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'resultado' : 'resultados'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0 ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de promociones, desplazable horizontalmente"><table className="admin-products-table admin-promotions-table">
                <thead><tr><th scope="col">Producto</th><th scope="col">Precio base</th><th scope="col">Tipo</th><th scope="col">Descuento</th><th scope="col">Precio promocional</th><th scope="col">Vigencia</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                {result.data.map(row => <tbody key={row.id}><tr className="admin-products-row">
                    <td data-label="Producto"><div className="admin-products-name"><ProductImage src={row.product.image_url} /><div className="admin-promotions-product"><strong>{row.product.name}</strong><small>{row.variants.map(variant => variant.name).join(' · ') || 'Sin presentaciones'}</small></div></div></td>
                    <td data-label="Precio base" className="admin-products-price">{row.variants.length ? range(row.base_min_cop, row.base_max_cop) : '—'}</td>
                    <td data-label="Tipo">{row.discount_type === 'percentage' ? 'Porcentaje' : 'Valor fijo'}</td>
                    <td data-label="Descuento"><strong className="admin-promotions-discount">{row.discount_label}</strong></td>
                    <td data-label="Precio promocional" className="admin-products-price">{row.variants.length ? range(row.promo_min_cop, row.promo_max_cop) : '—'}</td>
                    <td data-label="Vigencia">{validity(row)}</td>
                    <td data-label="Estado"><StatusBadge value={`promo_${row.status}`} />{row.note && <small className="admin-promotions-note">No se aplica en la tienda: {NOTES[row.note]}</small>}</td>
                    <td data-label="Acciones"><div className="admin-products-row-actions">
                        <button className="admin-action admin-action--neutral" type="button" onClick={() => setDrawer({ id: row.id })} aria-label={`Editar promoción de ${row.product.name}`}><Pencil aria-hidden="true" />Editar</button>
                        <button className={`admin-action ${row.is_active ? 'admin-action--deactivate' : 'admin-action--activate'}`} type="button" disabled={busyId !== null} onClick={() => toggle(row)} aria-label={`${row.is_active ? 'Desactivar' : 'Activar'} promoción de ${row.product.name}`}>{row.is_active ? <CircleOff aria-hidden="true" /> : <CircleCheck aria-hidden="true" />}{busyId === row.id ? 'Guardando…' : row.is_active ? 'Desactivar' : 'Activar'}</button>
                        {row.used_in_orders
                            ? <span className="admin-promotions-used" title={USED_HINT} tabIndex="0" role="note" aria-label={`Eliminar no disponible. ${USED_HINT}`}>Ya se usó en pedidos</span>
                            : <button className="admin-action admin-action--deactivate" type="button" disabled={busyId !== null} onClick={event => { setDialogError(''); setRemoving({ row, opener: event.currentTarget }); }} aria-label={`Eliminar promoción de ${row.product.name}`}><Trash2 aria-hidden="true" />Eliminar</button>}
                    </div></td>
                </tr></tbody>)}
            </table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando promociones…' : state === 'error' ? <>No se pudieron cargar las promociones. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></>
                    : noPromotions ? <div className="admin-promotions-empty"><Tag aria-hidden="true" /><strong>No hay promociones creadas.</strong><p>Crea una promoción para ofrecer un descuento temporal en uno de tus productos; aparecerá en la tienda mientras esté vigente.</p><button className="admin-products-new" type="button" onClick={() => setDrawer({ id: null })}>+ Nueva promoción</button></div>
                    : meta.total ? 'No hay resultados en esta página.' : 'No se encontraron promociones con estos filtros.'}</div>}
            {state === 'success' && meta.total > 0 && <nav className="admin-products-pagination" aria-label="Paginación de promociones"><span>{meta.from ? `Mostrando ${meta.from}-${meta.to} de ${number(meta.total)} ${meta.total === 1 ? 'promoción' : 'promociones'}` : `Página ${meta.current_page} sin resultados`}</span>
                <div className="admin-products-pages"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                    {pageList(meta.current_page, meta.last_page).map(value => typeof value === 'string' ? <span key={value} className="admin-products-gap" aria-hidden="true">…</span> : <button key={value} type="button" className={value === meta.current_page ? 'is-current' : ''} aria-current={value === meta.current_page ? 'page' : undefined} aria-label={`Página ${value}`} onClick={() => setPage(value)}>{value}</button>)}
                    <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></nav>}
        </div>
    </section>;
}
