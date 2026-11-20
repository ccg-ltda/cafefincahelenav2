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
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | `stack` / `daily` / `warning` | Registro de errores en `storage/logs`, un archivo por día (se conservan 14: `LOG_DAILY_DAYS`). Con `single` el archivo crece sin límite |
| `DB_CONNECTION` | `mysql` | Tipo de base de datos |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Dónde está MySQL |
| `DB_DATABASE` | `cafe_finca_helena` | Nombre de la base |
| `DB_USERNAME` / `DB_PASSWORD` | el usuario del paso 3 | Acceso a la base |
| `SESSION_DRIVER` | `file` (o `database`) | Dónde se guardan las sesiones del panel |
| `SESSION_LIFETIME` | `120` | Minutos de inactividad antes de cerrar la sesión |
| `SESSION_ENCRYPT` | `true` | Cifra el contenido de las sesiones guardadas en el servidor |
| `SESSION_SECURE_COOKIE` | `true` | La cookie de sesión solo viaja por HTTPS |
| `SESSION_HTTP_ONLY` | `true` | El JavaScript de la página no puede leer la cookie |
| `SESSION_SAME_SITE` | `lax` | Protección contra envíos desde otros sitios |
| `SECURITY_CSP_REPORT_ONLY` | `false` | `true` solo para diagnosticar: la política de contenido no bloquea, solo avisa en la consola (sección 14b) |
| `TRUSTED_PROXIES` | vacío | IP o rangos del proxy/Cloudflare, separados por coma. Vacío = no confiar en ninguno (sección 14 y 14b) |
| `SECURITY_CHECK_PWNED` | `true` | Revisa las contraseñas nuevas contra filtraciones conocidas; si el servicio no responde, se acepta la contraseña |
| `CACHE_STORE` | `file` | Dónde se guarda la caché (también cuenta los límites por IP) |
| `QUEUE_CONNECTION` | `database` | Los correos del pedido salen por la cola (sección 11b). Con `sync` se envían dentro de la misma petición: solo para local |
| `MAIL_MAILER` | `smtp` | Correos del pedido. Con `log` no sale nada (solo se escribe en `storage/logs`). Ver sección 11b |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_SCHEME` | los de tu proveedor | SMTP del dominio. Puerto 587 con STARTTLS (`MAIL_SCHEME` vacío) o 465 con `smtps` |
| `MAIL_FROM_ADDRESS` | `notificaciones@tu-dominio.com` | **Obligatorio.** Remitente: debe ser un buzón del dominio autenticado con SPF/DKIM (sección 11b) |
| `MAIL_FROM_NAME` | `"Café Finca Helena"` | Nombre que ve el cliente |
| `SHOP_ADMIN_NOTIFICATION_EMAILS` | `ventas@tu-dominio.com,otra@tu-dominio.com` | Quién recibe «Nuevo pedido pagado» y la alerta de pago sobre pedido cancelado (separados por comas). Vacía = esos avisos no se envían y queda un aviso en el log. Un correo por destinatario |
| `SHOP_WHATSAPP_NUMBER` | `573XXXXXXXXX` | **Obligatorio.** Número de WhatsApp de la tienda: indicativo de país + número, solo dígitos (ej. `573027880823`). Sin él, desaparecen los botones de WhatsApp de la tienda |
| `SHOP_REQUIRE_PAYMENT` | `false` hoy | Si es `true`, solo los pedidos con pago aprobado se pueden preparar y enviar. Déjalo en `false` mientras no esté conectado el pago en línea (Wompi); pasarlo a `true` sin pagos conectados deja los pedidos sin poder moverse |
| `SHOP_ACTIVITY_AUTH_RETENTION_DAYS` | `180` (opcional) | Días que se guardan los registros de inicio de sesión en el Historial |
| `WOMPI_ENV` | `production` (o `sandbox` para probar) | Ambiente de Wompi. Las 4 llaves deben ser de **ese** ambiente (`pub_prod_…`, `prv_prod_…`, `prod_integrity_…`, `prod_events_…`; en sandbox `pub_test_…`, `prv_test_…`, `test_integrity_…`, `test_events_…`) |
| `WOMPI_PUBLIC_KEY` | llave pública | Va en la dirección del Web Checkout (no es secreta) |
| `WOMPI_PRIVATE_KEY` | llave privada | **Secreta.** Solo el servidor la usa para consultar transacciones |
| `WOMPI_INTEGRITY_SECRET` | secreto de integridad | **Secreto.** Firma cada pago; nunca sale del servidor |
| `WOMPI_EVENTS_SECRET` | secreto de eventos | **Secreto.** Verifica que los avisos (webhook) vienen de Wompi |
| `WOMPI_CHECKOUT_WINDOW_MINUTES` | `30` | Minutos que tiene el cliente para pagar en Wompi |
| `WOMPI_PENDING_EXPIRY_MINUTES` | `60` | Minutos tras los que un pedido sin pago se cancela (devolviendo stock y cupón). Un pago aún pendiente en Wompi lo mantiene vivo, con tope de 24 h |
| `WOMPI_MANUAL_PENDING_EXPIRY_HOURS` | `24` | Solo **pedidos manuales** (los registra el administrador desde Pedido manual y el cliente paga con un link de Wompi): horas que dura el link de pago y plazo para que el pedido se cancele solo si nadie paga. Mínimo 1. Ver sección 11c |

**Pagos con Wompi.** Sin las 4 llaves válidas del ambiente configurado, la tienda **no recibe pedidos** (el checkout muestra "Los pagos en línea no están disponibles en este momento" con el enlace de WhatsApp). Después de instalar:

1. En el panel de Wompi (`comercios.wompi.co`), en el ambiente de **Producción**, configura la **URL de eventos**: `https://tu-dominio.com/api/payments/wompi/events`. Wompi usa una URL distinta por ambiente.
2. Para que el webhook y la redirección funcionen, `APP_URL` debe ser la dirección pública con `https`.
3. Con `SHOP_REQUIRE_PAYMENT=true`, solo los pedidos pagados llegan a Hoy y a Despacho. Actívalo cuando los pagos funcionen.
4. Cómo probar todo en sandbox: `docs/wompi-sandbox.md`.

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

