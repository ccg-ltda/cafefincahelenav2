// Contenido y precios del HTML de referencia; no representan inventario real.
const weights = ['125 gr', '250 gr', '340 gr', '500 gr', '2.5 Kg'];
const prices = [19900, 37900, 45900, 60900, 265900];
const codes = ['125', '250', '340', '500', '25k'];
const descriptions = [
    'Molido 125 gr: tamaño ideal para probar. Para goteo, prensa o greca.',
    'Molienda media para el café de todos los días en goteo o greca.',
    'Molido 340 gr, rinde para varias semanas. Notas florales y a madera.',
    'Bolsa de 500 gr para la familia o la oficina. Para goteo, prensa o greca.',
    '2.5 Kg para cafeterías, oficinas y eventos. Molienda media pareja.',
    'Grano entero 125 gr. Muela al momento y conserve todo el aroma.',
    'Grano entero para molino casero, espresso o prensa francesa.',
    'Grano de 340 gr, tostión media-baja. Equilibrio entre suavidad y acidez.',
    'Grano entero 500 gr para quienes muelen cada mañana.',
    '2.5 Kg en grano para barras de café y molinos profesionales.',
];
export const products = ['Molido', 'Grano entero'].flatMap((variant, v) => weights.map((weight, i) => ({
    id: v * 5 + i + 1, name: `Café Premium ${weight}`, variant, price: prices[i],
    image: `cafe-${codes[i]}-${v ? 't' : 'm'}`, description: descriptions[v * 5 + i], category: 'Café',
})));
products.push(
    { id: 11, category: 'Promociones', name: 'Kit Degustación — 2 x 125 gr', variant: 'Molido · 2 bolsas', price: 34900, image: 'cafe-125-m', description: 'Dos bolsas de 125 gr molido para regalar o compartir.' },
    { id: 12, category: 'Promociones', name: 'Combo Familiar — 250 gr + 125 gr', variant: 'Molido', price: 49900, image: 'cafe-250-m', description: 'Molido 250 gr + 125 gr. El café de la casa con descuento.' },
    { id: 13, category: 'Promociones', name: 'Pack Grano Premium — 500 gr', variant: 'Tostado en Grano', price: 54900, image: 'cafe-500-t', description: 'Grano entero 500 gr a precio especial. Ideal para espresso.' },
);
