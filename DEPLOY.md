# DEPLOY.md — Puesta en producción de Café Finca Helena

Guía paso a paso para instalar la tienda y el panel administrativo en un servidor, y para actualizarla después.
Los comandos son para Linux (Ubuntu/Debian). Reemplaza `/var/www/cafe-finca-helena` por la carpeta que uses.

> **Importante:** el catálogo inicial (Café Premium, Kit Degustación, Combo Familiar y Pack Grano Premium, con sus 13 presentaciones e imágenes) se carga con `db:seed`. Los productos, pedidos, clientes y usuarios de prueba que se crearon en el computador de desarrollo **no** van al servidor: la base de datos de producción empieza vacía.

---

## 1. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| PHP | 8.2 o superior, con las extensiones: `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `curl`, `zip`, `bcmath` |
| Base de datos | **MySQL 8 o MariaDB 10.6+** con `utf8mb4`. **No usar SQLite en producción**: la generación de SKU usa `REGEXP` de MySQL y WhatsApp guarda emojis |
| Composer | 2.x |
| Node.js | **20.19 o superior** (Vite 7) y npm |
| Servidor web | Apache o nginx, con la carpeta raíz apuntando a `public/` (nunca a la raíz del proyecto) |
| HTTPS | Obligatorio (el panel usa cookies de sesión seguras) |
| Cron | Para el scheduler de Laravel (ver paso 11) |

---

## 2. Obtener el código

```sh
cd /var/www
git clone <URL-DEL-REPOSITORIO> cafe-finca-helena
cd cafe-finca-helena
git checkout main
```

---

## 3. Crear la base de datos

Entra a MySQL y crea la base y un usuario propio (no uses `root`):

```sql
CREATE DATABASE cafe_finca_helena CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cfh_user'@'localhost' IDENTIFIED BY 'UNA_CLAVE_LARGA_Y_UNICA';
GRANT ALL PRIVILEGES ON cafe_finca_helena.* TO 'cfh_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## 4. Dependencias de PHP

```sh
composer install --no-dev --optimize-autoloader
```

---

## 5. Dependencias y compilación del frontend

`public/build` no está en el repositorio: hay que compilarlo en el servidor.

```sh
npm ci
npm run build
```

> Usa `npm ci` completo (no `--omit=dev`): Vite, que compila el frontend, es una dependencia de desarrollo.
> Verifica que **no exista** el archivo `public/hot` (solo se crea con `npm run dev`). Si existe, bórralo: haría que la página busque un servidor de desarrollo.

---

## 6. Archivo `.env` de producción

```sh
cp .env.example .env
```

Edita `.env`. **Nunca subas este archivo al repositorio ni lo compartas por chat o correo.**

| Variable | Valor en producción | Para qué sirve |
| --- | --- | --- |
| `APP_NAME` | `"Cafe Finca Helena"` | Nombre de la aplicación |
| `APP_ENV` | `production` | Modo producción |
| `APP_DEBUG` | `false` | **Debe ser `false`**: con `true` los errores muestran rutas y datos internos |
| `APP_URL` | `https://tu-dominio.com` | Dirección pública con `https`. Se usa en el sitemap, los enlaces canónicos y los datos para buscadores |
| `APP_KEY` | (se genera en el paso 7) | Clave que cifra sesiones y cookies. No la cambies después |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stack` / `warning` | Registro de errores en `storage/logs` |
| `DB_CONNECTION` | `mysql` | Tipo de base de datos |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Dónde está MySQL |
| `DB_DATABASE` | `cafe_finca_helena` | Nombre de la base |
| `DB_USERNAME` / `DB_PASSWORD` | el usuario del paso 3 | Acceso a la base |
| `SESSION_DRIVER` | `file` (o `database`) | Dónde se guardan las sesiones del panel |
| `SESSION_LIFETIME` | `120` | Minutos de inactividad antes de cerrar la sesión |
| `SESSION_SECURE_COOKIE` | `true` | La cookie de sesión solo viaja por HTTPS |
| `SESSION_HTTP_ONLY` | `true` | El JavaScript de la página no puede leer la cookie |
| `SESSION_SAME_SITE` | `lax` | Protección contra envíos desde otros sitios |
| `CACHE_STORE` | `file` | Dónde se guarda la caché (también cuenta los límites por IP) |
| `QUEUE_CONNECTION` | `sync` | No hay trabajos en cola todavía |
| `MAIL_MAILER` | `log` por ahora | Aún no se envían correos. Se configurará cuando se conecten los correos de pedido |
| `SHOP_WHATSAPP_NUMBER` | `573XXXXXXXXX` | **Obligatorio.** Número de WhatsApp de la tienda: indicativo de país + número, solo dígitos (ej. `573027880823`). Sin él, desaparecen los botones de WhatsApp de la tienda |
| `SHOP_REQUIRE_PAYMENT` | `false` hoy | Si es `true`, solo los pedidos con pago aprobado se pueden preparar y enviar. Déjalo en `false` mientras no esté conectado el pago en línea (Wompi); pasarlo a `true` sin pagos conectados deja los pedidos sin poder moverse |
| `SHOP_ACTIVITY_AUTH_RETENTION_DAYS` | `180` (opcional) | Días que se guardan los registros de inicio de sesión en el Historial |

Cualquier variable que no aparezca aquí puede quedar como está en `.env.example`.

---

## 7. Clave de la aplicación

```sh
php artisan key:generate --force
```

---

## 8. Migraciones y catálogo inicial

```sh
php artisan migrate --force
php artisan db:seed --force
```

- `migrate` crea todas las tablas en orden y carga las 3 respuestas rápidas de WhatsApp.
- `db:seed` carga el catálogo inicial: la categoría **Café**, los 4 productos y las 13 presentaciones (FH-001 a FH-013), y copia sus 10 imágenes (desde `database/seeders/images/products`) al disco público.
- **El stock inicial queda en 0** ("Agotado"). Se carga desde el panel (paso 13).
- **`db:seed` solo crea lo que falta.** Se puede correr varias veces sin riesgo: nunca cambia precios, stock, publicación, textos ni imágenes que el administrador haya editado.

---

## 9. Enlace de imágenes

```sh
php artisan storage:link
```

Crea `public/storage` → `storage/app/public`. Sin esto no se ven las imágenes de los productos.

---

## 10. Permisos de carpetas

El usuario con el que corre el servidor web (normalmente `www-data`) debe poder escribir en estas carpetas:

```sh
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

