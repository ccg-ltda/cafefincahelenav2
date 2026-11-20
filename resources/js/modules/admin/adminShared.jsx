// Display only (the stored name is never changed): "maría GONZÁLEZ" -> "María González".
export const titleCase = value => String(value ?? '').toLocaleLowerCase('es').replace(/(^|[\s-])(\p{L})/gu, (_, before, letter) => before + letter.toLocaleUpperCase('es'));

// Name of whoever did something in the Historial: "Carlos Rojas · Solo entrega" keeps the role as written, an email stays as is.
export const actorText = actor => (!actor ? '—' : actor.includes('@') ? actor : actor.includes(' · ') ? `${titleCase(actor.split(' · ')[0])} · ${actor.split(' · ').slice(1).join(' · ')}` : titleCase(actor));

// Page buttons: first, last and the neighbours of the current page, with "…" (a string key) for the gaps.
export function pageList(current, last) {
    const numbers = [...new Set([1, last, current - 1, current, current + 1])].filter(value => value >= 1 && value <= last).sort((a, b) => a - b);
    return numbers.flatMap((value, index) => (index > 0 && value - numbers[index - 1] > 1 ? [`gap-${value}`, value] : [value]));
}

export const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

export async function request(url, options = {}) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...options.headers };
    const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    if (response.status === 401 || response.redirected) { window.location.replace('/admin/login'); throw new Error('Sesión expirada.'); }
    if (response.status === 419) { const error = new Error('La sesión o el token de seguridad vencieron. Recarga la página e inicia sesión de nuevo si es necesario.'); error.confirmedRejected = true; throw error; }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { const error = new Error(response.status === 422 ? 'Revisa los campos señalados.' : response.status === 409 && data.message ? data.message : 'No fue posible guardar o consultar la información.'); error.fields = data.errors || {}; error.status = response.status; error.currentStock = data.current_stock; error.confirmedRejected = [422, 403, 404, 409].includes(response.status); throw error; }
    return data;
}

export function Field({ label, name, value, onChange, errors, children, ...props }) {
    const id = `${props.prefix}-${name}`;
    delete props.prefix;
    return <label className="admin-editor-field" htmlFor={id}><span>{label}</span>{children || <input id={id} name={name} value={value ?? ''} onChange={onChange} aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `${id}-error` : undefined} {...props} />}{errors[name] && <small id={`${id}-error`} role="alert">{errors[name][0]}</small>}</label>;
}

// "19 nov", "19 nov 2026" in Colombian time.
export function dayText(iso, withYear) {
    const parts = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'America/Bogota' }).formatToParts(new Date(iso));
    const get = type => parts.find(part => part.type === type).value;
    return `${get('day')} ${get('month').replace('.', '')}${withYear ? ` ${get('year')}` : ''}`;
}
const yearOf = iso => new Intl.DateTimeFormat('es-CO', { year: 'numeric', timeZone: 'America/Bogota' }).format(new Date(iso));
export function validity(row) {
    if (row.starts_at && row.ends_at) return yearOf(row.starts_at) === yearOf(row.ends_at) ? `${dayText(row.starts_at)} – ${dayText(row.ends_at, true)}` : `${dayText(row.starts_at, true)} – ${dayText(row.ends_at, true)}`;
    if (row.starts_at) return `Desde ${dayText(row.starts_at)}`;
    if (row.ends_at) return `Hasta ${dayText(row.ends_at)}`;
    return 'Sin fecha límite';
}

// Calendar days between today and `iso`, in Colombian time: "hoy", "ayer", "hace 9 días".
const bogotaDay = date => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(date);
export function daysAgoText(iso) {
    const days = Math.round((Date.parse(bogotaDay(new Date())) - Date.parse(bogotaDay(new Date(iso)))) / 86400000);
    return days <= 0 ? 'hoy' : days === 1 ? 'ayer' : `hace ${days} días`;
}

// wa.me link with the first message already written. encodeURIComponent turns accents, ¡ ¿ and emojis into their
// UTF-8 percent sequences, so WhatsApp receives them intact.
export const whatsappChatUrl = (phone, text) => `https://wa.me/57${phone}?text=${encodeURIComponent(text)}`;

export function whatsappUrl(customer) {
    return whatsappChatUrl(customer.phone, `Hola ${titleCase(customer.name).split(' ')[0]}, te escribimos de Café Finca Helena.`);
}

// "hace 5 min", "hace 3 horas", "hace 2 días".
export function timeAgoText(iso) {
    const minutes = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60000));
    if (minutes < 1) return 'hace un momento';
    if (minutes < 60) return `hace ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `hace ${hours} ${hours === 1 ? 'hora' : 'horas'}`;
    const days = Math.floor(hours / 24);
    return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
}

// WhatsApp module: topics shared by the page, the form and the drawer.
export const TOPICS = { wholesale: 'Mayorista', order_support: 'Soporte', buying_help: 'Ayuda para comprar' };
export const TopicPill = ({ topic }) => <span className={`admin-wa-topic is-${topic}`}>{TOPICS[topic]}</span>;
export const formatPhone = phone => String(phone).replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3');
