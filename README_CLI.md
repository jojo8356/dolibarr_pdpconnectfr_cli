# PDPConnectFR CLI

`pdpconnectfr-cli.php` exposes common PDPConnectFR administration tasks for shell use.

It follows common Linux/POSIX/GNU command-line conventions:

- `--help` and `--version` are available.
- Primary output is written to stdout.
- Errors and diagnostics are written to stderr.
- Exit code `0` means success; non-zero means failure.
- `--json` emits machine-readable output for scripts.

## Installation

The script is installed with the module:

```sh
php /path/to/dolibarr/htdocs/custom/pdpconnectfr/scripts/pdpconnectfr-cli.php --help
```

For convenience, create a symlink in your `PATH`:

```sh
ln -s /path/to/dolibarr/htdocs/custom/pdpconnectfr/scripts/pdpconnectfr-cli.php /usr/local/bin/pdpconnectfr
```

## Commands

```sh
pdpconnectfr provider:list
pdpconnectfr provider:get
pdpconnectfr provider:set --provider SUPERPDP --test --protocol CII
pdpconnectfr provider:health
pdpconnectfr token:get
pdpconnectfr company:validate
pdpconnectfr routing:list --socid 2
pdpconnectfr routing:set --socid 2 --routing-id 322324963 --info SIREN
pdpconnectfr routing:delete --socid 2
pdpconnectfr sync:flows --from 2026-09-01 --limit 50
```

## JSON Output

Add `--json` to any command:

```sh
pdpconnectfr --json provider:get
pdpconnectfr --json routing:list --socid 2
```

## Notes

`provider:health`, `token:get`, and `sync:flows` require a configured PA provider and valid provider credentials.