Hay tres tareas programadas: `queue:work` (cada minuto: envía los correos pendientes, sección 11b), `orders:expire-unpaid` (cada 5 minutos: cancela los pedidos sin pago vencidos, consultando antes a Wompi) y `activity:prune`, que cada día a las 03:30 borra los registros de inicio de sesión más antiguos que el límite (los registros de acciones administrativas **nunca** se borran).

Agrega al cron del usuario del servidor web (`sudo crontab -u www-data -e`):

```
* * * * * cd /var/www/cafe-finca-helena && php artisan schedule:run >> /dev/null 2>&1
```

## 11b. Correos del pedido (cola, SMTP y entregabilidad)

Qué se envía: al cliente, el comprobante de pago y los cambios a *En preparación*, *Preparado*, *En camino*, *Entregado* y (solo si ya estaba pagado) *Cancelado*; a los administradores de `SHOP_ADMIN_NOTIFICATION_EMAILS`, «Nuevo pedido pagado» y la alerta de pago sobre un pedido cancelado. **No** se envía nada al crear el pedido ni cuando un pedido sin pagar vence. Despacho y Solo entrega no reciben correos.

**Cola sin supervisor (hosting compartido).** Con `QUEUE_CONNECTION=database` el pedido solo anota el correo en la tabla `jobs`; el scheduler ejecuta cada minuto `queue:work --stop-when-empty --max-time=55 --tries=1`. Solo hace falta el cron del paso 11. Un correo puede tardar hasta un minuto en salir. Si el SMTP falla, el pedido **no** se ve afectado: el trabajo se reintenta hasta 5 veces (a 1, 5, 15 y 60 minutos) y después queda como *Falló*, con el botón **Reenviar** en Pedidos → detalle → Correos. El `--tries=1` del comando no limita esos reintentos: manda el `$tries`/`backoff()` del propio trabajo (verificado). Al desplegar código nuevo no hay worker que reiniciar: cada minuto arranca uno nuevo.

**SMTP del dominio.** Crea el buzón `notificaciones@tu-dominio.com` en tu hosting o proveedor (cPanel, Google Workspace, Zoho, Brevo, etc.) y copia sus datos a `MAIL_*`. Prueba con `php artisan tinker` → `Mail::raw('prueba', fn ($m) => $m->to('tu@correo.com')->subject('Prueba'));` (`Mail::raw` envía al instante, sin pasar por la cola).

