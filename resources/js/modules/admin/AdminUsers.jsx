import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Copy, Check, Ellipsis, KeyRound, Link2, Pencil, Search, UserCheck, UserCog, UserX } from 'lucide-react';
import './AdminProducts.css';
import './AdminActions.css';
import './AdminCustomers.css';
import './AdminActivity.css';
import './AdminUsers.css';
import AdminConfirmDialog from './AdminConfirmDialog';
import AdminUserDrawer, { PasswordField, generatePassword } from './AdminUserDrawer';
import { pageList, request, timeAgoText, titleCase } from './adminShared';
import { useAdminToast } from './AdminToast';
import useModalLock from './useModalLock';
import { createPortal } from 'react-dom';

const number = value => new Intl.NumberFormat('es-CO').format(value);
const ROLES = ['admin', 'dispatch', 'delivery'];
const STATUSES = [['active', 'Activos'], ['inactive', 'Desactivados'], ['all', 'Todos']];
const json = body => ({ headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });

// URL: /admin/users[/{id}]?search=&role=&status=&page=  (the id is the person open in the drawer; "active" is the default status)
const readId = () => Number(window.location.pathname.match(/^\/admin\/users\/(\d+)$/)?.[1]) || null;
function initialLocation() {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    return {
        search: (params.get('search') || '').slice(0, 100), role: ROLES.includes(params.get('role')) ? params.get('role') : '',
        status: ['inactive', 'all'].includes(params.get('status')) ? params.get('status') : 'active', page: Number.isInteger(page) && page > 0 && page <= 100000 ? page : 1,
    };
}

export const RolePill = ({ role, label }) => <span className={`admin-users-role is-${role}`}>{label}</span>;

// The temporary password is shown ONCE: copy it now, it is never stored in clear and cannot be shown again.
function TemporaryPassword({ person, password, onClose }) {
    useModalLock();
    const [copied, setCopied] = useState(false);
    const close = useRef(null);
    useEffect(() => { close.current?.focus(); }, []);
    async function copy() {
        try { await navigator.clipboard.writeText(password); setCopied(true); window.setTimeout(() => setCopied(false), 2500); } catch { /* the password stays visible to copy by hand */ }
    }
    return createPortal(<div className="admin-confirm-layer">
        <div className="admin-confirm-overlay" aria-hidden="true" />
        <div className="admin-confirm-dialog admin-users-temp" role="alertdialog" aria-modal="true" aria-labelledby="temp-title" aria-describedby="temp-text" onKeyDown={event => { if (event.key === 'Escape') { event.stopPropagation(); onClose(); } }}>
            <div className="admin-confirm-head"><KeyRound className="is-ok" aria-hidden="true" /><h2 id="temp-title">Contraseña temporal</h2></div>
            <div className="admin-confirm-body" id="temp-text">
                <p>Entrégasela a <strong>{titleCase(person.name)}</strong> (usuario <code>{person.username}</code>). Deberá cambiarla al entrar.</p>
                <div className="admin-users-temp-box"><code aria-label="Contraseña temporal">{password}</code>
                    <button type="button" className="admin-categories-secondary" onClick={copy}>{copied ? <><Check aria-hidden="true" /> Copiada</> : <><Copy aria-hidden="true" /> Copiar</>}</button></div>
                <p className="admin-users-warning" role="note">No se volverá a mostrar. Si la pierdes, restablece la contraseña de nuevo.</p>
            </div>
            <div className="admin-confirm-buttons"><button type="button" ref={close} className="admin-confirm-primary" onClick={onClose}>Listo</button></div>
        </div>
    </div>, document.body);
}

// The single-use link is shown ONCE: only its hash is stored. The administrator copies it and hands it to the person.
function PasswordLink({ person, url, hours, onClose }) {
    useModalLock();
    const [copied, setCopied] = useState(false);
    const close = useRef(null);
    useEffect(() => { close.current?.focus(); }, []);
    async function copy() {
        try { await navigator.clipboard.writeText(url); setCopied(true); window.setTimeout(() => setCopied(false), 2500); } catch { /* the link stays visible to copy by hand */ }
    }
    return createPortal(<div className="admin-confirm-layer">
        <div className="admin-confirm-overlay" aria-hidden="true" />
        <div className="admin-confirm-dialog admin-users-temp" role="alertdialog" aria-modal="true" aria-labelledby="link-title" aria-describedby="link-text" onKeyDown={event => { if (event.key === 'Escape') { event.stopPropagation(); onClose(); } }}>
            <div className="admin-confirm-head"><Link2 className="is-ok" aria-hidden="true" /><h2 id="link-title">Enlace para crear contraseña</h2></div>
            <div className="admin-confirm-body" id="link-text">
                <p>Envíaselo a <strong>{titleCase(person.name)}</strong> (usuario <code>{person.username}</code>). Con él elegirá su propia contraseña.</p>
                <div className="admin-users-temp-box"><code aria-label="Enlace" style={{ wordBreak: 'break-all' }}>{url}</code>
                    <button type="button" className="admin-categories-secondary" onClick={copy}>{copied ? <><Check aria-hidden="true" /> Copiado</> : <><Copy aria-hidden="true" /> Copiar</>}</button></div>
                <p className="admin-users-warning" role="note">Sirve una sola vez y vence en {hours} horas. No se volverá a mostrar: si lo pierdes, crea otro (el anterior deja de funcionar). Quien tenga el enlace puede fijar la contraseña, envíalo solo a esa persona.</p>
            </div>
            <div className="admin-confirm-buttons"><button type="button" ref={close} className="admin-confirm-primary" onClick={onClose}>Listo</button></div>
        </div>
    </div>, document.body);
}

