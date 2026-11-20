const isPositiveInteger = value => Number.isSafeInteger(value) && value > 0;

/**
 * Validates the /api/products payload and builds the two shapes the storefront uses:
 * `variants` (flat, one entry per purchasable variant; the cart is keyed by its id) and
 * `grouped` (one entry per main product with its variants and its category name).
 * Throws when the payload does not have the expected shape, so bad data shows an error instead of `undefined`.
 */
export function buildCatalog(data) {
    if (!Array.isArray(data)) throw new Error('Respuesta de catálogo inválida');
    const seenIds = new Set();
    for (const product of data) {
        if (!product || !Array.isArray(product.variants) || !isPositiveInteger(product.id) || typeof product.slug !== 'string'
            || typeof product.category?.slug !== 'string' || typeof product.category?.name !== 'string' || product.category.name === '') throw new Error('Respuesta de catálogo inválida');
        for (const variant of product.variants) {
            if (!variant || !isPositiveInteger(variant.id) || seenIds.has(variant.id) || typeof variant.sku !== 'string' || typeof variant.name !== 'string' || !Number.isSafeInteger(variant.price_cop) || variant.price_cop < 0 || (variant.image_url !== null && typeof variant.image_url !== 'string')) throw new Error('Respuesta de catálogo inválida');
            seenIds.add(variant.id);
        }
    }
    const variants = data.flatMap(product => product.variants.map(variant => ({
        id: variant.id,
        productId: product.id,
        sku: variant.sku,
        name: variant.name,
        // `variant` is read by the cart drawer; `grind` is the name used by the catalog and product page.
        variant: variant.grind_type,
        grind: variant.grind_type,
        weight: variant.weight_grams,
        price: variant.price_cop, // final price: already the promotional one while a promotion applies
        originalPrice: Number.isSafeInteger(variant.original_price_cop) ? variant.original_price_cop : null,
        discountLabel: typeof variant.discount_label === 'string' ? variant.discount_label : null,
        promotionEndsAt: typeof variant.promotion_ends_at === 'string' ? variant.promotion_ends_at : null,
        // Units that can be bought right now (0 = sold out; the variant stays listed).
        available: Number.isSafeInteger(variant.available_quantity) ? Math.max(0, variant.available_quantity) : 0,
        image: variant.image_url,
        description: product.variants.length === 1 && !/descuento|precio especial/i.test(product.description ?? '') ? product.description : null,
        category: product.category.slug,
    })));
    const grouped = data.map(product => ({
        id: product.id,
        slug: product.slug,
        name: product.name,
        description: product.description,
        category: product.category.slug,
        categoryName: product.category.name,
        variants: variants.filter(variant => variant.productId === product.id),
    }));
    return { variants, grouped };
}
