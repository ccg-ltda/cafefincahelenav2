import { useState } from 'react';
import { Eye, EyeOff, LoaderCircle, LockKeyhole } from 'lucide-react';
import './AdminUsers.css';
import { csrf } from './adminShared';

const MIN = 10;

// "Crea tu nueva contraseña": the only screen a person with a temporary password can use (the server blocks everything else).
export default function ChangePasswordForm({ session }) {
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [show, setShow] = useState(false);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');

    async function submit(event) {
        event.preventDefault();
        if (pending) return;
        if (password.length < MIN) { setError(`La contraseña debe tener al menos ${MIN} caracteres.`); return; }
        if (password !== confirmation) { setError('Las contraseñas no coinciden.'); return; }
        setPending(true); setError('');
        try {
            const response = await fetch('/admin/password', {
                method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ password, password_confirmation: confirmation }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok) { window.location.assign(data.home || '/admin'); return; }
            if (response.status === 401) { window.location.replace('/admin/login'); return; }
            setError(data.errors?.password?.[0] || data.message || 'No fue posible guardar la contraseña. Inténtalo de nuevo.');
        } catch { setError('No fue posible conectar. Comprueba tu conexión e inténtalo de nuevo.'); }
        setPending(false);
    }

    return <>
        <span className="admin-eyebrow">Primer acceso</span><h1>Crea tu nueva contraseña</h1>
        <p className="admin-intro">{session?.name ? `${session.name}, e` : 'E'}l administrador te dio una contraseña temporal. Elige una propia para continuar: mínimo {MIN} caracteres y distinta de la temporal.</p>
        <form onSubmit={submit} noValidate>
            <label className="admin-field-label" htmlFor="new-password">Nueva contraseña</label>
            <div className="admin-field admin-password"><LockKeyhole className="admin-field-icon" aria-hidden="true" /><input id="new-password" type={show ? 'text' : 'password'} placeholder="Nueva contraseña" autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} aria-invalid={Boolean(error)} />
                <button type="button" className="admin-password-toggle" onClick={() => setShow(value => !value)} aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'}>{show ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}</button></div><div className="admin-error" aria-hidden="true" />
            <label className="admin-field-label" htmlFor="confirm-password">Repite la contraseña</label>
            <div className="admin-field"><LockKeyhole className="admin-field-icon" aria-hidden="true" /><input id="confirm-password" type={show ? 'text' : 'password'} placeholder="Repite la contraseña" autoComplete="new-password" value={confirmation} onChange={event => setConfirmation(event.target.value)} aria-invalid={Boolean(error)} /></div><div className="admin-error" aria-hidden="true" />
            {error && <p className="admin-message" role="alert">{error}</p>}
            <button className="admin-primary" type="submit" disabled={pending}>{pending ? <><LoaderCircle className="admin-spinner" aria-hidden="true" /> Guardando…</> : 'Guardar y continuar'}</button>
        </form>
    </>;
}
