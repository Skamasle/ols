# Skamasle OLS Connector para Plesk

[English version](README.md)

Licencia: GNU General Public License v3.0. Consulta [LICENSE](LICENSE).

Skamasle OLS añade OpenLiteSpeed a Plesk como backend opcional por dominio. No
sustituye Apache, no elimina nginx y no modifica directamente la configuración
web generada por Plesk.

La ruta prevista es:

```text
nginx de Plesk :80/:443 -> OpenLiteSpeed en loopback -> LSPHP/LSAPI
```

Cada dominio puede mantenerse en el stack nativo de Plesk o enviarse a OLS si
su runtime PHP y sus reglas `.htaccess` son compatibles.

## Estado del proyecto

Este módulo es experimental y está pensado para laboratorio o pruebas
controladas. Todavía no está listo para producción.

Limitaciones importantes:

- Plesk no soporta oficialmente OpenLiteSpeed instalado manualmente:
  https://support.plesk.com/hc/en-us/articles/12377585683095-Does-Plesk-support-OpenLiteSpeed-Web-Server-or-LiteSpeed-installed-manually
- OpenLiteSpeed no soporta todas las directivas Apache que pueden aparecer en
  `.htaccess`: https://docs.openlitespeed.org/config/rewriterules/
- El código todavía necesita una revisión completa de seguridad y operación
  antes de considerarse apto para producción.
- La validación actual se centra en AlmaLinux 10.2 con una versión reciente de
  Plesk. Faltan pruebas con más versiones de Plesk y del sistema operativo.

Úsalo como proyecto de desarrollo y evaluación, no como sustituto de una
instalación LiteSpeed Enterprise correctamente licenciada y soportada.

## Qué existe hoy

El repositorio contiene una extensión de Plesk y un agente independiente
opcional. El agente todavía no se empaqueta en el ZIP de la extensión.

La extensión puede actualmente:

- detectar capacidades del servidor y handlers PHP instalados;
- mostrar inventario de dominios y estado de routing;
- validar si un dominio puede moverse hacia OLS;
- instalar OpenLiteSpeed sin reemplazar Apache ni nginx;
- guardar la configuración del vhost en la ruta estándar
  `/usr/local/lsws/conf/vhosts/<dominio>/vhconf.conf`;
- exponer ajustes LSAPI por dominio: procesos, conexiones, backlog, timeouts y
  buffer de respuesta;
- mantener el routing nativo de Plesk como fallback;
- guardar estado de control para activación y reversión.

Para obtener el mayor control sobre la cadena de suministro, OpenLiteSpeed
debería ser instalado previamente por el administrador del servidor. La
siguiente opción preferente es utilizar un repositorio que el administrador ya
haya configurado y verificado. Los modos automáticos de bootstrap del proveedor
y repositorio personalizado siguen disponibles para entornos controlados, pero
ejecutan operaciones de paquetes como root y confían en la fuente externa
seleccionada por el administrador.

Los cambios manuales desde OpenLiteSpeed WebAdmin son posibles, pero la
extensión sigue siendo autoritativa: al reconstruir un vhost se regenera
`vhconf.conf`.

## Construir

Compila el ZIP de la extensión de Plesk desde la raíz del repositorio con:

```bash
bash scripts/build-extension.sh
```

El script prepara `extension/` dentro de un archivo nuevo en `build/` e
incrementa automáticamente el número de release. El archivo resultante sigue
este patrón:

```text
build/skamasle-ols-plesk-<version>-<release>.zip
```

Instala el último build local con:

```bash
plesk bin extension -i "build/skamasle-ols-plesk-latest.zip"
```

Valida el paquete con:

```bash
bash tests/package.sh
```

## Capturas

![Panel de la extensión](./screenshot/dashboard.png)
![Estado instalado](./screenshot/domain-installed.png)
![Compatibilidad de dominio](./screenshot/domain-readiness.png)
![LSCache](./screenshot/lscache.png)

## La idea

Una instalación Plesk ya dispone de dos modalidades nativas:

```text
nginx -> Apache -> handler PHP configurado en Plesk
nginx -> PHP-FPM de Plesk
```

Este proyecto conserva ambas y añade una tercera:

```text
nginx -> OpenLiteSpeed -> LSPHP/LSAPI
```

La selección se realiza por dominio. Un servidor puede tener simultáneamente:

- dominios servidos mediante Apache y el handler PHP de Plesk;
- dominios nginx-only servidos mediante PHP-FPM;
- dominios enviados a OpenLiteSpeed y ejecutados mediante LSPHP/LSAPI.

