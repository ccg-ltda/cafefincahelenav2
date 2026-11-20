import { useEffect, useRef, useState } from 'react';
import { CalendarCheck, UserCog, BadgePercent, BarChart3, CircleDollarSign, ClipboardList, Clock3, CreditCard, History, LayoutDashboard, LogOut, MapPin, Menu, MessageCircle, Package, ShoppingBag, Ticket, UserRound, Users, X } from 'lucide-react';
import './AdminDashboard.css';
import AdminProducts from './AdminProducts';
import AdminCategories from './AdminCategories';
import AdminPromotions from './AdminPromotions';
import AdminCoupons from './AdminCoupons';
import AdminCustomers from './AdminCustomers';
import AdminWhatsApp from './AdminWhatsApp';
import AdminActivity from './AdminActivity';
import AdminOrders from './AdminOrders';
import AdminUsers from './AdminUsers';
import AdminToday from './AdminToday';
import { setOrderStatuses } from './orderStatuses';
import NoAccess from './NoAccess';
import { adminPagePermission } from '../../routes';
import { AdminToastProvider } from './AdminToast';
import StatusBadge, { statusLabel } from './StatusBadge';

// Menu entries: each one shows only to people whose role has its permission (the server enforces the same list).
const navigation = [
    { label: 'Hoy', icon: CalendarCheck, href: '/admin/today', permission: 'today.view' },
    { label: 'Dashboard', icon: LayoutDashboard, href: '/admin', permission: 'dashboard.view', exact: true },
    { label: 'Pedidos', icon: ShoppingBag, href: '/admin/orders', permission: 'orders.list' },
    { label: 'Clientes', icon: Users, href: '/admin/customers', permission: 'customers.view' },
    { label: 'WhatsApp', icon: MessageCircle, href: '/admin/whatsapp', permission: 'whatsapp.manage' },
    { label: 'Productos', icon: Package, href: '/admin/products', permission: 'products.manage' },
    { label: 'Promociones', icon: BadgePercent, href: '/admin/promotions', permission: 'promotions.manage' },
    { label: 'Cupones', icon: Ticket, href: '/admin/coupons', permission: 'coupons.manage' },
    { label: 'Usuarios', icon: UserCog, href: '/admin/users', permission: 'users.manage' },
    { label: 'Historial', icon: History, href: '/admin/activity', permission: 'activity.view' },
];

const indicators = [
    { label: 'Ventas pagadas', icon: CircleDollarSign, key: 'paid_sales_cop', money: true },
    { label: 'Pedidos pagados', icon: ShoppingBag, key: 'paid_orders' },
    { label: 'Ticket promedio', icon: CreditCard, key: 'average_ticket_cop', money: true },
    { label: 'Clientes nuevos', icon: Users, key: 'new_customers' },
];

const number = value => new Intl.NumberFormat('es-CO').format(value);
const money = value => `$ ${number(value)}`;

function EmptyPanel({ title, icon: Icon, className = '', data, state, currency = false, daily = false, tasks = false }) {
    const available = state === 'success' && data?.length > 0;
    const max = available ? Math.max(1, ...data.map(item => item.value)) : 1;
    return <section className={`dashboard-card dashboard-chart ${className}`}>
        <h2>{title}</h2>
        {available ? daily ? <div className="dashboard-series" role="img" aria-label={data.map(item => `${item.label}: ${money(item.value)}`).join('; ')}><svg viewBox={`0 0 ${data.length * 10} 100`} preserveAspectRatio="none" aria-hidden="true">{data.map((item, index) => <rect key={item.label} x={index * 10 + 1} y={100 - item.value / max * 96} width="8" height={item.value / max * 96} rx="1" />)}</svg><div><span>{data[0].label}</span><span>{data[data.length - 1].label}</span></div></div>
            : <ul className="dashboard-data-list">{data.map(item => <li key={item.label}><div><span>{tasks ? (item.label === 'pending' ? 'Pedidos pendientes' : 'Pedidos en preparación') : statusLabel(`order_${item.label}`)}</span><strong>{currency ? money(item.value) : number(item.value)}</strong></div><span className="dashboard-bar" aria-hidden="true"><span style={{ width: `${item.value / max * 100}%` }} /></span></li>)}</ul>
            : <div className="dashboard-empty"><span className="dashboard-empty-icon"><Icon aria-hidden="true" /></span><p>{state === 'loading' ? 'Cargando información…' : state === 'error' ? 'No se pudo cargar la información' : 'Sin datos disponibles'}</p><span>{state === 'error' ? 'Intenta nuevamente.' : state === 'success' ? (tasks ? 'No hay tareas pendientes.' : 'No hay registros para este período.') : ''}</span></div>}
    </section>;
}

