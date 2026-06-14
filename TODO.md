# TODO

Lista viva de pendientes técnicos y revisiones del módulo.

## Prioridad alta

- Revisar y endurecer LSCache para el caso real de Plesk/OpenLiteSpeed.
- Verificar el flujo de activación y desactivación de LSCache por dominio.
- Confirmar que la caché se genera solo en `vhostRoot/lscache` y no en rutas internas del módulo.
- Afinar la política de caché pública por defecto y revisar cuándo conviene habilitar caché privada.
- Revisar la limpieza automática de `lscache` al desactivar caché o al eliminar un dominio.

## Integración con Plesk

- Detectar cambios de versión de PHP desde Plesk y resincronizar el dominio cuando cambie el handler.
- Revisar qué datos exactos conviene persistir desde `plesk bin site --info`.
- Confirmar el comportamiento con dominios que usan PHP-FPM, modo proxy y cambios de plantilla.
- Revisar el flujo de preparación del dominio para reducir dependencias de reconstrucción manual.
- Definir y documentar los logs de cada vhost en `/var/www/vhosts/system/<dominio>/logs/`, por ejemplo `errorlog /var/www/vhosts/DOMINIO/logs/ols-error.log` y `accesslog /var/www/vhosts/DOMINIO/logs/ols-access-ssl.log`.
- Confirmar si OLS debe confiar en `X-Real-IP` y `X-Forwarded-For` para reflejar la IP real en logs, o si basta con la cabecera para las aplicaciones.
- Revisar si el certificado global temporal de OLS puede reutilizar el SSL de nginx o si debe mantenerse como trust anchor interno en `/usr/local/lsws/conf/ssl/`.

## Daemon y automatización

- Crear el daemon para detectar cambios en `.htaccess` mediante `inotify`.
- Hacer que el daemon aplique un reload controlado de OpenLiteSpeed cuando cambien reglas relevantes.
- Limitar el daemon solo a dominios con routing `ols`.
- Añadir debounce para evitar recargas excesivas durante ediciones múltiples.
- Definir validación previa y health checks antes del reload.
- Unificar el watcher con el `skamasle-ols-agent` previsto.

## Calidad y mantenimiento

- Revisar tests para cubrir los casos de caché pública, privada y limpieza.
- Añadir pruebas para el cambio de versión de PHP detectado desde Plesk.
- Añadir pruebas para la ruta real de caché por dominio.
- Revisar logs y diagnósticos para que los fallos de cache y daemon sean visibles.
- Documentar claramente qué partes son prototipo y qué partes ya son aptas para uso controlado.

## Creación pendiente

- Diseñar el sistema de invalidación de caché por eventos de Plesk.
- Diseñar el plan de reconciliación automática entre estado deseado y estado real de OLS.
- Diseñar el empaquetado del agente independiente como entrega separada del ZIP del módulo, si aplica.
- Diseñar el modo de instalación y desinstalación del agente sin dejar procesos huérfanos.
