# Skamasle OLS Connector para Plesk

[English version](README.md)

Licencia: GNU General Public License v3.0. Consulta [LICENSE](LICENSE).

## Advertencia

No instales este módulo en producción.

Plesk no soporta oficialmente OpenLiteSpeed:
https://support.plesk.com/hc/en-us/articles/12377585683095-Does-Plesk-support-OpenLiteSpeed-Web-Server-or-LiteSpeed-installed-manually

OpenLiteSpeed no soporta todas las reglas que puedan existir en `.htaccess`:
https://docs.openlitespeed.org/config/rewriterules/

Repito: no instales este módulo en producción. El código ha sido generado con IA a partir de un proyecto redactado por un humano, y no debe considerarse apto para producción hasta que un humano haya revisado el 100% del código generado. El estado actual de la revisión es inferior al 7%.

Aunque las pruebas realizadas por humanos pueden reducir el riesgo, no convierten esto en algo seguro. Si aun así lo instalas, el riesgo es tuyo, no del módulo. Y si la idea es usarlo como sustituto de una instalación correcta y con licencia de LiteSpeed Enterprise, ese no es el caso de uso adecuado.

El desarrollo se está haciendo sobre AlmaLinux 10.2 y la última versión de Plesk disponible en el laboratorio. Todavía faltan pruebas en más versiones de Plesk y del sistema operativo antes de asumir compatibilidad amplia.

Integración de OpenLiteSpeed con Plesk como backend web opcional por dominio,
con soporte para instalar el motor sin sustituir Apache, sin quitar nginx y
sin modificar archivos gestionados por Plesk.

El proyecto está dividido ahora en dos sistemas que cooperan pero son
independientes:

- la extensión de Plesk en `extension/`;
- un agente opcional independiente para reconciliación de `.htaccess` y eventos de
  Plesk.

El agente todavía no se empaqueta en el ZIP del módulo. Ambos pueden trabajar
juntos, pero ninguno depende del otro para existir o instalarse.

Skamasle OLS es la identidad del producto. Plesk es el primer adaptador de
plataforma; otros paneles podrán reutilizar el modelo de estado, renderer OLS,
scanner de compatibilidad y motor de reconciliación.

El objetivo no es engañar a Plesk para que crea que Apache sigue funcionando.
Apache continúa instalado, arrancado y disponible para los dominios que lo
usan. OpenLiteSpeed se añade como un tercer camino de ejecución para aquellos
dominios donde tenga sentido aprovechar LSPHP/LSAPI.

La configuración de cada vhost OLS se guarda en la ruta estándar
`/usr/local/lsws/conf/vhosts/<dominio>/vhconf.conf`. Esto permite verla y
editarla desde WebAdmin. Los cambios manuales están permitidos, pero la
extensión de Plesk sigue siendo la fuente autoritativa: al reconstruir el
vhost se regenera el archivo y se sobrescriben los cambios hechos en WebAdmin.

Desde la tabla de dominios de la extensión también se pueden ajustar por
dominio las conexiones máximas LSAPI, procesos `PHP_LSAPI_CHILDREN`,
instancias, backlog, timeouts, conexión persistente y buffer de respuesta.

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

Plesk administra Apache como parte de su web stack. Espera encontrar sus
binarios, unidades systemd, módulos y configuraciones en ubicaciones concretas.
También los valida y regenera durante operaciones como:

- `plesk repair web`;
- actualizaciones de Plesk y del sistema operativo;
- cambios de hosting y versión PHP;
- creación, suspensión o eliminación de dominios;
- renovación de certificados;
- operaciones de WordPress Toolkit.

Mover los binarios de Apache, sustituir su servicio o colocar wrappers que
simulen sus respuestas introduce una dependencia frágil sobre detalles internos
de Plesk y del gestor de paquetes.

Este proyecto establece como regla:

> Apache permanece intacto y plenamente funcional.

Por tanto:

- no se renombran `/usr/sbin/httpd` o `/usr/sbin/apache2`;
- no se sustituye ni enmascara `httpd.service` o `apache2.service`;
- no se devuelve un `Syntax OK` falso;
- no se modifican las configuraciones generadas por Plesk;
- `plesk repair web` puede seguir validando y regenerando el stack nativo.

Si OpenLiteSpeed deja de estar disponible o una actualización no es compatible,
el dominio vuelve a su modalidad nativa de Plesk.

## Por qué nginx sigue siendo el orquestador

Plesk ya utiliza nginx como frontend y genera su configuración para cada
dominio. Mantenerlo en los puertos públicos `80` y `443` permite conservar:

