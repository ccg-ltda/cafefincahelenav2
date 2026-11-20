import { useAdminToast } from './AdminToast';
import { useEffect, useRef, useState } from 'react';
import { BASE, CategoryDrawer, request } from './AdminCategoryDrawer';

const normalize = text => String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');

// Creates a category through the existing admin endpoint without leaving the product form.
function CreateCategoryDrawer({ categories, onCreated, onUseExisting, onClose }) {
    const [form, setForm] = useState({ id: null, slug: '', name: '', is_active: true });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [saving, setSaving] = useState(false);
    const [closing, setClosing] = useState(false);
    const [discard, setDiscard] = useState(false);
    const busy = useRef(false);
    const timer = useRef(null);
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const dirty = form.name !== '' || !form.is_active;
    const existing = errors.name ? categories.find(category => normalize(category.name) === normalize(form.name)) : null;

    function finish(callback) {
        setClosing(true); setDiscard(false);
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        timer.current = window.setTimeout(() => { callback?.(); onClose(); }, reduced ? 0 : 180);
    }

    function requestClose() {
        if (saving || closing) return;
        if (dirty) setDiscard(true); else finish();
    }

    async function submit(event) {
        // The drawer is portaled, but React still bubbles its events to the product form: stop it here.
        event.preventDefault(); event.stopPropagation();
        if (busy.current || discard || closing) return;
        if (!form.name.trim()) { setErrors({ name: ['El nombre es obligatorio.'] }); setFormError('Revisa los campos señalados.'); return; }
        busy.current = true; setSaving(true); setErrors({}); setFormError('');
        try {
            const data = await request(BASE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: form.name.trim(), is_active: form.is_active }) });
            finish(() => onCreated(data.category));
        } catch (error) { setErrors(error.fields || {}); setFormError(error.message); }
        finally { busy.current = false; setSaving(false); }
    }

    const change = name => event => setForm(current => ({ ...current, [name]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));

    return <CategoryDrawer form={form} errors={errors} formError={formError} saving={saving} closing={closing}
        prompt={discard ? { kind: 'discard' } : null} onChange={change} onSubmit={submit} onRequestClose={requestClose}
        onPromptCancel={() => setDiscard(false)} onPromptConfirm={() => finish()}
        existing={existing} onUseExisting={() => finish(() => onUseExisting(existing))} />;
}

// Category select shared by every product form, with an inline "create new" flow.
export default function CategoryField({ id, value, categories, onChange, error, onCategoryCreated }) {
    const toast = useAdminToast();
    const [open, setOpen] = useState(false);
    const opener = useRef(null);
    const select = ids => onChange({ target: { name: 'category_id', type: 'select-one', checked: false, value: String(ids) } });
    // Only active categories are offered; the product's current one stays visible even when inactive.
    const options = categories.filter(category => category.is_active || String(category.id) === String(value));

    function created(category) {
        toast.success('Categoría creada', `La categoría ${category.name} se creó correctamente.`);
        onCategoryCreated?.({ id: category.id, name: category.name, is_active: category.is_active });
        if (category.is_active) select(category.id);
    }

    function closed() {
        setOpen(false);
        window.setTimeout(() => opener.current?.focus(), 0);
    }

    return <div className="admin-editor-field admin-category-field">
        <label htmlFor={id}>Categoría</label>
        <select id={id} name="category_id" value={value} onChange={onChange} aria-invalid={!!error} aria-describedby={error ? `${id}-error` : undefined} required>
            <option value="">Selecciona una categoría</option>
            {options.map(category => <option key={category.id} value={category.id}>{category.name}{category.is_active ? '' : ' (inactiva)'}</option>)}
        </select>
        {error && <small id={`${id}-error`} role="alert">{error[0]}</small>}
        <button type="button" ref={opener} className="admin-category-create" onClick={() => setOpen(true)}>¿No encuentras la categoría? Crear nueva</button>
        <small>La categoría pertenece al producto principal y clasifica todas sus variantes. Solo cambia al guardar el producto.</small>
        {open && <CreateCategoryDrawer categories={categories} onCreated={created} onUseExisting={category => select(category.id)} onClose={closed} />}
    </div>;
}
