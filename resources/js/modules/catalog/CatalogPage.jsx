import ProductCard from './ProductCard';
import { Link, useLocation, useNavigate } from 'react-router';
import { BadgePercent, Coffee, Tags } from 'lucide-react';
import { useStorefront } from '../../context/StorefrontContext';
import { PROMOTIONS_TAB, formatWeight, hasPromotion } from './variant';

const normalize = value => value.toLocaleLowerCase('es').normalize('NFD').replace(/[̀-ͯ]/g, '');
const subName = grind => grind === 'Grano entero' ? 'Tostado en Grano' : grind === 'Molido' ? 'Molido' : '';
// A main product belongs to a sub-filter when any of its variants has that grind.
const hasSub = (product, sub) => product.variants.some(variant => subName(variant.grind) === sub);

export default function CatalogPage() {
    const { search, setSearch, category: selectedCategory, selectCategory, subcategory: selectedSubcategory, setSubcategory, catalog, catalogStatus, hasPromotions } = useStorefront();
    const navigate = useNavigate();
    const { pathname } = useLocation();
    // Categories come from the public API: only active ones with at least one purchasable product appear.
    const categories = [...new Map(catalog.map(p => [p.category, { slug: p.category, name: p.categoryName }])).values()];
    const shopTabs = categories.length ? categories : [{ slug: 'cafe', name: 'Café' }];
    // Virtual tab: it only exists while the server reports a current promotion, and it lists the products that have one.
    const promoTab = { slug: 'promociones', name: PROMOTIONS_TAB, promo: true };
    const tabs = hasPromotions ? [promoTab, ...shopTabs] : shopTabs;
    const current = selectedCategory === PROMOTIONS_TAB ? promoTab : categories.find(item => item.name === selectedCategory) || shopTabs[0];
    // The tab also has its own URL (/promociones); the others live at /.
    const pick = name => { selectCategory(name); if (name === PROMOTIONS_TAB) navigate('/promociones'); else if (pathname === '/promociones') navigate('/'); };
    const category = current.name;
    const subcategory = current.name === selectedCategory ? selectedSubcategory : 'Todas';
    const group = current.promo ? catalog.filter(hasPromotion) : catalog.filter(p => p.category === current.slug);
    const subs = ['Molido', 'Tostado en Grano'].filter(sub => group.some(p => hasSub(p, sub)));
    const searchText = p => normalize(`${p.name} ${p.variants.map(v => `${v.sku} ${v.grind ?? ''} ${v.weight ?? ''} ${formatWeight(v.weight) ?? ''} ${subName(v.grind)}`).join(' ')}`);
    const shown = search ? group.filter(p => searchText(p).includes(normalize(search.trim()))) : group.filter(p => subcategory === 'Todas' || hasSub(p, subcategory));
    const hidePromoFilters = Boolean(current.promo) && group.length === 0;
    const emptyPromotions = hidePromoFilters && catalogStatus === 'ready';
    return <div className="catalog-section"><div className="cat-nav" id="catNav"><div className="cat-nav-inner">{tabs.map(({ slug, name: cat }) => <button type="button" key={slug} className={`cat-tab ${slug === 'promociones' ? 'cat-tab--promo ' : ''}${category === cat ? 'active' : ''}`} aria-pressed={category === cat} onClick={() => pick(cat)}>{slug === 'cafe' ? <Coffee className="ui-icon" aria-hidden="true" /> : slug === 'promociones' ? <BadgePercent className="ui-icon" aria-hidden="true" /> : <Tags className="ui-icon" aria-hidden="true" />} {cat}</button>)}</div></div>
        <div className={`main${hidePromoFilters ? ' main--no-sidebar' : ''}`}>
            {!hidePromoFilters && <aside className="sidebar"><div className="sidebar-sticky"><h4>{current.slug === 'cafe' ? <Coffee className="ui-icon" aria-hidden="true" /> : current.promo ? <BadgePercent className="ui-icon" aria-hidden="true" /> : <Tags className="ui-icon" aria-hidden="true" />} {category}</h4><ul className="sub-list">{['Todas', ...subs].map(sub => <li key={sub}><button className={`sub-item ${subcategory === sub ? 'active' : ''}`} onClick={() => {setSubcategory(sub);setSearch('');}}>{sub === 'Todas' ? 'Todos' : sub} <span className="sub-count">{sub === 'Todas' ? group.length : group.filter(p => hasSub(p, sub)).length}</span></button></li>)}</ul></div></aside>}
            <div className={`products-section${emptyPromotions ? ' products-section--promotions-empty' : ''}`}>
                {!emptyPromotions && <div className="section-head"><h2>{search ? `Resultados para "${search}"` : category + (subcategory !== 'Todas' ? ` · ${subcategory}` : '')}</h2><span className="section-count">{shown.length} producto{shown.length !== 1 && 's'}</span></div>}
                {emptyPromotions ? <div className="promotions-empty"><BadgePercent className="promotions-empty-icon" aria-hidden="true" /><h2>No hay promociones activas en este momento</h2><p>Vuelve pronto: publicamos descuentos cada tanto. Mientras tanto, explora nuestro catálogo.</p><Link to="/" onClick={() => selectCategory(shopTabs[0].name)}>Ver el catálogo</Link></div> : <div className="product-grid">{catalogStatus === 'loading' ? <div className="empty-state" role="status">Cargando catálogo…</div> : catalogStatus === 'error' ? <div className="empty-state" role="alert"><strong>No se pudo cargar el catálogo</strong>Comprueba tu conexión e inténtalo de nuevo.</div> : <>{shown.map(p => <ProductCard key={p.id} product={p} />)}{shown.length === 0 && <div className="empty-state"><strong>Sin resultados</strong>{search || subcategory !== 'Todas' ? 'Prueba con otro término o filtro.' : 'El catálogo está vacío.'}</div>}</>}</div>}
            </div>
        </div></div>;
}
