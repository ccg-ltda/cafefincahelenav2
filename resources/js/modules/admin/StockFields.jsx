import { useEffect, useRef } from 'react';
import { Field } from './adminShared';
import StatusBadge from './StatusBadge';

const isInteger = value => /^\d+$/.test(String(value ?? '').trim());
const MESSAGE = 'Escribe un número entero mayor o igual a 0.';

/** Client-side check of the stock fields (the backend enforces the same rules). `mode` is 'edit' or 'create'. */
export function validateStock(values, mode) {
    const errors = {};
    if (mode === 'edit' ? !isInteger(values.stock_quantity) : String(values.stock_quantity ?? '').trim() !== '' && !isInteger(values.stock_quantity)) errors.stock_quantity = [MESSAGE];
    return errors;
}

/** Same rule as InventoryService::state(): 0 = out, 1..threshold = low, otherwise ok. */
const stateOf = (stock, threshold) => (stock <= 0 ? 'out' : stock <= threshold ? 'low' : 'ok');

// Stock block of the variant editors. In 'edit' mode the typed value becomes an exact "ajuste" movement on the server;
// `current` is the stock registered right now (the value the form started from). In 'create' mode it is the initial stock.
export default function StockFields({ prefix, values, onChange, errors, mode, current, threshold, focusStock }) {
    const box = useRef(null);
    const edit = mode === 'edit';

    useEffect(() => {
        if (!focusStock) return;
        box.current?.scrollIntoView({ block: 'center' });
        box.current?.querySelector('input')?.focus({ preventScroll: true });
    }, [focusStock]);

    const stock = isInteger(values.stock_quantity) ? Number(values.stock_quantity) : edit ? null : 0;
    const state = stock === null ? null : stateOf(stock, threshold);

    return <div className="admin-editor-full admin-stock-fields" ref={box} id={`${prefix}-stock`} role="group" aria-label="Inventario">
        <h3>Inventario</h3>
        {edit && <p className="admin-editor-hint">Stock registrado ahora: <strong>{current}</strong>. Si cambias el valor, la diferencia queda en el historial de movimientos.</p>}
        <div className="admin-stock-fields-grid">
            <Field prefix={prefix} label={edit ? 'Stock disponible' : 'Stock inicial (opcional)'} name="stock_quantity" type="number" inputMode="numeric" value={values.stock_quantity} onChange={onChange} errors={errors} min="0" max="1000000" step="1" required={edit} />
        </div>
        {state && <p className="admin-stock-state" role="status"><StatusBadge value={`stock_${state}`} /><span>Se considera stock bajo con {threshold} {threshold === 1 ? 'unidad' : 'unidades'} o menos.</span></p>}
    </div>;
}