**Para que no lleguen a spam**, en el DNS del dominio:
1. **SPF** (TXT en la raíz): incluye a quien envía, por ejemplo `v=spf1 include:_spf.tu-proveedor.com ~all`. Debe haber **un solo** registro SPF.
2. **DKIM** (TXT `selector._domainkey`): tu proveedor te da el selector y la clave pública; actívalo en su panel.
3. **DMARC** (TXT `_dmarc`): empieza en modo observación, `v=DMARC1; p=none; rua=mailto:dmarc@tu-dominio.com`, y pasa a `quarantine` cuando veas que todo se firma bien.
4. El remitente (`MAIL_FROM_ADDRESS`) debe ser del mismo dominio que firma DKIM. Revisa el resultado enviando un correo a una cuenta de Gmail y abriendo «Mostrar original» (SPF, DKIM y DMARC deben decir `PASS`), o con una herramienta como mail-tester.com.
5. `APP_URL` debe ser la URL pública con `https`: de ahí salen el logo (`/media/email/logo.png`) y el enlace al panel de los correos.

Vista previa sin enviar: `/admin/emails/preview` (solo administradores).

---

## 11c. Pedido manual (pedidos por WhatsApp o teléfono)

El administrador registra en **Pedido manual** (`/admin/orders/new`) un pedido que llegó por WhatsApp o por teléfono. El cliente **solo puede pagar con Wompi**: al guardar se crea el pedido (pendiente de pago, canal «Manual») y un link de pago que el administrador envía por WhatsApp. El pedido pasa a Pagado **únicamente** cuando Wompi aprueba el pago (el webhook de siempre); no existe «ya pagó», medio de pago manual ni comprobante.

- **Requisitos:** las llaves de Wompi (sección 11b/Wompi) y un `APP_URL` público con `https` (Wompi rechaza una dirección local en `redirect-url`). Sin ellas el botón «Guardar y generar link de pago» responde que no se puede y **no crea nada**.
- **Plazo:** `WOMPI_MANUAL_PENDING_EXPIRY_HOURS` (24 por defecto). Es a la vez lo que dura el link (`expiration-time`) y el plazo tras el que `orders:expire-unpaid` cancela el pedido (devolviendo stock y cupón): cada pedido guarda su propio vencimiento (`orders.payment_due_at`), así que cambiar la variable no altera los pedidos ya creados. Los pedidos de la tienda siguen con `WOMPI_PENDING_EXPIRY_MINUTES` y `WOMPI_CHECKOUT_WINDOW_MINUTES`, sin cambios. Probado en el sandbox de Wompi: acepta links de 24 h, 48 h, 72 h, 7 días y 30 días.
- **Nuevo link:** en el detalle de un pedido manual pendiente, «Copiar link» entrega el link vigente. «Generar nuevo link de pago» solo aparece cuando el anterior ya venció (un link de Wompi no se puede anular: con dos vigentes el cliente podría pagar dos veces); el nuevo link renueva el plazo del pedido.
- **Privacidad:** el link de un pedido manual solo lleva lo necesario para cobrar (llave pública, moneda, monto, referencia, firma, vencimiento, dirección de retorno y el correo si existe); **no** lleva nombre, celular ni dirección. El mensaje de WhatsApp sí menciona el nombre del cliente porque se envía a su propio número.
- **Pagos y despacho:** un pedido manual sin pagar no aparece en Hoy ni en la lista de despacho, ni se puede preparar, aunque `SHOP_REQUIRE_PAYMENT=false`. Tampoco cuenta como compra del cliente hasta que Wompi lo apruebe.
- **Sin correo:** el correo del cliente es opcional en el pedido manual (se identifica por celular). Sin correo no se envía el comprobante al cliente; el aviso al administrador sí sale.
- **Migraciones** (se aplican una por una, con copia de seguridad antes): `2026_11_21_000001_add_channel_created_by_and_payment_due_to_orders` y `2026_11_21_000002_make_customer_email_optional`.

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

