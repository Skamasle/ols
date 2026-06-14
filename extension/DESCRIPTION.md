# Skamasle OLS Connector

Skamasle OLS Connector prepares a safe, per-domain integration between Plesk
and OpenLiteSpeed.

Version 0.1 reports server capabilities and domain inventory and can install
OpenLiteSpeed without changing Apache, nginx, PHP, or domain routing. It also
validates an empty, native-only desired-state model used by later releases.

The target routing modes are:

- Plesk native: nginx to Apache and the Plesk PHP handler.
- Plesk native: nginx-only to Plesk PHP-FPM.
- Optional: nginx to OpenLiteSpeed to isolated LSPHP/LSAPI per domain.
