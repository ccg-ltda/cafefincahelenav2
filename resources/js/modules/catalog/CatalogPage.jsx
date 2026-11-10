import { products } from './demoProducts';
import ProductCard from './ProductCard';
import { useStorefront } from '../../context/StorefrontContext';

const normalize = value => value.toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
export default function CatalogPage() {
    const { search, setSearch, category, selectCategory, subcategory, setSubcategory } = useStorefront();
    const group = products.filter(p => p.category === category);
    const subs = category === 'Café' ? ['Molido','Tostado en Grano'] : ['Ofertas'];
    const subOf = p => p.category === 'Promociones' ? 'Ofertas' : p.variant === 'Grano entero' ? 'Tostado en Grano' : 'Molido';
    const shown = search ? products.filter(p => normalize(`${p.name} ${p.variant} ${p.category} ${subOf(p)}`).includes(normalize(search.trim()))) : group.filter(p => subcategory === 'Todas' || subOf(p) === subcategory);
    return <><div className="cat-nav" id="catNav"><div className="cat-nav-inner">{['Café','Promociones'].map(cat => <button type="button" key={cat} className={`cat-tab ${category === cat ? 'active' : ''}`} onClick={() => selectCategory(cat)}>{cat === 'Café' ? '☕' : '🏷️'} {cat}</button>)}</div></div>
        <div className="main"><aside className="sidebar"><div className="sidebar-sticky"><h4>{category === 'Café' ? '☕' : '🏷️'} {category}</h4><ul className="sub-list">{['Todas', ...subs].map(sub => <li key={sub}><button className={`sub-item ${subcategory === sub ? 'active' : ''}`} onClick={() => {setSubcategory(sub);setSearch('');}}>{sub === 'Todas' ? 'Todos' : sub} <span className="sub-count">{sub === 'Todas' ? group.length : group.filter(p => subOf(p) === sub).length}</span></button></li>)}</ul></div></aside>
        <div className="products-section"><div className="section-head"><h2>{search ? `Resultados para "${search}"` : category + (subcategory !== 'Todas' ? ` · ${subcategory}` : '')}</h2><span className="section-count">{shown.length} producto{shown.length !== 1 && 's'}</span></div><div className="product-grid">{shown.map(p => <ProductCard key={p.id} product={p} />)}{shown.length === 0 && <div className="empty-state"><strong>Sin resultados</strong>Prueba con otro término.</div>}</div></div></div></>;
}
