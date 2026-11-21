import { useEffect, useState } from 'react';
import { Eye, EyeOff, LoaderCircle, LockKeyhole, LogOut, Mail } from 'lucide-react';
import logo from '../../../images/reference/logo.png';
import loginPhoto from '../../../images/reference/blog-1.webp';
import './AdminPage.css';
import AdminDashboard from './AdminDashboard';
import ChangePasswordForm from './AdminChangePassword';
import RecoverPasswordForm from './AdminRecoverPassword';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const requestOptions = { headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin' };

export default function AdminPage() {
    const isLogin = window.location.pathname === '/admin/login';
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [pending, setPending] = useState(false);
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [session, setSession] = useState(null); // who is signed in and what they may do (GET /admin/session)
    const isChange = window.location.pathname === '/admin/change-password';
    const isRecover = window.location.pathname === '/admin/recover-password'; // public: the link is the credential
    const isLoginShell = isLogin || isChange || isRecover;
    const name = session?.name || '';

    useEffect(() => {
        if (isLogin || isRecover) return;
        const controller = new AbortController();
        fetch('/admin/session', { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then(async response => {
                if (response.status === 401) { window.location.replace('/admin/login'); return; }
                if (!response.ok) throw new Error();
                setSession(await response.json());
            }).catch(error => { if (error.name !== 'AbortError') setMessage('No se pudo consultar la sesión.'); });
        return () => controller.abort();
    }, [isLogin, isRecover]);

    async function submit(event) {
        event.preventDefault();
        if (pending) return;
        setPending(true); setErrors({}); setMessage('');
        try {
            const response = await fetch('/admin/login', { ...requestOptions, method: 'POST', headers: { ...requestOptions.headers, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ login: email.trim(), password }) });
            const data = await response.json().catch(() => ({}));
            if (response.ok) { window.location.assign(data.must_change_password ? '/admin/change-password' : (data.home || '/admin')); return; }
            if (response.status === 422) setErrors(data.errors || {});
            else setMessage(response.status === 429 ? 'Demasiados intentos. Inténtalo de nuevo más tarde.' : 'No fue posible iniciar sesión. Inténtalo de nuevo.');
        } catch { setMessage('No fue posible conectar. Comprueba tu conexión e inténtalo de nuevo.'); }
        setPending(false);
    }

    async function logout() {
        if (pending) return;
        setPending(true); setMessage('');
        try {
            const response = await fetch('/admin/logout', { ...requestOptions, method: 'POST', headers: { ...requestOptions.headers, 'X-CSRF-TOKEN': csrf() } });
            if (response.ok || response.status === 401) { window.location.replace('/admin/login'); return; }
            throw new Error();
        } catch { setMessage('No fue posible cerrar la sesión. Inténtalo de nuevo.'); setPending(false); }
    }

    if (!isLoginShell) return <AdminDashboard session={session} message={message} pending={pending} logout={logout} />;

    return <main className={`admin-shell${isLoginShell ? ' admin-shell--login' : ''}`}>
        <aside className="admin-brand-panel"><div className="admin-brand"><img src={isLoginShell ? '/apple-touch-icon.png' : logo} alt="" /><div><strong>Finca Helena</strong><span>Panel administrativo</span></div></div><div className="admin-brand-copy"><h2>Café de origen, gestión cercana.</h2><p>Un espacio para la administración de Café Finca Helena.</p></div>{isLoginShell && <div className="admin-login-photo" aria-hidden="true"><img src={loginPhoto} alt="" /></div>}</aside>
        <div className="admin-content"><div className="admin-form-wrap">{isLoginShell ? <div className="admin-login-brand"><img src="/apple-touch-icon.png" alt="" /><div><strong>Finca Helena</strong><span>Panel administrativo</span></div></div> : <div className="admin-mobile-brand"><img src={logo} alt="" /><strong>Finca Helena</strong><span>Panel administrativo</span></div>}
            {isRecover ? <RecoverPasswordForm /> : isChange ? <ChangePasswordForm session={session} /> : isLogin ? <><span className="admin-eyebrow">Acceso administrativo</span><h1>Bienvenido de nuevo</h1><p className="admin-intro">Ingresa tus credenciales para continuar al panel administrativo.</p>
                <form onSubmit={submit} noValidate><label className="admin-field-label" htmlFor="admin-email">Usuario o correo</label><div className="admin-field"><Mail className="admin-field-icon" aria-hidden="true" /><input id="admin-email" type="text" placeholder="Usuario o correo" autoCapitalize="none" spellCheck={false} autoComplete="username" value={email} onChange={event => setEmail(event.target.value)} aria-invalid={!!errors.login} aria-describedby={errors.login ? 'admin-email-error' : undefined} /></div><div className="admin-error" id="admin-email-error">{errors.login?.[0]}</div>
                    <label className="admin-field-label" htmlFor="admin-password">Contraseña</label><div className="admin-field admin-password"><LockKeyhole className="admin-field-icon" aria-hidden="true" /><input id="admin-password" type={showPassword ? 'text' : 'password'} placeholder="Contraseña" autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} aria-invalid={!!errors.password} aria-describedby={errors.password ? 'admin-password-error' : undefined} /><button type="button" aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={showPassword} onClick={() => setShowPassword(value => !value)}>{showPassword ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}</button></div><div className="admin-error" id="admin-password-error">{errors.password?.[0]}</div>
                    {message && <p className="admin-message" role="alert">{message}</p>}<button className="admin-primary" type="submit" disabled={pending}>{pending ? <><LoaderCircle className="admin-spinner" aria-hidden="true" /> Procesando…</> : 'Iniciar sesión'}</button>
                </form><p className="admin-access-note">Acceso exclusivo para el equipo autorizado</p></> : <><span className="admin-eyebrow">Panel administrativo</span><h1>Acceso autorizado</h1>{name && <p className="admin-intro">Bienvenido, {name}.</p>}{message && <p className="admin-message" role="alert">{message}</p>}<button className="admin-primary" type="button" onClick={logout} disabled={pending}><LogOut aria-hidden="true" /> {pending ? 'Cerrando sesión…' : 'Cerrar sesión'}</button></>}
        </div></div>
    </main>;
}
