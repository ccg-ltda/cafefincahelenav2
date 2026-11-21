import { useEffect } from 'react';
import { Link } from 'react-router';
import { referenceImage } from '../../components/referenceImages';
import './NotFoundPage.css';

export default function NotFoundPage() {
    useEffect(() => {
        const previous = document.title;
        document.title = 'Página no encontrada | Café Finca Helena';
        return () => { document.title = previous; };
    }, []);
    return <main className="nf-page">
        <div className="nf-art"><img src={referenceImage('error-404.png')} alt="Una caficultora con un mapa en la mano, buscando su camino entre cafetales" /></div>
        <div className="nf-copy">
            <p className="nf-code">Error 404</p>
            <h1>¡Uy! Parece que nos perdimos</h1>
            <p>Esta página no existe o cambió de lugar. Vuelve a la tienda y sigue el aroma del mejor café del Catatumbo.</p>
            <div className="nf-actions">
                <Link className="nf-btn nf-btn-main" to="/">Ir a la tienda</Link>
                <Link className="nf-btn" to="/blog">El Rincón del Café</Link>
            </div>
        </div>
    </main>;
}
