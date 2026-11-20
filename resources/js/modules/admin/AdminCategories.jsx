import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, CircleCheck, CircleOff, Pencil, Plus, TriangleAlert } from 'lucide-react';
import './AdminCategories.css';
import './AdminActions.css';
import { useAdminToast, reportSaveError } from './AdminToast';
import { BASE, CategoryDrawer, countLabel, request, sortByName } from './AdminCategoryDrawer';

export default function AdminCategories() {
    const [items, setItems] = useState([]);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const [form, setForm] = useState(null);
    const [baseline, setBaseline] = useState(null);
    const [closing, setClosing] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [saving, setSaving] = useState(false);
    const toast = useAdminToast();
    const [confirm, setConfirm] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const busy = useRef(false);
    const opener = useRef(null);
    const newButton = useRef(null);
    const closeTimer = useRef(null);
    const cancelButton = useRef(null);

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        request(`${BASE}/data`, { signal: controller.signal })
            .then(data => { setItems(data.data); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [retry]);

    useEffect(() => { if (confirm && !confirm.fromForm) cancelButton.current?.focus(); }, [confirm]);
    useEffect(() => () => window.clearTimeout(closeTimer.current), []);

    const dirty = !!form && !!baseline && (form.name !== baseline.name || form.is_active !== baseline.is_active);
    const upsert = category => setItems(current => sortByName(current.some(item => item.id === category.id) ? current.map(item => item.id === category.id ? category : item) : [...current, category]));

    function openForm(category = null, trigger = null) {
        if (form) return;
        const next = category ? { id: category.id, slug: category.slug, name: category.name, is_active: category.is_active } : { id: null, slug: '', name: '', is_active: true };
        opener.current = trigger || document.activeElement;
        setErrors({}); setFormError(''); setConfirm(null); setDiscard(false); setClosing(false);
        setBaseline({ name: next.name, is_active: next.is_active });
        setForm(next);
    }

    function closeForm() {
        if (!form || closing) return;
        setDiscard(false); setConfirm(null); setClosing(true);
        const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        closeTimer.current = window.setTimeout(() => {
            setForm(null); setBaseline(null); setClosing(false);
            const target = opener.current?.isConnected ? opener.current : newButton.current;
            target?.focus();
        }, reduced ? 0 : 180);
    }

    function requestClose() {
        if (saving || closing) return;
        if (dirty) { setConfirm(null); setDiscard(true); return; }
        closeForm();
    }

    async function save() {
        if (busy.current) return;
        busy.current = true; setSaving(true); setErrors({}); setFormError(''); setConfirm(null);
        try {
            const data = await request(form.id ? `${BASE}/${form.id}` : BASE, { method: form.id ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: form.name.trim(), is_active: form.is_active }) });
            upsert(data.category);
            toast.success(form.id ? 'Categoría actualizada' : 'Categoría creada', `La categoría ${data.category.name} se ${form.id ? 'actualizó' : 'creó'} correctamente.`);
            closeForm();
        } catch (error) { setErrors(error.fields || {}); setFormError(reportSaveError(toast, error)); }
        finally { busy.current = false; setSaving(false); }
    }

    function submit(event) {
        event.preventDefault();
        if (busy.current || discard || confirm || closing) return;
        if (!form.name.trim()) { setErrors({ name: ['El nombre es obligatorio.'] }); setFormError('Revisa los campos señalados.'); return; }
        const original = form.id ? items.find(item => item.id === form.id) : null;
        if (original?.is_active && !form.is_active) { setConfirm({ category: original, fromForm: true }); return; }
        save();
    }

    async function changeStatus(category, active) {
        if (busy.current) return;
        busy.current = true; setBusyId(category.id); setConfirm(null);
        try {
            const data = await request(`${BASE}/${category.id}/status`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ is_active: active }) });
            upsert(data.category);
            toast.success(active ? 'Categoría activada' : 'Categoría desactivada', active ? `La categoría ${category.name} está activa. Sus productos se muestran si el producto y alguna variante están activos.` : `La categoría ${category.name} está inactiva y sus productos dejan de mostrarse en la tienda.`);
        } catch (error) { toast.error('No se pudo cambiar el estado', error.message); }
        finally { busy.current = false; setBusyId(null); }
    }

    const confirmed = () => { const pending = confirm; setConfirm(null); if (pending.fromForm) save(); else changeStatus(pending.category, false); };
    const change = name => event => setForm(current => ({ ...current, [name]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
    const prompt = discard ? { kind: 'discard' } : confirm?.fromForm ? { kind: 'deactivate', category: confirm.category } : null;

    return <section className="admin-categories">
        <header className="dashboard-heading">
            <div><span className="dashboard-eyebrow">Catálogo</span><h1>Categorías</h1><p>Administra las categorías de los productos.</p></div>
            <div className="admin-categories-actions">
                <a className="admin-categories-back" href="/admin/products"><ArrowLeft aria-hidden="true" /> Volver a productos</a>
                <button ref={newButton} className="admin-categories-primary" type="button" disabled={state !== 'success' || saving} onClick={event => openForm(null, event.currentTarget)}><Plus aria-hidden="true" /> Nueva categoría</button>
            </div>
        </header>

        {confirm && !confirm.fromForm && <div className="dashboard-card admin-categories-confirm" role="alertdialog" aria-labelledby="category-confirm-title" aria-describedby="category-confirm-text" onKeyDown={event => { if (event.key === 'Escape') setConfirm(null); }}>
            <TriangleAlert aria-hidden="true" />
            <div><h2 id="category-confirm-title">¿Desactivar «{confirm.category.name}»?</h2>
                <p id="category-confirm-text">Los productos de esta categoría ({countLabel(confirm.category.products_count)}) dejarán de mostrarse en el catálogo público. No se modifica el estado de los productos ni de sus variantes, y volverán a mostrarse al activarla de nuevo.</p>
                <div className="admin-categories-buttons"><button type="button" className="admin-categories-secondary" ref={cancelButton} onClick={() => setConfirm(null)}>Cancelar</button><button type="button" className="admin-categories-danger" onClick={confirmed}>Desactivar categoría</button></div>
            </div>
        </div>}

        {form && <CategoryDrawer form={form} errors={errors} formError={formError} saving={saving} closing={closing} prompt={prompt} onChange={change} onSubmit={submit} onRequestClose={requestClose} onPromptCancel={() => { setDiscard(false); setConfirm(null); }} onPromptConfirm={discard ? closeForm : confirmed} />}

        <div className="dashboard-card admin-categories-list">
            <div className="admin-categories-list-head"><h2>Listado de categorías</h2><span>{state === 'success' ? `${items.length} ${items.length === 1 ? 'categoría' : 'categorías'}` : state === 'loading' ? 'Cargando…' : 'No disponible'}</span></div>
            {state === 'success' && items.length > 0
                ? <table><thead><tr><th scope="col">Nombre</th><th scope="col">Slug</th><th scope="col">Estado</th><th scope="col">Productos</th><th scope="col">Acciones</th></tr></thead>
                    <tbody>{items.map(item => <tr key={item.id}>
                        <td data-label="Nombre"><strong>{item.name}</strong></td>
                        <td data-label="Slug"><code>{item.slug}</code></td>
                        <td data-label="Estado"><span className={`admin-products-badge${item.is_active ? ' is-published' : ''}`}>{item.is_active ? 'Activa' : 'Inactiva'}</span></td>
                        <td data-label="Productos">{new Intl.NumberFormat('es-CO').format(item.products_count)}</td>
                        <td data-label="Acciones"><div className="admin-categories-row-actions">
                            <button type="button" className="admin-action admin-action--neutral" disabled={busyId !== null || saving} onClick={event => openForm(item, event.currentTarget)} aria-label={`Editar categoría ${item.name}`}><Pencil aria-hidden="true" /> Editar</button>
                            <button type="button" className={`admin-action ${item.is_active ? "admin-action--deactivate" : "admin-action--activate"}`} disabled={busyId !== null || saving} onClick={() => item.is_active ? (setConfirm({ category: item })) : changeStatus(item, true)} aria-label={`${item.is_active ? 'Desactivar' : 'Activar'} categoría ${item.name}`}>{item.is_active ? <CircleOff aria-hidden="true" /> : <CircleCheck aria-hidden="true" />} {busyId === item.id ? 'Guardando…' : item.is_active ? 'Desactivar' : 'Activar'}</button>
                        </div></td></tr>)}</tbody></table>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando categorías…' : state === 'error' ? <>No se pudieron cargar las categorías. <button type="button" onClick={() => setRetry(value => value + 1)}>Reintentar</button></> : 'Aún no hay categorías. Crea la primera con «Nueva categoría».'}</div>}
        </div>
    </section>;
}
