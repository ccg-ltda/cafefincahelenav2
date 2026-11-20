# Acceso administrativo

Antes de crear administradores, configurar la conexión de Laravel y ejecutar las migraciones pendientes mediante `php artisan migrate` en el entorno correspondiente. La migración agrega `users.is_admin` con valor predeterminado falso; no convierte usuarios existentes en administradores.

Para crear una cuenta autorizada, ejecutar el comando en una terminal interactiva:

```sh
php artisan admin:create
```

El comando solicita nombre, correo, contraseña y confirmación. La contraseña se escribe de forma oculta y debe tener al menos 12 caracteres, con mayúsculas, minúsculas, números y símbolos. El modelo aplica el hash configurado de Laravel.

Usar un correo distinto para cada administrador. No hay registro público. Para desautorizar una cuenta, cambiar `is_admin` a `false` desde una sesión administrativa de mantenimiento autorizada.

En producción usar HTTPS y configurar `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true` y `SESSION_SAME_SITE=lax`. Configurar un almacén de caché compartido y persistente si hay varios servidores, para que los límites de intentos se apliquen conjuntamente.
