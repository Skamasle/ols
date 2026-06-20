# Changelog

Build releases are generated incrementally. The release number identifies the
exact ZIP artifact and does not replace earlier builds.

## 0.1.1-4

- Add optional private LSCache enablement per domain while keeping public
  cache as the default activation path.
- Persist and validate the per-domain private-cache flag in desired state and
  vhost generation.
- Detect domains that use nginx + PHP-FPM (`nginx-only`) from Plesk web server
  settings.
- Block OLS staging and activation for `nginx-only` domains and explain that
  they must be switched to `nginx + Apache + PHP` first.
- Surface the `nginx-only` warning directly in the domain table and disable
  OLS action buttons for affected domains.

## 0.1.1-2

- Make LSAPI use a single user-facing value and mirror it to both
  `maxConns` and `PHP_LSAPI_CHILDREN`.
- Update the injected LSAPI defaults to use `maxConns 8`,
  `PHP_LSAPI_CHILDREN=8`, `PHP_LSAPI_MAX_REQUESTS=1000`,
  `LSAPI_AVOID_FORK=100M`, `LSAPI_ACCEPT_NOTIFY=1`, and `backlog 100`.
- Disable `enableGzip` and `enableBr` by default in managed vhosts.
- Fix LSCache activation.
- Allow staged OLS vhosts to be reverted from the domain table without first
  activating OLS routing.

## 0.1.1-beta

- Move managed domain files to the standard OLS layout under
  `/usr/local/lsws/conf/vhosts/<domain>/vhconf.conf`.
- Refresh the domain panel so it stays closer to the current OLS staging
  state.
- Add per-domain LSAPI tuning in the panel so each domain can use its own
  process and timeout settings.
- Simplify the domain actions in the UI and keep `Disable OLS` as the main
  cleanup path.
- Improve cleanup when a domain no longer exists in Plesk.
- Keep the OLS Example directory ownership aligned with `apache:apache`.

## 0.1.0-11

- Add a POST-backed action that installs OpenLiteSpeed.
- Record the installation receipt after the package step succeeds.
- Update the UI to present the action as installation, not plan generation.

## 0.1.0-10

- Stop synthesizing a persisted engine plan when no plan file exists.
- Show the engine installation state as `unplanned` until `install-engine` runs.
- Mark persisted plans explicitly as installed.
- Add a UI action to persist the engine installation plan.
- Expose the persisted engine plan as a POST-backed admin button.
- Add a test for the engine installer service.

## 0.1.0-9

- Add automatic migration for legacy desired-state files without listener data.
- Surface the persisted engine plan path in the admin panel.
- Keep `install-engine` status reads aligned with persisted plans.

## 0.1.0-8

- Surface the persisted engine install plan in the admin panel.
- Make `status` return the stored plan when `install-engine` has run.
- Verify that `install-engine` writes a persistent plan file.

## 0.1.0-7

- Add a private loopback listener to the desired-state schema.
- Plan the engine install with listener, repository, package, and path data.
- Make `install-engine` produce a persisted installation plan.
- Cover the listener and plan store with tests.

## 0.1.0-6

- Make nginx service state part of domain readiness and activation gating.
- Add a visible warning when nginx is not active or cannot be verified.
- Extend readiness tests to cover the nginx prerequisite.

## 0.1.0-5

- Detect nginx service state in the capability report and desired-state panel.
- Surface nginx as an explicit server prerequisite for activation planning.
- Add tests for service-state probing and panel integration.

## 0.1.0-4

- Change known and unknown Apache incompatibilities from hard blocks to
  review-required warnings.
- Reserve hard blocking for incomplete or failed compatibility analysis.
- Group repeated findings by directive and show representative examples.
- Recognize common ownCloud `SetEnvIfNoCase` and `RequestHeader` directives.

## 0.1.0-3

- Add bounded, recursive `.htaccess` compatibility scanning.
- Report blocking directives with relative file paths and line numbers.
- Add per-domain readiness states and a disabled Native/OLS selector.
- Export anonymized compatibility summaries in diagnostics.

## 0.1.0-2

- Add locked, validated, atomic desired-state storage.
- Reject stale or skipped desired-state generations.
- Add an atomic transaction journal for future privileged operations.
- Keep all engine and routing mutations disabled.

## 0.1.0-1

- Introduce the Skamasle OLS Connector product and Plesk adapter.
- Add an administrator-only, read-only capability and domain report.
- Add anonymized diagnostics for platform, PHP handlers, LSPHP, and domains.
- Detect and verify Plesk-managed LSPHP across installed PHP branches.
- Add the versioned desired-state schema and strict validator.
- Initialize an atomic, native-only desired state during installation.
- Add read-only `skamasle-olsctl capabilities`, `validate`, and `status`
  commands.
- Reject engine installation, reconciliation, and removal commands.
- Display and export the validated control-plane status.
- Add local parser and package tests.
- Add reproducible lint, test, and agent build targets.
- Do not install packages or change web server configuration.