---

## 11. Scheduler (tareas programadas)

Hay una sola tarea programada: `activity:prune`, que cada día a las 03:30 borra los registros de inicio de sesión más antiguos que el límite (los registros de acciones administrativas **nunca** se borran).

Agrega al cron del usuario del servidor web (`sudo crontab -u www-data -e`):

```
* * * * * cd /var/www/cafe-finca-helena && php artisan schedule:run >> /dev/null 2>&1
```

---

## 12. Cachés de producción

```sh
php artisan config:cache
php artisan view:cache
php artisan event:cache
```

> **No uses `php artisan route:cache`.** Algunas rutas están definidas con funciones (closures) y Laravel no puede guardarlas en caché: el comando falla.

---

## 13. Primer administrador y carga del stock

```sh
php artisan admin:create
```

El comando es interactivo: pide el nombre completo, un usuario (minúsculas, números, punto o guion), el correo (opcional) y la contraseña (dos veces). Crea un usuario **administrador**. Si más adelante necesitas personas de despacho o de entrega, créalas desde el panel en **Usuarios**.

Entra a `https://tu-dominio.com/admin/login` y:

1. Ve a **Productos** y carga el stock real de cada presentación (botón de stock). Mientras esté en 0 se ve "Agotado" y no se puede comprar.
2. Revisa precios, textos y fotos del catálogo.

---

## 14. ¿El servidor está detrás de Cloudflare, nginx o un balanceador?

**Por qué importa (en lenguaje simple).** Para evitar abusos, la tienda limita cuántos pedidos puede hacer una misma persona: **10 pedidos por minuto por dirección IP** (y también limita las consultas del resumen y los cupones). Para saber "quién es" cada visitante, Laravel mira la dirección IP que le llega.

Si el sitio está detrás de **Cloudflare, un nginx que hace de intermediario (proxy inverso) o un balanceador de carga**, a Laravel le llega la IP *del intermediario*, no la del cliente. Resultado: **todos los clientes parecen ser la misma persona**. Con pocas compras alcanza para que el límite se active y empiece a rechazar pedidos legítimos con error 429 ("demasiados intentos"). También se mezclan las IP que quedan en los registros de seguridad del inicio de sesión.

**Qué hay que hacer en ese caso.** Decirle a Laravel que confíe en el intermediario, para que lea la IP real del cliente que el intermediario envía en la cabecera `X-Forwarded-For`. Se hace en `bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware): void { ... })`, agregando:

