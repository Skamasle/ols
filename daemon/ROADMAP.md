# Roadmap: skamasle-ols-agent

## Objetivo

Convertir `daemon/` en la base del agente independiente `skamasle-ols-agent`.

El agente:

- no se empaqueta en el ZIP del modulo Plesk en esta fase;
- se instala manualmente por separado;
- coopera con el modulo, pero no depende de su UI para arrancar;
- usa el estado y la configuracion del proyecto para reconciliar OLS.

El modulo y el agente son dos entregables distintos del mismo proyecto.

## Principios

1. El agente no modifica Apache ni sustituye la logica principal de Plesk.
2. El agente no debe ser necesario para que el modulo funcione en modo basico.
3. El agente debe converger al estado correcto aunque pierda eventos.
4. Toda accion que cambie OLS debe validar configuracion antes del reload.
5. Los reloads deben pasar por debounce y deduplicacion.
6. El agente debe actuar solo sobre dominios con routing `ols`.

## Alcance de la primera version

La primera version del agente debe cubrir solo esto:

- detectar cambios en `.htaccess` de dominios `ols`;
- detectar cambios relevantes de dominio/hosting/PHP desde Plesk;
- encolar reconciliacion por dominio;
- regenerar estado derivado cuando proceda;
- validar configuracion OLS antes de aplicar reload;
- ejecutar graceful reload tras cada cambio relevante en dominios `ols`;
- registrar findings del scanner como base para reglas de riesgo futuras;
- registrar logs y metricas basicas del agente.

Queda fuera en esta fase:

- instalacion automatica desde el modulo;
- empaquetado dentro del ZIP de la extension;
- panel de control propio del agente;
- cluster, HA o coordinacion multi nodo;
- traduccion automatica avanzada de directivas Apache no soportadas.

## Modelo operativo

Una sola pieza ejecutable: `skamasle-ols-agent`.

Tres fuentes de senales:

- eventos de Plesk para cambios de dominio, hosting, alias y PHP;
- watcher de filesystem para `.htaccess`;
- timer periodico como red de seguridad.

Un solo reconciler:

- recibe senales;
- normaliza el dominio afectado;
- relee el estado real;
- compara con el estado esperado;
- decide si no hace nada, reescribe runtime o recarga OLS.

## Integracion con el modulo

Responsabilidades del modulo:

- instalar y gestionar OpenLiteSpeed;
- gestionar listener, vhosts, routing y UI;
- persistir el estado deseado;
- exponer suficiente contexto local para que el agente pueda reconciliar.

Responsabilidades del agente:

- observar cambios fuera del flujo manual del modulo;
- solicitar reconciliacion;
- aplicar acciones runtime sobre OLS bajo reglas seguras;
- registrar estado observado y ultimos eventos.

Relacion entre ambos:

- pueden trabajar juntos;
- no se instalan juntos;
- no dependen uno del otro para existir;
- comparten convenciones de rutas, estado y diagnostico.

## Fases

### Fase A: formalizar `daemon/` como agente independiente

- quitar la narrativa de "solo prototipo legado" y pasar a "base del futuro agente";
- mantener el watcher actual como referencia tecnica, no como solucion final;
- definir estructura de carpetas para el agente real;
- documentar instalacion manual y limites.

Criterio de salida:

- `daemon/` queda reconocido como la base del agente independiente;
- la documentacion distingue claramente modulo vs agente.

### Fase B: esqueleto del agente

- crear `cmd/skamasle-ols-agent/`;
- crear paquetes internos para config, queue, reconcile, watch y plesk-events;
- soportar arranque, lectura de config, logs y parada limpia;
- definir archivo de configuracion local del agente.

Criterio de salida:

- binario arrancable sin logica destructiva;
- service unit y config minima documentadas.

### Fase C: watcher de `.htaccess`

- vigilar solo dominios con routing `ols`;
- soportar `Create`, `Write`, `Remove` y `Rename`;
- reescaneo cuando aparezcan nuevos arboles `httpdocs`;
- debounce por dominio;
- no recargar OLS directamente desde el watcher.

Criterio de salida:

- cambios en `.htaccess` generan solicitudes de reconcile deduplicadas.

### Fase D: integracion con eventos de Plesk

- consumir eventos relevantes de dominio y hosting;
- detectar cambios de handler PHP, version PHP y datos de hosting;
- convertir el evento en solicitud de reconcile rapida;
- no ejecutar trabajo pesado dentro del handler de evento.

Criterio de salida:

- un cambio de version PHP o hosting provoca reconcile del dominio correcto.

### Fase E: reconciler real

- releer inventario y estado deseado;
- comparar `phpHandlerId`, version PHP, `prepared`, routing y estado `.htaccess`;
- regenerar runtime derivado si cambia el dominio;
- validar OLS antes de aplicar reload;
- hacer rollback logico cuando el reconcile no sea seguro;
- mantener el scanner como fuente de observabilidad y futura lista de patrones inseguros.

Criterio de salida:

- el agente converge al estado correcto sin intervencion manual en casos comunes.

### Fase F: operacion y observabilidad

- logs estructurados;
- metricas basicas;
- ultima reconciliacion por dominio;
- causas de reload y de no-op;
- estado del agente visible para el modulo en una fase posterior.

Criterio de salida:

- un operador puede entender que paso y por que.

## Estructura propuesta

```text
daemon/
├── README.md
├── ROADMAP.md
├── go.mod
├── go.sum
├── cmd/
│   └── skamasle-ols-agent/
├── internal/
│   ├── config/
│   ├── eventqueue/
│   ├── htaccesswatch/
│   ├── pleskevents/
│   ├── reconcile/
│   ├── runtime/
│   └── telemetry/
└── packaging/
    ├── systemd/
    └── examples/
```

## Rutas y despliegue

Primera fase del agente independiente:

- instalacion manual;
- binario separado del ZIP del modulo;
- unidad systemd propia;
- configuracion propia.

Rutas recomendadas:

```text
/usr/local/bin/skamasle-ols-agent
/etc/skamasle-ols-agent/
/var/lib/skamasle-ols-agent/
/var/log/skamasle-ols-agent/
/etc/systemd/system/skamasle-ols-agent.service
/etc/systemd/system/skamasle-ols-agent.timer
```

El agente puede leer estado compartido desde:

```text
/usr/local/psa/var/modules/skamasle-ols/
```

## Riesgos a controlar

- duplicar logica entre modulo y agente;
- recargas excesivas por cambios masivos;
- eventos de Plesk perdidos o incompletos;
- consumo excesivo de inotify en servidores con muchos vhosts;
- reconciliaciones concurrentes sobre el mismo dominio;
- asumir que todos los cambios de PHP pasan por la misma ruta de evento.

## Decision actual

- `daemon/` sigue fuera del ZIP del modulo;
- el agente sera opcional e independiente en la primera version;
- el modulo podra ofrecer una instalacion asistida del agente en una fase posterior;
- hasta entonces, ambos componentes deben mantenerse desacoplados en despliegue.
