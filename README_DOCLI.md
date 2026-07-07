# docli

`docli.php` exposes common Dolibarr administration tasks for shell use, including PDPConnectFR e-invoicing operations and faster third-party/contact creation.

It follows common Linux/POSIX/GNU command-line conventions:

- `--help` and `--version` are available.
- Primary output is written to stdout.
- Errors and diagnostics are written to stderr.
- Exit code `0` means success; non-zero means failure.
- `--json` emits machine-readable output for scripts.

## Installation

The script is installed with the module:

```sh
php /path/to/dolibarr/htdocs/custom/pdpconnectfr/scripts/docli.php --help
```

The CLI is built on `splitbrain/php-cli`, vendored in `vendor/splitbrain/php-cli`, so it does not require Composer on the target Dolibarr installation.

For convenience, create a symlink in your `PATH`:

```sh
ln -s /path/to/dolibarr/htdocs/custom/pdpconnectfr/scripts/docli.php /usr/local/bin/docli
```

## Commands

```sh
docli provider:list
docli provider:get
docli provider:set --provider SUPERPDP --test --protocol CII
docli provider:health
docli token:get
docli company:validate
docli routing:list --socid 2
docli routing:set --socid 2 --routing-id 322324963 --info SIREN
docli routing:delete --socid 2
docli sync:flows --from 2026-09-01 --limit 50
docli thirdparty:create --name "Example SAS" --prospect --address "1 rue Exemple" --zip 75001 --town Paris --country FR --email contact@example.com
docli thirdparty:create --entity 2 --name "Example SAS" --prospect --country FR
docli thirdparty:update --socid 2 --phone +33494196025 --email contact@example.com
docli thirdparty:delete --socid 2 --yes
docli thirdparty:logo:get --socid 2
docli thirdparty:logo:set --socid 2 --file ./logo.png
docli thirdparty:logo:fetch --socid 2 --url https://example.com/logo.png
docli thirdparty:logo:delete --socid 2 --yes
docli prospect:create --name "Prospect Example" --country FR --email prospect@example.com
docli prospect:update --socid 2 --address "2 rue Exemple" --zip 75002
docli customer:create --name "Client Example" --country FR --email client@example.com
docli customer:update --socid 2 --vat FR00000000000
docli thirdparty:get --socid 2
docli thirdparty:list --kind prospect --limit 50
docli prospect:list
docli customer:list
docli other:list
docli contact:create --socid 2 --firstname Alice --lastname Martin --job "Responsable achats" --email alice@example.com
docli contact:create --entity 2 --socid 2 --firstname Alice --lastname Martin --job "Responsable achats" --email alice@example.com
docli contact:update --id 12 --email alice@example.com --phone-perso "06 01 02 03 04"
docli contact:delete --id 12 --yes
docli contact:list --socid 2
docli contact:prospects
docli contact:customers
docli contact:others
```

## JSON Output

Add `--json` to any command:

```sh
docli --json provider:get
docli --json routing:list --socid 2
docli thirdparty:create --json --name "Example SAS" --customer --country FR
docli thirdparty:logo:get --json --socid 2
docli prospect:list --json
docli customer:list --json
docli other:list --json
docli contact:create --json --socid 2 --firstname Alice --lastname Martin
docli contact:update --json --id 12 --mobile "06 01 02 03 04"
docli contact:delete --json --id 12 --yes
docli contact:list --json --kind prospect
```

## Notes

`provider:health`, `token:get`, and `sync:flows` require a configured PA provider and valid provider credentials.

Use `--entity ID` on multi-entity installations to run a command in a specific Dolibarr entity, for example `--entity 2` for the second entity.

`thirdparty:create` defaults to prospect when neither `--customer`, `--prospect`, nor `--supplier` is provided. Use `--code-client auto` or omit it to let Dolibarr generate the customer/prospect code.

`thirdparty:update`, `prospect:update`, and `customer:update` only change the fields passed on the command line. `prospect:update` keeps the third party as prospect, and `customer:update` keeps it as customer.

`thirdparty:delete` requires `--yes` and uses Dolibarr's native delete checks. Dolibarr may refuse deletion if the third party is used by other objects.

`thirdparty:logo:set`, `thirdparty:logo:fetch`, `thirdparty:logo:get`, and `thirdparty:logo:delete` manage the logo shown on the Dolibarr third-party card. Logos are stored in Dolibarr's native `societe/{socid}/logos` document directory and thumbnails are generated automatically.

`prospect:create` and `customer:create` are shortcuts that match the Dolibarr menu entries "Nouveau prospect" and "Nouveau client".

`thirdparty:list --kind` accepts `prospect`, `customer`, `supplier`, `other`, and `all`. The shortcut commands `prospect:list`, `customer:list`, and `other:list` use those filters directly.

`contact:create` creates a Dolibarr contact/address linked to a third party. If address fields are omitted, it reuses the third-party address. `contact:list --kind`, `contact:prospects`, `contact:customers`, and `contact:others` match the Dolibarr "Contacts/Adresses" list filters.

`contact:update` only changes fields passed on the command line. Use `--phone` for professional phone, `--phone-perso` for personal phone, and `--mobile` for mobile phone.

`contact:delete` requires `--yes` and uses Dolibarr's native contact delete cleanup.
