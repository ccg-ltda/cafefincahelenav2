export const productPath = slug => `/producto/${encodeURIComponent(slug)}`;

export const formatWeight = grams => grams == null ? null : grams >= 1000 ? `${grams / 1000} kg` : `${grams} g`;

export const variantSummary = variant => [
    variant.weight != null ? `Peso total: ${variant.weight} g` : null,
].filter(Boolean).join(' · ');

// A product with several published variants is configurable; with one it is simple.
export const isConfigurable = product => product.variants.length > 1;

// Promotions: prices come from the server (`price` is final, `originalPrice` only exists while a promotion applies).
export const PROMOTIONS_TAB = 'Promociones';
export const isPromoted = variant => variant.originalPrice != null;
export const hasPromotion = product => product.variants.some(isPromoted);
// "Hasta el 30 nov" (Colombian time).
export const untilText = iso => {
    if (!iso) return null;
    const parts = new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', timeZone: 'America/Bogota' }).formatToParts(new Date(iso));
    return `Hasta el ${parts.find(part => part.type === 'day').value} ${parts.find(part => part.type === 'month').value.replace('.', '')}`;
};
// Badge of a product card: the label its promoted variants share, or "Promoción" when they differ.
export const promoBadge = product => {
    const labels = [...new Set(product.variants.filter(isPromoted).map(variant => variant.discountLabel))];
    return labels.length === 0 ? null : labels.length === 1 ? labels[0] : 'Promoción';
};

// Stock helpers: a variant with `available <= 0` is sold out; a product is sold out when every variant is.
export const LOW_STOCK_UNITS = 5;
export const isSoldOut = product => product.variants.every(variant => variant.available <= 0);
export const lowStockText = available => (available > 0 && available <= LOW_STOCK_UNITS ? `¡Solo quedan ${available}!` : null);

export const minPrice = product => Math.min(...product.variants.map(variant => variant.price));

// Representative image: the first variant that has one.
export const productImage = product => (product.variants.find(variant => variant.image) || product.variants[0]).image;

export const uniqueValues = (variants, key) => [...new Set(variants.map(variant => variant[key]).filter(value => value != null && value !== ''))];

export const groupSummary = product => {
    const weights = uniqueValues(product.variants, 'weight').sort((a, b) => a - b).map(formatWeight);
    const grinds = uniqueValues(product.variants, 'grind');
    return [`${product.variants.length} presentaciones`, weights.length ? weights.join(' · ') : null, grinds.length ? grinds.join(' / ') : null].filter(Boolean).join(' — ');
};

// Same rule as the server-side description: unverifiable promotional copy is never reused.
export const safeDescription = text => {
    const clean = String(text ?? '').replace(/\s+/g, ' ').trim();
    return clean && clean.toLowerCase() !== 'n/a' && !/descuento|precio especial/i.test(clean) ? clean : '';
};

const descriptionLines = text => String(text ?? '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);

// A product with several variants whose description spans several lines stores one note per presentation
// (database/seeders/ProductSeeder.php joins them with "\n"): it is not a general product description.
export const hasPerVariantNotes = product => product.variants.length > 1 && descriptionLines(product.description).length > 1;

export const generalDescription = product => hasPerVariantNotes(product) ? '' : safeDescription(product.description);

// The note belongs to a variant only while there is exactly one line per published variant, in variant order.
export const variantNote = (product, variant) => {
    const lines = descriptionLines(product.description);
    if (!hasPerVariantNotes(product) || lines.length !== product.variants.length) return '';
    return safeDescription(lines[product.variants.findIndex(item => item.id === variant.id)]);
};
