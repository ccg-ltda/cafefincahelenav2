# Datos del dashboard administrativo

`GET /admin/dashboard/summary?days=30` usa la sesión web y el middleware administrativo existente. Acepta `days=7`, `30` o `90` (30 por defecto); otros valores reciben 422. La respuesta es JSON privado sin caché. Sin sesión devuelve 401 para solicitudes JSON; un usuario no administrador recibe 403.

El período incluye el día actual y los días calendarios anteriores, desde las 00:00 de Bogotá hasta el instante de consulta. Las fechas se consultan en UTC. Un pedido pagado requiere una transacción `approved` con identificador externo no vacío, `confirmed_at` y `verified_at`, moneda COP y un importe igual al total COP del pedido. Se usa la primera fecha de confirmación que cumpla estos requisitos para contar cada pedido una sola vez, incluso si existen múltiples intentos. La verificación de proveedor debe hacerla un proceso de servidor confiable; esta entrega no implementa ese proceso, el checkout ni el webhook. No se deben rellenar esos campos desde datos afirmados por el navegador.

Ventas y ticket usan el total del pedido, incluido envío y descuento; el ticket se redondea al peso entero más cercano. Ventas diarias se agrupan por fecha de confirmación en Bogotá; pedidos por estado usan el estado operativo actual. Productos agrupa cantidades por SKU y nombre históricos de las líneas, y ciudades por el texto guardado en el pedido; ambos muestran los cinco primeros. Wompi cuenta pedidos del período con al menos un pago Wompi que cumpla los criterios verificados, no intentos; puede coexistir con otro proveedor para el mismo pedido. Los gráficos vacíos no contienen datos sintéticos.

Un cliente nuevo es un correo de comprador invitado, normalizado con `LOWER(TRIM(...))`, cuyo primer pedido histórico se creó durante el período, sin exigir que esté pagado. No se consultan usuarios ni se crean cuentas. Los pedidos recientes son los últimos cinco pedidos creados en el período. Para atender hoy muestra los totales actuales de pedidos en estados operativos `pending` y `processing`, sin límite temporal ni promesa de que tengan vencimiento hoy. Los demás estados no se infieren.

Las migraciones se preparan sin ejecutarse. Los importes son enteros no negativos en COP; los identificadores de proveedor/referencia y proveedor/transacción son únicos. Para MariaDB/MySQL se preparan restricciones CHECK de moneda, cantidad positiva, subtotal de línea y total de pedido; su aplicación depende de que la versión del servidor aplique CHECK. En otros motores esas restricciones no se añaden. Antes de incorporar una escritura de pedidos, esta deberá validar además que el subtotal del pedido corresponda a la suma de sus líneas, una relación entre tablas que estas restricciones no imponen. No hay una ruta de creación en esta etapa. Los estados y la sincronización de `orders.payment_status` y `orders.paid_at` deberán definirse en el flujo transaccional futuro, después de verificar al proveedor. El dashboard no confía en esos campos para calcular ventas.

Aplicar manualmente tras revisar la conexión de base de datos y respaldar según el procedimiento del proyecto:

```sh
php artisan migrate:status
php artisan migrate
```