export default function AdminDashboard({ session, message, pending, logout }) {
    setOrderStatuses(session?.order_statuses); // labels of the order states come from the server (OrderStatusService::LABELS)
    const name = session?.name;
    const can = permission => !permission || Boolean(session?.permissions?.includes(permission));
    const required = adminPagePermission(window.location.pathname);
    const allowed = Boolean(session) && can(required); // the server answers 403 too: this only decides what React draws
    const isUsers = /^\/admin\/users(\/\d+)?$/.test(window.location.pathname);
    const isToday = window.location.pathname === '/admin/today';
    const isDashboard = window.location.pathname === '/admin';
    const isCategories = window.location.pathname === '/admin/products/categories';
    const isProducts = isCategories || window.location.pathname === '/admin/products';
    const isPromotions = window.location.pathname === '/admin/promotions';
    const isCoupons = window.location.pathname === '/admin/coupons';
    const isCustomers = /^\/admin\/customers(\/\d+)?$/.test(window.location.pathname);
    const isWhatsApp = /^\/admin\/whatsapp(\/\d+)?$/.test(window.location.pathname);
    const isActivity = /^\/admin\/activity(\/\d+)?$/.test(window.location.pathname);
    const isOrders = window.location.pathname === '/admin/orders' || /^\/admin\/orders\/\d+$/.test(window.location.pathname);
    const [period, setPeriod] = useState(30);
    const [menuOpen, setMenuOpen] = useState(false);
    const [summary, setSummary] = useState(null);
    const [loadState, setLoadState] = useState('loading');
    const [retry, setRetry] = useState(0);
    const menuButton = useRef(null);

    useEffect(() => {
        if (!isDashboard || !allowed) return undefined; // the summary is only requested on the dashboard, by someone allowed to see it
        const controller = new AbortController();
        setLoadState('loading');
        setSummary(null);
        fetch(`/admin/dashboard/summary?days=${period}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then(async response => {
                if (response.status === 401 || response.redirected) { window.location.replace('/admin/login'); return null; }
                if (!response.ok) throw new Error('summary');
                return response.json();
            }).then(data => { if (data) { setSummary(data); setLoadState('success'); } })
            .catch(error => { if (error.name !== 'AbortError') setLoadState('error'); });
        return () => controller.abort();
    }, [period, retry, isDashboard, allowed]);

    useEffect(() => {
        if (!menuOpen) return undefined;
        const onKeyDown = event => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                menuButton.current?.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [menuOpen]);

    return <AdminToastProvider><div className="dashboard-shell">
        <header className="dashboard-mobile-header">
            <a href={session?.home || '/admin'} className="dashboard-mobile-brand"><img src="/apple-touch-icon.png" alt="" /><span>Finca Helena</span></a>
            <button ref={menuButton} type="button" className="dashboard-menu-button" aria-label={menuOpen ? 'Cerrar menú' : 'Abrir menú'} aria-expanded={menuOpen} aria-controls="dashboard-sidebar" onClick={() => setMenuOpen(open => !open)}>{menuOpen ? <X /> : <Menu />}</button>
        </header>
        {menuOpen && <button className="dashboard-backdrop" type="button" aria-label="Cerrar menú" onClick={() => { setMenuOpen(false); menuButton.current?.focus(); }} />}
        <aside id="dashboard-sidebar" className={`dashboard-sidebar${menuOpen ? ' is-open' : ''}`}>
            <div className="dashboard-sidebar-top">
                <div className="dashboard-brand"><img src="/apple-touch-icon.png" alt="" /><div><strong>Finca Helena</strong><span>Panel administrativo</span></div></div>
                <nav aria-label="Navegación administrativa">
                    {navigation.filter(item => can(item.permission)).map(({ label, icon: Icon, href, exact }) => {
                        const path = window.location.pathname;
                        const active = exact ? path === href : path === href || path.startsWith(`${href}/`);
                        return <a key={label} className={`dashboard-nav-item${active ? ' is-active' : ''}`} href={href} aria-current={active ? 'page' : undefined} onClick={() => setMenuOpen(false)}><Icon aria-hidden="true" /><span>{label}</span></a>;
                    })}
                </nav>
            </div>
            <div className="dashboard-account"><div className="dashboard-account-name"><span className="dashboard-avatar"><UserRound aria-hidden="true" /></span><div><span>{session?.role_label || 'Panel'}</span><strong>{name || (message ? 'Sesión no disponible' : 'Consultando sesión…')}</strong></div></div>{message && <p role="alert" className="dashboard-session-error">{message}</p>}<button type="button" onClick={logout} disabled={pending}><LogOut aria-hidden="true" />{pending ? 'Cerrando sesión…' : 'Cerrar sesión'}</button></div>
        </aside>
        <main className="dashboard-main">
            <div className="dashboard-content">
                {!session ? <p className="dashboard-data-note" role="status">Cargando…</p> : !allowed ? <NoAccess home={session.home} /> : isUsers ? <AdminUsers me={session} /> : isToday ? <AdminToday session={session} /> : isActivity ? <AdminActivity /> : isWhatsApp ? <AdminWhatsApp /> : isCustomers ? <AdminCustomers /> : isCoupons ? <AdminCoupons /> : isPromotions ? <AdminPromotions /> : isOrders ? <AdminOrders session={session} /> : isCategories ? <AdminCategories /> : isProducts ? <AdminProducts /> : <>
                <header className="dashboard-heading"><div><span className="dashboard-eyebrow">Vista general</span><h1>Dashboard</h1><p>Indicadores de los últimos {period} días</p></div><div className="dashboard-period"><span id="dashboard-period-label">Período</span><div role="group" aria-labelledby="dashboard-period-label">{[7, 30, 90].map(days => <button key={days} type="button" aria-pressed={period === days} onClick={() => setPeriod(days)}>{days} días</button>)}</div></div></header>
                <p className="dashboard-data-note" role="status">{loadState === 'loading' ? 'Cargando indicadores…' : loadState === 'error' ? <>No se pudieron cargar los indicadores. <button type="button" className="dashboard-retry" onClick={() => setRetry(value => value + 1)}>Reintentar</button></> : 'Ventas de pagos aprobados y verificados · COP'}</p>
                <section className="dashboard-kpis" aria-label="Indicadores principales">{indicators.map(({ label, icon: Icon, key, money: isMoney }) => <article className="dashboard-card dashboard-kpi" key={label}><div className="dashboard-kpi-heading"><h2>{label}</h2><span><Icon aria-hidden="true" /></span></div><p>{loadState === 'success' ? (isMoney ? money(summary.metrics[key]) : number(summary.metrics[key])) : loadState === 'loading' ? 'Cargando…' : 'No disponible'}</p></article>)}</section>
                <div className="dashboard-charts">
                    <EmptyPanel title="Ventas por día" icon={BarChart3} className="dashboard-chart-wide" data={summary?.sales_by_day} state={loadState} daily />
                    <EmptyPanel title="Pedidos pagados por estado" icon={ClipboardList} data={summary?.orders_by_status} state={loadState} />
                    <EmptyPanel title="Pagos con Wompi" icon={CreditCard} data={summary?.wompi_payments} state={loadState} />
                    <EmptyPanel title="Productos más vendidos" icon={Package} data={summary?.top_products} state={loadState} />
                    <EmptyPanel title="Ventas por ciudad" icon={MapPin} data={summary?.sales_by_city} state={loadState} currency />
                </div>
                <div className="dashboard-operations"><section className="dashboard-card dashboard-orders"><h2>Pedidos recientes</h2><div className="dashboard-table-scroll"><table><thead><tr><th scope="col">Referencia</th><th scope="col">Cliente</th><th scope="col">Total</th><th scope="col">Pago</th><th scope="col">Estado</th></tr></thead><tbody>{loadState === 'success' && summary.recent_orders.length ? summary.recent_orders.map(order => <tr className="dashboard-order-row" key={order.reference}><td>{order.reference}</td><td>{order.customer_name}</td><td>{money(order.total_cop)}</td><td><StatusBadge value={order.payment_status} /></td><td><StatusBadge value={`order_${order.status}`} /></td></tr>) : <tr><td colSpan="5">{loadState === 'loading' ? 'Cargando pedidos…' : loadState === 'error' ? 'No se pudieron cargar los pedidos.' : 'Sin pedidos en este período.'}</td></tr>}</tbody></table></div></section><EmptyPanel title="Para atender hoy" icon={Clock3} className="dashboard-tasks" data={summary?.tasks} state={loadState} tasks /></div>
                </>}
            </div>
        </main>
    </div></AdminToastProvider>;
}