- terminación TLS;
- certificados y renovaciones ACME;
- direcciones IP y bindings gestionados por Plesk;
- redirecciones y cabeceras generadas por Plesk;
- integración con WordPress Toolkit;
- logs y operaciones normales del panel;
- regeneración mediante las herramientas oficiales de Plesk.

OpenLiteSpeed no escucha directamente en `80` o `443`. Funciona como backend en
un listener privado ligado a loopback:

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

nginx decide el backend de cada dominio a partir de la configuración generada
por Plesk y del routing solicitado a la extensión.

Esto reduce el impacto de la integración: desde el punto de vista de Plesk,
nginx continúa siendo el frontend real y Apache continúa siendo un servicio
real. El módulo solo transforma el upstream de dominios explícitamente
activados para OLS mediante puntos de extensión soportados.

## Por qué OLS solo se usa con LSAPI/LSPHP

OpenLiteSpeed por sí solo no aporta una ventaja suficiente si PHP continúa
ejecutándose mediante PHP-FPM. Para ese caso, Plesk ya ofrece nginx-only con
PHP-FPM y añadir otro servidor web solo aumentaría la complejidad.

Por ello este proyecto no implementa `OLS + PHP-FPM`.

Un dominio OLS utiliza:

- una external app LSPHP propia;
- un socket LSAPI exclusivo;
- el usuario y grupo del dominio;
- PHP SuEXEC ProcessGroup;
- Detached Mode;
- límites de procesos, memoria y tiempo;
- configuración PHP generada para ese dominio.

La extensión debe comprobar antes de activar tráfico:

- que existe `/opt/plesk/php/<versión>/bin/lsphp` para la versión PHP elegida
  en Plesk;
- que el binario se identifica como una compilación LiteSpeed/LSAPI;
- que están disponibles las extensiones PHP necesarias;
- que los ajustes relevantes pueden reproducirse;
- que el proceso ejecuta con el usuario correcto;
- que las respuestas estáticas y PHP superan los health checks.

No se comparte un socket PHP global entre subscriptions.

El binario `lsphp` incluido por Plesk es el runtime preferente y soportado. La
extensión no instala por defecto un paquete `lsphpXX` paralelo. Plesk continúa
gestionando las versiones PHP, actualizaciones de seguridad, extensiones y
`php.ini` base. La mera existencia del archivo no basta: antes de activar el
dominio se verifican LSAPI, versión, módulos, archivos INI cargados y ejecución
mediante socket.

El entorno actual confirma que las ramas PHP de Plesk ya incluyen un binario
`lsphp` ejecutable en `/opt/plesk/php/<versión>/bin/lsphp`. La integración debe
reutilizar ese runtime gestionado por Plesk en lugar de aprovisionar un árbol
LiteSpeed PHP paralelo.

Los nombres `extProcessor lsphp` y `scriptHandler add lsapi:lsphp php` se
renderizan dentro de cada configuración de vhost. En la práctica eso los hace
locales al vhost, así que reutilizar esos nombres en varios dominios no genera
un choque multiusuario por sí mismo. El verdadero límite de aislamiento es la
configuración del vhost, la ruta del socket y el usuario y grupo del dominio.
Usar nombres específicos por dominio puede ayudar a nivel operativo, pero no es
necesario para la seguridad.

nginx ya reenvía `X-Real-IP` y `X-Forwarded-For` al backend OLS. Eso basta para
que el código de aplicación recupere la IP real desde las cabeceras de la
petición. Si queremos que los access logs de OLS muestren la IP del cliente de
forma directa, todavía falta definir el comportamiento exacto de logging o de
trusted proxy en OLS; eso no está modelado todavía.

También conviene dejar explícita la ruta de logs por vhost. El diseño actual
debería documentar y, más adelante, emitir ficheros de log por dominio dentro de
`/var/www/vhosts/system/<dominio>/logs/`, por ejemplo:

```text
errorlog /var/www/vhosts/DOMINIO/logs/ols-error.log {
  useServer               0
  logLevel                ERROR
  rollingSize             100M
}

accesslog /var/www/vhosts/DOMINIO/logs/ols-access-ssl.log {
  useServer               0
  rollingSize             200M
  keepDays                7
  compressArchive         1
}
```

Esas rutas siguen siendo una decisión de configuración pendiente en el módulo.

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

El flujo de incorporación es:

1. comprobar sistema operativo, Plesk, estado del servicio nginx, Apache y
   capacidades disponibles;
2. instalar OpenLiteSpeed y validar los binarios `lsphp` incluidos por Plesk;
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
