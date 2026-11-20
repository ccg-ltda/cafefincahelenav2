import { Link, useParams, useSearchParams } from 'react-router';
import { whatsappLink } from '../storefront/shop';
import { useEffect, useState } from 'react';
import { MessageCircle, Minus, Plus, ShoppingCart } from 'lucide-react';
import { useStorefront } from '../../context/StorefrontContext';
import ProductImage from './ProductImage';
import { money } from './ProductCard';
import { applyPageMeta } from './pageMeta';
import { formatWeight, generalDescription, isConfigurable, lowStockText, minPrice, productImage, productPath, uniqueValues, untilText, variantNote } from './variant';
import './ProductDetailPage.css';

const BRAND = 'Café Finca Helena';

export default function ProductDetailPage() {
    const { slug } = useParams();
    const [params] = useSearchParams();
    const requestedSku = (params.get('sku') || '').toLowerCase();
    const { catalog, catalogStatus, cart, changeQuantity, setDrawer } = useStorefront();
    const key = `${slug}|${requestedSku}`;
    const [choice, setChoice] = useState({ key, weight: undefined, grind: undefined, quantity: 1 });
    const active = choice.key === key ? choice : { key, weight: undefined, grind: undefined, quantity: 1 };
    useEffect(() => { setChoice({ key, weight: undefined, grind: undefined, quantity: 1 }); }, [key]);

    const product = catalog.find(item => item.slug === slug.toLowerCase());
    const requestedQuantity = active.quantity;

    // Title, description, canonical and Open Graph for client-side navigation (the server renders them on direct visits).
    useEffect(() => {
        if (!product) return undefined;
        const description = generalDescription(product) || `${product.name} de ${BRAND}. Desde ${money(minPrice(product))} COP.`;
        return applyPageMeta({ title: `${product.name} | ${BRAND}`, description: description.length > 155 ? `${description.slice(0, 154)}…` : description, path: productPath(product.slug), image: productImage(product) });
    }, [product]);

    if (catalogStatus === 'loading') return <main className="product-detail-state" role="status">Cargando producto…</main>;
    if (catalogStatus === 'error') return <main className="product-detail-state" role="alert">No se pudo cargar el producto. Comprueba tu conexión e inténtalo de nuevo.</main>;
    if (!product) return <main className="product-detail-state"><h1>Producto no encontrado</h1><Link to="/">Volver a la tienda</Link></main>;

    const configurable = isConfigurable(product);
    const weights = uniqueValues(product.variants, 'weight').sort((a, b) => a - b);
    const grinds = uniqueValues(product.variants, 'grind');
    // Grind is chosen first, then one of the weights that have a published variant for that grind. The variant is the
    // exact (grind, weight) pair; nothing is substituted. Only published variants reach this page, so an inactive
    // combination simply never appears as an option.
    const requested = requestedSku ? product.variants.find(item => item.sku.toLowerCase() === requestedSku) : null;
    const unknownRequest = Boolean(requestedSku) && !requested;
    const touched = active.weight !== undefined || active.grind !== undefined;
    const initial = touched ? null : (requested || (unknownRequest ? null : product.variants[0]));
    const only = (values, current) => current !== undefined ? current : (values.length === 1 ? values[0] : null);
    const selectedWeight = active.weight !== undefined ? active.weight : (initial ? initial.weight : only(weights, undefined));
    const selectedGrind = active.grind !== undefined ? active.grind : (initial ? initial.grind : only(grinds, undefined));
    const variant = product.variants.find(item => (item.weight ?? null) === (selectedWeight ?? null) && (item.grind ?? null) === (selectedGrind ?? null)) || null;
    const grindChosen = grinds.length <= 1 || selectedGrind != null;
    const availableWeights = grindChosen ? weights.filter(weight => product.variants.some(item => item.weight === weight && (grinds.length <= 1 || item.grind === selectedGrind))) : [];
    const unavailableText = !grindChosen ? (unknownRequest && !touched ? 'La presentación solicitada no está disponible. Elige una molienda y un peso disponibles.' : 'Selecciona una molienda para continuar.') : unknownRequest && !touched ? 'La presentación solicitada no está disponible. Elige un peso disponible.' : 'Selecciona un peso disponible para continuar.';
    const selectedLabel = [selectedWeight != null ? formatWeight(selectedWeight) : null, selectedGrind].filter(Boolean).join(' · ');
    // The quantity can never go past the stock that is not already in the cart.
    const inCart = variant ? cart[variant.id] || 0 : 0;
    const soldOut = Boolean(variant) && variant.available <= 0;
    const room = variant ? Math.max(0, variant.available - inCart) : 0;
    const quantity = Math.min(requestedQuantity, Math.max(1, room));
    const select = patch => setChoice({ key, weight: selectedWeight, grind: selectedGrind, quantity, ...patch });
    // Changing grind keeps the weight when that grind has a published variant for it; otherwise it moves to the
    // first (lightest) published weight of the new grind, so the page never holds an invalid combination.
    const pickGrind = grind => {
        const own = weights.filter(weight => product.variants.some(item => item.grind === grind && item.weight === weight));
        select({ grind, weight: own.includes(selectedWeight) ? selectedWeight : (own[0] ?? null) });
    };

    const related = catalog.filter(item => item.id !== product.id).slice(0, 4);
    const whatsapp = whatsappLink(variant ? `Hola, quisiera consultar por ${variant.name}.` : `Hola, quisiera consultar por ${product.name}${selectedLabel ? ` (${selectedLabel})` : ''}.`);
    const cartQuantity = variant ? cart[variant.id] || 0 : 0;
    const canAdd = Boolean(variant) && !soldOut && room >= quantity && catalogStatus === 'ready' && Number.isSafeInteger(variant.id) && variant.id > 0 && Number.isSafeInteger(cartQuantity + quantity);
    const general = generalDescription(product);
    const note = variant ? variantNote(product, variant) : '';
    const description = general || note || (variant ? [variant.grind, variant.weight != null ? formatWeight(variant.weight) : null].filter(Boolean).join(' · ') : '') || product.name;
    const characteristics = [
        ...(variant ? [
            ...(variant.grind ? [['Molienda', variant.grind]] : []),
            ...(variant.weight != null ? [['Peso total', formatWeight(variant.weight)]] : []),
        ] : []),
    ];
    const options = (label, values, current, onPick, format = value => value) => <fieldset className="product-detail-options"><legend>{label}</legend><div>{values.map(value => <button key={value} type="button" aria-pressed={current === value} className={current === value ? 'is-selected' : ''} onClick={() => onPick(value)}>{format(value)}</button>)}</div></fieldset>;

    return <div className="product-detail">
        <nav className="product-detail-crumb" aria-label="Ruta de navegación"><Link to="/">Tienda</Link> / <Link to="/">Café</Link> / {product.name}</nav>
        <main className="product-detail-main">
            <div className="product-detail-image"><ProductImage src={variant ? variant.image : null} alt={variant ? variant.name : product.name} width="360" height="360" /></div>
            <div className="product-detail-info">
                {!configurable && variant?.grind && <span className="product-detail-badge">{variant.grind}</span>}
                <h1>{product.name}</h1>
                {configurable && <p className="product-detail-presentation">{variant ? variant.name : selectedLabel || product.name}</p>}
                {!configurable && variant?.weight != null && <p className="product-detail-presentation">Peso total: {formatWeight(variant.weight)}</p>}
                {configurable && <div className="product-detail-selectors">
                    {grinds.length > 1 && options('Molienda', grinds, selectedGrind, pickGrind)}
                    {weights.length > 1 && options('Peso', availableWeights, selectedWeight, weight => select({ weight }), formatWeight)}
                </div>}
                {variant ? <><div className="product-detail-price">{variant.originalPrice != null && <s className="price-old">{money(variant.originalPrice)}</s>} {money(variant.price)} <small>COP</small>{variant.discountLabel && <span className="fh-discount-badge fh-discount-badge--inline">{variant.discountLabel}</span>}</div>{variant.promotionEndsAt && <p className="product-detail-promo-until">{untilText(variant.promotionEndsAt)}</p>}</> : <p className="product-detail-unavailable" role="status">{unavailableText}</p>}
                {variant && (soldOut ? <p className="product-detail-unavailable" role="status">Agotado</p> : <>{lowStockText(variant.available) && <p className="product-detail-stock-low" role="status">{lowStockText(variant.available)}</p>}{room === 0 && <p className="product-detail-stock-low" role="status">Ya tienes en tu carrito todas las unidades disponibles.</p>}</>)}
                <div className="product-detail-quantity"><span id="product-quantity-label">Cantidad</span><div className="product-detail-stepper" role="group" aria-labelledby="product-quantity-label"><button type="button" aria-label="Reducir cantidad" disabled={quantity <= 1} onClick={() => select({ quantity: quantity - 1 })}><Minus aria-hidden="true" /></button><output aria-live="polite">{quantity}</output><button type="button" aria-label="Aumentar cantidad" disabled={quantity >= room} onClick={() => select({ quantity: quantity + 1 })}><Plus aria-hidden="true" /></button></div></div>
                <div className="product-detail-actions">
                    <button type="button" disabled={!canAdd} onClick={() => { if (!variant) return; changeQuantity(variant.id, cartQuantity + quantity); setDrawer(true); }}><ShoppingCart className="ui-icon" aria-hidden="true" /> {soldOut ? 'Agotado' : `Agregar al carrito${variant ? ` · ${money(variant.price * quantity)}` : ''}`}</button>
                    {whatsapp && <a href={whatsapp} target="_blank" rel="noopener noreferrer"><MessageCircle className="ui-icon" aria-hidden="true" /> Consultar por WhatsApp</a>}
                </div>
                <p className="product-detail-description">{description}</p>{general && note && <p className="product-detail-description"><strong>Esta presentación:</strong> {note}</p>}
                {characteristics.length > 0 && <section className="product-detail-specs"><h2>Características del café</h2><dl>{characteristics.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>}
            </div>
        </main>
        {related.length > 0 && <section className="product-detail-related"><h2>También te puede gustar</h2><div>{related.map(item => <Link key={item.id} to={productPath(item.slug)}><ProductImage src={productImage(item)} alt={item.name} loading="lazy" width="110" height="110" />{item.name}<span>{isConfigurable(item) ? `Desde ${money(minPrice(item))}` : money(item.variants[0].price)}</span></Link>)}</div></section>}
    </div>;
}
