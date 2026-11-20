# Pagos con Wompi: cómo probar en sandbox y plan de pruebas

Guía para el equipo. Los datos de prueba (tarjetas, Nequi, PSE) salen de la documentación oficial de Wompi
(`docs.wompi.co/docs/colombia/datos-de-prueba-en-sandbox/`); lo que no está documentado por Wompi se marca como **(a confirmar)**.

## 0. Cómo funciona (resumen)

1. En el paso 3 del checkout, **Pagar con Wompi** crea el pedido (`pendiente de pago`, con precios, promociones, cupón y stock descontado) y un **intento de pago** con referencia `CFH-XXXXXXXXXXXX-1` (`-2`, `-3`… en cada reintento).
2. El servidor firma el intento con el secreto de integridad (nunca sale del servidor) y manda al cliente al Web Checkout de Wompi, con 30 minutos para pagar.
3. Wompi avisa **al servidor** por webhook (`POST /api/payments/wompi/events`): es la fuente de verdad, aunque el cliente cierre el navegador.
4. El cliente vuelve a `/pago/resultado?id=…`; esa página **no confía en la URL**: el servidor consulta la transacción a Wompi y valida referencia y monto.
5. Un pago aprobado pasa el pedido a **Pagado** (`PaymentService`). Un pedido sin pago se cancela a los 60 minutos (`orders:expire-unpaid`, cada 5 min) devolviendo stock y cupón, **salvo** que Wompi tenga un intento aprobado (entonces se marca pagado) o pendiente (se espera, tope 24 h).
6. Si un pago se aprueba sobre un pedido **ya cancelado**, se registra y aparece una **alerta roja** al admin hasta que la marque como "Revisado".

## 1. Cómo probar en sandbox (Windows, paso a paso)

### a) Túnel hacia tu computador

Wompi necesita una dirección **pública con https** para (1) redirigir al cliente de vuelta y (2) enviar el webhook. Elige **una** opción:

**Opción A: ngrok** (recomendada: tiene inspector para reenviar eventos)

1. Crea una cuenta gratis en ngrok.com y copia tu *authtoken*.
2. En PowerShell: `winget install ngrok.ngrok` y abre una terminal nueva.
3. `ngrok config add-authtoken TU_TOKEN`
4. Con el servidor de Laravel corriendo (paso c), en otra terminal: `ngrok http 8000`
5. Copia la dirección `https://xxxx.ngrok-free.app` que muestra. El inspector de peticiones está en `http://127.0.0.1:4040` (ahí puedes **Replay** de un evento).

**Opción B: cloudflared** (sin cuenta)

1. `winget install --id Cloudflare.cloudflared` y abre una terminal nueva.
2. `cloudflared tunnel --url http://127.0.0.1:8000`
3. Copia la dirección `https://xxxx.trycloudflare.com`.

> La dirección **cambia cada vez** que reinicias el túnel (salvo con un dominio fijo de ngrok). Cada vez que cambie, repite los pasos b) y de la URL de eventos.
> Las instrucciones de ngrok y cloudflared no vienen de Wompi: si cambian sus comandos, consulta su documentación.

### b) `APP_URL` con el túnel (y cómo dejarlo como estaba)

`APP_URL` es la dirección a la que Wompi devuelve al cliente (`/pago/resultado`).

1. **Anota el valor actual** de `APP_URL` en tu `.env` (por ejemplo `http://127.0.0.1:8000`).
2. Cambia solo esa línea: `APP_URL=https://xxxx.ngrok-free.app`
3. `php artisan config:clear`
4. **Al terminar de probar:** vuelve a escribir el valor que anotaste, `php artisan config:clear` y cierra el túnel.

### c) Servidor y frontend (con el túnel: `npm run build`, NO `npm run dev`)

Con el túnel, el celular y Wompi cargan la página desde internet, y el servidor de desarrollo de Vite (`npm run dev`) solo existe en tu computador: **la página saldría sin estilos ni código**.

