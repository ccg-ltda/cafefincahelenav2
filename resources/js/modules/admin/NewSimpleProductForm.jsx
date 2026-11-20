import { useState } from 'react';
import AdminImagePicker from './AdminImagePicker';
import CategoryField from './CategoryField';
import { Field, request } from './adminShared';
import { useAdminToast, reportSaveError } from './AdminToast';

// Creates a simple product and its single presentation in one request (one transaction on the server).
export default function NewSimpleProductForm({ categories, onCategoryCreated, onSaved, onDirty, onBusy, isSaving }) {
    const [product, setProduct] = useState({ name: '', category_id: '', description: '', is_published: false });
    const [variant, setVariant] = useState({ name: '', price_cop: '', weight_grams: '', grind_type: '', stock_quantity: '', is_published: false });
    const [file, setFile] = useState(null);
    const [imageError, setImageError] = useState('');
    const [errors, setErrors] = useState({});
    const toast = useAdminToast();
    const [message, setMessage] = useState('');
    const [saving, setSaving] = useState(false);
    const change = setter => event => { const { name, type, checked, value } = event.target; setter(current => ({ ...current, [name]: type === 'checkbox' ? checked : value })); onDirty('new-simple', true); setMessage(''); };
    const fieldErrors = prefix => Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith(prefix)).map(([key, value]) => [key.slice(prefix.length), value]));
    const productErrors = Object.fromEntries(Object.entries(errors).filter(([key]) => !key.startsWith('variant.')));
    const variantErrors = fieldErrors('variant.');

    async function submit(event) {
        event.preventDefault(); if (saving || imageError) return;
        setSaving(true); onBusy('new-simple', true); setErrors({}); setMessage('');
        const body = new FormData();
        body.append('simple', '1');
        Object.entries(product).forEach(([name, value]) => body.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : value));
        Object.entries(variant).forEach(([name, value]) => body.append(`variant[${name}]`, typeof value === 'boolean' ? (value ? '1' : '0') : value));
        if (file) body.append('variant[image]', file);
        try {
            const response = await request('/admin/products', { method: 'POST', body });
            onDirty('new-simple', false);
            toast.success('Producto creado', `El producto ${product.name} se creó correctamente.`);
            onSaved(response.product);
        } catch (error) { setErrors(error.fields || {}); setMessage(reportSaveError(toast, error)); }
        finally { setSaving(false); onBusy('new-simple', false); }
    }

    return <form className="dashboard-card admin-editor-card" onSubmit={submit} noValidate><fieldset disabled={isSaving}>
        <h2>Producto simple</h2><p className="admin-editor-hint">Se guardan el producto y su presentación juntos, en una sola operación.</p><div className="admin-editor-grid">
        <Field prefix="new-simple-product" label="Nombre del producto" name="name" value={product.name} onChange={change(setProduct)} errors={productErrors} maxLength={255} required />
        <CategoryField id="new-simple-category" value={product.category_id} categories={categories} onChange={change(setProduct)} error={productErrors.category_id} onCategoryCreated={onCategoryCreated} />
        <label className="admin-editor-field admin-editor-full" htmlFor="new-simple-description"><span>Descripción del producto</span><textarea id="new-simple-description" name="description" value={product.description} onChange={change(setProduct)} maxLength={10000} rows={4} aria-invalid={!!productErrors.description} aria-describedby={productErrors.description ? 'new-simple-description-error' : undefined} />{productErrors.description && <small id="new-simple-description-error" role="alert">{productErrors.description[0]}</small>}</label>
        <p className="admin-editor-hint admin-editor-sku">El SKU se asignará automáticamente al guardar.</p>
        <Field prefix="new-simple-variant" label="Presentación" name="name" value={variant.name} onChange={change(setVariant)} errors={variantErrors} maxLength={255} required />
        <Field prefix="new-simple-variant" label="Precio en COP" name="price_cop" type="number" value={variant.price_cop} onChange={change(setVariant)} errors={variantErrors} min="0" max="9007199254740991" step="1" required />
        <Field prefix="new-simple-variant" label="Peso en gramos (opcional)" name="weight_grams" type="number" value={variant.weight_grams} onChange={change(setVariant)} errors={variantErrors} min="0" max="4294967295" step="1" />
        <Field prefix="new-simple-variant" label="Molienda (opcional)" name="grind_type" value={variant.grind_type} onChange={change(setVariant)} errors={variantErrors} maxLength={100} />
        <Field prefix="new-simple-variant" label="Stock inicial (opcional)" name="stock_quantity" type="number" value={variant.stock_quantity} onChange={change(setVariant)} errors={variantErrors} min="0" max="1000000" step="1" />
        <AdminImagePicker id="new-simple-image" imageUrl={null} file={file} clientError={imageError} onClientError={setImageError} serverError={variantErrors.image?.[0]} onFileChange={next => { setFile(next); onDirty('new-simple', true); setMessage(''); }} />
        </div><label className="admin-editor-check"><input type="checkbox" name="is_published" checked={product.is_published} onChange={change(setProduct)} /> Producto activo</label><label className="admin-editor-check"><input type="checkbox" name="is_published" checked={variant.is_published} onChange={change(setVariant)} /> Presentación publicada</label>
        {productErrors.is_published && <small className="admin-editor-error" role="alert">{productErrors.is_published[0]}</small>}{variantErrors.is_published && <small className="admin-editor-error" role="alert">{variantErrors.is_published[0]}</small>}
        <p className="admin-editor-hint">La tienda requiere que el producto, la presentación y la categoría estén activos.</p>{message && <p className="admin-editor-notice" role="status">{message}</p>}<button className="admin-editor-primary" type="submit" disabled={saving}>{saving ? 'Guardando…' : 'Crear producto simple'}</button>
    </fieldset></form>;
}
