import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, ArrowRight, Headset, Mail, MapPin, MessageCircle, Search, ShoppingBag, ShoppingCart, Sparkles, Store, X } from 'lucide-react';
import { Link, Outlet, useLocation } from 'react-router';
import { referenceImage } from '../components/referenceImages';
import { useStorefront } from '../context/StorefrontContext';
import StoreOverlays from '../modules/storefront/StoreOverlays';
import { layoutFor, metaFor } from '../routes';
import { applyStaticMeta } from '../modules/catalog/pageMeta';
import { lastOrderReference, nationalNumber, shopWhatsapp, trackWhatsappClick, whatsappLink } from '../modules/storefront/shop';
import { scrollToCatalog } from '../modules/catalog/scrollToCatalog';

function Brand() { return <Link className="logo-wrap" to="/"><span className="logo-mark" style={{background:'#F7E8D2',padding:0}}><img src={referenceImage('logo.png')} alt="Finca Helena Café" style={{width:'100%',height:'100%',objectFit:'contain',borderRadius:'50%'}} /></span><span className="logo-text">Finca Helena <small>Café · Norte de Santander</small></span></Link>; }
function SocialIcon({name}) { return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{name === 'Instagram' ? <><rect x="2" y="2" width="20" height="20" rx="5" /><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" /><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" /></> : name === 'Facebook' ? <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" /> : name === 'LinkedIn' ? <><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z" /><rect x="2" y="9" width="4" height="12" /><circle cx="4" cy="4" r="2" /></> : <path fill="currentColor" stroke="none" d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.32 6.32 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.17 8.17 0 0 0 4.78 1.52V6.77a4.85 4.85 0 0 1-1.01-.08z" />}</svg>; }
function WhatsAppIcon() { return <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22" aria-hidden="true"><path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.1 1.523 5.824L.057 23.27a.75.75 0 0 0 .922.921l5.344-1.434A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 1.5c5.799 0 10.5 4.701 10.5 10.5 0 5.8-4.701 10.5-10.5 10.5-1.94 0-3.757-.527-5.315-1.447a.75.75 0 0 0-.576-.079l-4.243 1.139 1.161-4.335a.75.75 0 0 0-.084-.583A10.456 10.456 0 0 1 1.5 12C1.5 6.201 6.201 1.5 12 1.5zm-3.3 5.25c-.22 0-.576.083-.878.408-.302.325-1.153 1.126-1.153 2.746 0 1.62 1.18 3.187 1.344 3.408.165.22 2.295 3.666 5.659 4.993 2.797 1.103 3.365.883 3.972.828.606-.055 1.956-.8 2.231-1.572.275-.771.275-1.433.193-1.57-.083-.138-.303-.22-.633-.385-.33-.165-1.956-.965-2.259-1.075-.303-.11-.523-.165-.744.165-.22.33-.853 1.075-.963 1.296-.11.22-.22.247-.55.083-.33-.165-1.394-.514-2.656-1.638-.982-.875-1.645-1.955-1.838-2.285-.192-.33-.02-.508.145-.672.148-.148.33-.385.495-.578.165-.193.22-.33.33-.55.11-.22.055-.413-.027-.578-.083-.165-.733-1.793-1.018-2.447-.248-.578-.51-.578-.716-.578z" /></svg>; }
// Topics of the menu (the same keys the server validates). The messages are written here; the number comes from the config.
const whatsappOptions = [
    { topic: 'wholesale', title: 'Comprar al por mayor', description: 'Cafeterías, tiendas, oficinas y regalos corporativos.', icon: Store, message: () => 'Hola, quiero información para comprar Café Finca Helena al por mayor.' },
    { topic: 'order_support', title: 'Soporte de mi pedido', description: 'Estado, envío o un problema con tu compra.', icon: Headset, message: () => `Hola, necesito ayuda con mi pedido ${lastOrderReference() ?? 'CFH-…'}` },
    { topic: 'buying_help', title: 'Ayuda para comprar', description: 'Te ayudamos a elegir tu café y la molienda.', icon: ShoppingBag, message: () => 'Hola, quiero ayuda para elegir mi café.' },
];

function WhatsAppMenu() {
    const [open, setOpen] = useState(false);
    const triggerRef = useRef(null);
    const closeRef = useRef(null);
    const containerRef = useRef(null);
    const { drawer, checkout } = useStorefront();
    const { pathname } = useLocation();
    const numberIsValid = Boolean(shopWhatsapp());

    const closeMenu = () => {
        setOpen(false);
        triggerRef.current?.focus();
    };

    useEffect(() => {
        if (!open) return;
        closeRef.current?.focus();
        const handleKey = event => { if (event.key === 'Escape') { event.preventDefault(); closeMenu(); } };
        const handleOutside = event => { if (!containerRef.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('keydown', handleKey);
        document.addEventListener('pointerdown', handleOutside);
        return () => { document.removeEventListener('keydown', handleKey); document.removeEventListener('pointerdown', handleOutside); };
    }, [open]);

    useEffect(() => { if (drawer || checkout) setOpen(false); }, [drawer, checkout]);

    return <div className="fab-stack" ref={containerRef}>
        {open && <section className="whatsapp-menu" id="whatsapp-menu" aria-labelledby="whatsapp-menu-title">
            <div className="whatsapp-menu-header">
                <div><h2 id="whatsapp-menu-title">¿En qué te ayudamos?</h2></div>
                <button ref={closeRef} type="button" className="whatsapp-menu-close" onClick={closeMenu} aria-label="Cerrar menú de WhatsApp"><X aria-hidden="true" /></button>
            </div>
            <div className="whatsapp-menu-options">
                {numberIsValid ? whatsappOptions.map(({ topic, title, description, icon: Icon, message }) => <a key={topic} className="whatsapp-menu-option" href={whatsappLink(message())} target="_blank" rel="noopener noreferrer" onClick={() => { trackWhatsappClick(topic, pathname); closeMenu(); }}>
                    <span className="whatsapp-menu-option-icon"><Icon aria-hidden="true" /></span><span className="whatsapp-menu-option-text"><strong>{title}</strong><small>{description}</small></span><ArrowRight className="whatsapp-menu-arrow" aria-hidden="true" />
                </a>) : <p className="whatsapp-menu-error" role="alert">El número de WhatsApp no está configurado correctamente.</p>}
            </div>
            <p className="whatsapp-menu-note">Te responderemos por WhatsApp.</p>
        </section>}
        <button ref={triggerRef} type="button" className="fab fab-mayorista" aria-expanded={open} aria-controls="whatsapp-menu" onClick={() => setOpen(value => !value)}>
            <span className="ic ic-mayorista"><WhatsAppIcon /></span><span className="whatsapp-trigger-text"><strong>¿Te ayudamos?</strong><small>Escríbenos por WhatsApp</small></span>
        </button>
    </div>;
}

function InfoHeader({ origin, scrolled }) { if (origin) return <header className={scrolled ? 'scrolled' : ''}><Link className="h-logo" to="/"><span className="h-logo-img"><img src={referenceImage('logo.png')} alt="Finca Helena" /></span><span className="h-logo-text">Finca Helena <small>Café · Norte de Santander</small></span></Link><div style={{display:'flex',gap:10,alignItems:'center'}}><Link className="h-back" style={{background:'var(--cafe)'}} to="/blog">Rincón del café</Link><Link className="h-back" to="/"><ArrowLeft className="ui-icon" aria-hidden="true" /> Ir a la Tienda</Link></div></header>; return <header className={scrolled ? 'scrolled' : ''}><div className="hd"><Link className="logo" to="/"><img src={referenceImage('logo.png')} alt="Café Finca Helena" /><b>Finca Helena<small>Café · Norte de Santander</small></b></Link><nav aria-label="Principal"><Link to="/sello-de-origen">Sello de origen</Link><Link to="/blog" className="on">Rincón del café</Link><Link to="/" className="shop">Tienda</Link></nav></div></header>; }

function HomeFooter() {
    const { setPolicy, selectCategory, catalog } = useStorefront();
    const categoryNames = [...new Set(catalog.map(product => product.categoryName))];
    const goCat = cat => {selectCategory(cat); scrollToCatalog();};
    return <footer><div className="footer-inner"><div><h5>Café Finca Helena</h5><ul><li><MapPin className="ui-icon" aria-hidden="true" /> Cra. 20 #133-74 Bogotá</li>{shopWhatsapp() && <li><MessageCircle className="ui-icon" aria-hidden="true" /> <a href={whatsappLink()} target="_blank" rel="noreferrer">WhatsApp: {nationalNumber(shopWhatsapp())}</a></li>}<li><Mail className="ui-icon" aria-hidden="true" /> <a href="mailto:comercial@cafefincahelena.com">comercial@cafefincahelena.com</a></li></ul><div className="footer-social">{[['Instagram','https://www.instagram.com/cafefincahelena/'],['Facebook','https://www.facebook.com/cafefincahelena'],['TikTok','https://www.tiktok.com/@cafefincahelena'],['LinkedIn','https://www.linkedin.com/company/cafefincahelena']].map(([name,url]) => <a key={name} href={url} target="_blank" rel="noreferrer" className="soc-icon" aria-label={name} title={name}><SocialIcon name={name} /></a>)}</div></div>
        <div><h5>Categorías</h5><ul>{(categoryNames.length ? categoryNames : ['Café']).map(name => <li key={name}><button className="footer-link" onClick={() => goCat(name)}>{name}</button></li>)}<li><Link to="/blog">Rincón del café</Link></li><li><Link to="/sello-de-origen">Sello de Origen</Link></li></ul></div>
        <div><h5>Ayuda</h5><ul>{[['envio','Política de envío'],['reembolso','Política de devoluciones'],['privacidad','Política de privacidad'],['terminos','Términos y condiciones']].map(([key,label]) => <li key={key}><button className="footer-link" onClick={() => setPolicy(key)}>{label}</button></li>)}<li><a href="#preguntas-frecuentes">Preguntas frecuentes</a></li><li><Link to="/sello-de-origen">Sello de Origen</Link></li><li><Link to="/blog">El Rincón del Café (blog)</Link></li></ul></div></div><div className="footer-bottom"><span>© {new Date().getFullYear()} Café Finca Helena. Todos los derechos reservados.</span><span>Desarrollado por CCG · AVAchat</span></div></footer>;
}

export default function StorefrontLayout() {
    const { pathname } = useLocation(); const layout = layoutFor(pathname); const isHome = layout === 'shop'; const origin = layout === 'origin'; const article = layout === 'article';
    const pageRef = useRef(null);
    const previousPath = useRef(pathname);
    useEffect(() => { const meta = metaFor(pathname); return meta ? applyStaticMeta(meta) : undefined; }, [pathname]);
    useEffect(() => {
        if (previousPath.current !== pathname) pageRef.current?.focus({ preventScroll: true });
        previousPath.current = pathname;
    }, [pathname]);
    const [scrolled, setScrolled] = useState(window.scrollY > 8);
    useEffect(() => { const update = () => setScrolled(window.scrollY > 8); window.addEventListener('scroll', update, {passive:true}); update(); return () => window.removeEventListener('scroll', update); }, [pathname]);
    const { search, setSearch, items, setDrawer } = useStorefront();
    const scope = isHome ? 'demo-home' : origin ? 'demo-origin' : article ? 'demo-article' : 'demo-blog';
    return <div className={scope}>{isHome ? <><div className="announce">Café 100% COLOMBIANO <Sparkles className="ui-icon" aria-hidden="true" /> Norte de Santander &nbsp;·&nbsp; <b>Envíos a toda Colombia</b> &nbsp;·&nbsp; Bogotá: 24h hábiles · Resto del país: 3–5 días hábiles</div><header className={scrolled ? 'scrolled' : ''}><div className="header-inner"><Brand /><div className="search-wrap"><span className="search-icon"><Search className="ui-icon" aria-hidden="true" /></span><label htmlFor="searchInput" className="sr-only">Buscar producto</label><input id="searchInput" type="search" placeholder="Buscar producto…" autoComplete="off" value={search} onChange={e => setSearch(e.target.value)} /></div><button className="cart-btn" aria-label="Ver carrito" onClick={() => setDrawer(true)}><ShoppingCart className="ui-icon" aria-hidden="true" /> <span className="cart-count">{items.reduce((n,p) => n + p.quantity,0)}</span></button></div></header></> : <InfoHeader origin={origin} scrolled={scrolled} />}
        <div key={pathname} ref={pageRef} tabIndex={-1} className="page-transition"><Outlet /></div>{isHome ? <><HomeFooter /><StoreOverlays /><WhatsAppMenu /></> : origin ? <footer><div className="foot-inner"><span>© 2026 Café Finca Helena · Norte de Santander</span></div></footer> : <footer><div className="ft"><div>© 2026 Café Finca Helena · Cra. 20 #133-74 Bogotá{shopWhatsapp() && <> · <a href={whatsappLink()}>WhatsApp {nationalNumber(shopWhatsapp()).replace(/(\d{3})(\d{3})(\d{4})/, '$1 $2 $3')}</a></>}</div><nav aria-label="Pie"><Link to="/">Tienda</Link> · <Link to="/sello-de-origen">Sello de origen</Link> · <Link to="/blog">Rincón del café</Link></nav></div></footer>}</div>;
}
