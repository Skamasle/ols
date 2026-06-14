# Agent Base

This directory is the current base for the future standalone
`skamasle-ols-agent`.

Right now it still contains the original `.htaccess` watcher prototype, but the
project direction is no longer to fold that logic into the Plesk extension ZIP.
Instead:

- the Plesk extension remains one deliverable;
- the agent becomes a separate optional deliverable;
- both components can cooperate without depending on each other at install time.

The code in this directory is not part of the extension package and must not be
treated as production-ready yet.

OpenLiteSpeed loads rewrite rules from `.htaccess`, but requires a graceful
restart after rewrite changes. That responsibility will move into the real
`skamasle-ols-agent`, scoped to domains whose applied routing is `ols`, with
debouncing, validation, health checks, Plesk event handling, and periodic
reconciliation.

See [ROADMAP.md](/home/maks/Programando/PHP/Plesk-OpenLitespeed/daemon/ROADMAP.md)
for the implementation path.
