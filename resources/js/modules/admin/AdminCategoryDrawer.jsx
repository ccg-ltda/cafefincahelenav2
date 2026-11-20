import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { TriangleAlert, X } from 'lucide-react';
import './AdminCategories.css';
import useModalLock from './useModalLock';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
export const BASE = '/admin/products/categories';
export const sortByName = list => [...list].sort((a, b) => a.name.localeCompare(b.name, 'es', { sensitivity: 'base' }) || a.id - b.id);
export const countLabel = count => `${new Intl.NumberFormat('es-CO').format(count)} ${count === 1 ? 'producto' : 'productos'}`;
const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';

export async function request(url, options = {}) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...options.headers };
    const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    if (response.status === 401 || response.redirected) { window.location.replace('/admin/login?expired=1'); throw new Error('Sesión expirada.'); }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const messages = { 403: 'No tienes permiso para realizar esta acción.', 404: 'La categoría ya no existe. Recarga la página.', 419: 'La sesión o el token de seguridad vencieron. Recarga la página e inicia sesión de nuevo si es necesario.', 422: 'Revisa los campos señalados.' };
        const error = new Error(messages[response.status] || 'No fue posible completar la operación. Inténtalo de nuevo.');
        error.fields = data.errors || {};
        throw error;
    }
    return data;
}

export function CategoryDrawer({ form, errors, formError, saving, closing, prompt, onChange, onSubmit, onRequestClose, onPromptCancel, onPromptConfirm, existing, onUseExisting }) {
    const panel = useRef(null);
    const nameInput = useRef(null);
    const promptCancel = useRef(null);

    useModalLock();

    // Follow the visual viewport so the virtual keyboard never hides the footer buttons.
    useEffect(() => {
        const viewport = window.visualViewport;
        if (!viewport) return undefined;
        const sync = () => panel.current?.style.setProperty('--drawer-height', `${viewport.height}px`);
        sync(); viewport.addEventListener('resize', sync);
        return () => viewport.removeEventListener('resize', sync);
    }, []);

    // Keep focus inside the dialog if it escapes (e.g. through the browser UI).
    useEffect(() => {
        const keepFocus = event => { if (panel.current && !panel.current.contains(event.target)) nameInput.current?.focus(); };
        document.addEventListener('focusin', keepFocus);
        return () => document.removeEventListener('focusin', keepFocus);
    }, []);

    useEffect(() => {
        if (prompt) promptCancel.current?.focus();
        else nameInput.current?.focus();
    }, [prompt?.kind]);

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (prompt) onPromptCancel(); else onRequestClose(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={onRequestClose} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer" role="dialog" aria-modal="true" aria-labelledby="category-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Categorías</span><h2 id="category-drawer-title">{form.id ? 'Editar categoría' : 'Nueva categoría'}</h2></div>
                <button type="button" className="admin-categories-close" onClick={onRequestClose} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <form className="admin-categories-drawer-form" onSubmit={onSubmit} noValidate>
                <div className="admin-categories-drawer-body">
                    <label className="admin-categories-field" htmlFor="category-name"><span>Nombre</span>
                        <input id="category-name" ref={nameInput} value={form.name} maxLength={255} required autoComplete="off" onChange={onChange('name')} aria-invalid={!!errors.name} aria-describedby={errors.name ? 'category-name-error' : undefined} /></label>
                    {errors.name && <small className="admin-categories-error" id="category-name-error" role="alert">{errors.name[0]}</small>}
                    {existing && errors.name && (existing.is_active
                        ? <div className="admin-categories-existing" role="status"><p>Ya existe la categoría activa «{existing.name}». Puedes usarla sin crear un duplicado.</p><button type="button" className="admin-categories-secondary" onClick={onUseExisting}>Usar «{existing.name}»</button></div>
                        : <p className="admin-categories-hint" role="status">«{existing.name}» ya existe pero está inactiva. Actívala desde «Gestionar categorías» para usarla.</p>)}
                    {form.id
                        ? <div className="admin-categories-slug"><span>Slug (solo lectura)</span><code>{form.slug}</code><p className="admin-categories-hint">La URL pública no cambia al renombrar la categoría.</p></div>
                        : <p className="admin-categories-hint">El slug se genera a partir del nombre y no se puede repetir.</p>}
                    <label className="admin-categories-check" htmlFor="category-active"><input id="category-active" type="checkbox" checked={form.is_active} onChange={onChange('is_active')} aria-describedby={errors.is_active ? 'category-active-error' : undefined} /><span>Categoría activa</span></label>
                    {errors.is_active && <small className="admin-categories-error" id="category-active-error" role="alert">{errors.is_active[0]}</small>}
                    {!form.is_active && <p className="admin-categories-hint">Una categoría inactiva no muestra sus productos en la tienda pública.</p>}
                    {formError && <p className="admin-categories-alert" role="alert">{formError}</p>}
                </div>
                <footer className="admin-categories-drawer-foot">
                    {prompt
                        ? <div className="admin-categories-prompt" role="alertdialog" aria-labelledby="category-prompt-title" aria-describedby="category-prompt-text">
                            <TriangleAlert aria-hidden="true" />
                            <div>
                                <strong id="category-prompt-title">{prompt.kind === 'discard' ? '¿Descartar los cambios?' : `¿Desactivar «${prompt.category.name}»?`}</strong>
                                <p id="category-prompt-text">{prompt.kind === 'discard' ? 'Tienes cambios sin guardar. Si cierras ahora, se perderán.' : `Sus productos (${countLabel(prompt.category.products_count)}) dejarán de mostrarse en el catálogo público. No se modifica el estado de los productos ni de sus variantes.`}</p>
                                <div className="admin-categories-buttons">
                                    <button type="button" className="admin-categories-secondary" ref={promptCancel} onClick={onPromptCancel}>{prompt.kind === 'discard' ? 'Seguir editando' : 'Cancelar'}</button>
                                    <button type="button" className="admin-categories-danger" onClick={onPromptConfirm}>{prompt.kind === 'discard' ? 'Descartar cambios' : 'Desactivar categoría'}</button>
                                </div>
                            </div>
                        </div>
                        : <div className="admin-categories-buttons">
                            <button type="button" className="admin-categories-secondary" disabled={saving} onClick={onRequestClose}>Cancelar</button>
                            <button type="submit" className="admin-categories-primary" disabled={saving}>{saving ? 'Guardando…' : 'Guardar categoría'}</button>
                        </div>}
                </footer>
            </form>
        </div>
    </div>, document.body);
}