No se fuerza una migración global del servidor. OpenLiteSpeed se usa únicamente
en los dominios donde su rendimiento y LSAPI justifican el cambio y cuya
configuración sea compatible.

No todos los dominios pueden ejecutarse de forma segura en OpenLiteSpeed. Su
soporte de `.htaccess` no equivale al de Apache: la compatibilidad se centra
principalmente en reglas `mod_rewrite`, mientras que otras directivas Apache
pueden no estar soportadas o ser ignoradas. Los dominios que dependan de esas
directivas permanecen en su modalidad nativa de Plesk.

## Por qué no reemplazamos Apache

Plesk es quien gestiona Apache: binarios, servicios, módulos, configuración,
reparaciones, actualizaciones, certificados, WordPress Toolkit y eventos del
ciclo de vida de dominios esperan que Apache siga siendo real.

> Apache permanece intacto y plenamente funcional.

La extensión no renombra binarios de Apache, no sustituye servicios, no finge
`Syntax OK` y no edita la configuración Apache generada por Plesk. Si
OpenLiteSpeed deja de estar disponible, los dominios afectados pueden volver a
su modo nativo de Plesk.

## Por qué nginx sigue siendo el orquestador

Plesk ya usa nginx como frontend público. Mantenerlo en los puertos `80` y
`443` conserva TLS, certificados, bindings de IP, redirecciones, logs del
panel, WordPress Toolkit y regeneración mediante herramientas oficiales.

OpenLiteSpeed queda detrás de nginx en un listener privado de loopback:

```text
Internet
   |
   v
nginx gestionado por Plesk :80/:443
   |
   +--> Apache + PHP de Plesk
   |
   +--> PHP-FPM de Plesk
   |
   `--> OpenLiteSpeed en loopback --> LSPHP/LSAPI
```

El módulo solo cambia el upstream de los dominios activados explícitamente para
OLS.

## Por qué OLS solo se usa con LSAPI/LSPHP

OpenLiteSpeed por sí solo no aporta una ventaja suficiente si PHP continúa
ejecutándose mediante PHP-FPM. Para ese caso, Plesk ya ofrece nginx-only con
PHP-FPM y añadir otro servidor web solo aumentaría la complejidad.

Por ello este proyecto no implementa `OLS + PHP-FPM`. Un dominio OLS utiliza:

- una external app LSPHP propia;
- un socket LSAPI exclusivo;
- el usuario y grupo del dominio;
- límites de procesos, memoria y tiempo;
- configuración PHP generada para ese dominio.

El runtime preferente es el propio
`/opt/plesk/php/<versión>/bin/lsphp` de Plesk. La extensión no instala por
defecto un árbol LiteSpeed PHP paralelo, y antes de activar un dominio verifica
LSAPI, paridad PHP, módulos cargados, ejecución por socket y health checks
básicos. No se comparten procesos ni sockets PHP globales entre subscriptions.

Los nombres `extProcessor lsphp` y `scriptHandler add lsapi:lsphp php` se
renderizan dentro de cada configuración de vhost. En la práctica eso los hace
locales al vhost, así que reutilizar esos nombres en varios dominios no genera
un choque multiusuario por sí mismo. El verdadero límite de aislamiento es la
configuración del vhost, la ruta del socket y el usuario y grupo del dominio.
Usar nombres específicos por dominio puede ayudar a nivel operativo, pero no es
necesario para la seguridad.

nginx reenvía `X-Real-IP` y `X-Forwarded-For` al backend OLS, así que las
aplicaciones pueden recuperar la IP real desde las cabeceras de la petición.
El access log de OLS queda desactivado por defecto de forma intencionada.
nginx/Plesk sigue siendo responsable de los logs de acceso y escribe el tráfico
enrutado a OLS en los logs proxy de Plesk. Esto evita cambiar propietarios o
permisos de ficheros gestionados por Apache/Plesk como `access_ssl_log`, dentro
de la política del proyecto de no romper el estado gestionado por Plesk.

Los logs de error por vhost se dejan explícitos y viven bajo el directorio
estándar de logs del dominio con un nombre propio de OLS. Las peticiones de
acceso deben revisarse en los logs proxy de nginx/Plesk: `proxy_access_ssl_log`
o `proxy_access_log` según el vhost público. Por ejemplo:

```text
errorlog /var/www/vhosts/system/DOMINIO/logs/ols_error_log {
  useServer               0
  logLevel                ERROR
  rollingSize             100M
}