// The administrator sets (or generates) a temporary password for a person who forgot theirs.
function ResetPasswordDialog({ person, busy, error, opener, onCancel, onConfirm }) {
    const [password, setPassword] = useState(generatePassword);
    const [show, setShow] = useState(true);
    return <AdminConfirmDialog title={`Restablecer contraseña de ${titleCase(person.name)}`} confirmLabel="Restablecer" tone="primary" busy={busy} error={error} opener={opener} onCancel={onCancel} onConfirm={() => onConfirm(password)}>
        <p>Define una contraseña temporal. La persona deberá cambiarla al entrar y sus sesiones abiertas se cerrarán.</p>
        <PasswordField id="reset-password" label="Contraseña temporal" value={password} onChange={setPassword} show={show} onToggle={() => setShow(value => !value)} onGenerate={() => setPassword(generatePassword())} />
    </AdminConfirmDialog>;
}

export default function AdminUsers({ me }) {
    const toast = useAdminToast();
    const [initial] = useState(initialLocation);
    const [draft, setDraft] = useState(initial.search);
    const [search, setSearch] = useState(initial.search);
    const [role, setRole] = useState(initial.role);
    const [status, setStatus] = useState(initial.status);
    const [page, setPage] = useState(initial.page);
    const [drawer, setDrawer] = useState(() => (readId() ? { id: readId() } : null)); // { id } edits, { id: null } creates
    const [result, setResult] = useState(null);
    const [state, setState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const [menu, setMenu] = useState(null);
    const [dialog, setDialog] = useState(null); // { type: 'deactivate' | 'reset', person, opener }
    const [dialogBusy, setDialogBusy] = useState(false);
    const [dialogError, setDialogError] = useState('');
    const [temporary, setTemporary] = useState(null);
    const [passwordLink, setPasswordLink] = useState(null); // { person, url, hours }: shown once
    const skipUrl = useRef(true);

    const queryString = () => {
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (role) params.set('role', role);
        if (status !== 'active') params.set('status', status);
        return params;
    };

    // Keep the URL in step with the filters and the open person. Opening/closing the drawer adds/removes a history entry.
    useEffect(() => {
        const params = queryString();
        if (page > 1) params.set('page', String(page));
        const text = params.toString();
        const next = `${drawer?.id ? `/admin/users/${drawer.id}` : '/admin/users'}${text ? `?${text}` : ''}`;
        if (next === `${window.location.pathname}${window.location.search}`) return;
        if (skipUrl.current) { skipUrl.current = false; window.history.replaceState(window.history.state, '', next); return; }
        window.history[readId() === (drawer?.id || null) ? 'replaceState' : 'pushState'](window.history.state, '', next);
    }, [search, role, status, page, drawer]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        const onPop = () => setDrawer(readId() ? { id: readId() } : null);
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
        params.set('status', status);
        params.set('page', String(page));
        setState('loading');
        request(`/admin/users/data?${params}`, { signal: controller.signal })
            .then(data => { setResult(data); setState('success'); })
            .catch(error => { if (error.name !== 'AbortError') setState('error'); });
        return () => controller.abort();
    }, [search, role, status, page, retry]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (menu === null) return undefined;
        const close = event => { if (!event.target.closest?.('.admin-users-menu-wrap')) setMenu(null); };
        const esc = event => { if (event.key === 'Escape') setMenu(null); };
        document.addEventListener('mousedown', close); document.addEventListener('keydown', esc);
        return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc); };
    }, [menu]);

    const refresh = () => setRetry(value => value + 1);

    async function reactivate(person) {
        setMenu(null);
        try { await request(`/admin/users/${person.id}/reactivate`, { method: 'PATCH' }); toast.success('Persona reactivada', `${titleCase(person.name)} ya puede entrar al panel.`); refresh(); }
        catch (error) { toast.error('No se pudo reactivar', error.message); }
    }

    async function confirmDialog(password) {
        if (dialogBusy) return;
        setDialogBusy(true); setDialogError('');
        const { type, person } = dialog;
        try {
            if (type === 'deactivate') {
                await request(`/admin/users/${person.id}/deactivate`, { method: 'PATCH' });
                toast.success('Persona desactivada', `${titleCase(person.name)} ya no puede entrar y sus sesiones se cerraron.`);
                setDialog(null); refresh();
            } else if (type === 'link') {
                const response = await request(`/admin/users/${person.id}/password-link`, { method: 'POST' });
                setDialog(null); setPasswordLink({ person: response.data, url: response.link, hours: response.valid_hours });
            } else {
                const response = await request(`/admin/users/${person.id}/reset-password`, { method: 'POST', ...json({ password }) });
                setDialog(null); setTemporary({ person: response.data, password: response.temporary_password }); refresh();
            }
        } catch (error) { setDialogError(Object.values(error.fields || {})[0]?.[0] || error.message); } finally { setDialogBusy(false); }
    }

    const summary = result?.summary;
    const meta = result?.meta;
    const roles = result?.roles || [];
    const roleLabel = value => roles.find(item => item.value === value)?.label || value;
    const open = person => { setMenu(null); setDrawer({ id: person.id }); };

    return <section className="admin-products admin-users">
        <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Equipo del panel</span><h1>Usuarios</h1><p>Quién entra al panel y qué puede hacer. Las contraseñas nunca se muestran.</p></div>
            <div className="admin-products-header-actions"><button className="admin-products-new" type="button" onClick={() => setDrawer({ id: null })}>+ Agregar persona</button></div></header>

        {summary && <div className="admin-products-summary">
            <div className="dashboard-card"><span>Activos</span><strong>{number(summary.active)}</strong></div>
            <div className="dashboard-card"><span>Administradores</span><strong>{number(summary.admin)}</strong></div>
            <div className="dashboard-card"><span>Despacho y entrega</span><strong>{number(summary.dispatch)}</strong></div>
            <div className="dashboard-card"><span>Solo entrega</span><strong>{number(summary.delivery)}</strong></div>
        </div>}

        <div className="admin-users-filters dashboard-card">
            <label className="admin-products-search"><span>Buscar por nombre o usuario</span><span className="admin-products-input"><Search aria-hidden="true" /><input type="search" value={draft} maxLength={100} onChange={event => setDraft(event.target.value)} placeholder="Nombre o usuario" /></span></label>
            <label className="admin-activity-field"><span>Rol</span><select value={role} onChange={event => { setRole(event.target.value); setPage(1); }}><option value="">Todos</option>{ROLES.map(value => <option key={value} value={value}>{roleLabel(value)}</option>)}</select></label>
            <label className="admin-activity-field"><span>Estado</span><select value={status} onChange={event => { setStatus(event.target.value); setPage(1); }}>{STATUSES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
        </div>

        {drawer && <AdminUserDrawer id={drawer.id} me={me} roles={roles} onClose={() => setDrawer(null)}
            onSaved={(person, password) => { setDrawer(null); refresh(); if (password) setTemporary({ person, password }); else toast.success('Cambios guardados', `${titleCase(person.name)} se actualizó.`); }} />}
        {dialog?.type === 'deactivate' && <AdminConfirmDialog title={`¿Desactivar a ${titleCase(dialog.person.name)}?`} confirmLabel="Desactivar" busy={dialogBusy} error={dialogError} opener={dialog.opener} onCancel={() => { setDialog(null); setDialogError(''); }} onConfirm={() => confirmDialog()}>
            <p>No podrá entrar al panel y sus sesiones abiertas se cerrarán al instante. Puedes reactivarla cuando quieras; las personas nunca se borran.</p></AdminConfirmDialog>}
        {dialog?.type === 'link' && <AdminConfirmDialog title={`Crear enlace para ${titleCase(dialog.person.name)}`} confirmLabel="Crear enlace" tone="primary" busy={dialogBusy} error={dialogError} opener={dialog.opener} onCancel={() => { setDialog(null); setDialogError(''); }} onConfirm={() => confirmDialog()}>
            <p>Se creará un enlace de un solo uso, válido por 24 horas, para que la persona elija su contraseña. Cualquier enlace anterior dejará de funcionar. Sus sesiones abiertas siguen activas hasta que lo use.</p></AdminConfirmDialog>}
        {dialog?.type === 'reset' && <ResetPasswordDialog person={dialog.person} busy={dialogBusy} error={dialogError} opener={dialog.opener} onCancel={() => { setDialog(null); setDialogError(''); }} onConfirm={confirmDialog} />}
        {passwordLink && <PasswordLink person={passwordLink.person} url={passwordLink.url} hours={passwordLink.hours} onClose={() => setPasswordLink(null)} />}
        {temporary && <TemporaryPassword person={temporary.person} password={temporary.password} onClose={() => setTemporary(null)} />}

        <div className="dashboard-card admin-products-list">
            <div className="admin-products-list-head"><h2>Personas del panel</h2><span role="status">{state === 'success' ? `${number(meta.total)} ${meta.total === 1 ? 'persona' : 'personas'}` : state === 'loading' ? 'Cargando…' : 'Resultados no disponibles'}</span></div>
            {state === 'success' && result.data.length > 0 ? <div className="admin-products-scroll" tabIndex="0" role="region" aria-label="Tabla de personas, desplazable horizontalmente"><table className="admin-products-table admin-users-table">
                <thead><tr><th scope="col">Persona</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Último acceso</th><th scope="col"><span className="admin-customers-sr">Acciones</span></th></tr></thead>
                {result.data.map(row => <tbody key={row.id} className={row.is_active ? undefined : 'is-inactive'}><tr className={`admin-products-row admin-users-row${row.is_active ? '' : ' is-inactive'}`}>
                    <td data-label="Persona"><div><div className="admin-users-namerow"><button type="button" className="admin-customers-name" onClick={() => open(row)} aria-label={`Editar a ${titleCase(row.name)}`}>{titleCase(row.name)}</button>{row.is_self && <span className="admin-users-you">Tú</span>}</div><small className="admin-users-username">@{row.username}</small></div></td>
                    <td data-label="Rol"><RolePill role={row.role} label={row.role_label} /></td>
                    <td data-label="Estado"><span className={`admin-users-state${row.is_active ? ' is-active' : ''}`}>{row.is_active ? 'Activo' : 'Desactivado'}</span>{row.must_change_password && <small>Contraseña temporal pendiente</small>}</td>
                    <td data-label="Último acceso">{row.last_login_at ? timeAgoText(row.last_login_at) : 'Nunca'}</td>
                    <td data-label="Acciones" className="admin-users-actions"><button type="button" className="admin-categories-secondary" onClick={() => open(row)}><Pencil aria-hidden="true" />Editar</button>
                        <span className="admin-users-menu-wrap"><button type="button" className="admin-users-more" aria-haspopup="menu" aria-expanded={menu === row.id} aria-label={`Más acciones de ${titleCase(row.name)}`} onClick={() => setMenu(menu === row.id ? null : row.id)}><Ellipsis aria-hidden="true" /></button>
                            {menu === row.id && <div className="admin-users-menu" role="menu">
                                {!row.is_self && <button type="button" role="menuitem" onClick={event => { setMenu(null); setDialogError(''); setDialog({ type: 'reset', person: row, opener: event.currentTarget }); }}><KeyRound aria-hidden="true" />Restablecer contraseña</button>}
                                {!row.is_self && row.is_active && <button type="button" role="menuitem" onClick={event => { setMenu(null); setDialogError(''); setDialog({ type: 'link', person: row, opener: event.currentTarget }); }}><Link2 aria-hidden="true" />Crear enlace de contraseña</button>}
                                {row.is_active ? (!row.is_self && <button type="button" role="menuitem" onClick={event => { setMenu(null); setDialogError(''); setDialog({ type: 'deactivate', person: row, opener: event.currentTarget }); }}><UserX aria-hidden="true" />Desactivar</button>)
                                    : <button type="button" role="menuitem" onClick={() => reactivate(row)}><UserCheck aria-hidden="true" />Reactivar</button>}
                                {row.is_self && <p className="admin-users-menu-note">No puedes desactivarte ni restablecer tu propia contraseña.</p>}
                            </div>}</span></td>
                </tr></tbody>)}
            </table></div>
                : <div className="admin-products-message" role={state === 'error' ? 'alert' : 'status'}>{state === 'loading' ? 'Cargando personas…' : state === 'error' ? <>No se pudieron cargar las personas. <button type="button" onClick={refresh}>Reintentar</button></>
                    : <div className="admin-promotions-empty"><UserCog aria-hidden="true" /><strong>No hay personas con esos filtros.</strong></div>}</div>}
            {state === 'success' && meta.total > 0 && <nav className="admin-products-pagination" aria-label="Paginación de personas"><span>{`Mostrando ${meta.from}-${meta.to} de ${number(meta.total)}`}</span>
                <div className="admin-products-pages"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}><ChevronLeft aria-hidden="true" /> Anterior</button>
                    {pageList(meta.current_page, meta.last_page).map(value => typeof value === 'string' ? <span key={value} className="admin-products-gap" aria-hidden="true">…</span> : <button key={value} type="button" className={value === meta.current_page ? 'is-current' : ''} aria-current={value === meta.current_page ? 'page' : undefined} aria-label={`Página ${value}`} onClick={() => setPage(value)}>{value}</button>)}
                    <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)}>Siguiente <ChevronRight aria-hidden="true" /></button></div></nav>}
        </div>
    </section>;
}
