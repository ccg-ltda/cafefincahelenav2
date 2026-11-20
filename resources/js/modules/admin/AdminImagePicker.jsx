import { useEffect, useRef, useState } from 'react';
import { Image as ImageIcon, Upload, X } from 'lucide-react';

const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);

export function validateImageFile(file) {
    if (!allowedTypes.has(file.type)) return 'Selecciona una imagen JPG, PNG o WebP.';
    if (file.size > 5 * 1024 * 1024) return 'La imagen debe pesar como máximo 5 MB.';
    return '';
}

export default function AdminImagePicker({ id, imageUrl, file, onFileChange, clientError, onClientError, serverError, resetKey }) {
    const [preview, setPreview] = useState('');
    const inputRef = useRef(null);

    useEffect(() => {
        if (!file) { setPreview(''); return undefined; }
        const url = URL.createObjectURL(file);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);

    const error = clientError || serverError;
    const choose = event => {
        const selected = event.target.files?.[0];
        if (!selected) return;
        const validation = validateImageFile(selected);
        onClientError(validation);
        onFileChange(validation ? null : selected);
        if (validation) event.target.value = '';
    };
    const cancel = () => {
        if (inputRef.current) inputRef.current.value = '';
        onClientError('');
        onFileChange(null);
    };

    return <div className="admin-image-picker" role="group" aria-labelledby={`${id}-title`}>
        <span id={`${id}-title`} className="admin-image-title">Imagen de la presentación</span>
        <div className="admin-image-content"><div className="admin-image-current">{imageUrl ? <img src={imageUrl} alt="Imagen actual de la presentación" /> : <span className="admin-image-placeholder"><ImageIcon aria-hidden="true" /></span>}<span>{imageUrl ? 'Imagen actual' : 'Sin imagen actual'}</span></div>
            <div className="admin-image-controls"><input key={resetKey} ref={inputRef} className="admin-image-input" id={id} type="file" accept="image/jpeg,image/png,image/webp" onChange={choose} aria-invalid={!!error} aria-describedby={`${id}-help${error ? ` ${id}-error` : ''}`} /><label className="admin-image-select" htmlFor={id}><Upload aria-hidden="true" />{imageUrl ? 'Cambiar imagen' : 'Seleccionar imagen'}</label><span id={`${id}-help`} className="admin-image-help">JPG, PNG o WebP · Máximo 5 MB</span></div>
        </div>
        {file && <div className="admin-image-selected">{preview && <img src={preview} alt="Vista previa de la imagen seleccionada" />}<div><span>Imagen seleccionada</span><strong>{file.name}</strong><button type="button" onClick={cancel}><X aria-hidden="true" /> Cancelar selección</button></div></div>}
        {clientError && !file && <button type="button" className="admin-image-cancel-error" onClick={cancel}>Cancelar selección</button>}
        {error && <small id={`${id}-error`} className="admin-image-error" role="alert">{error}</small>}
    </div>;
}