1. Detén `npm run dev` si está corriendo (Ctrl+C) y comprueba que **no exista** el archivo `public/hot` (si existe, la página sigue buscando a Vite).
2. `npm run build`
3. `php artisan serve` (puerto 8000).
4. Abre `https://xxxx.ngrok-free.app` en tu navegador: debe verse la tienda completa. Después de cambiar código del frontend hay que repetir `npm run build`.
5. Al terminar: puedes volver a `npm run dev` (genera `public/hot` de nuevo).

### d) URL de eventos en el panel de Wompi

1. Entra a `https://comercios.wompi.co` y pasa al ambiente **Sandbox**.
2. Ve a la sección de desarrolladores / secretos para integración técnica y busca el campo de **URL de eventos** (el menú puede llamarse distinto: **a confirmar** en tu panel).
3. Escribe: `https://xxxx.ngrok-free.app/api/payments/wompi/events` y guarda.
4. Wompi configura **una URL por ambiente**: la de Sandbox no afecta a Producción.
5. Comprueba que en tu `.env` estén `WOMPI_ENV=sandbox` y las 4 llaves de pruebas (`pub_test_…`, `prv_test_…`, `test_integrity_…`, `test_events_…`). Si falta alguna, o es de otro ambiente, la tienda **no muestra el botón de pago** y no recibe pedidos.

### e) Datos de prueba (documentación oficial de Wompi)

| Método | Para obtener… | Datos |
| --- | --- | --- |
| Tarjeta | **Aprobado** | `4242 4242 4242 4242`, cualquier fecha futura, cualquier CVC de 3 dígitos |
| Tarjeta | **Rechazado** | `4111 1111 1111 1111`, cualquier fecha futura, cualquier CVC de 3 dígitos |
| Tarjeta | **Error** | Cualquier otra tarjeta: el estado final es `ERROR` |
| Nequi | **Aprobado** | Celular `3991111111` |
| Nequi | **Rechazado** | Celular `3992222222` |
| Nequi | **Error** | Cualquier otro número |
| PSE | **Aprobado / Rechazado** | En el simulador, elegir el "banco que aprueba" o el "banco que rechaza" |
| Bancolombia (botón de transferencia) | Estado a elección | Una página de pruebas te deja elegir el estado final |
| **Pendiente** | PSE o Bancolombia | La documentación **no** da un dato específico para PENDING. Inicia el pago y **no termines** la página simulada del banco: la transacción queda `PENDING` hasta que elijas un estado **(a confirmar en tu sandbox)** |

### f) Qué revisar después de CADA prueba

1. **Hoy** y **Pedidos**: el pedido está en la etapa correcta (ver los casos del plan).
2. **Stock** (Productos): bajó al crear el pedido y no volvió a bajar con los reintentos.
3. **Cupón** (Cupones): usos contados (pedido activo) o liberados (pedido cancelado).
4. **Historial**: una fila por cambio de estado, con actor "Sistema" para los pagos.
5. **Pedidos → detalle → Pago**: cada intento con su estado, método, id de transacción y fecha.
6. Terminal de `php artisan serve` y `storage/logs/laravel.log`: sin errores, y **sin llaves ni secretos**.

### g) `SHOP_REQUIRE_PAYMENT=true` (solo pagados entran a Hoy)

1. En `.env`: `SHOP_REQUIRE_PAYMENT=true` y `php artisan config:clear`.
2. Efecto: en **Hoy** la sección Nuevos solo muestra pedidos **Pagados**; un pedido pendiente de pago **no aparece**; Despacho no lo ve en Pedidos; y el vencimiento cancela todo pedido pendiente (hubiera o no intento de pago).
3. Prueba el flujo completo: crear pedido → pagar → aparece en Hoy como Pagado → "Iniciar preparación".
4. **Al terminar**, vuelve a `SHOP_REQUIRE_PAYMENT=false` si quieres seguir operando pedidos sin pago, y `php artisan config:clear`.

### h) Ajustes para pruebas de tiempo (en `.env`, y `php artisan config:clear`)

| Variable | Producción | Para probar vencimiento |
| --- | --- | --- |
| `WOMPI_PENDING_EXPIRY_MINUTES` | `60` | `1` |
| `WOMPI_CHECKOUT_WINDOW_MINUTES` | `30` | `10` (para el caso de pago tardío) |
| `WOMPI_MANUAL_PENDING_EXPIRY_HOURS` | `24` | `1` (para probar el vencimiento de un pedido manual) |

