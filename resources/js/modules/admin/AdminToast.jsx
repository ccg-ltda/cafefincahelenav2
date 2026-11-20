import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-react';
import './AdminToast.css';

/**
 * Global admin notifications. One <AdminToastProvider> is mounted in the admin layout (AdminDashboard);
 * any admin module then calls:
 *
 *   const toast = useAdminToast();
 *   toast.success('Producto creado', 'El producto Café Premium se creó correctamente.');
 *   toast.error('No se pudo guardar', 'Inténtalo nuevamente.');
 *   toast.warning(title, message);
 *   toast.info(title, message);
 *
 * Each call takes (title, message, options?) and returns the toast id; `toast.dismiss(id)` closes one.
 * Only call it after the server confirmed the outcome. Durations: success/info 5 s, warning 7 s,
 * error stays until closed (options.duration overrides; 0 = until closed). At most MAX_VISIBLE are shown
 * and the oldest non-error one is replaced when the limit is exceeded. An identical toast fired twice
 * within DUPLICATE_MS (double event) is shown once; a legitimate repeat later is shown again.
 *
 * Sound: success, error and warning play a short synthesized tone (Web Audio, no files); info is silent.
 * There is no sound control and nothing is stored in the browser.
 */
const TYPES = {
    success: { icon: CircleCheck, duration: 5000, role: 'status', live: 'polite' },
    info: { icon: Info, duration: 5000, role: 'status', live: 'polite' },
    warning: { icon: TriangleAlert, duration: 7000, role: 'status', live: 'polite' },
    error: { icon: CircleAlert, duration: 0, role: 'alert', live: 'assertive' },
};
const MAX_VISIBLE = 4;
const DUPLICATE_MS = 800;
const EXIT_MS = 200;
const SOUND_GAP_MS = 450;

// Each note: [frequency Hz, start s, duration s, oscillator type]. Every tone ends before 0.5 s at a low gain.
const SOUNDS = {
    success: [[659, 0, 0.14, 'sine'], [880, 0.12, 0.22, 'sine']],
    error: [[330, 0, 0.16, 'triangle'], [247, 0.15, 0.26, 'triangle']],
    warning: [[523, 0, 0.3, 'sine']],
};
const PEAK_GAIN = 0.09;

const noop = () => null;
const AdminToastContext = createContext({ success: noop, error: noop, warning: noop, info: noop, dismiss: noop });

export const useAdminToast = () => useContext(AdminToastContext);

/** Form-level save failure: validation errors stay inline (returns their message); anything else becomes an error toast. */
export const reportSaveError = (toast, error) => {
    if (error?.fields) return error.message || '';
    toast.error('No se pudieron guardar los cambios', error?.message || 'Inténtalo nuevamente.');
    return '';
};

const reducedMotion = () => Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);


// Plays the tone for a toast type. Never throws: a blocked or missing AudioContext just means silence.
function useToastSound() {
    const context = useRef(null);
    const lastPlayed = useRef(0);
    useEffect(() => () => { context.current?.close?.().catch(() => {}); context.current = null; }, []);
    return useCallback(type => {
        const notes = SOUNDS[type];
        const now = Date.now();
        if (!notes || (now - lastPlayed.current < SOUND_GAP_MS && type !== 'error')) return;
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;
            context.current = context.current || new AudioContextClass();
            const audio = context.current;
            if (audio.state === 'suspended') audio.resume().catch(() => {}); // if autoplay is blocked the tone is simply not heard
            lastPlayed.current = now;
            const start = audio.currentTime + 0.01;
            notes.forEach(([frequency, offset, length, wave]) => {
                const oscillator = audio.createOscillator();
                const gain = audio.createGain();
                oscillator.type = wave;
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0.0001, start + offset);
                gain.gain.exponentialRampToValueAtTime(PEAK_GAIN, start + offset + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + length);
                oscillator.connect(gain).connect(audio.destination);
                oscillator.start(start + offset);
                oscillator.stop(start + offset + length + 0.02);
            });
        } catch { /* audio is optional */ }
    }, []);
}

