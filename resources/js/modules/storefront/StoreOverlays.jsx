import { lazy, Suspense, useEffect, useState } from 'react';
import { Coffee, FileText, Minus, Plus, RotateCcw, ScrollText, ShoppingCart, Tags, Truck, X } from 'lucide-react';
import { useStorefront } from '../../context/StorefrontContext';
import ProductImage from '../catalog/ProductImage';
import { untilText } from '../catalog/variant';
import { money } from '../catalog/ProductCard';
import content from '../content/referenceContent.json';
const CheckoutModal = lazy(() => import('./CheckoutModal')); // only downloaded when the shopper starts the checkout

export default function StoreOverlays() {
    const { items, changeQuantity, cartNotice, dismissCartNotice, drawer, setDrawer, checkout, setCheckout, policy, setPolicy } = useStorefront();
    const policyIcons = { envio: Truck, reembolso: RotateCcw, privacidad: ScrollText, terminos: FileText };
    const PolicyIcon = policyIcons[policy];
    useEffect(() => { const close = e => {if (e.key === 'Escape') {setDrawer(false);setCheckout(false);setPolicy(null);}}; window.addEventListener('keydown',close); return () => window.removeEventListener('keydown',close); }, [setDrawer,setCheckout,setPolicy]);
    const total = items.reduce((sum,p) => sum + p.price * p.quantity, 0);
    // Only a display of the discounts the server already applied to each price.
    const savings = items.reduce((sum,p) => sum + ((p.originalPrice ?? p.price) - p.price) * p.quantity, 0);
    
    return <>
        {policy && <div className="policy-overlay open" onClick={e => {if(e.target === e.currentTarget)setPolicy(null);}}><div className="policy-modal" role="dialog" aria-modal="true" aria-labelledby="policy-title"><div className="policy-modal-head"><h3 id="policy-title">{PolicyIcon && <PolicyIcon className="ui-icon" aria-hidden="true" />} {content.policies[policy].title.replace(/^\S+\s+/, '')}</h3><button className="policy-modal-close" onClick={() => setPolicy(null)} aria-label="Cerrar"><X className="ui-icon" aria-hidden="true" /></button></div><div className="policy-modal-body"><p className="preview-note">Contenido de la referencia, pendiente de validación comercial.</p><div dangerouslySetInnerHTML={{__html:content.policies[policy].body}} /></div></div></div>}
        <div className={`overlay ${drawer ? 'open' : ''}`} onClick={() => setDrawer(false)} />
        <aside className={`drawer ${drawer ? 'open' : ''}`} aria-label="Tu pedido" aria-hidden={!drawer} inert={!drawer}>
            <div className="drawer-head"><h3>Tu pedido <Coffee className="ui-icon" aria-hidden="true" /></h3><button className="drawer-close" onClick={() => setDrawer(false)} aria-label="Cerrar carrito"><X className="ui-icon" aria-hidden="true" /></button></div>
            <div className="drawer-body">
                {items.length === 0 ? <div className="drawer-empty">Tu carrito está vacío</div> : items.map(p => {
                    const presentation = p.variant && !p.name.toLocaleLowerCase('es').includes(p.variant.toLocaleLowerCase('es')) ? p.variant : null;
                    return <div className="drawer-item" key={p.id}>
                        <div className="di-img"><ProductImage src={p.image} alt="" /></div>
                        <div className="di-info">
                            <div className="di-heading"><strong>{p.name}</strong><button className="di-remove" onClick={() => changeQuantity(p.id,0)} aria-label={`Eliminar ${p.name}`}><X className="ui-icon" aria-hidden="true" /></button></div>
                            <div className="di-pres">{presentation && <span>{presentation}</span>}</div>
                            <div className="di-unit">Precio unitario: {p.originalPrice != null && <s className="price-old">{money(p.originalPrice)}</s>} {money(p.price)}</div>{p.discountLabel && <div className="di-promo">{p.discountLabel}{p.promotionEndsAt ? ` · ${untilText(p.promotionEndsAt)}` : ''}</div>}{p.available > 0 && p.available <= 5 && <div className="di-stock-low">¡Solo quedan {p.available}!</div>}
                            <div className="di-actions">
                                <div className="qty-stepper"><button onClick={() => changeQuantity(p.id,p.quantity-1)} aria-label={`Reducir ${p.name}`}><Minus className="ui-icon" aria-hidden="true" /></button><span>{p.quantity}</span><button disabled={p.quantity >= p.available} onClick={() => changeQuantity(p.id,p.quantity+1)} aria-label={`Aumentar ${p.name}`}><Plus className="ui-icon" aria-hidden="true" /></button></div>
                                <div className="di-line-total"><span>Subtotal</span><strong className="di-price">{money(p.price*p.quantity)}</strong></div>
                            </div>
                        </div>
                    </div>;
                })}
            </div>
            <div className="drawer-foot"><div className="drawer-total"><span>Subtotal</span><span>{money(total)}</span></div>{savings > 0 && <div className="drawer-savings"><span>Ahorras</span><span>{money(savings)}</span></div>}<button className="checkout-btn" disabled={!items.length} onClick={() => {setDrawer(false);setCheckout(true);}}><ShoppingCart className="ui-icon" aria-hidden="true" /> Continuar con el pedido</button><div className="checkout-note">Tu pedido queda registrado y te contactamos para coordinar el pago.</div><p className="preview-note">El pago en línea aún no está disponible.</p></div>
        </aside>
        {cartNotice.length > 0 && <div className="stock-notice" role="alert"><div><strong>Actualizamos tu carrito</strong><ul>{cartNotice.map(message => <li key={message}>{message}</li>)}</ul></div><button type="button" onClick={dismissCartNotice}>Entendido</button></div>}
        {checkout && <Suspense fallback={null}><CheckoutModal items={items} onClose={() => setCheckout(false)} onRegistered={() => items.forEach(item => changeQuantity(item.id, 0))} onFix={fixes => fixes.forEach(fix => changeQuantity(fix.id, fix.quantity))} /></Suspense>}
    </>;
}
