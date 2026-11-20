# Edición administrativa del catálogo

La interfaz usa temporalmente el número de variantes para elegir la pantalla: exactamente una abre la edición unificada; cero o más de una abren la gestión de producto. Esta clasificación es solo una decisión de interfaz, no un tipo permanente del modelo de datos. Las variantes, sus imágenes y los datos generales permanecen en sus registros originales.

El formulario unificado usa los endpoints existentes de producto y variante con dos solicitudes independientes. Si cambian ambas entidades, guarda primero el producto y luego la variante. No es una operación atómica: ante un fallo parcial informa qué operación fue confirmada y conserva los campos pendientes para reintentar. Si la respuesta de una operación no llega, se debe comprobar el estado real antes de reintentar. Las imágenes se siguen almacenando por variante.