**Qué hay que hacer en ese caso.** Decirle a Laravel en qué intermediarios confiar para que lea la IP real del cliente (la cabecera `X-Forwarded-For` que envía el intermediario). Ya **no hay que editar `bootstrap/app.php`**: se hace en `.env` con `TRUSTED_PROXIES` (vacío por defecto = no confiar en ninguno):

```env
TRUSTED_PROXIES=IP_DEL_PROXY_O_BALANCEADOR
```

Ejemplos para nginx y Cloudflare en la sección 14b.

- **Si no hay intermediario** (el servidor recibe a los visitantes directamente), **no agregues nada**.
- Usa `TRUSTED_PROXIES=*` solo si el servidor **no es accesible directamente desde internet** (solo a través del proxy o de Cloudflare). Si lo fuera, alguien podría falsificar su IP. Si no estás seguro, pon la IP concreta del proxy o del balanceador.
- Detrás de **Cloudflare**, el servidor debería aceptar conexiones solo desde los rangos de IP de Cloudflare (publicados por ellos) y entonces `*` es aceptable; aun así es mejor listar los rangos (sección 14b).
- El proxy debe enviar `X-Forwarded-Proto: https`; si no, Laravel creará enlaces con `http://` y la cookie segura del panel puede fallar.

**Cómo comprobarlo.** Desde dos redes distintas (por ejemplo tu celular con datos y un computador con wifi) abre el panel y consulta el Historial: los inicios de sesión deben mostrar IP diferentes, no la del proxy. Después de cambiar `.env`, ejecuta `php artisan config:clear` (o `config:cache`) y recarga PHP-FPM.

---

## 14b. Seguridad en producción (cabeceras, HTTPS, bloqueo de acceso, servidor web y copias)

Esta sección reúne lo que protege el sitio una vez publicado. Lo que hace la aplicación por sí sola está en el código; lo que **debe configurarse en el servidor** está marcado con ☐.

### Qué hace la aplicación sola

| Protección | Qué hace | Cómo se ajusta |
| --- | --- | --- |
| **Cabeceras de seguridad** | Cada respuesta lleva `Content-Security-Policy` (la página solo carga sus propios archivos, Google Fonts y sus imágenes), `frame-ancestors 'none'` (el panel no se puede incrustar en otra web), `X-Content-Type-Options: nosniff`, `Referrer-Policy` y `Permissions-Policy`. Quita `X-Powered-By`. | Nada. Si algo se ve roto después de publicar, ver "Modo solo reporte" abajo. |
| **HTTPS** | Con `APP_ENV=production` todos los enlaces se generan con `https://` y, en una visita por HTTPS, se envía `Strict-Transport-Security` (HSTS, 1 año) y `upgrade-insecure-requests`. | `SECURITY_HSTS_MAX_AGE` (segundos). |
| **Bloqueo de cuentas** | 5 intentos fallidos seguidos a una cuenta → espera de **1 minuto**; el siguiente fallo → **5 minutos**; luego **15 minutos** cada vez. Es por cuenta, sin importar la IP. Un acierto o una hora sin fallos lo reinicia. Además hay un límite por IP más holgado (60 intentos por minuto). El Historial registra "Cuenta bloqueada tras N intentos fallidos". | Valores en `config/security.php` (`login`). |
| **Contraseñas** | Mínimo 10 caracteres; se rechazan repeticiones (`aaaa…`), secuencias (`12345`, `abcde`), palabras comunes y contraseñas que aparecen en filtraciones conocidas. Si el servicio de filtraciones no responde, se acepta la contraseña. | `SECURITY_CHECK_PWNED=false` apaga solo la consulta de filtraciones. |
| **Sesiones** | Cifradas (`SESSION_ENCRYPT=true`), cookie `Secure` + `HttpOnly` + `SameSite=lax`, cierre por **120 minutos de inactividad** (`SESSION_LIFETIME`). Al volver, el login dice "Tu sesión se cerró por inactividad. Vuelve a iniciar sesión." | `SESSION_LIFETIME` (minutos). |
| **CORS** | Solo la propia dirección de la tienda (`APP_URL`) puede llamar a `/api/*` desde un navegador. | `APP_URL`. |
| **Páginas de error** | 403, 404, 419, 429, 500 y 503 con la marca y en español, sin rutas ni versiones. | `resources/views/errors/`. |

