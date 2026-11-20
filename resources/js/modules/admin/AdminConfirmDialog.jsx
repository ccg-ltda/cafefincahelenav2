import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { TriangleAlert } from 'lucide-react';
import useModalLock from './useModalLock';

const FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';

// Compact modal confirmation: traps focus, closes with Escape or the overlay (never while busy),
// locks the page without shifting it and returns focus to the control that opened it.
export default function AdminConfirmDialog({ title, confirmLabel, tone = 'danger', busy = false, error = '', opener, onCancel, onConfirm, children }) {
    useModalLock();
    const panel = useRef(null);
    const cancel = useRef(null);
    const openerRef = useRef(opener);

    useEffect(() => {
        cancel.current?.focus();
        const keepFocus = event => { if (panel.current && !panel.current.contains(event.target)) cancel.current?.focus(); };
        document.addEventListener('focusin', keepFocus);
        return () => {
            document.removeEventListener('focusin', keepFocus);
            // #root becomes interactive again when the lock is released, then focus returns to the opener.
            window.setTimeout(() => { if (openerRef.current?.isConnected) openerRef.current.focus({ preventScroll: true }); }, 0);
        };
    }, []);

    function keyDown(event) {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (!busy) onCancel(); return; }
        if (event.key !== 'Tab') return;
        const nodes = [...panel.current.querySelectorAll(FOCUSABLE)].filter(node => node.offsetParent !== null);
        if (!nodes.length) { event.preventDefault(); return; }
        const first = nodes[0]; const last = nodes[nodes.length - 1]; const active = document.activeElement;
        if (event.shiftKey && (active === first || !panel.current.contains(active))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && (active === last || !panel.current.contains(active))) { event.preventDefault(); first.focus(); }
    }

    return createPortal(<div className="admin-confirm-layer">
        <div className="admin-confirm-overlay" onClick={() => { if (!busy) onCancel(); }} aria-hidden="true" />
        <div ref={panel} className="admin-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-text" onKeyDown={keyDown}>
            <div className="admin-confirm-head"><TriangleAlert className={tone === 'danger' ? 'is-danger' : 'is-ok'} aria-hidden="true" /><h2 id="admin-confirm-title">{title}</h2></div>
            <div className="admin-confirm-body" id="admin-confirm-text">{children}</div>
            {error && <p className="admin-confirm-error" role="alert">{error}</p>}
            <div className="admin-confirm-buttons">
                <button type="button" className="admin-confirm-cancel" ref={cancel} disabled={busy} onClick={onCancel}>Cancelar</button>
                <button type="button" className={tone === 'danger' ? 'admin-confirm-danger' : 'admin-confirm-primary'} disabled={busy} onClick={onConfirm}>{busy ? 'Guardando…' : confirmLabel}</button>
            </div>
        </div>
    </div>, document.body);
}
