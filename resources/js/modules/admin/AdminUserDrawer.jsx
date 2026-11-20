import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Check, Eye, EyeOff, PackageCheck, RefreshCw, ShieldCheck, Truck, X } from 'lucide-react';
import './AdminCategories.css';
import './AdminUsers.css';
import useModalLock from './useModalLock';
import { request, titleCase } from './adminShared';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';
const ROLE_ICONS = { admin: ShieldCheck, dispatch: PackageCheck, delivery: Truck };
const json = body => ({ headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
const EMPTY = { name: '', username: '', email: '', role: 'dispatch', password: '' };

// 14 letters and digits, without look-alike characters, from the browser's secure random source.
export function generatePassword() {
    const alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = crypto.getRandomValues(new Uint32Array(14));
    return Array.from(bytes, value => alphabet[value % alphabet.length]).join('');
}

// "Carlos Andrés Rojas" -> carlos.rojas (first and last word, no accents)
const suggestUsername = name => {
    const words = name.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().split(/\s+/).map(word => word.replace(/[^a-z0-9-]/g, '')).filter(Boolean);
    return (words.length > 1 ? `${words[0]}.${words[words.length - 1]}` : words[0] || '').slice(0, 40);
};

export function PasswordField({ id, label, value, onChange, show, onToggle, onGenerate, error, hint }) {
    return <div className="admin-categories-field admin-users-password">
        <label htmlFor={id}><span>{label}</span></label>
        <div className="admin-users-password-row">
            <input id={id} type={show ? 'text' : 'password'} value={value} autoComplete="new-password" spellCheck={false} onChange={event => onChange(event.target.value)} aria-invalid={Boolean(error)} aria-describedby={`${id}-hint`} />
            <button type="button" className="admin-categories-secondary" onClick={onToggle} aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'}>{show ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}</button>
            <button type="button" className="admin-categories-secondary" onClick={onGenerate}><RefreshCw aria-hidden="true" />Generar</button>
        </div>
        {error ? <small className="admin-categories-error" role="alert">{error}</small> : <small className="admin-categories-hint" id={`${id}-hint`}>{hint || 'Mínimo 10 caracteres.'}</small>}
    </div>;
}

// Create ("Agregar persona", id null) or edit ("Editar persona") one person in a right-hand panel.
export default function AdminUserDrawer({ id, me, roles, onClose, onSaved }) {
    const panel = useRef(null);
    const firstField = useRef(null);
    const editing = id !== null;
    const [form, setForm] = useState(EMPTY);
    const [state, setState] = useState(editing ? 'loading' : 'success');
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState('');
    const [busy, setBusy] = useState(false);
    const [closing, setClosing] = useState(false);
    const [touchedUsername, setTouchedUsername] = useState(false);
    const [check, setCheck] = useState(null); // { available, valid } of the typed username
    const [show, setShow] = useState(false);
    const [self, setSelf] = useState(false);

    useModalLock();

    useEffect(() => {
        if (!editing) return undefined;
        const controller = new AbortController();
        request(`/admin/users/${id}/detail`, { signal: controller.signal })
            .then(({ data }) => { setForm({ name: data.name, username: data.username, email: data.email || '', role: data.role, password: '' }); setSelf(data.is_self); setTouchedUsername(true); setState('success'); })
            .catch(failure => { if (failure.name !== 'AbortError') setState(failure.status === 404 ? 'missing' : 'error'); });
        return () => controller.abort();
    }, [id, editing]);

    useEffect(() => { if (state === 'success') firstField.current?.focus(); }, [state]);

    // Live uniqueness check of the username (debounced).
    useEffect(() => {
        const username = form.username.trim().toLowerCase();
        if (!username) { setCheck(null); return undefined; }
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            request(`/admin/users/username-check?username=${encodeURIComponent(username)}${editing ? `&ignore=${id}` : ''}`, { signal: controller.signal })
                .then(setCheck).catch(() => setCheck(null));
        }, 350);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [form.username, editing, id]);

    function change(field, value) {
        setForm(current => {
            const next = { ...current, [field]: value };
            if (field === 'name' && !touchedUsername && !editing) next.username = suggestUsername(value); // the suggestion follows the name until the admin edits it
            return next;
        });
        setErrors(current => ({ ...current, [field]: undefined }));
    }

    function close() { if (busy) return; setClosing(true); window.setTimeout(onClose, 180); }

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    async function submit(event) {
        event.preventDefault();
        if (busy) return;
        const problems = {};
        if (form.name.trim().length < 2) problems.name = ['Escribe el nombre de la persona.'];
        if (!/^[a-z0-9.-]{3,40}$/.test(form.username.trim().toLowerCase())) problems.username = ['Usa de 3 a 40 letras minúsculas, números, punto o guion.'];
        if (!editing && form.password.length < 10) problems.password = ['La contraseña temporal debe tener al menos 10 caracteres.'];
        if (Object.keys(problems).length) { setErrors(problems); return; }
        setBusy(true); setFormError(''); setErrors({});
        const body = { name: form.name.trim(), username: form.username.trim().toLowerCase(), email: form.email.trim() || null, role: form.role, ...(editing ? {} : { password: form.password }) };
        try {
            const response = await request(editing ? `/admin/users/${id}` : '/admin/users', { method: editing ? 'PUT' : 'POST', ...json(body) });
            onSaved(response.data, editing ? null : form.password);
        } catch (failure) {
            setErrors(failure.fields || {});
            setFormError(Object.keys(failure.fields || {}).length ? '' : failure.message);
            if (failure.fields?.user) setFormError(failure.fields.user[0]);
        } finally { setBusy(false); }
    }

    const hint = check && form.username.trim()
        ? (!check.valid ? ['is-bad', 'Usa de 3 a 40 letras minúsculas, números, punto o guion.'] : check.available ? ['is-ok', 'Disponible'] : ['is-bad', 'Ese usuario ya existe.']) : null;

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={close} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer admin-users-drawer" role="dialog" aria-modal="true" aria-labelledby="user-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Equipo del panel</span><h2 id="user-drawer-title">{editing ? 'Editar persona' : 'Agregar persona'}</h2></div>
                <button type="button" className="admin-categories-close" onClick={close} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <form className="admin-categories-drawer-form" onSubmit={submit} noValidate>
                <div className="admin-categories-drawer-body">
                    {state === 'loading' && <p className="admin-categories-hint" role="status">Cargando persona…</p>}
                    {state === 'missing' && <p className="admin-categories-alert" role="alert">Esta persona no existe.</p>}
                    {state === 'error' && <p className="admin-categories-alert" role="alert">No se pudo cargar la persona.</p>}
                    {state === 'success' && <>
                        <label className="admin-categories-field" htmlFor="user-name"><span>Nombre</span>
                            <input id="user-name" ref={firstField} value={form.name} maxLength={255} autoComplete="off" onChange={event => change('name', event.target.value)} aria-invalid={Boolean(errors.name)} /></label>
                        {errors.name && <small className="admin-categories-error" role="alert">{errors.name[0]}</small>}

                        <label className="admin-categories-field" htmlFor="user-username"><span>Usuario</span>
                            <input id="user-username" className="admin-users-mono" value={form.username} maxLength={40} autoComplete="off" autoCapitalize="none" spellCheck={false} onChange={event => { setTouchedUsername(true); change('username', event.target.value.toLowerCase()); }} aria-invalid={Boolean(errors.username)} aria-describedby="user-username-hint" /></label>
                        {errors.username ? <small className="admin-categories-error" role="alert">{errors.username[0]}</small>
                            : <small className={`admin-users-check ${hint ? hint[0] : ''}`} id="user-username-hint" role="status">{hint ? (hint[0] === 'is-ok' ? <><Check aria-hidden="true" />{hint[1]}</> : hint[1]) : 'Con este usuario entra al panel. Se sugiere a partir del nombre.'}</small>}

                        <label className="admin-categories-field" htmlFor="user-email"><span>Correo <em>opcional</em></span>
                            <input id="user-email" type="email" value={form.email} maxLength={255} autoComplete="off" onChange={event => change('email', event.target.value)} aria-invalid={Boolean(errors.email)} /></label>
                        {errors.email && <small className="admin-categories-error" role="alert">{errors.email[0]}</small>}

                        <fieldset className="admin-users-roles" disabled={self}>
                            <legend>Rol</legend>
                            <div className="admin-users-role-grid" role="radiogroup" aria-label="Rol">
                                {roles.map(item => { const Icon = ROLE_ICONS[item.value]; return <label key={item.value} className={`admin-users-role-card is-${item.value}${form.role === item.value ? ' is-selected' : ''}`}>
                                    <input type="radio" name="user-role" value={item.value} checked={form.role === item.value} onChange={() => change('role', item.value)} />
                                    <span className="admin-users-role-icon"><Icon aria-hidden="true" /></span>
                                    <span className="admin-users-role-text"><strong>{item.label}</strong><small>{item.description}</small></span>
                                    <span className="admin-users-role-check" aria-hidden="true"><Check /></span></label>; })}
                            </div>
                            {self && <small className="admin-categories-hint">No puedes cambiar tu propio rol.</small>}
                            {errors.role && <small className="admin-categories-error" role="alert">{errors.role[0]}</small>}
                        </fieldset>

                        {!editing && <PasswordField id="user-password" label="Contraseña temporal" value={form.password} onChange={value => change('password', value)} show={show} onToggle={() => setShow(value => !value)}
                            onGenerate={() => { change('password', generatePassword()); setShow(true); }} error={errors.password?.[0]} hint="Mínimo 10 caracteres. La persona deberá cambiarla en su primer acceso." />}
                        {editing && <p className="admin-categories-hint">Para cambiar su contraseña usa «Restablecer contraseña» en la lista.</p>}
                        {formError && <p className="admin-categories-alert" role="alert">{formError}</p>}
                    </>}
                </div>
                <footer className="admin-categories-drawer-foot"><div className="admin-categories-buttons">
                    <button type="button" className="admin-categories-secondary" onClick={close} disabled={busy}>Cancelar</button>
                    <button type="submit" className="admin-categories-primary" disabled={busy || state !== 'success'}>{busy ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear usuario'}</button>
                </div></footer>
            </form>
        </div>
    </div>, document.body);
}