> **Un bloqueo de cuenta se puede provocar a propósito.** Alguien que sepa el usuario de una persona puede equivocarse 5 veces para dejarla fuera 1 minuto (y hasta 15 si insiste). Es el costo de frenar la adivinación de contraseñas. Las demás cuentas siguen entrando, y el Historial deja constancia. Para liberar una cuenta antes: `php artisan cache:clear` (borra también otras cachés, no afecta datos).

### Modo solo reporte de la CSP

Si después de publicar una página se ve rota (una fuente, una imagen, un botón) y sospechas de la política de contenido:

1. En `.env` pon `SECURITY_CSP_REPORT_ONLY=true`.
2. `php artisan config:clear` (o `config:cache` si usas caché de configuración) y recarga PHP-FPM.
3. El navegador **deja de bloquear** y escribe en la consola (F12 → Consola) lo que habría bloqueado: `[Report Only] Refused to load ... because it violates the following Content Security Policy directive ...`.
4. Avisa con ese texto para ajustar la política y vuelve a `SECURITY_CSP_REPORT_ONLY=false`. No dejes el modo de reporte como estado normal.

### ☐ HTTPS y HSTS

- El servidor debe **redirigir todo `http://` a `https://`** (ver ejemplos más abajo) y tener un certificado válido **antes** de publicar: HSTS le dice al navegador que durante un año solo use HTTPS para este dominio.
- Si el sitio está detrás de un proxy o Cloudflare, el proxy debe enviar `X-Forwarded-Proto: https` y hay que configurar `TRUSTED_PROXIES` (siguiente punto); si no, la aplicación no sabe que la visita es segura y no envía HSTS.

### ☐ Proxies de confianza (`TRUSTED_PROXIES`)

Por defecto **no se confía en ningún proxy**: la IP del visitante es la IP de la conexión. Eso es lo correcto cuando el servidor recibe a los visitantes directamente. Si hay un intermediario, escribe sus IP o rangos en `.env`, separados por coma:

```env
# nginx en el mismo servidor haciendo de proxy inverso (PHP detrás de él):
TRUSTED_PROXIES=127.0.0.1

# Un balanceador o proxy propio con IP fija:
TRUSTED_PROXIES=10.0.0.5

# Cloudflare: los rangos oficiales están en https://www.cloudflare.com/ips-v4 y /ips-v6 (cámbialos si Cloudflare los actualiza):
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22
```

- **Nunca** pongas `*` salvo que el servidor sea inalcanzable desde internet excepto a través del proxy (con `*` cualquiera podría falsificar su IP y esquivar los límites). Un `*` dentro de una lista se ignora a propósito.
- Después de cambiarlo: `php artisan config:clear`. **Comprobación**: con dos redes distintas, el Historial debe mostrar IP diferentes, no la del proxy.

### ☐ Servidor web: archivos bloqueados, sin listado de directorios

**La carpeta pública del servidor debe ser `public/`**, nunca la raíz del proyecto: así `.env`, `.git`, `vendor`, `storage`, `docs`, `database`, `composer.json`, `CLAUDE.md`, `DEPLOY.md` y las copias `.sql` no están en la web. Aun así, bloquéalos explícitamente (cinturón y tirantes).

**nginx** (ejemplo completo del sitio; ajusta rutas, dominio y socket de PHP):

```nginx
server {
    listen 80;
    server_name tu-dominio.com www.tu-dominio.com;
    return 301 https://tu-dominio.com$request_uri;
}

server {
    listen 443 ssl http2;
    server_name tu-dominio.com;

    root /var/www/cafe-finca-helena/public;     # <-- siempre public/
    index index.php;
    charset utf-8;
    server_tokens off;                          # no mostrar la versión de nginx
    autoindex off;                              # sin listado de directorios
    client_max_body_size 8m;                    # las imágenes de producto pesan hasta 5 MB

    ssl_certificate     /etc/letsencrypt/live/tu-dominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tu-dominio.com/privkey.pem;

    # HSTS lo envía la aplicación en las páginas; esta línea lo extiende a archivos estáticos (imágenes, JS, CSS).
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Archivos ocultos (.env, .git, .htaccess...) y de respaldo/código: nunca.
    location ~ /\.(?!well-known) { deny all; return 404; }
    location ~* \.(md|sql|bak|log|ini|lock|sh|swp|old|orig|dist|yml|yaml|env)$ { deny all; return 404; }
    location ~* ^/(vendor|docs|database|tests|config|app|bootstrap|routes|resources|node_modules|storage/app|storage/logs|storage/framework)(/|$) { deny all; return 404; }

    # /storage = enlace a storage/app/public: solo imágenes, nada más.
    location ^~ /storage/ {
        location ~* \.(jpe?g|png|webp|gif|ico)$ { try_files $uri =404; expires 30d; access_log off; }
        return 404;
    }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location = /index.php {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \.php$ { return 404; }           # ningún otro .php se ejecuta
}
```