function Toast({ toast, onClose }) {
    const { icon: Icon } = TYPES[toast.type];
    const timer = useRef(null);
    const left = useRef(toast.duration);
    const startedAt = useRef(0);

    const stop = () => { window.clearTimeout(timer.current); timer.current = null; };
    const start = useCallback(() => {
        if (!left.current || toast.leaving) return;
        stop();
        startedAt.current = Date.now();
        timer.current = window.setTimeout(() => onClose(toast.id), left.current);
    }, [onClose, toast.id, toast.leaving]);
    // Hover/focus pauses the countdown; the CSS progress bar pauses with it and both resume from what is left.
    const pause = () => { if (timer.current) { left.current = Math.max(0, left.current - (Date.now() - startedAt.current)); stop(); } };

    useEffect(() => { start(); return stop; }, [start]);

    return <div className={`admin-toast admin-toast--${toast.type}${toast.leaving ? ' is-leaving' : ''}`} role={TYPES[toast.type].role} aria-live={TYPES[toast.type].live} aria-atomic="true" onMouseEnter={pause} onMouseLeave={start} onFocus={pause} onBlur={start}>
        <span className="admin-toast-badge"><Icon className="admin-toast-icon" aria-hidden="true" /></span>
        <div className="admin-toast-text"><strong>{toast.title}</strong>{toast.message && <p>{toast.message}</p>}</div>
        <button type="button" className="admin-toast-close" aria-label="Cerrar notificación" onClick={() => onClose(toast.id)}><X aria-hidden="true" /></button>
        {toast.duration > 0 && <span className="admin-toast-bar" aria-hidden="true" style={{ animationDuration: `${toast.duration}ms` }} />}
    </div>;
}

export function AdminToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const nextId = useRef(1);
    const recent = useRef(new Map());
    const removers = useRef(new Set());
    const play = useToastSound();

    useEffect(() => () => { removers.current.forEach(window.clearTimeout); removers.current.clear(); }, []);

    const dismiss = useCallback(id => {
        // Mark as leaving so the exit animation plays, then remove it.
        setToasts(current => current.map(item => item.id === id ? { ...item, leaving: true } : item));
        const timeout = window.setTimeout(() => { removers.current.delete(timeout); setToasts(current => current.filter(item => item.id !== id)); }, reducedMotion() ? 0 : EXIT_MS);
        removers.current.add(timeout);
    }, []);

    const push = useCallback((type, title, message = '', options = {}) => {
        const signature = `${type}|${title}|${message}`;
        const now = Date.now();
        if (now - (recent.current.get(signature) || 0) < DUPLICATE_MS) return null;
        recent.current.set(signature, now);
        recent.current.forEach((time, key) => { if (now - time > DUPLICATE_MS) recent.current.delete(key); });
        const id = nextId.current++;
        const toast = { id, type, title, message, duration: options.duration ?? TYPES[type].duration, leaving: false };
        setToasts(current => {
            const live = current.filter(item => !item.leaving);
            const overflow = live.length + 1 - MAX_VISIBLE;
            const drop = overflow > 0 ? new Set([...live.filter(item => item.type !== 'error'), ...live.filter(item => item.type === 'error')].slice(0, overflow).map(item => item.id)) : new Set();
            return [...current.filter(item => !drop.has(item.id)), toast];
        });
        play(type);
        return id;
    }, [play]);

    const api = useMemo(() => ({
        success: (title, message, options) => push('success', title, message, options),
        error: (title, message, options) => push('error', title, message, options),
        warning: (title, message, options) => push('warning', title, message, options),
        info: (title, message, options) => push('info', title, message, options),
        dismiss,
    }), [push, dismiss]);


    return <AdminToastContext.Provider value={api}>
        {children}
        {createPortal(
            <div className="admin-toast-container">{toasts.map(toast => <Toast key={toast.id} toast={toast} onClose={dismiss} />)}</div>
, document.body)}
    </AdminToastContext.Provider>;
}