```

Esa ruta de error queda renderizada por el módulo en cada configuración de vhost
OLS.

El listener privado de OLS también necesita TLS para funcionar con `secure 1`.
La estrategia actual es generar un certificado auto-firmado global después de
instalar OLS, guardarlo en `/usr/local/lsws/conf/ssl/` y reutilizarlo en el
listener de loopback para todos los dominios. Los nombres previstos son
`skamasle-ols.key` y `skamasle-ols.crt`, con una validez larga de unas
10 años. Es una solución temporal como ancla de confianza interna para la
comunicación nginx -> OLS, hasta que podamos reutilizar mejor el SSL que ya
genera nginx o cambiar el modelo de confianza.

## Modos por dominio

La extensión expone únicamente dos estados de routing:

### `native`

Plesk utiliza la configuración normal del dominio:

- proxy mode: nginx -> Apache -> handler PHP de Plesk;
- nginx-only: nginx -> PHP-FPM de Plesk.

La extensión no modifica estas preferencias. Plesk continúa siendo su fuente de
verdad.

### `ols`

nginx envía el dominio a OpenLiteSpeed y PHP se ejecuta mediante LSPHP/LSAPI.

Este modo solo se aplica cuando:

1. la versión y plantilla de Plesk están reconocidas;
2. la configuración OLS es válida;
3. existe paridad PHP suficiente;
4. `.htaccess` no contiene incompatibilidades bloqueantes;
5. `openlitespeed -t` y `nginx -t` pasan;
6. los health checks estáticos y PHP pasan;
7. el retorno a `native` está preparado.

## Compatibilidad con actualizaciones y `plesk repair web`

La extensión no edita directamente archivos bajo
`/var/www/vhosts/system`, plantillas de Plesk ni configuración nginx generada.
La integración utiliza APIs y hooks documentados, principalmente:

- `pm_Hook_WebServer::processTemplate()`;
- `pm_WebServer::updateDomainConfiguration()`;
- eventos de Plesk como señales de reconciliación;
- `pm_ApiCli::callSbin()` para operaciones privilegiadas controladas.

Los adaptadores de routing se validan contra fixtures de versiones concretas de
Plesk. Si una actualización cambia una plantilla y el adaptador deja de
reconocerla, devuelve el contenido original y conserva o restaura el modo
`native`.

El principio de seguridad es:

> Una versión desconocida no debe producir una configuración parcialmente
> modificada. Debe dejar el dominio en el stack nativo de Plesk.

El proyecto no promete compatibilidad ciega con cualquier versión futura.
Promete detectar una versión no certificada y fallar hacia una configuración
gestionada por Plesk.

## `.htaccess`

OpenLiteSpeed puede cargar reglas `mod_rewrite` desde `.htaccess`, incluidos
subdirectorios, pero no es compatible con el sistema completo de directivas
por directorio de Apache:

- su compatibilidad con `.htaccess` se centra principalmente en
  `mod_rewrite`;
- directivas como `Require`, `Allow`, `Deny`, `AuthType`, `Header`, `Options`,
  `php_value` o `php_flag` requieren análisis específico y pueden bloquear el
  uso de OLS;
- las directivas no soportadas pueden ser ignoradas por OLS, lo que podría
  eliminar silenciosamente controles de seguridad o cambiar el comportamiento;
- los cambios de rewrite necesitan un graceful restart de OLS.

Esta limitación es también uno de los motivos para conservar nginx como
frontend. La extensión puede traducir controles compatibles de acceso,
autenticación, cabeceras y comportamiento HTTP a configuración nginx generada
y validada antes de enviar el dominio a OLS. De forma equivalente, ajustes como
`php_value` y `php_flag` deben trasladarse a la configuración LSPHP específica
del dominio.

nginx no interpreta `.htaccess` ni permite traducir automáticamente cualquier
directiva Apache. Si una regla no tiene una equivalencia segura en nginx, OLS o
LSPHP, la extensión la muestra y exige aceptación explícita del administrador
antes de activar OLS.

Antes de activar un dominio, la extensión analiza los `.htaccess` del document
root y sus subdirectorios. Las directivas desconocidas o incompatibles generan
una advertencia de revisión. Solo se bloquea sin override cuando el análisis no
puede completarse de forma fiable, por ejemplo por archivos ilegibles o límites
de seguridad excedidos.

`skamasle-ols-agent` incluye un sistema de vigilancia de `.htaccess` limitado a
los dominios que tengan aplicado el routing OLS. Cuando detecta la creación,
modificación, sustitución o eliminación de uno de estos archivos, agrupa los
eventos para evitar recargas repetidas, vuelve a analizar su compatibilidad y
valida la configuración resultante.

Si el cambio es compatible, el agente recarga OLS mediante un graceful restart
para aplicar las nuevas reglas sin interrumpir las conexiones activas. Si el
cambio introduce una directiva insegura o no traducible, no aplica
silenciosamente una configuración parcial: registra la incompatibilidad y
mantiene o devuelve el dominio a su modalidad nativa de Plesk.

## Componentes

```text
Adaptador Skamasle OLS para Plesk
  - interfaz de administración
  - inventario de dominios
  - estado deseado native/ols
  - hooks oficiales de Plesk
  - tareas largas de instalación y actualización

