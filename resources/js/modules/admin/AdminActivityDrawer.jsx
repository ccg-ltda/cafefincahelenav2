import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import './AdminCategories.css';
import './AdminActivity.css';
import useModalLock from './useModalLock';
import { actorText, request } from './adminShared';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';

// Every datum of one audit record in a right-hand panel. The URL reflects the open record (/admin/activity/{id}). Read-only.
export default function AdminActivityDrawer({ id, onClose }) {
    const panel = useRef(null);
    const closeButton = useRef(null);
    const [item, setItem] = useState(null);
    const [state, setState] = useState('loading');
    const [closing, setClosing] = useState(false);

    useModalLock();

    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        request(`/admin/activity/${id}/detail`, { signal: controller.signal })
            .then(({ data }) => { setItem(data); setState('success'); })
            .catch(failure => { if (failure.name !== 'AbortError') setState(failure.status === 404 || failure.confirmedRejected ? 'missing' : 'error'); });
        return () => controller.abort();
    }, [id]);

    useEffect(() => { closeButton.current?.focus(); }, []);

    function close() { setClosing(true); window.setTimeout(onClose, 180); }

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    return createPortal(<div className={`admin-categories-layer${closing ? ' is-closing' : ''}`}>
        <div className="admin-categories-overlay" onClick={close} aria-hidden="true" />
        <div ref={panel} className="admin-categories-drawer admin-activity-drawer" role="dialog" aria-modal="true" aria-labelledby="activity-drawer-title" onKeyDown={keyDown}>
            <header className="admin-categories-drawer-head">
                <div><span className="admin-categories-drawer-eyebrow">Registro del historial</span><h2 id="activity-drawer-title">{item ? `${item.action_label} · ${item.module_label}` : 'Registro'}</h2></div>
                <button type="button" ref={closeButton} className="admin-categories-close" onClick={close} aria-label="Cerrar panel"><X aria-hidden="true" /></button>
            </header>
            <div className="admin-categories-drawer-form">
                <div className="admin-categories-drawer-body">
                    {state === 'loading' && <p className="admin-categories-hint" role="status">Cargando registro…</p>}
                    {state === 'missing' && <p className="admin-categories-alert" role="alert">Este registro no existe.</p>}
                    {state === 'error' && <p className="admin-categories-alert" role="alert">No se pudo cargar el registro.</p>}
                    {state === 'success' && <>
                        <section className="admin-customer-section"><h3>Acción</h3><dl>
                            <div><dt>Fecha exacta</dt><dd>{item.occurred_text}</dd></div>
                            <div><dt>Administrador</dt><dd>{actorText(item.actor)}</dd></div>
                            <div><dt>Módulo</dt><dd>{item.module_label}</dd></div>
                            <div><dt>Acción</dt><dd><span className={`admin-activity-action${item.failed ? ' is-failed' : ''}`}>{item.action_label}</span></dd></div>
                            <div><dt>Recurso</dt><dd>{item.subject_label ? (item.link ? <a className="admin-activity-link" href={item.link}>{item.subject_label}</a> : <>{item.subject_label}{item.subject_type && <small>Este recurso ya no existe.</small>}</>) : '—'}</dd></div>
                        </dl></section>

                        <section className="admin-customer-section"><h3>Cambios</h3>
                            {item.changes.length ? <div className="admin-activity-changes-wrap"><table className="admin-activity-changes"><thead><tr><th scope="col">Campo</th><th scope="col">Antes</th><th scope="col">Después</th></tr></thead>
                                <tbody>{item.changes.map(change => <tr key={change.field}><th scope="row" data-label="Campo">{change.label}</th><td data-label="Antes"><del>{change.before}</del></td><td data-label="Después"><ins>{change.after}</ins></td></tr>)}</tbody></table></div>
                                : <p className="admin-activity-none">Esta acción no cambió campos.</p>}</section>

                        {item.context.length > 0 && <section className="admin-customer-section"><h3>Contexto</h3><dl>{item.context.map(entry => <div key={entry.label}><dt>{entry.label}</dt><dd>{entry.value}</dd></div>)}</dl></section>}

                        <section className="admin-customer-section"><h3>Datos técnicos</h3><dl>
                            <div><dt>IP</dt><dd className="admin-activity-mono">{item.technical.ip || '—'}</dd></div>
                            <div><dt>Navegador</dt><dd>{item.technical.browser || '—'}</dd></div>
                            <div><dt>ID de la petición</dt><dd className="admin-activity-mono">{item.technical.request_id || '—'}</dd></div>
                        </dl></section>
                    </>}
                </div>
                <footer className="admin-categories-drawer-foot"><div className="admin-categories-buttons admin-customer-foot">
                    <button type="button" className="admin-categories-secondary" onClick={close}>Cerrar</button>
                </div></footer>
            </div>
        </div>
    </div>, document.body);
}
