import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, CircleCheck, CircleOff, Package, Pencil, Plus } from 'lucide-react';
import { saveSimpleProduct } from './adminSimpleSave';
import AdminImagePicker from './AdminImagePicker';
import CategoryField from './CategoryField';
import AdminConfirmDialog from './AdminConfirmDialog';
import './AdminActions.css';
import NewSimpleProductForm from './NewSimpleProductForm';
import { Field, request } from './adminShared';
import StockFields, { validateStock } from './StockFields';
import { useAdminToast, reportSaveError } from './AdminToast';
import './AdminProductEditor.css';

function ProductForm({ product, categories, onCategoryCreated, onSaved, onDirty, onBusy, isSaving }) {
    const toast = useAdminToast();
    const [data, setData] = useState({ name: product?.name || '', category_id: product?.category_id || '', short_description: product?.short_description || '', description: product?.description || '', is_published: product?.is_published || false });
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [saving, setSaving] = useState(false);
    const change = event => { const { name, type, checked, value } = event.target; setData(current => ({ ...current, [name]: type === 'checkbox' ? checked : value })); onDirty('product', true); setMessage(''); };
    async function submit(event) {
        event.preventDefault(); if (saving) return; setSaving(true); onBusy('product', true); setErrors({}); setMessage('');
        try {
            const response = await request(product ? `/admin/products/${product.id}` : '/admin/products', { method: product ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
            onDirty('product', false); toast.success(product ? 'Producto actualizado' : 'Producto creado', `El producto ${response.product.name} se ${product ? 'actualizó' : 'creó'} correctamente.`); onSaved(response.product);
        } catch (error) { setErrors(error.fields || {}); setMessage(reportSaveError(toast, error)); }
        finally { setSaving(false); onBusy('product', false); }
    }
    return <form className="dashboard-card admin-editor-card" onSubmit={submit} noValidate><fieldset disabled={isSaving}><h2>Información general</h2><p className="admin-editor-hint">La imagen se asigna a cada variante. La dirección pública del producto se conserva al editar su nombre.</p><div className="admin-editor-grid">
        <Field prefix="product" label="Nombre del producto" name="name" value={data.name} onChange={change} errors={errors} maxLength={255} required />
        <CategoryField id="product-category" value={data.category_id} categories={categories} onChange={change} error={errors.category_id} onCategoryCreated={onCategoryCreated} />
        <label className="admin-editor-field admin-editor-full" htmlFor="product-description"><span>Descripción del producto</span><textarea id="product-description" name="description" value={data.description} onChange={change} maxLength={10000} rows={4} aria-invalid={!!errors.description} aria-describedby={errors.description ? 'product-description-error' : undefined} />{errors.description && <small id="product-description-error" role="alert">{errors.description[0]}</small>}</label>
        </div><label className="admin-editor-check"><input type="checkbox" name="is_published" checked={data.is_published} onChange={change} /> Producto activo</label>{errors.is_published && <small className="admin-editor-error" role="alert">{errors.is_published[0]}</small>}
        <p className="admin-editor-hint">Para aparecer en la tienda, el producto, la variante y la categoría deben estar activos.</p>{message && <p className="admin-editor-notice" role="status">{message}</p>}<button className="admin-editor-primary" type="submit" disabled={saving}>{saving ? 'Guardando…' : product ? 'Guardar producto' : 'Crear producto'}</button>
    </fieldset></form>;
}

function VariantForm({ productId, variant, onSaved, onDirty, onBusy, isSaving, focusStock }) {
    const toast = useAdminToast();
    const key = variant ? `variant-${variant.id}` : 'variant-new';
    const [data, setData] = useState({ name: variant?.name || '', price_cop: variant?.price_cop ?? '', weight_grams: variant?.weight_grams ?? '', grind_type: variant?.grind_type || '', is_published: variant?.is_published || false, stock_quantity: variant ? String(variant.stock_quantity ?? 0) : '' });
    // Stock registered when the form was opened (sent back so the server can detect that it changed meanwhile).
    const [expected, setExpected] = useState(variant?.stock_quantity ?? 0);
    const [file, setFile] = useState(null);
    const [imageError, setImageError] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [fileKey, setFileKey] = useState(0);
    const change = event => { const { name, type, checked, value } = event.target; setData(current => ({ ...current, [name]: type === 'checkbox' ? checked : value })); onDirty(key, true); setMessage(''); };
    async function submit(event) {
        event.preventDefault(); if (saving || imageError) return;
        const invalid = validateStock(data, variant ? 'edit' : 'create');
        if (Object.keys(invalid).length) { setErrors(invalid); setMessage('Revisa los campos señalados.'); return; }
        setSaving(true); onBusy(key, true); setErrors({}); setMessage('');
        const body = new FormData(); Object.entries(data).forEach(([name, value]) => body.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : value));
        if (variant) body.append('expected_stock', String(expected));
        if (file) body.append('image', file);
        if (variant) body.append('_method', 'PUT');
        try {
            const response = await request(`/admin/products/${productId}/variants${variant ? `/${variant.id}` : ''}`, { method: 'POST', body });
            onDirty(key, false); onDirty(`${key}-image`, false); setFile(null);
            const saved = variant && response.product.variants.find(item => item.id === variant.id);
            if (saved) { setExpected(saved.stock_quantity); setData(current => ({ ...current, stock_quantity: String(saved.stock_quantity) })); } setFileKey(value => value + 1); toast.success(variant ? 'Variante actualizada' : 'Variante creada', `La presentación ${data.name} se ${variant ? 'actualizó' : 'creó'} correctamente.`); onSaved(response.product, !variant);
        } catch (error) {
            // Stock changed while editing: nothing was saved. Show the new stock and let the admin review before saving again.
            if (error.status === 409 && error.currentStock != null) { setExpected(error.currentStock); setErrors({ stock_quantity: [error.message] }); setMessage(error.message); }
            else { setErrors(error.fields || {}); setMessage(reportSaveError(toast, error)); }
        }
        finally { setSaving(false); onBusy(key, false); }
    }
    return <form className="dashboard-card admin-editor-card" onSubmit={submit} noValidate><fieldset disabled={isSaving}><h3>{variant ? `Variante: ${variant.name}` : 'Nueva variante'}</h3><div className="admin-editor-grid">
        <Field prefix={key} label="Presentación" name="name" value={data.name} onChange={change} errors={errors} maxLength={255} required />
        <p className="admin-editor-hint admin-editor-sku">{variant ? <>SKU: <strong>{variant.sku}</strong>. Se asignó automáticamente y no se puede editar.</> : 'El SKU se asignará automáticamente al guardar.'}</p>
        <Field prefix={key} label="Precio en COP" name="price_cop" type="number" value={data.price_cop} onChange={change} errors={errors} min="0" max="9007199254740991" step="1" required />
        <Field prefix={key} label="Peso en gramos (opcional)" name="weight_grams" type="number" value={data.weight_grams} onChange={change} errors={errors} min="0" max="4294967295" step="1" />
        <Field prefix={key} label="Molienda (opcional)" name="grind_type" value={data.grind_type} onChange={change} errors={errors} maxLength={100} />
        <AdminImagePicker id={`${key}-image`} imageUrl={variant?.image_url} file={file} resetKey={fileKey} clientError={imageError} onClientError={setImageError} serverError={errors.image?.[0]} onFileChange={next => { setFile(next); onDirty(`${key}-image`, !!next); setMessage(''); setErrors(current => { const { image, ...remaining } = current; return remaining; }); }} />
        <StockFields prefix={key} values={data} onChange={change} errors={errors} mode={variant ? 'edit' : 'create'} current={expected} threshold={variant?.low_stock_threshold ?? 5} focusStock={focusStock} />
        </div><label className="admin-editor-check"><input type="checkbox" name="is_published" checked={data.is_published} onChange={change} /> Variante publicada</label>{errors.is_published && <small className="admin-editor-error" role="alert">{errors.is_published[0]}</small>}{message && <p className="admin-editor-notice" role="status">{message}</p>}<button className="admin-editor-primary" type="submit" disabled={saving}>{saving ? 'Guardando…' : variant ? 'Guardar cambios' : 'Crear variante'}</button>
    </fieldset></form>;
}

const simpleProductFields = product => ({ name: product.name || '', category_id: String(product.category_id ?? ''), short_description: product.short_description || '', description: product.description || '', is_published: !!product.is_published });
const simpleVariantFields = variant => ({ name: variant.name || '', price_cop: String(variant.price_cop ?? ''), weight_grams: variant.weight_grams == null ? '' : String(variant.weight_grams), grind_type: variant.grind_type || '', is_published: !!variant.is_published, stock_quantity: String(variant.stock_quantity ?? 0) });

function SimpleProductForm({ product, categories, onCategoryCreated, onSaved, onDirty, onBusy, isSaving, focusStock }) {
    const toast = useAdminToast();
    const variant = product.variants[0];
    const [productData, setProductData] = useState(() => simpleProductFields(product));
    const [variantData, setVariantData] = useState(() => simpleVariantFields(variant));
    const [savedProduct, setSavedProduct] = useState(() => simpleProductFields(product));
    const [savedVariant, setSavedVariant] = useState(() => simpleVariantFields(variant));
    const [file, setFile] = useState(null);
    const [imageError, setImageError] = useState('');
    const [fileKey, setFileKey] = useState(0);
    const [productErrors, setProductErrors] = useState({});
    const [variantErrors, setVariantErrors] = useState({});
    const [message, setMessage] = useState('');
    const [saving, setSaving] = useState(false);
    // Stock registered when the form was opened (sent back so the server can detect that it changed meanwhile).
    const [expected, setExpected] = useState(variant.stock_quantity ?? 0);
    const change = setter => event => { const { name, type, checked, value } = event.target; setter(current => ({ ...current, [name]: type === 'checkbox' ? checked : value })); onDirty('simple', true); setMessage(''); };
    async function submit(event) {
        event.preventDefault(); if (saving || imageError) return;
        const productChanged = JSON.stringify(productData) !== JSON.stringify(savedProduct);
        const variantChanged = JSON.stringify(variantData) !== JSON.stringify(savedVariant) || !!file;
        if (!productChanged && !variantChanged) { onDirty('simple', false); toast.info('Sin cambios', 'No hay cambios pendientes.'); return; }
        const invalid = validateStock(variantData, 'edit');
        if (Object.keys(invalid).length) { setVariantErrors(invalid); setMessage('Revisa los campos señalados.'); return; }
        setSaving(true); onBusy('simple', true); setProductErrors({}); setVariantErrors({}); setMessage('');
        try {
            const result = await saveSimpleProduct({
                productChanged, variantChanged,
                saveProduct: () => request(`/admin/products/${product.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(productData) }),
                saveVariant: () => {
                    const body = new FormData();
                    Object.entries(variantData).forEach(([name, value]) => body.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : value));
                    body.append('expected_stock', String(expected));
                    if (file) body.append('image', file);
                    body.append('_method', 'PUT');
                    return request(`/admin/products/${product.id}/variants/${variant.id}`, { method: 'POST', body });
                },
            });
            if (result.productResult) {
                const confirmed = simpleProductFields(result.productResult.product);
                setProductData(confirmed); setSavedProduct(confirmed); onSaved(result.productResult.product);
            }
            if (result.variantResult) {
                const confirmedVariant = result.variantResult.product.variants.find(item => String(item.id) === String(variant.id));
                if (!confirmedVariant) throw new Error('La respuesta no incluye la presentación guardada. Revisa los datos antes de reintentar.');
                const confirmed = simpleVariantFields(confirmedVariant);
                setVariantData(confirmed); setSavedVariant(confirmed); setExpected(confirmedVariant.stock_quantity); setFile(null); onDirty('simple-image', false); setFileKey(value => value + 1); onSaved(result.variantResult.product);
            }
            if (result.failed) {
                const rejected = result.error.confirmedRejected;
                if (result.failed === 'product') {
                    setProductErrors(result.error.fields || {});
                    toast.error('No se guardaron los cambios', `${rejected ? 'No se guardó la información general.' : 'No se pudo confirmar el guardado de la información general; comprueba los datos antes de reintentar.'}${variantChanged ? ' La presentación no se intentó guardar.' : ''} ${rejected ? result.error.message : ''}`);
                } else {
                    // Stock changed while editing: nothing of the variant was saved. Show the new stock before saving again.
                    if (result.error.status === 409 && result.error.currentStock != null) { setExpected(result.error.currentStock); setVariantErrors({ stock_quantity: [result.error.message] }); }
                    else setVariantErrors(result.error.fields || {});
                    toast.error('No se guardaron todos los cambios', `${result.productResult ? 'La información general se guardó. ' : ''}${rejected ? 'No se guardó la presentación. ' + result.error.message : 'No se pudo confirmar el guardado de la presentación; comprueba los datos antes de reintentar.'}`);
                }
                onDirty('simple', true);
            } else { onDirty('simple', false); toast.success('Producto actualizado', `El producto ${productData.name} se actualizó correctamente.`); }
        } catch (error) { onDirty('simple', true); toast.error('No se pudo confirmar el resultado', error.message || 'Comprueba los datos antes de reintentar.'); }
        finally { setSaving(false); onBusy('simple', false); }
    }
    return <form className="dashboard-card admin-editor-card" onSubmit={submit} noValidate><fieldset disabled={isSaving}>
        <h2>Editar producto</h2><p className="admin-editor-hint">La información general y la presentación se guardan en sus registros correspondientes.</p><div className="admin-editor-grid">
        <Field prefix="simple-product" label="Nombre del producto" name="name" value={productData.name} onChange={change(setProductData)} errors={productErrors} maxLength={255} required />
        <CategoryField id="simple-category" value={productData.category_id} categories={categories} onChange={change(setProductData)} error={productErrors.category_id} onCategoryCreated={onCategoryCreated} />
        <label className="admin-editor-field admin-editor-full" htmlFor="simple-description"><span>Descripción del producto</span><textarea id="simple-description" name="description" value={productData.description} onChange={change(setProductData)} maxLength={10000} rows={4} aria-invalid={!!productErrors.description} aria-describedby={productErrors.description ? 'simple-description-error' : undefined} />{productErrors.description && <small id="simple-description-error" role="alert">{productErrors.description[0]}</small>}</label>
        <p className="admin-editor-hint admin-editor-sku">SKU: <strong>{variant.sku}</strong>. Se asignó automáticamente y no se puede editar.</p>
        <Field prefix="simple-variant" label="Presentación" name="name" value={variantData.name} onChange={change(setVariantData)} errors={variantErrors} maxLength={255} required />
        <Field prefix="simple-variant" label="Precio en COP" name="price_cop" type="number" value={variantData.price_cop} onChange={change(setVariantData)} errors={variantErrors} min="0" max="9007199254740991" step="1" required />
        <Field prefix="simple-variant" label="Peso en gramos (opcional)" name="weight_grams" type="number" value={variantData.weight_grams} onChange={change(setVariantData)} errors={variantErrors} min="0" max="4294967295" step="1" />
        <Field prefix="simple-variant" label="Molienda (opcional)" name="grind_type" value={variantData.grind_type} onChange={change(setVariantData)} errors={variantErrors} maxLength={100} />
        <AdminImagePicker id="simple-image" imageUrl={variant.image_url} file={file} resetKey={fileKey} clientError={imageError} onClientError={setImageError} serverError={variantErrors.image?.[0]} onFileChange={next => { setFile(next); onDirty('simple-image', !!next); setMessage(''); setVariantErrors(current => { const { image, ...remaining } = current; return remaining; }); }} />
        <StockFields prefix="simple-variant" values={variantData} onChange={change(setVariantData)} errors={variantErrors} mode="edit" current={expected} threshold={variant.low_stock_threshold ?? 5} focusStock={focusStock} />
        </div><label className="admin-editor-check"><input type="checkbox" name="is_published" checked={productData.is_published} onChange={change(setProductData)} /> Producto activo</label><label className="admin-editor-check"><input type="checkbox" name="is_published" checked={variantData.is_published} onChange={change(setVariantData)} /> Presentación publicada</label>
        {productErrors.is_published && <small className="admin-editor-error" role="alert">{productErrors.is_published[0]}</small>}{variantErrors.is_published && <small className="admin-editor-error" role="alert">{variantErrors.is_published[0]}</small>}
        <p className="admin-editor-hint">La tienda requiere que el producto, la presentación y la categoría estén activos.</p>{message && <p className="admin-editor-notice" role="status">{message}</p>}<button className="admin-editor-primary" type="submit" disabled={saving}>{saving ? 'Guardando…' : 'Guardar cambios'}</button>
    </fieldset></form>;
}

// New product: a simple product is created in one step; a product with variants is created first and its combinations added afterwards.
function NewProduct(props) {
    const [kind, setKind] = useState('simple');
    return <>
        <div className="dashboard-card admin-editor-card admin-editor-kind" role="radiogroup" aria-label="Tipo de producto">
            {[['simple', 'Producto simple', 'Una sola presentación con su precio, SKU e imagen.'], ['configurable', 'Producto con variantes', 'Varias combinaciones (peso, molienda…), cada una con su precio, SKU e imagen.']].map(([value, label, hint]) => <button key={value} type="button" role="radio" aria-checked={kind === value} className={kind === value ? 'is-selected' : ''} disabled={props.isSaving} onClick={() => setKind(value)}><strong>{label}</strong><span>{hint}</span></button>)}
        </div>
        <div hidden={kind !== 'simple'}><NewSimpleProductForm {...props} /></div>
        <div hidden={kind !== 'configurable'}><ProductForm key="new" product={null} {...props} /></div>
    </>;
}

function VariantImage({ src }) {
    const [failed, setFailed] = useState(false);
    useEffect(() => setFailed(false), [src]);
    return <span className="admin-manage-image">{src && !failed ? <img src={src} alt="" loading="lazy" onError={() => setFailed(true)} /> : <Package aria-hidden="true" />}</span>;
}

function ProductManagement({ product, categories, onNavigate, onProductChange }) {
    const category = categories.find(item => String(item.id) === String(product.category_id));
    const money = value => `$ ${new Intl.NumberFormat('es-CO').format(value)}`;
    const toast = useAdminToast();
    const [dialog, setDialog] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [dialogError, setDialogError] = useState('');
    const busy = useRef(false);
    // A variant is purchasable only with an active category, an active product and an active variant.
    const hiddenBy = !product.is_published ? 'el producto principal está inactivo' : category && !category.is_active ? 'su categoría está inactiva' : '';

    function ask(variant, opener) {
        setDialogError('');
        if (variant.is_published || hiddenBy) setDialog({ variant, opener }); else changeStatus(variant, true, false);
    }

    async function changeStatus(variant, active, viaDialog) {
        if (busy.current) return;
        busy.current = true; setBusyId(variant.id); setDialogError('');
        try {
            const response = await request(`/admin/products/${product.id}/variants/${variant.id}/status`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ is_published: active }) });
            onProductChange(response.product);
            setDialog(null);
            toast.success(active ? 'Variante activada' : 'Variante desactivada', `${variant.name} (SKU ${variant.sku}) ${active ? 'está disponible nuevamente' : 'dejó de estar disponible en la tienda'}.${active && hiddenBy ? ` Seguirá sin mostrarse en la tienda porque ${hiddenBy}.` : ''}`);
        } catch (failure) {
            if (viaDialog) setDialogError(failure.message); else toast.error('No se pudo cambiar el estado', failure.message);
        } finally { busy.current = false; setBusyId(null); }
    }

    return <div className="admin-manage">
        <section className="dashboard-card admin-manage-card"><div className="admin-manage-heading"><div><h2>Información general</h2><p>Datos del producto principal.</p></div><button type="button" className="admin-products-edit" onClick={() => onNavigate({ mode: 'product', productId: product.id })}>Editar información general</button></div>
            <dl className="admin-manage-details"><div><dt>Nombre</dt><dd>{product.name}</dd></div><div><dt>Categoría</dt><dd>{category ? `${category.name}${category.is_active ? '' : ' (inactiva)'}` : 'No disponible'}</dd></div><div><dt>Estado</dt><dd><span className={`admin-products-badge${product.is_published ? ' is-published' : ''}`}>{product.is_published ? 'Activo' : 'Inactivo'}</span></dd></div><div className="admin-manage-wide"><dt>Descripción del producto</dt><dd className="admin-manage-description">{product.description || 'Sin descripción'}</dd></div></dl>
        </section>
        <section className="dashboard-card admin-manage-card"><div className="admin-manage-heading"><div><h2>Variantes y presentaciones</h2><p>Cada variante conserva su propia imagen, SKU y estado.</p></div><button type="button" className="admin-products-new admin-manage-add" onClick={() => onNavigate({ mode: 'add', productId: product.id })}><Plus aria-hidden="true" /> Agregar variante</button></div>
            {product.variants.length ? <ul className="admin-manage-variants">{product.variants.map(variant => <li key={variant.id} className="admin-manage-variant"><VariantImage src={variant.image_url} /><div className="admin-manage-variant-info"><h3>{variant.name}</h3><p>SKU: {variant.sku}</p><dl><div><dt>Peso</dt><dd>{variant.weight_grams === null ? '—' : `${new Intl.NumberFormat('es-CO').format(variant.weight_grams)} g`}</dd></div><div><dt>Molienda</dt><dd>{variant.grind_type || '—'}</dd></div><div><dt>Precio</dt><dd>{money(variant.price_cop)}</dd></div><div><dt>Estado</dt><dd><span className={`admin-products-badge${variant.is_published ? ' is-published' : ''}`}>{variant.is_published ? 'Activa' : 'Inactiva'}</span></dd></div></dl></div>
                <div className="admin-manage-variant-actions">
                    <button type="button" className="admin-action admin-action--neutral" onClick={() => onNavigate({ mode: 'variant', productId: product.id, variantId: variant.id })} aria-label={`Editar variante ${variant.name}, SKU ${variant.sku}`}><Pencil aria-hidden="true" />Editar variante</button>
                    <button type="button" className={`admin-action ${variant.is_published ? 'admin-action--deactivate' : 'admin-action--activate'}`} disabled={busyId !== null} onClick={event => ask(variant, event.currentTarget)} aria-label={`${variant.is_published ? 'Desactivar' : 'Activar'} presentación ${variant.name}, SKU ${variant.sku}`}>{variant.is_published ? <CircleOff aria-hidden="true" /> : <CircleCheck aria-hidden="true" />}{busyId === variant.id ? 'Guardando…' : variant.is_published ? 'Desactivar' : 'Activar'}</button>
                </div></li>)}</ul> : <p className="admin-manage-empty">Este producto aún no tiene variantes.</p>}
        </section>
        {dialog && <AdminConfirmDialog opener={dialog.opener} busy={busyId !== null} error={dialogError} tone={dialog.variant.is_published ? 'danger' : 'ok'}
            title={dialog.variant.is_published ? '¿Desactivar presentación?' : '¿Activar presentación?'} confirmLabel={dialog.variant.is_published ? 'Desactivar presentación' : 'Activar presentación'}
            onCancel={() => { setDialog(null); setDialogError(''); }} onConfirm={() => changeStatus(dialog.variant, !dialog.variant.is_published, true)}>
            {dialog.variant.is_published ? <>
                <p>La presentación «{dialog.variant.name}» (SKU {dialog.variant.sku}) dejará de poder comprarse en la tienda pública.</p>
                <p>Esta acción no elimina registros y no modifica imágenes, SKU, precios ni históricos. Las demás presentaciones no se ven afectadas.</p>
            </> : <>
                <p>La presentación «{dialog.variant.name}» (SKU {dialog.variant.sku}) pasará a estar activa.</p>
                <p>Seguirá sin mostrarse en la tienda porque {hiddenBy}. Activar la presentación no activa el producto ni su categoría.</p>
            </>}
        </AdminConfirmDialog>}
    </div>;
}

export default function AdminProductEditor({ mode, productId, variantId, focus, categories, onCategoryCreated, onNavigate, onClose }) {
    const [product, setProduct] = useState(null);
    const [loading, setLoading] = useState(!!productId);
    const [error, setError] = useState('');
    const [dirty, setDirty] = useState({});
    const [busy, setBusy] = useState({});
    const [newKey, setNewKey] = useState(0);
    const hasChanges = Object.values(dirty).some(Boolean);
    const isSaving = Object.values(busy).some(Boolean);
    const onDirty = (key, value) => setDirty(current => ({ ...current, [key]: value }));
    const onBusy = (key, value) => setBusy(current => ({ ...current, [key]: value }));
    useEffect(() => {
        if (!productId) return undefined;
        const controller = new AbortController();
        request(`/admin/products/${productId}/detail`, { signal: controller.signal }).then(data => setProduct(data.product)).catch(err => { if (err.name !== 'AbortError') setError(err.message); }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [productId]);
    useEffect(() => {
        if (!hasChanges && !isSaving) return undefined;
        const beforeUnload = event => { event.preventDefault(); event.returnValue = ''; };
        const onClick = event => { if (event.target.closest('.dashboard-account button') && (isSaving || !window.confirm('Hay cambios sin guardar. ¿Deseas cerrar sesión?'))) { event.preventDefault(); event.stopPropagation(); } };
        window.addEventListener('beforeunload', beforeUnload); document.addEventListener('click', onClick, true);
        return () => { window.removeEventListener('beforeunload', beforeUnload); document.removeEventListener('click', onClick, true); };
    }, [hasChanges, isSaving]);
    const close = () => { if (!isSaving && (!hasChanges || window.confirm('Hay cambios sin guardar. ¿Deseas salir del formulario?'))) { if (productId && mode !== 'manage' && mode !== 'simple') onNavigate({ mode: 'manage', productId }); else onClose(); } };
    const selectedVariant = product?.variants.find(variant => String(variant.id) === String(variantId));
    const title = mode === 'new' ? 'Nuevo producto' : mode === 'manage' ? 'Gestionar producto' : mode === 'product' || mode === 'simple' ? 'Editar producto' : mode === 'variant' ? 'Editar variante' : 'Agregar variante';
    return <section className="admin-product-editor"><button className="admin-editor-back" type="button" disabled={isSaving} onClick={close}><ArrowLeft aria-hidden="true" /> {productId && mode !== 'manage' && mode !== 'simple' ? 'Volver a gestionar producto' : 'Volver a productos'}</button><header className="dashboard-heading"><div><span className="dashboard-eyebrow">Catálogo</span><h1>{title}</h1>{product && <p>{product.name}</p>}</div></header>
        {loading ? <p className="admin-editor-status" role="status">Cargando producto…</p> : error ? <p className="admin-editor-status" role="alert">{error}</p> : mode === 'simple' ? product.variants.length === 1 ? <SimpleProductForm focusStock={focus === 'stock'} product={product} categories={categories} onCategoryCreated={onCategoryCreated} onDirty={onDirty} onBusy={onBusy} isSaving={isSaving} onSaved={saved => setProduct(current => ({ ...saved, variants: current.variants.map(existing => saved.variants.find(item => item.id === existing.id) || existing) }))} /> : <div className="admin-editor-status" role="alert">La cantidad de variantes cambió. <button className="admin-products-edit" type="button" onClick={() => onNavigate({ mode: 'manage', productId: product.id })}>Gestionar producto</button></div> : mode === 'manage' ? <ProductManagement product={product} categories={categories} onNavigate={onNavigate} onProductChange={saved => setProduct(saved)} /> : mode === 'new' ? <NewProduct categories={categories} onCategoryCreated={onCategoryCreated} onDirty={onDirty} onBusy={onBusy} isSaving={isSaving} onSaved={saved => onNavigate({ mode: saved.variants.length === 1 ? 'simple' : 'manage', productId: saved.id })} /> : mode === 'product' ? <ProductForm key={product?.id} product={product} categories={categories} onCategoryCreated={onCategoryCreated} onDirty={onDirty} onBusy={onBusy} isSaving={isSaving} onSaved={saved => setProduct(saved)} /> : mode === 'variant' ? selectedVariant ? <VariantForm key={selectedVariant.id} focusStock={focus === 'stock'} productId={product.id} variant={selectedVariant} onDirty={onDirty} onBusy={onBusy} isSaving={isSaving} onSaved={saved => setProduct(saved)} /> : <p className="admin-editor-status" role="alert">La variante no pertenece a este producto o ya no está disponible.</p> : <><VariantForm key={`new-${newKey}`} productId={product.id} onDirty={onDirty} onBusy={onBusy} isSaving={isSaving} onSaved={(saved, created) => { setProduct(saved); if (created) { setNewKey(value => value + 1); } }} /></>}
    </section>;
}