skamasle-olsctl
  - interfaz privilegiada con comandos cerrados
  - instalación y validación de paquetes
  - solicitud de reconciliación

skamasle-ols-agent
  - reconciliación de estado
  - generación atómica de configuración OLS
  - configuración LSPHP/LSAPI por dominio
  - validación y health checks
  - supervisión de .htaccess
  - rollback a la última generación válida

OpenLiteSpeed
  - listener backend solo en loopback
  - un virtual host por dominio
  - LSPHP/LSAPI aislado por dominio
```

## Instalación y activación

La instalación de la extensión no cambia automáticamente el web stack ni activa
dominios en OLS.

La postura de seguridad recomendada consiste en instalar OpenLiteSpeed
manualmente como administrador del servidor, verificar sus paquetes y claves de
repositorio conforme a la política del sistema operativo y seleccionar después
`OpenLiteSpeed already installed` en la extensión. Si se delega la instalación
de paquetes a la extensión, es preferible usar `Repository already configured`
después de que el administrador haya verificado el repositorio. El bootstrap
automático y el repositorio personalizado son decisiones explícitas del
administrador y transfieren la confianza a la fuente externa configurada.

El flujo de incorporación es:

1. comprobar sistema operativo, Plesk, estado del servicio nginx, Apache y
   capacidades disponibles;
2. verificar la instalación de OpenLiteSpeed realizada por el administrador, o
   elegir explícitamente un modo de aprovisionamiento automático, y validar los
   binarios `lsphp` incluidos por Plesk;
3. configurar el listener OLS privado;
4. inventariar dominios y sus configuraciones PHP;
5. analizar compatibilidad y preparar cada virtual host;
6. validar OLS, LSAPI y nginx;
7. activar explícitamente los dominios seleccionados.

Cada dominio conserva su modalidad nativa como camino de recuperación.

## Operación y recuperación

La extensión mantiene por dominio:

- routing solicitado y aplicado;
- modalidad nativa observada;
- versión y configuración PHP;
- runtime y socket LSAPI;
- compatibilidad `.htaccess`;
- resultado de las validaciones;
- última configuración OLS válida.

Ante un fallo de OLS, LSAPI, paridad PHP, health check o compatibilidad del
adaptador, el dominio vuelve a `native` mediante una regeneración gestionada por
Plesk.

La desinstalación restaura primero todos los dominios a su modalidad nativa,
valida Apache, nginx y PHP según corresponda y elimina únicamente recursos
creados por la extensión.

## Principios del diseño: no romper Plesk

La idea central es integrar OpenLiteSpeed reduciendo al mínimo la interferencia
con los componentes y procesos gestionados por Plesk:

- mantener Apache instalado, arrancado y sin wrappers;
- dejar nginx bajo control de Plesk y conservarlo en los puertos públicos;
- utilizar OLS únicamente como backend privado;
- ejecutar PHP en OLS mediante LSPHP/LSAPI, no PHP-FPM;
- aislar el runtime PHP de cada dominio;
- evitar la edición directa de archivos generados por Plesk;
- generar y aplicar la configuración OLS de forma atómica;
- no enviar tráfico a OLS antes de superar las validaciones;
- mantener en `native` los dominios cuando la versión de Plesk no sea
  reconocida;
- permitir que `plesk repair web` opere sobre el stack real de Plesk.

Estos principios reducen el riesgo, pero no sustituyen las pruebas de
compatibilidad necesarias para cada versión soportada de Plesk, OLS, nginx,
Apache, PHP y LSPHP.