**Apache** (con `AllowOverride All`, en `public/.htaccess`, añade esto **arriba** de las reglas de Laravel; o en el `VirtualHost`):

```apache
DocumentRoot /var/www/cafe-finca-helena/public   # <-- siempre public/
ServerTokens Prod
ServerSignature Off
Options -Indexes                                  # sin listado de directorios

<FilesMatch "(^\.|\.(md|sql|bak|log|ini|lock|sh|swp|old|orig|dist|yml|yaml|env)$)">
    Require all denied
</FilesMatch>

<IfModule mod_headers.c>
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always unset X-Powered-By
</IfModule>

# Redirigir http a https
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

En hosting compartido con cPanel (Bluehost): sube el proyecto **fuera** de `public_html` y deja en `public_html` solo el contenido de `public/` (ajustando las dos rutas de `index.php`), o apunta el dominio a la carpeta `public/` desde el panel. Si `.env` o `.git` quedaran dentro de `public_html`, bloquéalos con el bloque `FilesMatch` de arriba.

**PHP** (`php.ini` del servidor): `expose_php = Off` (quita la cabecera `X-Powered-By` desde la raíz; la aplicación también la elimina), `display_errors = Off`, `log_errors = On`. Reinicia PHP-FPM.

**Comprobación** (desde tu computador; todas deben dar **404 o 403**, nunca 200 ni contenido):

```sh
for u in /.env /.env.example /.git/config /vendor/autoload.php /composer.json /package.json /artisan \
         /storage/logs/laravel.log /storage/app/private/backups/ /database/ /docs/admin-login.md \
         /CLAUDE.md /DEPLOY.md /backup.sql /public/index.php /storage/ /build/ /media/ ; do
  printf '%s  ' "$(curl -s -o /dev/null -w '%{http_code}' https://tu-dominio.com$u)"; echo "$u"