Al terminar, devuelve ambos valores. El comando `php artisan orders:expire-unpaid` corre solo cada 5 minutos con el scheduler; para probar sin esperar, lo ejecuta una persona del equipo a mano.

---

## 2. Plan de pruebas

Marca cada caso. **El módulo no está terminado hasta que el equipo confirme que el plan pasó.**
Preparación: stock conocido (anótalo), un cupón (por ejemplo `HELENA10`) y una promoción activa en un producto. Los casos "No debe pasar" son pruebas negativas.

### 2.1 Cliente (celular y escritorio)

| # | Pasos | Resultado esperado |
| --- | --- | --- |
| C1 | Agrega un producto, llega al paso 3 | El botón dice **Pagar con Wompi**. No aparece ningún aviso de "pago en línea aún no disponible" (ni en el checkout ni en el carrito). |
| C2 | Pulsa **Pagar con Wompi** | Llega al Web Checkout de Wompi con tu correo, nombre, celular y dirección ya llenos. Muestra el monto correcto y una cuenta regresiva. |
| C3 | Paga con tarjeta `4242 4242 4242 4242` | Vuelve a `/pago/resultado`: **¡Pago recibido!** con referencia, total, correo enmascarado (`m***@dominio.com`) y productos. El carrito queda vacío y el checkout empieza de cero. |
| C4 | Repite con tarjeta `4111 1111 1111 1111` | **Pago rechazado** con el motivo (si Wompi lo da) y el botón **Intentar de nuevo**. El carrito **no** se vacía. |
| C5 | En C4, pulsa **Intentar de nuevo** y paga con `4242…` | Vuelve a Wompi con el mismo pedido; al aprobar muestra **¡Pago recibido!**. Hay un solo pedido (ver D2). |
| C6 | Paga con Nequi `3991111111` | Aprobado igual que con tarjeta (C3). |
| C7 | Paga con Nequi `3992222222` | Pago rechazado (C4). |
| C8 | Paga con PSE eligiendo el banco que aprueba | Aprobado. Con el banco que rechaza: rechazado. |
| C9 | Inicia un pago PSE o Bancolombia y no termines el banco simulado | `/pago/resultado` muestra **Pago en proceso** y se actualiza sola cada pocos segundos. Al terminar el pago en el banco simulado, pasa a **¡Pago recibido!** sin recargar. |
| C10 | Deja el resultado en "en proceso" unos 3 minutos | Cambia el mensaje a "sigue en proceso… tu pedido quedará pagado automáticamente" con enlace a WhatsApp. |
| C11 | Cierra el navegador **antes de volver** de Wompi (en el paso de pago, tras aprobar) | Ver S1: el webhook lo marca pagado igual. Al reabrir la tienda, el carrito sigue lleno hasta que se abra `/pago/resultado` (o se compre de nuevo). |
| C12 | Pulsa **Pagar con Wompi** dos veces seguidas rápido | Un solo pedido y un solo intento abierto (ver D2). |
| C13 | Con cupón `HELENA10` y un producto en promoción, paga | Wompi muestra el total ya con descuento y promoción; el pedido pagado muestra el mismo total (ver D3). |
| C14 | Quita las llaves de Wompi del `.env` (`php artisan config:clear`) y abre el checkout | Mensaje claro "Los pagos en línea no están disponibles en este momento" con enlace a WhatsApp, **sin** botón de pago. La tienda sigue funcionando y no se crea ningún pedido. Restaura las llaves. |
| C15 | **No debe pasar:** abre `/pago/resultado?id=inventado` | "No encontramos ese pago". Ningún dato de pedidos. |
| C16 | **No debe pasar:** abre `/pago/resultado?id=<id real>&status=APPROVED` de un pago **rechazado** | Sigue mostrando **rechazado**: la página nunca confía en lo que dice la URL. |
| C17 | Repite C1–C4 en un **celular** (375 px) y en escritorio | Los botones se ven grandes y legibles; el texto no se corta; las páginas de resultado se ven con los estilos de la tienda. |

### 2.2 Administrador

