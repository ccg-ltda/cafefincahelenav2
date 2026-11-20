import { useEffect, useRef, useState } from 'react';
import { Boxes, ChevronDown, ChevronLeft, ChevronRight, CircleCheck, CircleOff, Package, PackagePlus, Pencil, Search, Tags } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import AdminProductEditor from './AdminProductEditor';
import AdminConfirmDialog from './AdminConfirmDialog';
import AdminStockDialog from './AdminStockDialog';
import StatusBadge from './StatusBadge';
import { pageList, request } from './adminShared';
import { useAdminToast } from './AdminToast';

const STATUSES = ['active', 'inactive', 'low', 'out'];
const STATUS_OPTIONS = [['', 'Todos'], ['active', 'Activos'], ['inactive', 'Inactivos'], ['low', 'Stock bajo'], ['out', 'Agotados']];
const LEGACY_PUBLICATION = { published: 'active', unpublished: 'inactive' };
const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;
const priceRange = item => (item.min_price_cop == null ? '—' : item.min_price_cop === item.max_price_cop ? money(item.min_price_cop) : `${money(item.min_price_cop)} – ${money(item.max_price_cop)}`);
const presentation = variant => [variant.weight_grams ? `${variant.weight_grams} g` : null, variant.grind_type].filter(Boolean).join(' · ');
const STOCK_FLAGS = { low: 'Stock bajo', out: 'Agotado' };

const VISIBILITY_REASONS = { category_inactive: 'categoría inactiva', no_variants: 'sin presentaciones', no_active_variants: 'sin presentaciones activas' };
const visibilityText = item => item.visible_in_store ? 'Visible en tienda' : `No visible en tienda${VISIBILITY_REASONS[item.visibility_reason] ? `: ${VISIBILITY_REASONS[item.visibility_reason]}` : ''}`;

function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const productId = params.get('product');
    const variantId = params.get('variant');
    const mode = params.get('editor');
    const id = value => /^\d+$/.test(value || '') && Number(value) > 0;
    const editor = mode === 'new' ? { mode } : id(productId) && (mode === 'simple' || mode === 'manage' || mode === 'product' || mode === 'add' || (mode === 'variant' && id(variantId))) ? { mode, productId, variantId, focus: params.get('focus') === 'stock' ? 'stock' : undefined } : null;
    const page = Number(params.get('page'));
    return { editor, search: (params.get('search') || '').slice(0, 100), category: id(params.get('category')) ? params.get('category') : '', status: STATUSES.includes(params.get('status')) ? params.get('status') : LEGACY_PUBLICATION[params.get('publication')] || '', page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1 };
}

function ProductImage({ src }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [src]);
    return <span className="admin-product-image">{src && !failed ? <img src={src} alt="" loading="lazy" onError={() => setFailed(true)} /> : <Package aria-hidden="true" />}</span>;
}

