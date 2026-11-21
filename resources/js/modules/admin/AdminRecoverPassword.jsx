import { useEffect, useState } from 'react';
import { Eye, EyeOff, LoaderCircle, LockKeyhole } from 'lucide-react';
import './AdminUsers.css';
import { csrf } from './adminShared';

const MIN = 10;
const headers = () => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() });

// "Crea tu contraseña": opened from a single-use link an administrator handed over (/admin/recover-password#TOKEN).
// The token travels in the URL fragment, so it never reaches server logs or a Referer; it is removed from the address bar on load.
export default function RecoverPasswordForm() {
    const [token] = useState(() => window.location.hash.slice(1));
    const [check, setCheck] = useState(token ? 'checking' : 'invalid'); // checking | valid | invalid | done
    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [show, setShow] = useState(false);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!token) return;
        window.history.replaceState(null, '', window.location.pathname);
        fetch('/admin/password-link/check', { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify({ token }) })
            .then(async response => {
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.valid) { setName(data.name || ''); setCheck('valid'); } else { setCheck('invalid'); }
            }).catch(() => { setError('No fue posible conectar. Comprueba tu conexión e inténtalo de nuevo.'); setCheck('invalid'); });
    }, [token]);

    async function submit(event) {
        event.preventDefault();
        if (pending) return;
        if (password.length < MIN) { setError(`La contraseña debe tener al menos ${MIN} caracteres.`); return; }
        if (password !== confirmation) { setError('Las contraseñas no coinciden.'); return; }
        setPending(true); setError('');
        try {
            const response = await fetch('/admin/password-link', { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify({ token, password, password_confirmation: confirmation }) });
            const data = await response.json().catch(() => ({}));
            if (response.ok) { setCheck('done'); return; }
            if (data.errors?.token) { setCheck('invalid'); return; }
            setError(data.errors?.password?.[0] || data.message || 'No fue posible guardar la contraseña. Inténtalo de nuevo.');
        } catch { setError('No fue posible conectar. Comprueba tu conexión e inténtalo de nuevo.'); }
        setPending(false);
    }

    if (check === 'checking') return <p className="admin-intro" role="status"><LoaderCircle className="admin-spinner" aria-hidden="true" /> Comprobando el enlace…</p>;
    if (check === 'invalid') return <><span className="admin-eyebrow">Enlace no disponible</span><h1>Este enlace no sirve</h1>
        <p className="admin-intro">{error || 'El enlace no es válido, ya se usó o venció. Pide uno nuevo al administrador.'}</p>
        <a className="admin-primary" href="/admin/login" style={{ textAlign: 'center', textDecoration: 'none' }}>Ir al inicio de sesión</a></>;
    if (check === 'done') return <><span className="admin-eyebrow">Listo</span><h1>Contraseña guardada</h1>
        <p className="admin-intro">Ya puedes entrar al panel con tu nueva contraseña.</p>
        <a className="admin-primary" href="/admin/login" style={{ textAlign: 'center', textDecoration: 'none' }}>Iniciar sesión</a></>;

    return <>
        <span className="admin-eyebrow">Restablecer contraseña</span><h1>Crea tu contraseña</h1>
        <p className="admin-intro">{name ? `${name}, e` : 'E'}lige una contraseña propia para entrar al panel: mínimo {MIN} caracteres. Este enlace solo se puede usar una vez.</p>
        <form onSubmit={submit} noValidate>
            <label className="admin-field-label" htmlFor="recover-password">Nueva contraseña</label>
            <div className="admin-field admin-password"><LockKeyhole className="admin-field-icon" aria-hidden="true" /><input id="recover-password" type={show ? 'text' : 'password'} placeholder="Nueva contraseña" autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} aria-invalid={Boolean(error)} />
                <button type="button" className="admin-password-toggle" onClick={() => setShow(value => !value)} aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'}>{show ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}</button></div><div className="admin-error" aria-hidden="true" />
            <label className="admin-field-label" htmlFor="recover-confirm">Repite la contraseña</label>
            <div className="admin-field"><LockKeyhole className="admin-field-icon" aria-hidden="true" /><input id="recover-confirm" type={show ? 'text' : 'password'} placeholder="Repite la contraseña" autoComplete="new-password" value={confirmation} onChange={event => setConfirmation(event.target.value)} aria-invalid={Boolean(error)} /></div><div className="admin-error" aria-hidden="true" />
            {error && <p className="admin-message" role="alert">{error}</p>}
            <button className="admin-primary" type="submit" disabled={pending}>{pending ? <><LoaderCircle className="admin-spinner" aria-hidden="true" /> Guardando…</> : 'Guardar contraseña'}</button>
        </form>
    </>;
}
