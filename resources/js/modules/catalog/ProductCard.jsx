import { useState } from 'react';
import ProductImage from './ProductImage';
import { Link } from 'react-router';
import { Check, Minus, Plus, SlidersHorizontal } from 'lucide-react';
import { useStorefront } from '../../context/StorefrontContext';
import { groupSummary, isConfigurable, isSoldOut, lowStockText, productImage, productPath, promoBadge, untilText, variantSummary } from './variant';

export const money = value => '$' + Math.round(value).toLocaleString('es-CO');

// One card per main product. A configurable product links to its page to choose weight and grind;
// a simple product keeps the direct quantity + add button.
export default function ProductCard({ product }) {
    const { cart, changeQuantity } = useStorefront();
    const configurable = isConfigurable(product);
    const variant = product.variants[0];
    const qty = configurable ? 0 : cart[variant.id] || 0;
    const sub = !configurable ? (variant.grind === 'Grano entero' ? 'Tostado en Grano' : variant.grind) : null;
    const path = productPath(product.slug);
    const soldOut = isSoldOut(product);
    // The quantity picker starts at 1 and stays between 1 and the stock; once the product is in the cart it edits the cart line.
    const [selection, setSelection] = useState(1);
    const shown = qty > 0 ? qty : Math.min(selection, Math.max(1, variant.available));
    const step = delta => { const next = Math.min(variant.available, Math.max(1, shown + delta)); if (qty > 0) changeQuantity(variant.id, next); else setSelection(next); };
    const low = !configurable ? lowStockText(variant.available) : null;
    const cheapest = product.variants.reduce((best, item) => (item.price < best.price ? item : best));
    const badge = promoBadge(product);
    const ends = [...new Set(product.variants.filter(item => item.promotionEndsAt).map(item => item.promotionEndsAt))];
    const until = badge && ends.length === 1 ? untilText(ends[0]) : null;
    return <article className={`card${soldOut ? ' is-sold-out' : ''}`} id={`producto-${product.id}`}><div className="card-img"><Link to={path} aria-label={`Ver ${product.name}`} style={{width:'100%', height:'100%'}}><ProductImage src={productImage(product)} alt={`Café Finca Helena ${product.name}`} loading="lazy" width="400" height="400" /></Link>{soldOut && <div className="card-sold-badge">Agotado</div>}{badge && <div className="fh-discount-badge">{badge}</div>}{sub && <div className="card-sub-badge" style={sub === 'Tostado en Grano' ? {background:'#3B1F0E'} : undefined}>{sub}</div>}</div>
        <div className="card-body"><h3><Link to={path}>{product.name}</Link></h3><div className="card-pres">{configurable ? 'Elige peso y molienda' : variant.grind}</div><p className="card-desc">{configurable ? groupSummary(product) : variantSummary(variant)}</p><div className="card-price">{configurable ? <>Desde {cheapest.originalPrice != null && <s className="price-old">{money(cheapest.originalPrice)}</s>} {money(cheapest.price)}</> : <>{variant.originalPrice != null && <s className="price-old">{money(variant.originalPrice)}</s>} {money(variant.price)}</>}</div>{until && <p className="card-promo-until">{until}</p>}{low && <p className="card-stock-low" role="status">{low}</p>}
        {soldOut
            ? <div className="card-actions"><button type="button" className="add-btn" disabled>Agotado</button></div>
            : configurable
            ? <div className="card-actions"><Link className="add-btn" to={path}><SlidersHorizontal className="ui-icon" aria-hidden="true" /> Elegir mi café</Link></div>
            : <div className="card-actions"><div className="qty-stepper"><button type="button" aria-label={`Reducir cantidad de ${product.name}`} disabled={shown <= 1} onClick={() => step(-1)}><Minus className="ui-icon" aria-hidden="true" /></button><span>{shown}</span><button type="button" aria-label={`Aumentar cantidad de ${product.name}`} disabled={shown >= variant.available} onClick={() => step(1)}><Plus className="ui-icon" aria-hidden="true" /></button></div><button type="button" className={`add-btn ${qty ? 'added' : ''}`} onClick={() => { if (!qty) changeQuantity(variant.id, shown); }}>{qty ? <>Agregado <Check className="ui-icon" aria-hidden="true" /></> : 'Agregar'}</button></div>}</div></article>;
}
