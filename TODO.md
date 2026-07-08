# TODO

Lista viva de pendientes técnicos y revisiones del módulo.

## Prioridad alta

- [ ] Revisar y endurecer LSCache para el caso real de Plesk/OpenLiteSpeed.
- [ ] Verificar el flujo de activación y desactivación de LSCache por dominio.
- [ ] Confirmar que la caché se genera solo en `vhostRoot/lscache` y no en rutas internas del módulo.
- [x] Apagar `qsCache` por defecto para evitar explosión de caché por query strings de bots, campañas y crawlers.
- [ ] Afinar la política de caché pública por defecto y revisar cuándo conviene habilitar caché privada.
- [ ] Confirmar el método seguro de purga/expiración gestionado por OLS al desactivar caché o eliminar un dominio; evitar borrar `lscache` manualmente salvo que OLS documente una operación segura.

## Integración con Plesk

- [ ] Revisar qué datos exactos conviene persistir desde `plesk bin site --info` para la detección ya funcional de cambios de dominio, hosting y PHP.
- [ ] Confirmar el comportamiento con dominios que usan PHP-FPM, modo proxy y cambios de plantilla.
- [ ] Revisar el flujo de preparación del dominio para reducir dependencias de reconstrucción manual.
- [x] Definir el error log de OLS bajo el directorio estándar del dominio (`/var/www/vhosts/system/<dominio>/logs/ols_error_log`), dejar el access log de OLS desactivado por defecto y hacer que nginx registre el tráfico OLS en `proxy_access_ssl_log`/`proxy_access_log` para conservar estadísticas de Plesk.
- [ ] Revisar si el certificado global temporal de OLS puede reutilizar el SSL de nginx o si debe mantenerse como trust anchor interno en `/usr/local/lsws/conf/ssl/`.

## Daemon y automatización

- [x] Crear el daemon para detectar cambios en `.htaccess` mediante `inotify`.
- [x] Hacer que el daemon aplique un reload controlado de OpenLiteSpeed cuando cambien reglas relevantes.
- [x] Limitar el daemon solo a dominios con routing `ols`.
- [x] Añadir debounce para evitar recargas excesivas durante ediciones múltiples.
- [ ] Revisar la ventana de debounce actual y ajustarla con datos reales de edición/despliegue.
- [ ] Completar health checks posteriores al reload; la validación previa de configuración ya existe.
- [ ] Definir un rate limit de reloads por dominio o global para evitar bucles de reinicio, con una ventana inicial de referencia de 2 minutos.
- [ ] Revisar el usuario/grupo con el que corre el daemon y endurecer permisos; el objetivo es salir de `root` si la capacidad de reload lo permite.
- [ ] Limitar watchers por dominio o evitar el seguimiento de directorios demasiado profundos para reducir consumo de `inotify`.
- [x] Unificar el watcher con el `skamasle-ols-agent` previsto.

## Calidad y mantenimiento

- [ ] Revisar tests para cubrir los casos de caché pública, privada y limpieza.
- [ ] Añadir pruebas para el cambio de versión de PHP detectado desde Plesk.
- [ ] Ampliar pruebas de ruta real de caché por dominio para variantes con `vhostRoot` explícito.
- [ ] Revisar logs y diagnósticos para que los fallos de cache y daemon sean visibles.
- [ ] Documentar claramente qué partes son prototipo y qué partes ya son aptas para uso controlado.

## Creación pendiente

- [ ] Diseñar el sistema de invalidación de caché por eventos de Plesk.
- [ ] Diseñar el plan de reconciliación automática entre estado deseado y estado real de OLS.
- [ ] Diseñar el empaquetado del agente independiente como entrega separada del ZIP del módulo, si aplica.
- [ ] Diseñar el modo de instalación y desinstalación del agente sin dejar procesos huérfanos.
