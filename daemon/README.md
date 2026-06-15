# Skamasle OLS Agent

This directory contains the standalone `skamasle-ols-agent`.

The current implementation focuses on `.htaccess` changes:

- watches Plesk `httpdocs` trees with `fsnotify`;
- groups rapid changes by vhost with a debounce queue;
- reads the extension desired state;
- ignores domains whose applied routing is not `ols`;
- rescans `.htaccess` compatibility before acting;
- holds reloads that require review or cannot be scanned safely;
- runs `openlitespeed -t` before each graceful reload.

The agent remains a separate deliverable and is not included in the Plesk
extension ZIP.

## Build and verify

Build the agent from this directory:

```bash
cd daemon
go build -o skamasle-ols-agent .
```

The resulting binary is `daemon/skamasle-ols-agent`. To run the local checks
used during development:

```bash
GOCACHE=/tmp/skamasle-ols-go-cache go test ./...
GOCACHE=/tmp/skamasle-ols-go-cache go test -race ./...
GOCACHE=/tmp/skamasle-ols-go-cache go vet ./...
```

## Runtime paths

The agent currently expects:

```text
/usr/local/psa/var/modules/skamasle-ols/desired-state.json
/usr/local/lsws/bin/openlitespeed
/usr/local/lsws/bin/lswsctrl
/var/www/vhosts/
```

Install the binary as `/usr/local/bin/skamasle-ols-agent` and use
`skamasle-ols-agent.service` as the systemd unit.

The current phase does not yet persist scan results back into Plesk or restore
native routing automatically when a new incompatibility is found. It blocks
the reload and logs the reason instead.

See [ROADMAP.md](ROADMAP.md) for the implementation path.
