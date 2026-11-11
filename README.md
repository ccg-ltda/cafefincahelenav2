# Café Finca Helena

Base de Laravel 12 con React, React Router, Vite y Tailwind CSS. El frontend se organiza en `resources/js/modules` y Laravel sirve la aplicación y la futura API.

## Desarrollo local

Con las dependencias instaladas, iniciar en dos terminales desde la raíz:

```powershell
php artisan serve
npm run dev
```

Abrir `http://127.0.0.1:8000`. Para compilar los recursos sin el servidor de Vite, ejecutar `npm.cmd run build`.

El archivo local `.env` se genera a partir de `.env.example` y debe tener una clave de aplicación propia (`php artisan key:generate`). Se mantiene fuera de Git. Durante esta etapa las sesiones y la caché usan archivos y la cola funciona de manera síncrona, para que la página inicial no requiera una base de datos. El controlador SQLite predeterminado está declarado, pero no se ha creado ninguna base de datos ni se ha conectado a ella. La configuración MySQL y las migraciones quedan pendientes.

Los scripts `composer setup` y `post-create-project-cmd` de la plantilla oficial incluyen migraciones; no ejecutarlos hasta que la base de datos esté configurada y su uso esté autorizado.