| # | Pasos | Resultado esperado |
| --- | --- | --- |
| A1 | Pedidos → detalle de un pedido pagado → sección **Pago** | Lista cada intento: estado, método según Wompi (Tarjeta, Nequi, PSE…), motivo si hubo, id de transacción, monto, fechas. |
| A2 | Pulsa **Consultar estado en Wompi** | Mensaje de éxito y el detalle se refresca. Si Wompi no responde: mensaje claro, sin cambios. |
| A3 | En un pedido con pago pendiente en Wompi que ya se aprobó, pulsa **Consultar estado en Wompi** | El pedido pasa a **Pagado** (mismas reglas que el webhook). |
| A4 | Busca un botón o acción para "marcar como pagado" o "confirmar" | **No debe existir** en ninguna pantalla. |
| A5 | Ver la alerta de pago sobre pedido cancelado (S6) | Aviso rojo destacado en **Hoy** y en el **Dashboard**: "Pago recibido en pedido cancelado: CFH-… · $X · revisar reembolso en Wompi"; pastilla roja "Pago recibido · revisar" en la fila del pedido en **Pedidos**; aviso rojo en el detalle. |
| A6 | Pulsa **Revisado** (en Hoy, Dashboard o el detalle) | La alerta desaparece de los tres lugares. En el Historial queda "Revisó pago en pedido cancelado" con tu nombre y la hora. |
| A7 | Historial, módulo Pedidos | Ves "Cambió estado" con actor **Sistema** para pagos y cancelaciones, y "Pago recibido en pedido cancelado" cuando corresponda. |

### 2.3 Despacho y entrega (rol Despacho)

| # | Pasos | Resultado esperado |
| --- | --- | --- |
| D-E1 | Con `SHOP_REQUIRE_PAYMENT=true`, crea un pedido y **no pagues** | **No aparece** en Hoy ni en Pedidos de Despacho. |
| D-E2 | Paga ese pedido | Aparece en **Hoy → Nuevos** con la pastilla verde **Pagado** y el botón "Iniciar preparación". |
| D-E3 | Abre el pedido pagado en Pedidos | Ves productos, dirección y notas; **no ves** precios, cupón, pagos ni correo del cliente. |
| D-E4 | **No debe pasar:** busca la sección Pago, el botón de Wompi o la alerta roja | No existen para Despacho. |
| D-E5 | Avanza el pedido: Iniciar preparación → Pedido preparado → Enviar (con repartidor) | Cada paso funciona y el pedido cambia de sección en Hoy. |

### 2.4 Solo entrega (rol Solo entrega)

| # | Pasos | Resultado esperado |
| --- | --- | --- |
| E1 | Entra con un repartidor al que enviaste un pedido pagado | Aterriza en Hoy → "Por entregar" con **solo sus** pedidos. |
| E2 | **No debe pasar:** busca pedidos sin pagar, precios, cupón, correo o pagos | No aparecen. Tampoco la alerta roja. |
| E3 | Pulsa **Entregado** en su pedido | El pedido pasa a Entregado y sale de Hoy. |
| E4 | **No debe pasar:** cambia el número en la URL `/admin/orders/<id de otro pedido>` | "No tienes acceso" (403). |

### 2.5 Sistema (webhooks, tareas programadas, concurrencia, reintentos e idempotencia)

