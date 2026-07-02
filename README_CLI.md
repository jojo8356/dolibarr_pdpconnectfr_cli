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

The CLI is built on `splitbrain/php-cli`, vendored in `vendor/splitbrain/php-cli`, so it does not require Composer on the target Dolibarr installation.

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
pdpconnectfr thirdparty:create --name "Example SAS" --prospect --address "1 rue Exemple" --zip 75001 --town Paris --country FR --email contact@example.com
pdpconnectfr prospect:create --name "Prospect Example" --country FR --email prospect@example.com
pdpconnectfr customer:create --name "Client Example" --country FR --email client@example.com
pdpconnectfr thirdparty:get --socid 2
pdpconnectfr thirdparty:list --kind prospect --limit 50
pdpconnectfr prospect:list
pdpconnectfr customer:list
pdpconnectfr other:list
pdpconnectfr contact:create --socid 2 --firstname Alice --lastname Martin --job "Responsable achats" --email alice@example.com
pdpconnectfr contact:list --socid 2
pdpconnectfr contact:prospects
pdpconnectfr contact:customers
pdpconnectfr contact:others
```

## JSON Output

Add `--json` to any command:

```sh
pdpconnectfr --json provider:get
pdpconnectfr --json routing:list --socid 2
pdpconnectfr thirdparty:create --json --name "Example SAS" --customer --country FR
pdpconnectfr prospect:list --json
pdpconnectfr customer:list --json
pdpconnectfr other:list --json
pdpconnectfr contact:create --json --socid 2 --firstname Alice --lastname Martin
pdpconnectfr contact:list --json --kind prospect
```

## Notes

`provider:health`, `token:get`, and `sync:flows` require a configured PA provider and valid provider credentials.

`thirdparty:create` defaults to prospect when neither `--customer`, `--prospect`, nor `--supplier` is provided. Use `--code-client auto` or omit it to let Dolibarr generate the customer/prospect code.

`prospect:create` and `customer:create` are shortcuts that match the Dolibarr menu entries "Nouveau prospect" and "Nouveau client".

`thirdparty:list --kind` accepts `prospect`, `customer`, `supplier`, `other`, and `all`. The shortcut commands `prospect:list`, `customer:list`, and `other:list` use those filters directly.

`contact:create` creates a Dolibarr contact/address linked to a third party. If address fields are omitted, it reuses the third-party address. `contact:list --kind`, `contact:prospects`, `contact:customers`, and `contact:others` match the Dolibarr "Contacts/Adresses" list filters.