export default function AdminProducts() {
    const [initial] = useState(initialLocation);
    const [draft, setDraft] = useState(initial.search);
    const [search, setSearch] = useState(initial.search);
    const [category, setCategory] = useState(initial.category);
    const [status, setStatus] = useState(initial.status);
    const [page, setPage] = useState(initial.page);
    const [result, setResult] = useState(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const [editor, setEditor] = useState(initial.editor);
    const [dialog, setDialog] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const toast = useAdminToast();
    const [dialogError, setDialogError] = useState('');
    const [expanded, setExpanded] = useState(() => new Set());
    const [stockDialog, setStockDialog] = useState(null);
    const stockChanged = useRef(false);
    const busy = useRef(false);

    // Activating never changes variants or categories, so say when the product will still not be visible.
    const activationWarnings = item => [
        !item.category_active && `La categoría «${item.category_name}» está inactiva: el producto no se mostrará hasta activarla.`,
        item.variant_count === 0 && 'Aún no tiene presentaciones: no se mostrará hasta que agregues y actives una.',
        item.variant_count > 0 && item.published_variant_count === 0 && 'Ninguna de sus presentaciones está activa: no se mostrará hasta activar al menos una.',
    ].filter(Boolean);

    function askStatus(item, opener) {
        setDialogError('');
        if (!item.product_published && activationWarnings(item).length === 0) { changeStatus(item, true, false); return; }
        setDialog({ item, opener });
    }

    async function changeStatus(item, active, viaDialog) {
        if (busy.current) return;
        busy.current = true; setBusyId(item.product_id); setDialogError('');
        try {
            const response = await request(`/admin/products/${item.product_id}/status`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ is_published: active }) });
            const saved = response.data;
            setDialog(null);
            toast.success(active ? 'Producto activado' : 'Producto desactivado', `${saved.product_name} ${active ? 'está activo nuevamente' : 'dejó de estar disponible en la tienda'}.${active && !saved.visible_in_store ? ' Continúa sin mostrarse en la tienda.' : ''}`);
            // Update only after the server confirmed. With a status filter the row may leave the list, so reload instead.
            if (status) setRetry(value => value + 1);
            else setResult(current => ({ ...current, summary: response.summary, data: current.data.map(row => row.product_id === saved.product_id ? saved : row) }));
        } catch (error) {
            if (viaDialog) setDialogError(error.message); else toast.error('No se pudo cambiar el estado', error.message);
        } finally { busy.current = false; setBusyId(null); }
    }

    useEffect(() => {
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (category) params.set('category', category);
        if (status) params.set('status', status);
        if (page > 1) params.set('page', String(page));
        if (editor) {
            params.set('editor', editor.mode);
            if (editor.productId) params.set('product', String(editor.productId));
            if (editor.mode === 'variant') params.set('variant', String(editor.variantId));
            if (editor.focus) params.set('focus', editor.focus);
        }
        const query = params.toString();
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
    }, [search, category, status, page, editor]);

    useEffect(() => {
        if (draft.trim() === search) return undefined;
        const timer = window.setTimeout(() => { setSearch(draft.trim()); setPage(1); }, 300);
        return () => window.clearTimeout(timer);
    }, [draft, search]);

    useEffect(() => {
        const controller = new AbortController();
        const params = new URLSearchParams({ page: String(page) });
        if (search) params.set('search', search);
        if (category) params.set('category', category);
        if (status) params.set('status', status);
        setState('loading');
        fetch(`/admin/products/data?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then(async response => {
                if (response.status === 401 || response.redirected) { window.location.replace('/admin/login'); return null; }
                if (!response.ok) throw new Error('products');
                return response.json();
            })
            .then(data => { if (data) { setResult(data); setState('success'); } })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [search, category, status, page, retry]);

    if (editor !== null) {
        if (!result && editor.mode !== 'variant' && editor.mode !== 'add') return <section className="admin-products"><p role={state === 'error' ? 'alert' : 'status'}>{state === 'error' ? 'No se pudieron cargar las categorías.' : 'Cargando información…'}</p>{state === 'error' && <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button>} <button type="button" onClick={() => setEditor(null)}>Volver a productos</button></section>;
        return <AdminProductEditor key={`${editor.mode}-${editor.productId || 'new'}-${editor.variantId || ''}`} mode={editor.mode} productId={editor.productId} variantId={editor.variantId} focus={editor.focus} categories={result?.categories || []} onCategoryCreated={category => setResult(current => current && ({ ...current, categories: [...current.categories.filter(item => item.id !== category.id), category].sort((a, b) => a.name.localeCompare(b.name, 'es', { sensitivity: 'base' }) || a.id - b.id) }))} onNavigate={setEditor} onClose={() => { setEditor(null); setRetry(value => value + 1); }} />;
    }

    // Opens the editor at the stock section: the single variant of a simple product, or the given variant.
    const editStock = (item, variant) => setEditor(item.variant_count === 1 ? { mode: 'simple', productId: item.product_id, focus: 'stock' } : { mode: 'variant', productId: item.product_id, variantId: variant.id, focus: 'stock' });

    function toggle(id) {
        setExpanded(current => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); return next; });
    }

    function stockSaved(response) {
        stockChanged.current = true;
        setResult(current => current && ({ ...current, summary: response.summary, data: current.data.map(row => row.product_id === response.data.product_id ? response.data : row) }));
        if (response.movement) toast.success('Stock actualizado', `${response.data.product_name}: de ${number(response.movement.from)} a ${number(response.movement.stock_after)}.`);
    }

    function closeStock() {
        setStockDialog(null);
        // With a stock filter the product may no longer belong to the list.
        if (stockChanged.current && (status === 'low' || status === 'out')) setRetry(value => value + 1);
        stockChanged.current = false;
    }

    const stockProduct = stockDialog && result?.data.find(row => row.product_id === stockDialog.productId);
    const stockVariant = stockProduct?.variants.find(variant => variant.id === stockDialog.variantId);
    const meta = result?.meta;
    const summary = result?.summary;

    return <section className="admin-products">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Catálogo</span><h1>Productos</h1><p>Gestiona tu catálogo, sus presentaciones y el inventario.</p></div><div className="admin-products-header-actions"><a className="admin-products-categories-link" href="/admin/products/categories"><Tags aria-hidden="true" /> Gestionar categorías</a><button className="admin-products-new" type="button" disabled={!result || state !== 'success'} onClick={() => setEditor({ mode: 'new' })}>+ Nuevo producto</button></div></header>
        {summary && <div className="admin-products-summary">
            <div className="dashboard-card"><span>Total de productos</span><strong>{number(summary.total)}</strong></div>
            <div className="dashboard-card"><span>Activos</span><strong>{number(summary.active)}</strong></div>
            <div className="dashboard-card"><span>Stock bajo</span><strong className={summary.low > 0 ? 'is-low' : ''}>{number(summary.low)}</strong></div>
            <div className="dashboard-card"><span>Agotados</span><strong className={summary.out > 0 ? 'is-out' : ''}>{number(summary.out)}</strong></div>
        </div>}
        <div className="admin-products-filters admin-products-filters--caps dashboard-card">
            <label className="admin-products-search"><span>Buscar producto</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={draft} maxLength={100} onChange={event => setDraft(event.target.value)} placeholder="Nombre del producto o SKU" /></span></label>
            <label><span>Categoría</span><select value={category} onChange={event => { setCategory(event.target.value); setPage(1); }}><option value="">Todas las categorías</option>{result?.categories.map(item => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (inactiva)'}</option>)}</select></label>
            <label><span>Estado</span><select value={status} onChange={event => { setStatus(event.target.value); setPage(1); }}>{STATUS_OPTIONS.map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>
        </div>
        {dialog && <AdminConfirmDialog opener={dialog.opener} busy={busyId !== null} error={dialogError} tone={dialog.item.product_published ? 'danger' : 'ok'}
            title={dialog.item.product_published ? '¿Desactivar producto?' : '¿Activar producto?'} confirmLabel={dialog.item.product_published ? 'Desactivar producto' : 'Activar producto'}
            onCancel={() => { setDialog(null); setDialogError(''); }} onConfirm={() => changeStatus(dialog.item, !dialog.item.product_published, true)}>
            {dialog.item.product_published ? <>
                <p>El producto «{dialog.item.product_name}» dejará de mostrarse en la tienda pública.</p>
                {dialog.item.variant_count > 1 && <p>Sus {dialog.item.variant_count} presentaciones dejarán de estar disponibles públicamente mientras el producto principal esté inactivo.</p>}
                <p>Esta acción no elimina el producto ni sus variantes, y no modifica imágenes, SKU, precios ni registros históricos.</p>
            </> : <>
                <p>El producto «{dialog.item.product_name}» pasará a estar activo.</p>
                <ul>{activationWarnings(dialog.item).map(text => <li key={text}>{text}</li>)}</ul>
                <p>Activar el producto no activa sus presentaciones ni su categoría.</p>
            </>}
        </AdminConfirmDialog>}
        {stockProduct && stockVariant && <AdminStockDialog key={stockVariant.id} product={stockProduct} variant={stockVariant} opener={stockDialog.opener} onClose={closeStock} onSaved={stockSaved} />}
        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Listado de productos</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'resultado' : 'resultados'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0 ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de productos, desplazable horizontalmente"><table className="admin-products-table">
                <thead><tr><th scope="col" className="is-chevron"><span className="admin-visually-hidden">Presentaciones</span></th><th scope="col">Producto</th><th scope="col">Categoría</th><th scope="col">Variantes</th><th scope="col">Precio</th><th scope="col">Inventario</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                {result.data.map(item => {
                    const open = expanded.has(item.product_id);
                    const simple = item.variant_count === 1;
                    return <tbody key={item.product_id} className={open ? 'is-open' : ''}>
                        <tr className="admin-products-row">
                            <td className="is-chevron"><button type="button" className="admin-products-chevron" aria-expanded={open} aria-controls={`variants-${item.product_id}`} aria-label={`${open ? 'Ocultar' : 'Ver'} presentaciones de ${item.product_name}`} onClick={() => toggle(item.product_id)}><ChevronDown aria-hidden="true" /><span>{open ? 'Ocultar presentaciones' : 'Ver presentaciones'}</span></button></td>
                            <td data-label="Producto"><div className="admin-products-name"><ProductImage src={item.image_url} /><strong>{item.product_name}</strong></div></td>
                            <td data-label="Categoría">{item.category_name}{!item.category_active && <small>Inactiva</small>}</td>
                            <td data-label="Variantes">{item.variant_count === 0 ? 'Sin presentaciones' : `${item.variant_count} ${item.variant_count === 1 ? 'presentación' : 'presentaciones'}`}</td>
                            <td data-label="Precio" className="admin-products-price">{priceRange(item)}</td>
                            <td data-label="Inventario"><strong className={`admin-stock-number is-${item.stock_state}`}>{number(item.stock_total)}</strong>{item.stock_state === 'out' ? <button type="button" className="admin-stock-flag is-out as-button" onClick={() => editStock(item, item.variants.find(variant => variant.is_published && variant.stock_state === 'out') || item.variants[0])} aria-label={`${item.product_name} agotado: abrir el editor en la sección de stock`}>Agotado</button> : STOCK_FLAGS[item.stock_state] && <small className={`admin-stock-flag is-${item.stock_state}`}>{STOCK_FLAGS[item.stock_state]}</small>}</td>
                            <td data-label="Estado"><StatusBadge value={item.product_published ? 'active' : 'inactive'} /><small className={`admin-products-visibility${item.visible_in_store ? ' is-visible' : ''}`}>{visibilityText(item)}</small></td>
                            <td data-label="Acciones"><div className="admin-products-row-actions"><button className="admin-action admin-action--neutral" type="button" onClick={() => setEditor({ mode: simple ? 'simple' : 'manage', productId: item.product_id })} aria-label={`Editar producto ${item.product_name}`}><Pencil aria-hidden="true" />Editar</button><button className={`admin-action ${item.product_published ? 'admin-action--deactivate' : 'admin-action--activate'}`} type="button" disabled={busyId !== null} onClick={event => askStatus(item, event.currentTarget)} aria-label={`${item.product_published ? 'Desactivar' : 'Activar'} producto ${item.product_name}`}>{item.product_published ? <CircleOff aria-hidden="true" /> : <CircleCheck aria-hidden="true" />}{busyId === item.product_id ? 'Guardando…' : item.product_published ? 'Desactivar' : 'Activar'}</button></div></td>
                        </tr>
                        {open && <tr className="admin-products-subrow"><td colSpan={8}><div className="admin-products-variants" id={`variants-${item.product_id}`}>
                            {item.variants.length === 0 ? <p className="admin-products-variants-empty">Este producto aún no tiene presentaciones.</p> : <div className="admin-products-variants-scroll" tabIndex="0" role="region" aria-label={`Presentaciones de ${item.product_name}, desplazable horizontalmente`}><table className="admin-variants-table">
                                <thead><tr><th scope="col">SKU</th><th scope="col">Presentación</th><th scope="col">Precio</th><th scope="col">Stock</th><th scope="col">Estado</th><th scope="col"><span className="admin-visually-hidden">Acciones</span></th></tr></thead>
                                <tbody>{item.variants.map(variant => <tr key={variant.id} className={variant.stock_state === 'out' ? 'is-out' : undefined}>
                                    <td><code>{variant.sku}</code></td>
                                    <td><strong>{variant.name}</strong>{presentation(variant) && <small>{presentation(variant)}</small>}</td>
                                    <td className="admin-products-price">{money(variant.price_cop)}</td>
                                    <td><strong className={`admin-stock-number is-${variant.stock_state}`}>{number(variant.stock_quantity)}</strong>{STOCK_FLAGS[variant.stock_state] && <small className={`admin-stock-flag is-${variant.stock_state}`}>{STOCK_FLAGS[variant.stock_state]}</small>}</td>
                                    <td><StatusBadge value={variant.is_published ? 'active' : 'inactive'} /></td>
                                    <td><div className="admin-products-row-actions">{variant.stock_state === 'out' && <button type="button" className="admin-action admin-action--activate" onClick={() => editStock(item, variant)} aria-label={`Reponer stock de ${variant.name}: abrir el editor`}><PackagePlus aria-hidden="true" />Reponer stock</button>}<button type="button" className="admin-action admin-action--neutral" onClick={event => setStockDialog({ productId: item.product_id, variantId: variant.id, opener: event.currentTarget })} aria-label={`Ajustar stock de ${variant.name}`}><Boxes aria-hidden="true" />Ajustar stock</button></div></td>
                                </tr>)}</tbody></table></div>}
                        </div></td></tr>}
                    </tbody>;
                })}
            </table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando productos…' : state === 'error' ? <>No se pudieron cargar los productos. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></> : result.catalog_empty ? 'El catálogo aún no tiene productos.' : meta.total ? 'No hay resultados en esta página.' : 'No se encontraron productos con estos filtros.'}</div>}
            {state === 'success' && meta.total > 0 && <nav className="admin-products-pagination" aria-label="Paginación de productos"><span>{meta.from ? `Mostrando ${meta.from}-${meta.to} de ${number(meta.total)} ${meta.total === 1 ? 'producto' : 'productos'}` : `Página ${meta.current_page} sin resultados`}</span>
                <div className="admin-products-pages"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                    {pageList(meta.current_page, meta.last_page).map(value => typeof value === 'string' ? <span key={value} className="admin-products-gap" aria-hidden="true">…</span> : <button key={value} type="button" className={value === meta.current_page ? 'is-current' : ''} aria-current={value === meta.current_page ? 'page' : undefined} aria-label={`Página ${value}`} onClick={() => setPage(value)}>{value}</button>)}
                    <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></nav>}
        </div>
    </section>;
}
