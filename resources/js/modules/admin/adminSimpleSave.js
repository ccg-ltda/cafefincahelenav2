// Separate requests are not atomic: retain each confirmed result for an honest retry.
export async function saveSimpleProduct({ productChanged, variantChanged, saveProduct, saveVariant }) {
    let productResult = null;
    let variantResult = null;
    if (productChanged) {
        try { productResult = await saveProduct(); }
        catch (error) { return { failed: 'product', error, productResult, variantResult }; }
    }
    if (variantChanged) {
        try { variantResult = await saveVariant(); }
        catch (error) { return { failed: 'variant', error, productResult, variantResult }; }
    }
    return { failed: null, productResult, variantResult };
}