done
curl -sI https://tu-dominio.com/ | grep -iE 'x-powered-by|server:|strict-transport|content-security|x-content-type'
```

La última línea debe mostrar CSP, HSTS y `nosniff`, y **no** `X-Powered-By` ni la versión del servidor.

### ☐ Registros (logs)

En producción: `LOG_STACK=daily`, `LOG_LEVEL=warning` y `LOG_DAILY_DAYS=14` (un archivo por día, se borran solos a los 14 días). Con `single` el archivo crece sin límite (en desarrollo llegó a 9 MB). Los registros nunca deben contener contraseñas ni direcciones completas; si ves una, avisa. La carpeta `storage/logs` no es accesible desde la web (ver arriba).

### ☐ Copias de seguridad automáticas de la base de datos

**Recomendación:** una copia **diaria** de madrugada, guardar **14 diarias + 8 semanales (domingos)**, y copiar **fuera del servidor** (otro disco, otro servidor o almacenamiento en la nube): una copia en el mismo servidor no sirve si el servidor se pierde. Haz también copia de `storage/app/public` (imágenes subidas) cuando cambien.

1. Guarda las credenciales en un archivo que solo tú puedas leer, para no escribir la contraseña en el cron: `~/.my.cnf` (permisos `chmod 600`):

   ```ini
   [client]
   user=cfh_user
   password=LA_CLAVE_DEL_USUARIO
   ```

2. Script `~/backup-db.sh` (`chmod 700`):

   ```sh
   #!/bin/sh
   set -e
   DIR=~/backups; mkdir -p "$DIR/diarias" "$DIR/semanales"
   FILE="$DIR/diarias/cfh-$(date +%Y%m%d-%H%M%S).sql.gz"
   mysqldump --single-transaction --routines --default-character-set=utf8mb4 cafe_finca_helena | gzip > "$FILE"
   [ -s "$FILE" ] || { echo "Copia vacía: $FILE" >&2; exit 1; }
   [ "$(date +%u)" = 7 ] && cp "$FILE" "$DIR/semanales/"
   find "$DIR/diarias"   -name '*.sql.gz' -mtime +14 -delete
   find "$DIR/semanales" -name '*.sql.gz' -mtime +56 -delete
   # Copia fuera del servidor (ajusta el destino): rsync -az "$DIR/" usuario@otro-servidor:backups-cfh/
   ```

3. Cron (`crontab -e`), todos los días a las 2:30: `30 2 * * * /bin/sh ~/backup-db.sh >> ~/backups/backup.log 2>&1`
4. **Cómo restaurar** (en caso de emergencia; primero pon el sitio en mantenimiento con `php artisan down`):

   ```sh
   gunzip -c ~/backups/diarias/ARCHIVO.sql.gz | mysql cafe_finca_helena
   php artisan up
   ```

5. **Prueba la restauración una vez al mes** en una base distinta (`mysql -e "CREATE DATABASE cfh_prueba"` y `gunzip -c ARCHIVO | mysql cfh_prueba`): una copia que nunca se probó no es una copia. Revisa también que el archivo del día no esté vacío.

### Notas para producción (de la auditoría)

- **Usuario de base de datos propio.** En desarrollo se usa `root` sin restricciones; en producción crea un usuario solo para esta base (paso 3) con `GRANT ALL PRIVILEGES ON cafe_finca_helena.* TO 'cfh_user'@'localhost';` y nada más. Nunca `root`.
- **`.env` de producción.** `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`. Con `APP_DEBUG=true` cualquier error muestra rutas y datos internos.
- **`public/.htaccess` y archivos ocultos.** El servidor de desarrollo de PHP (`php artisan serve`) sí entrega `public/.htaccess`; Apache y nginx no, y las reglas de arriba bloquean cualquier archivo que empiece con punto. No es un problema en producción, solo conviene saberlo si lo ves en pruebas locales.
- **El historial de git** contiene el nombre y correo de dos personas del sistema anterior (`backend/database/schema-inicial.sql`, sin contraseñas). Reescribir el historial lo decide el equipo; no se hizo en esta auditoría.

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
- [ ] `php artisan schedule:list` muestra `activity:prune` a las 03:30, `orders:expire-unpaid` cada 5 minutos y `queue:work` cada minuto, y el cron está instalado.
- [ ] Correos: `QUEUE_CONNECTION=database`, `MAIL_*` y `SHOP_ADMIN_NOTIFICATION_EMAILS` configurados, SPF/DKIM/DMARC en `PASS` y un pago de prueba envía el comprobante al cliente y el aviso al administrador (Pedidos → detalle → Correos en *Enviado*).
- [ ] Las 4 llaves de Wompi son del ambiente de `WOMPI_ENV`, la **URL de eventos** está configurada en Wompi y el checkout muestra **Pagar con Wompi** (con llaves faltantes no hay botón).
- [ ] Un pago de prueba en producción aprueba el pedido (Pedidos → detalle → Pago muestra el intento aprobado) y el webhook responde 200.
- [ ] `storage/logs` no tiene errores nuevos después de navegar.
- [ ] Los archivos `.env` y las copias de seguridad **no** están accesibles desde el navegador (la raíz del sitio es `public/`).
- [ ] Si hay proxy, cloudflare o balanceador: sección 14 revisada.
- [ ] Hay una copia de seguridad de la base de datos programada y probada (sección 14b).
- [ ] Seguridad (sección 14b): `SESSION_ENCRYPT=true`, `APP_DEBUG=false`, redirección a HTTPS, HSTS y la CSP presentes en `curl -I`, sin `X-Powered-By`; las URLs sensibles (`/.env`, `/.git/config`, `/storage/logs/laravel.log`, `/vendor/`, `/composer.json`, `/DEPLOY.md`, `*.sql`) responden 403/404; `TRUSTED_PROXIES` configurado si hay proxy; `LOG_STACK=daily` y `LOG_LEVEL=warning`.

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
