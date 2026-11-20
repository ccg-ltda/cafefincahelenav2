import { useState } from 'react';

export default function ProductImage({ src, alt, ...props }) {
    const [failedSrc, setFailedSrc] = useState(null);
    if (!src || failedSrc === src) return <span role="img" aria-label="Imagen no disponible">Imagen no disponible</span>;
    return <img src={src} alt={alt} onError={() => setFailedSrc(src)} {...props} />;
}