```php
use Illuminate\Http\Request;

// ...
$middleware->trustProxies(
    at: ['IP_DEL_PROXY_O_BALANCEADOR'], // o '*' si todo el tráfico solo puede llegar a través del proxy
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

- **Si no hay intermediario** (el servidor recibe a los visitantes directamente), **no agregues nada**.
- Usa `'*'` solo si el servidor **no es accesible directamente desde internet** (solo a través del proxy o de Cloudflare). Si lo fuera, alguien podría falsificar su IP. Si no estás seguro, pon la IP concreta del proxy o del balanceador.
- Detrás de **Cloudflare**, el servidor debería aceptar conexiones solo desde los rangos de IP de Cloudflare (publicados por ellos) y entonces `'*'` es aceptable.
- El proxy debe enviar `X-Forwarded-Proto: https`; si no, Laravel creará enlaces con `http://` y la cookie segura del panel puede fallar.

**Cómo comprobarlo.** Desde dos redes distintas (por ejemplo tu celular con datos y un computador con wifi) abre el panel y consulta el Historial: los inicios de sesión deben mostrar IP diferentes, no la del proxy. Después de cambiar `bootstrap/app.php`, ejecuta `php artisan config:cache` y recarga PHP-FPM.

---

## 15. Lista de verificación final

- [ ] `https://tu-dominio.com` carga la tienda con las imágenes de los 4 productos.
- [ ] El menú "¿En qué te ayudamos?" abre WhatsApp con el número correcto (`SHOP_WHATSAPP_NUMBER`).
- [ ] `https://tu-dominio.com/sitemap.xml` responde con enlaces `https://tu-dominio.com/...`.
- [ ] `https://tu-dominio.com/producto/cafe-premium` abre el producto; una URL inventada muestra la página 404.
- [ ] `https://tu-dominio.com/admin/login` abre el acceso y el administrador entra (aterriza en **Hoy**).
- [ ] La cookie de sesión aparece como `Secure` y `HttpOnly` en las herramientas del navegador.
- [ ] Con `APP_DEBUG=false`, una página que falle **no** muestra rutas ni datos internos.
- [ ] Hay stock cargado en Productos y se puede hacer un pedido de prueba completo (luego se puede anular desde la base de datos o dejarlo como pedido de prueba identificado).
- [ ] `php artisan schedule:list` muestra `activity:prune` a las 03:30, y el cron está instalado.
- [ ] `storage/logs` no tiene errores nuevos después de navegar.
- [ ] Los archivos `.env` y las copias de seguridad **no** están accesibles desde el navegador (la raíz del sitio es `public/`).
- [ ] Si hay proxy, cloudflare o balanceador: sección 14 revisada.
- [ ] Hay una copia de seguridad de la base de datos programada (ver abajo).

---

## 16. ACTUALIZACIÓN (cuando ya está en producción)

Haz esto cada vez que se suban cambios nuevos. **No vuelvas a correr `db:seed` ni `admin:create`**: la base ya tiene sus datos.

1. **Copia de seguridad de la base de datos** (siempre primero):

   ```sh
   mkdir -p ~/backups
   mysqldump -u cfh_user -p --single-transaction cafe_finca_helena > ~/backups/cfh-$(date +%Y%m%d-%H%M%S).sql
   ```

   Guarda también `storage/app/public` (las imágenes subidas desde el panel) si las has cambiado.

2. **Modo mantenimiento** (opcional, para cambios grandes):

   ```sh
   php artisan down
   ```

3. **Traer el código nuevo**:

   ```sh
   git pull origin main
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```

4. **Migraciones nuevas** (solo aplica las que faltan; no borra datos existentes):

   ```sh
   php artisan migrate --force
   ```

   Si el cambio incluye una migración que modifica datos, lee su comentario antes de ejecutarla y revisa que la copia del paso 1 esté bien.

5. **Limpiar y regenerar cachés**:

   ```sh
   php artisan optimize:clear
   php artisan config:cache
   php artisan view:cache
   php artisan event:cache
   ```

   (Otra vez: sin `route:cache`.) Si usas PHP-FPM con OPcache, reinícialo: `sudo systemctl reload php8.2-fpm`.

6. **Salir del modo mantenimiento**:

   ```sh
   php artisan up
   ```

7. **Verificar**: repite los puntos principales de la lista final (tienda, producto, login del panel, Hoy).

**Volver atrás si algo sale mal:** restaura la copia con `mysql -u cfh_user -p cafe_finca_helena < ~/backups/ARCHIVO.sql`, vuelve al commit anterior (`git checkout <commit-anterior>`) y repite los pasos 3 y 5.