| # | Pasos | Resultado esperado |
| --- | --- | --- |
| S1 | Paga con tarjeta y **cierra la pestaña** en Wompi antes de volver | En segundos el pedido aparece **Pagado** en Pedidos/Hoy (webhook). Sin abrir `/pago/resultado`. |
| S2 | **Firma inválida:** `curl.exe -i -X POST http://127.0.0.1:8000/api/payments/wompi/events -H "Content-Type: application/json" -d "{\"event\":\"transaction.updated\",\"data\":{\"transaction\":{\"id\":\"x\",\"status\":\"APPROVED\",\"amount_in_cents\":100}},\"signature\":{\"properties\":[\"transaction.id\",\"transaction.status\",\"transaction.amount_in_cents\"],\"checksum\":\"AAAA\"},\"timestamp\":1530291411}"` | Responde **401**. Ningún pedido cambia. En `laravel.log` solo "evento rechazado por checksum inválido", sin datos ni secretos. |
| S3 | **Mismo webhook dos veces:** en el inspector de ngrok (`127.0.0.1:4040`), **Replay** del evento aprobado de S1 | Responde 200 y **no cambia nada**: sigue un solo cambio de estado en el Historial y un solo evento `OrderPaid`. |
| S4 | **Monto o referencia que no coinciden** (alterar el cuerpo de un evento reenviado) | 401 por checksum; o, si es válido pero no coincide, 200 sin cambiar el pedido. |
| S5 | **Pedido vencido sin pago:** `WOMPI_PENDING_EXPIRY_MINUTES=1`; crea un pedido con cupón, **no pagues**; espera 1 min y ejecuta `php artisan orders:expire-unpaid` | El pedido queda **Cancelado**; ver D4 (stock devuelto una vez, cupón liberado). Ejecutarlo otra vez no cambia nada. |
| S6 | **Pago aprobado sobre pedido cancelado:** `WOMPI_CHECKOUT_WINDOW_MINUTES=10` y vencimiento 1 min; pulsa **Pagar con Wompi** y **deja abierta** la pestaña de Wompi sin pagar; espera 1 min y ejecuta `orders:expire-unpaid` (queda Cancelado); vuelve a la pestaña de Wompi y paga con `4242…` | El pago se aprueba pero el pedido **sigue Cancelado**; aparece la **alerta roja** (A5). El stock **no** se vuelve a descontar. |
| S7 | **Vencimiento con pago aprobado** (este caso también confirma la consulta por referencia, cuyo formato Wompi no documenta): crea un pedido, paga con tarjeta aprobada pero **simula** que el webhook no llegó (apaga el túnel antes de pagar), espera 1 min y ejecuta `orders:expire-unpaid` | El comando consulta a Wompi, ve el pago aprobado y marca el pedido **Pagado** en vez de cancelarlo. |
| S8 | **Pago pendiente no se cancela:** deja un pago PSE/Bancolombia en PENDING, espera el vencimiento y ejecuta el comando | El pedido **no** se cancela mientras Wompi lo reporte pendiente (tope 24 h). |
| S9 | **Wompi caído:** desconecta internet un momento y ejecuta `orders:expire-unpaid` con un pedido vencido | **No cancela** (no pudo consultar). Con internet de nuevo, sí actúa. |
| S10 | **Concurrencia:** abre el mismo pedido pagado en dos pestañas de admin y pulsa **Consultar estado en Wompi** en ambas a la vez | Un solo cambio de estado y un solo `OrderPaid`; sin duplicados en el Historial. |
| S11 | Reinicia `php artisan serve` con un pedido en proceso | Al volver, el estado correcto llega por webhook o por la consulta; nada queda a medias. |

### 2.6 Seguridad (permisos por rol, URLs manipuladas y datos sensibles)

| # | Pasos | Resultado esperado |
| --- | --- | --- |
| G1 | **No debe pasar:** con un cliente, llama `POST /api/payments/wompi/attempt` con la referencia de **otro** pedido y un correo equivocado | Respuesta genérica "No pudimos iniciar el pago…" (422), **idéntica** a la de una referencia inexistente: no revela si el pedido existe. |
| G2 | **No debe pasar:** repite G1 más de 10 veces en un minuto | Responde 429 (límite por IP). |
| G3 | **No debe pasar:** abre `/api/payments/wompi/result?id=<id de otro comercio o inventado>` | "No encontramos ese pago" (404), sin datos. |
| G4 | En `/pago/resultado` de un pago real, revisa la respuesta (herramientas del navegador → Red) | Solo referencia, total, productos y correo **enmascarado**. **No** hay dirección, celular, correo completo ni datos de otros pedidos. |
| G5 | Despacho y Solo entrega llaman `POST /admin/orders/<id>/wompi/refresh` o `/wompi/review` | 403. Solo el administrador puede. |
| G6 | **No debe pasar:** el administrador intenta marcar un pedido como pagado o confirmado por cualquier medio (botones, `PATCH /admin/orders/<id>/confirm`, enviando `payment_status=paid`) | No es posible: no existe la ruta o se ignora. El estado solo cambia por lo que dice Wompi. |
| G7 | Revisa el código fuente de la tienda (Ver código fuente / Red) y el Historial | **Ningún** secreto aparece: ni `prv_`, ni `test_integrity_`, ni `test_events_`. La URL de Wompi lleva la llave **pública** y la firma, nunca los secretos. |
| G8 | `/admin/today`, `/admin/orders` sin sesión | Redirige al login. |
| G9 | **No debe pasar:** `robots.txt` y el código de `/pago/resultado` | `robots.txt` tiene `Disallow: /pago/` y la página lleva `noindex`. |
| G10 | **No debe pasar:** un cliente entra a `/admin/orders/<id>/detail` | 401 (sin sesión). |

