import { ShieldAlert } from 'lucide-react';
import './AdminProducts.css';
import './AdminUsers.css';

// Shown (with HTTP 403 from the server) when someone opens a page their role does not include.
export default function NoAccess({ home }) {
    return <section className="admin-products admin-noaccess">
        <div className="dashboard-card admin-noaccess-card" role="alert">
            <span className="admin-noaccess-icon"><ShieldAlert aria-hidden="true" /></span>
            <h1>No tienes acceso a esta sección</h1>
            <p>Tu rol no incluye esta página. Si crees que es un error, pídele a un administrador que revise tus permisos.</p>
            <a className="admin-categories-primary admin-noaccess-link" href={home || '/admin'}>Ir a mi inicio</a>
        </div>
    </section>;
}
