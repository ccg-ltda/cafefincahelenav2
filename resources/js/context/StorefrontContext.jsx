import { createContext, useContext, useState } from 'react';
import { products } from '../modules/catalog/demoProducts';

const StorefrontContext = createContext(null);
export function StorefrontProvider({ children }) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('Café');
    const [subcategory, setSubcategory] = useState('Todas');
    const [cart, setCart] = useState({});
    const [drawer, setDrawer] = useState(false);
    const [checkout, setCheckout] = useState(false);
    const [policy, setPolicy] = useState(null);
    const changeQuantity = (id, quantity) => setCart(current => ({ ...current, [id]: Math.max(0, quantity) }));
    const items = products.filter(p => cart[p.id] > 0).map(p => ({ ...p, quantity: cart[p.id] }));
    const selectCategory = value => { setCategory(value); setSubcategory('Todas'); setSearch(''); };
    return <StorefrontContext.Provider value={{ search, setSearch, category, selectCategory, subcategory, setSubcategory, cart, changeQuantity, items, drawer, setDrawer, checkout, setCheckout, policy, setPolicy }}>{children}</StorefrontContext.Provider>;
}
export const useStorefront = () => useContext(StorefrontContext);