### 2.7 Datos (stock, cupones, totales e Historial coherentes después de cada caso)

| # | Después de… | Verifica |
| --- | --- | --- |
| D1 | C3 (aprobado con tarjeta) | Stock bajó **una vez** (cantidad del pedido). Pedido Pagado, total = suma de líneas − descuento. Cupón con 1 uso. Historial: una fila "Cambió estado pendiente → Pagado" con actor Sistema. |
| D2 | C4 + C5 (rechazo y reintento) | **Un solo pedido**, stock descontado **una sola vez**, dos intentos en Pago (`…-1` rechazado, `…-2` aprobado) con la misma base de referencia. |
| D3 | C13 (cupón y promoción) | El total pagado en Wompi = total del pedido. Precio promocional y descuento de cupón aplicados una sola vez; el pedido guarda el precio original y el cupón. |
| D4 | S5 (vencido sin pago) | Pedido Cancelado; stock **de vuelta al valor inicial** (una sola vez aunque corras el comando dos veces); el cupón sin uso y reutilizable; Historial "Cambió estado pendiente → Cancelado" con motivo y actor Sistema. |
| D5 | S6 (pago tardío) | Pedido sigue Cancelado; stock igual al valor inicial (no se vendió dos veces); el pago figura Aprobado con alerta; Historial con "Pago recibido en pedido cancelado"; tras **Revisado**, Historial con tu nombre y hora. |
| D6 | S3 (evento repetido) | Sin cambios respecto a D1: un solo cambio de estado, un solo uso de cupón. |
| D7 | S7 (vencimiento con pago) | Pedido Pagado, stock **sin devolver**, cupón con su uso. |
| D8 | Cualquier prueba | El **Dashboard** cuenta como "pagado" solo los pagos aprobados y verificados; los pagos rechazados o pendientes no suman a ventas. |
| D9 | Pedido pagado visto por cada rol | Admin: ve todo (total, pagos, correo). Despacho: sin precios, cupón, pagos ni correo. Solo entrega: solo su pedido asignado, sin datos financieros. |

### 2.8 Qué ve cada rol de un pedido pagado

| Dónde | Administrador | Despacho | Solo entrega |
| --- | --- | --- | --- |
| **Hoy** | Sección Nuevos, pastilla verde **Pagado**, total, botón "Iniciar preparación"; alertas rojas de pagos tardíos | Sección Nuevos con **Pagado**, sin total ni correo, mismo botón | No aparece hasta que se le asigne en "Por entregar" |
| **Pedidos (lista)** | Estado Pagado, pago Pagado, total, cupón | Estado Pagado, sin total ni pago ni cupón | Solo sus pedidos asignados |
| **Pedidos (detalle)** | Sección Pago con intentos, método, **Consultar estado en Wompi** | Datos de entrega y productos, sin Pago | Su pedido, sin datos financieros |

## 3. Comandos del equipo

Estos los ejecuta quien prueba (no los ejecuta Claude):

| Para qué | Comando |
| --- | --- |
| Servidor | `php artisan serve` |
| Frontend para el túnel | `npm run build` |
| Limpiar configuración tras tocar `.env` | `php artisan config:clear` |
| Probar el vencimiento sin esperar | `php artisan orders:expire-unpaid` |
| Probar el scheduler completo | `php artisan schedule:run` |
| Ver el log | `Get-Content storage/logs/laravel.log -Tail 50` |
