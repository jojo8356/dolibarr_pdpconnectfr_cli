#!/usr/bin/env php
<?php
/* Copyright (C) 2026 Johan Polsinelli
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Dolibarr command line interface.
 *
 * This script follows common POSIX/GNU CLI conventions:
 * - --help and --version are always available.
 * - Primary output goes to stdout.
 * - Diagnostics and errors go to stderr.
 * - Exit code is 0 on success and non-zero on failure.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "docli: this command must be run from a CLI SAPI\n");
	exit(2);
}

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}

require_once __DIR__.'/../vendor/splitbrain/php-cli/src/Exception.php';
require_once __DIR__.'/../vendor/splitbrain/php-cli/src/Colors.php';
require_once __DIR__.'/../vendor/splitbrain/php-cli/src/TableFormatter.php';
require_once __DIR__.'/../vendor/splitbrain/php-cli/src/Options.php';
require_once __DIR__.'/../vendor/splitbrain/php-cli/src/Base.php';
require_once __DIR__.'/../vendor/splitbrain/php-cli/src/CLI.php';

use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;

final class Docli extends CLI
{
	private const VERSION = '1.0.0';

	private const EXIT_OK = 0;
	private const EXIT_USAGE = 2;
	private const EXIT_SOFTWARE = 70;
	private const EXIT_CONFIG = 78;

	/** @var array<string, mixed> */
	private array $globalOptions = array(
		'format' => 'text',
		'quiet' => false,
		'verbose' => false,
		'entity' => 0,
	);

	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	private int $exitCode = self::EXIT_OK;

	protected function setup(Options $options)
	{
		$options->setHelp('Manage Dolibarr from the command line.');
		$options->setCommandHelp('Available commands:');
		$this->registerCommonOptions($options);

		$options->registerCommand('help', 'Show help for all commands.');
		$options->registerArgument('command', 'Command name.', false, 'help');

		$options->registerCommand('version', 'Show version.');

		$options->registerCommand('provider:list', 'List configured PA providers.');
		$this->registerCommonOptions($options, 'provider:list');

		$options->registerCommand('provider:get', 'Show current PA provider, mode, and protocol.');
		$this->registerCommonOptions($options, 'provider:get');

		$options->registerCommand('provider:set', 'Set PA provider.');
		$this->registerCommonOptions($options, 'provider:set');
		$options->registerOption('provider', 'Provider name, for example SUPERPDP.', null, 'NAME', 'provider:set');
		$options->registerOption('protocol', 'Invoice protocol, for example CII.', null, 'PROTOCOL', 'provider:set');
		$options->registerOption('live', 'Enable live mode.', null, false, 'provider:set');
		$options->registerOption('test', 'Enable test/sandbox mode.', null, false, 'provider:set');

		$options->registerCommand('provider:health', 'Call the configured provider health check endpoint.');
		$this->registerCommonOptions($options, 'provider:health');

		$options->registerCommand('token:get', 'Fetch and save an OAuth token for the configured provider.');
		$this->registerCommonOptions($options, 'token:get');
		$options->registerOption('print-token', 'Print the raw access token on stdout.', null, false, 'token:get');

		$options->registerCommand('company:validate', 'Validate Dolibarr company data required for e-invoicing.');
		$this->registerCommonOptions($options, 'company:validate');

		$options->registerCommand('routing:list', 'List routings for a third party.');
		$this->registerCommonOptions($options, 'routing:list');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'routing:list');
		$options->registerOption('type', 'Routing owner type.', null, 'TYPE', 'routing:list');

		$options->registerCommand('routing:set', 'Set default routing for a third party.');
		$this->registerCommonOptions($options, 'routing:set');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'routing:set');
		$options->registerOption('routing-id', 'Routing identifier, usually SIREN/SIRET.', null, 'VALUE', 'routing:set');
		$options->registerOption('type', 'Routing owner type.', null, 'TYPE', 'routing:set');
		$options->registerOption('source', 'Routing source.', null, 'SOURCE', 'routing:set');
		$options->registerOption('info', 'Routing info label.', null, 'INFO', 'routing:set');

		$options->registerCommand('routing:delete', 'Delete default routing for a third party.');
		$this->registerCommonOptions($options, 'routing:delete');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'routing:delete');
		$options->registerOption('type', 'Routing owner type.', null, 'TYPE', 'routing:delete');

		$options->registerCommand('sync:flows', 'Synchronize incoming flows.');
		$this->registerCommonOptions($options, 'sync:flows');
		$options->registerOption('from', 'Timestamp or parseable date.', null, 'DATE', 'sync:flows');
		$options->registerOption('limit', 'Maximum number of flows.', null, 'N', 'sync:flows');

		$options->registerCommand('thirdparty:create', 'Create a third party.');
		$this->registerThirdpartyCreateOptions($options, 'thirdparty:create', true);

		$options->registerCommand('thirdparty:update', 'Update a third party.');
		$this->registerThirdpartyUpdateOptions($options, 'thirdparty:update', true);

		$options->registerCommand('thirdparty:delete', 'Delete a third party.');
		$this->registerCommonOptions($options, 'thirdparty:delete');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'thirdparty:delete');
		$options->registerOption('yes', 'Confirm deletion.', null, false, 'thirdparty:delete');

		$options->registerCommand('thirdparty:logo:get', 'Show third-party logo information.');
		$this->registerCommonOptions($options, 'thirdparty:logo:get');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'thirdparty:logo:get');

		$options->registerCommand('thirdparty:logo:set', 'Set third-party logo from a local file.');
		$this->registerCommonOptions($options, 'thirdparty:logo:set');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'thirdparty:logo:set');
		$options->registerOption('file', 'Local image path.', null, 'PATH', 'thirdparty:logo:set');
		$options->registerOption('squared', 'Also set as squared logo.', null, false, 'thirdparty:logo:set');

		$options->registerCommand('thirdparty:logo:fetch', 'Set third-party logo from a URL.');
		$this->registerCommonOptions($options, 'thirdparty:logo:fetch');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'thirdparty:logo:fetch');
		$options->registerOption('url', 'Image URL.', null, 'URL', 'thirdparty:logo:fetch');
		$options->registerOption('squared', 'Also set as squared logo.', null, false, 'thirdparty:logo:fetch');

		$options->registerCommand('thirdparty:logo:delete', 'Delete third-party logo.');
		$this->registerCommonOptions($options, 'thirdparty:logo:delete');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'thirdparty:logo:delete');
		$options->registerOption('yes', 'Confirm deletion.', null, false, 'thirdparty:logo:delete');

		$options->registerCommand('prospect:create', 'Create a prospect.');
		$this->registerThirdpartyCreateOptions($options, 'prospect:create', false);

		$options->registerCommand('prospect:update', 'Update a prospect and keep it as prospect.');
		$this->registerThirdpartyUpdateOptions($options, 'prospect:update', false);

		$options->registerCommand('customer:create', 'Create a customer.');
		$this->registerThirdpartyCreateOptions($options, 'customer:create', false);

		$options->registerCommand('customer:update', 'Update a customer and keep it as customer.');
		$this->registerThirdpartyUpdateOptions($options, 'customer:update', false);

		$options->registerCommand('thirdparty:get', 'Fetch a third party by ID, SIREN, SIRET, or email.');
		$this->registerCommonOptions($options, 'thirdparty:get');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'thirdparty:get');
		$options->registerOption('siren', 'SIREN.', null, 'SIREN', 'thirdparty:get');
		$options->registerOption('siret', 'SIRET.', null, 'SIRET', 'thirdparty:get');
		$options->registerOption('email', 'Email address.', null, 'EMAIL', 'thirdparty:get');

		$options->registerCommand('thirdparty:list', 'List third parties.');
		$this->registerCommonOptions($options, 'thirdparty:list');
		$options->registerOption('kind', 'Filter: prospect, customer, supplier, other, or all.', null, 'KIND', 'thirdparty:list');
		$options->registerOption('limit', 'Maximum number of third parties.', null, 'N', 'thirdparty:list');

		$options->registerCommand('prospect:list', 'List prospects.');
		$this->registerCommonOptions($options, 'prospect:list');
		$options->registerOption('limit', 'Maximum number of prospects.', null, 'N', 'prospect:list');

		$options->registerCommand('customer:list', 'List customers.');
		$this->registerCommonOptions($options, 'customer:list');
		$options->registerOption('limit', 'Maximum number of customers.', null, 'N', 'customer:list');

		$options->registerCommand('other:list', 'List third parties that are neither prospect, customer, nor supplier.');
		$this->registerCommonOptions($options, 'other:list');
		$options->registerOption('limit', 'Maximum number of third parties.', null, 'N', 'other:list');

		$options->registerCommand('contact:create', 'Create a contact/address linked to a third party.');
		$this->registerContactOptions($options, 'contact:create', true);

		$options->registerCommand('contact:update', 'Update a contact/address.');
		$this->registerContactOptions($options, 'contact:update', false);

		$options->registerCommand('contact:delete', 'Delete a contact/address.');
		$this->registerCommonOptions($options, 'contact:delete');
		$options->registerOption('id', 'Dolibarr contact/address ID.', null, 'ID', 'contact:delete');
		$options->registerOption('yes', 'Confirm deletion.', null, false, 'contact:delete');

		$options->registerCommand('contact:list', 'List contacts/addresses linked to a third party.');
		$this->registerCommonOptions($options, 'contact:list');
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', 'contact:list');
		$options->registerOption('kind', 'Filter linked third party: prospect, customer, other, or all.', null, 'KIND', 'contact:list');
		$options->registerOption('limit', 'Maximum number of contacts.', null, 'N', 'contact:list');

		$options->registerCommand('contact:prospects', 'List contacts/addresses linked to prospects.');
		$this->registerCommonOptions($options, 'contact:prospects');
		$options->registerOption('limit', 'Maximum number of contacts.', null, 'N', 'contact:prospects');

		$options->registerCommand('contact:customers', 'List contacts/addresses linked to customers.');
		$this->registerCommonOptions($options, 'contact:customers');
		$options->registerOption('limit', 'Maximum number of contacts.', null, 'N', 'contact:customers');

		$options->registerCommand('contact:others', 'List contacts/addresses linked to other third parties.');
		$this->registerCommonOptions($options, 'contact:others');
		$options->registerOption('limit', 'Maximum number of contacts.', null, 'N', 'contact:others');
	}

	protected function main(Options $options)
	{
		$this->globalOptions['format'] = $options->getOpt('json') ? 'json' : 'text';
		$this->globalOptions['quiet'] = (bool) $options->getOpt('quiet');
		$this->globalOptions['verbose'] = (bool) $options->getOpt('verbose');

		try {
			$this->globalOptions['entity'] = $this->parseEntityOption($options);

			$command = $options->getCmd();
			if ($options->getOpt('version') || $command === 'version') {
				$this->writeLine('docli '.self::VERSION);
				$this->exitCode = self::EXIT_OK;
				return;
			}

			if ($command === '' || $command === 'help') {
				$this->writeLine($options->help());
				$this->exitCode = $command === 'help' ? self::EXIT_OK : self::EXIT_USAGE;
				return;
			}

			$this->bootstrapDolibarr();

			switch ($command) {
				case 'provider:list':
					$this->exitCode = $this->providerList();
					return;
				case 'provider:get':
					$this->exitCode = $this->providerGet();
					return;
				case 'provider:set':
					$this->exitCode = $this->providerSet($options);
					return;
				case 'provider:health':
					$this->exitCode = $this->providerHealth();
					return;
				case 'token:get':
					$this->exitCode = $this->tokenGet($options);
					return;
				case 'company:validate':
					$this->exitCode = $this->companyValidate();
					return;
				case 'routing:list':
					$this->exitCode = $this->routingList($options);
					return;
				case 'routing:set':
					$this->exitCode = $this->routingSet($options);
					return;
				case 'routing:delete':
					$this->exitCode = $this->routingDelete($options);
					return;
				case 'sync:flows':
					$this->exitCode = $this->syncFlows($options);
					return;
				case 'thirdparty:create':
					$this->exitCode = $this->thirdpartyCreate($options);
					return;
				case 'thirdparty:update':
					$this->exitCode = $this->thirdpartyUpdate($options);
					return;
				case 'thirdparty:delete':
					$this->exitCode = $this->thirdpartyDelete($options);
					return;
				case 'thirdparty:logo:get':
					$this->exitCode = $this->thirdpartyLogoGet($options);
					return;
				case 'thirdparty:logo:set':
					$this->exitCode = $this->thirdpartyLogoSet($options);
					return;
				case 'thirdparty:logo:fetch':
					$this->exitCode = $this->thirdpartyLogoFetch($options);
					return;
				case 'thirdparty:logo:delete':
					$this->exitCode = $this->thirdpartyLogoDelete($options);
					return;
				case 'prospect:create':
					$this->exitCode = $this->thirdpartyCreate($options, 'prospect');
					return;
				case 'prospect:update':
					$this->exitCode = $this->thirdpartyUpdate($options, 'prospect');
					return;
				case 'customer:create':
					$this->exitCode = $this->thirdpartyCreate($options, 'customer');
					return;
				case 'customer:update':
					$this->exitCode = $this->thirdpartyUpdate($options, 'customer');
					return;
				case 'thirdparty:get':
					$this->exitCode = $this->thirdpartyGet($options);
					return;
				case 'thirdparty:list':
					$this->exitCode = $this->thirdpartyList($options, (string) $options->getOpt('kind', 'all'));
					return;
				case 'prospect:list':
					$this->exitCode = $this->thirdpartyList($options, 'prospect');
					return;
				case 'customer:list':
					$this->exitCode = $this->thirdpartyList($options, 'customer');
					return;
				case 'other:list':
					$this->exitCode = $this->thirdpartyList($options, 'other');
					return;
				case 'contact:create':
					$this->exitCode = $this->contactCreate($options);
					return;
				case 'contact:update':
					$this->exitCode = $this->contactUpdate($options);
					return;
				case 'contact:delete':
					$this->exitCode = $this->contactDelete($options);
					return;
				case 'contact:list':
					$this->exitCode = $this->contactList($options, (string) $options->getOpt('kind', 'all'));
					return;
				case 'contact:prospects':
					$this->exitCode = $this->contactList($options, 'prospect');
					return;
				case 'contact:customers':
					$this->exitCode = $this->contactList($options, 'customer');
					return;
				case 'contact:others':
					$this->exitCode = $this->contactList($options, 'other');
					return;
				default:
					$this->error("unknown command: ".$command);
					$this->error("run 'docli help' for usage");
					$this->exitCode = self::EXIT_USAGE;
					return;
			}
		} catch (InvalidArgumentException $e) {
			$this->error($e->getMessage());
			$this->exitCode = self::EXIT_USAGE;
		} catch (RuntimeException $e) {
			$this->error($e->getMessage());
			$this->exitCode = self::EXIT_CONFIG;
		} catch (Throwable $e) {
			$this->error($e->getMessage());
			if (!empty($this->globalOptions['verbose'])) {
				$this->error($e->getTraceAsString());
			}
			$this->exitCode = self::EXIT_SOFTWARE;
		}
	}

	public function getExitCode(): int
	{
		return $this->exitCode;
	}

	private function registerCommonOptions(Options $options, string $command = ''): void
	{
		$options->registerOption('json', 'Emit machine-readable JSON on stdout.', null, false, $command);
		$options->registerOption('text', 'Emit human-readable text, the default.', null, false, $command);
		$options->registerOption('quiet', 'Suppress normal stdout output.', 'q', false, $command);
		$options->registerOption('verbose', 'Print stack traces on unexpected errors.', 'v', false, $command);
		$options->registerOption('entity', 'Dolibarr entity ID to use, for multi-entity installations.', null, 'ID', $command);
		if ($command === '') {
			$options->registerOption('version', 'Show version.', null, false, $command);
			return;
		}
		$options->registerOption('help', 'Display this help screen and exit immediately.', 'h', false, $command);
	}

	private function parseEntityOption(Options $options): int
	{
		$value = $options->getOpt('entity');
		if ($value === null || $value === false || $value === '') {
			return 0;
		}
		if (!ctype_digit((string) $value) || (int) $value <= 0) {
			throw new InvalidArgumentException('--entity must be a positive integer');
		}
		return (int) $value;
	}

	private function registerThirdpartyCreateOptions(Options $options, string $command, bool $withTypeFlags): void
	{
		$this->registerCommonOptions($options, $command);
		$options->registerOption('name', 'Third-party legal name.', null, 'NAME', $command);
		$options->registerOption('alias', 'Commercial name or brand.', null, 'ALIAS', $command);
		if ($withTypeFlags) {
			$options->registerOption('customer', 'Mark as customer.', null, false, $command);
			$options->registerOption('prospect', 'Mark as prospect. This is the default when no type is provided.', null, false, $command);
			$options->registerOption('supplier', 'Mark as supplier.', null, false, $command);
		}
		$options->registerOption('code-client', 'Customer/prospect code. Use auto for Dolibarr numbering.', null, 'CODE', $command);
		$options->registerOption('code-supplier', 'Supplier code. Use auto for Dolibarr numbering.', null, 'CODE', $command);
		$options->registerOption('address', 'Postal address.', null, 'ADDRESS', $command);
		$options->registerOption('zip', 'Postal code.', null, 'ZIP', $command);
		$options->registerOption('town', 'City/town.', null, 'TOWN', $command);
		$options->registerOption('country', 'ISO country code, for example FR.', null, 'CODE', $command);
		$options->registerOption('phone', 'Phone number.', null, 'PHONE', $command);
		$options->registerOption('mobile', 'Mobile phone number.', null, 'PHONE', $command);
		$options->registerOption('email', 'Email address.', null, 'EMAIL', $command);
		$options->registerOption('web', 'Website URL.', null, 'URL', $command);
		$options->registerOption('siren', 'French SIREN, stored as idprof1.', null, 'SIREN', $command);
		$options->registerOption('siret', 'French SIRET, stored as idprof2.', null, 'SIRET', $command);
		$options->registerOption('ape', 'NAF/APE code, stored as idprof3.', null, 'APE', $command);
		$options->registerOption('rcs', 'RCS/RM, stored as idprof4.', null, 'RCS', $command);
		$options->registerOption('eori', 'EORI number, stored as idprof5.', null, 'EORI', $command);
		$options->registerOption('rna', 'RNA number, stored as idprof6.', null, 'RNA', $command);
		$options->registerOption('vat', 'VAT number.', null, 'VAT', $command);
	}

	private function registerThirdpartyUpdateOptions(Options $options, string $command, bool $withTypeFlags): void
	{
		$this->registerCommonOptions($options, $command);
		$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', $command);
		$options->registerOption('name', 'Third-party legal name.', null, 'NAME', $command);
		$options->registerOption('alias', 'Commercial name or brand.', null, 'ALIAS', $command);
		if ($withTypeFlags) {
			$options->registerOption('customer', 'Mark as customer.', null, false, $command);
			$options->registerOption('prospect', 'Mark as prospect.', null, false, $command);
			$options->registerOption('supplier', 'Mark as supplier.', null, false, $command);
			$options->registerOption('no-supplier', 'Remove supplier status.', null, false, $command);
		}
		$options->registerOption('code-client', 'Customer/prospect code.', null, 'CODE', $command);
		$options->registerOption('code-supplier', 'Supplier code.', null, 'CODE', $command);
		$options->registerOption('address', 'Postal address.', null, 'ADDRESS', $command);
		$options->registerOption('zip', 'Postal code.', null, 'ZIP', $command);
		$options->registerOption('town', 'City/town.', null, 'TOWN', $command);
		$options->registerOption('country', 'ISO country code, for example FR.', null, 'CODE', $command);
		$options->registerOption('phone', 'Phone number.', null, 'PHONE', $command);
		$options->registerOption('mobile', 'Mobile phone number.', null, 'PHONE', $command);
		$options->registerOption('email', 'Email address.', null, 'EMAIL', $command);
		$options->registerOption('web', 'Website URL.', null, 'URL', $command);
		$options->registerOption('siren', 'French SIREN, stored as idprof1.', null, 'SIREN', $command);
		$options->registerOption('siret', 'French SIRET, stored as idprof2.', null, 'SIRET', $command);
		$options->registerOption('ape', 'NAF/APE code, stored as idprof3.', null, 'APE', $command);
		$options->registerOption('rcs', 'RCS/RM, stored as idprof4.', null, 'RCS', $command);
		$options->registerOption('eori', 'EORI number, stored as idprof5.', null, 'EORI', $command);
		$options->registerOption('rna', 'RNA number, stored as idprof6.', null, 'RNA', $command);
		$options->registerOption('vat', 'VAT number.', null, 'VAT', $command);
	}

	private function registerContactOptions(Options $options, string $command, bool $isCreate): void
	{
		$this->registerCommonOptions($options, $command);
		if ($isCreate) {
			$options->registerOption('socid', 'Dolibarr third-party ID.', null, 'ID', $command);
		} else {
			$options->registerOption('id', 'Dolibarr contact/address ID.', null, 'ID', $command);
			$options->registerOption('socid', 'Move contact to another third party ID.', null, 'ID', $command);
		}
		$options->registerOption('lastname', 'Last name.', null, 'NAME', $command);
		$options->registerOption('firstname', 'First name.', null, 'NAME', $command);
		$options->registerOption('alias', 'Alias.', null, 'ALIAS', $command);
		$options->registerOption('job', 'Job title/function.', null, 'JOB', $command);
		$options->registerOption('address', 'Postal address.', null, 'ADDRESS', $command);
		$options->registerOption('zip', 'Postal code.', null, 'ZIP', $command);
		$options->registerOption('town', 'City/town.', null, 'TOWN', $command);
		$options->registerOption('country', 'ISO country code, for example FR.', null, 'CODE', $command);
		$options->registerOption('phone', 'Professional phone number.', null, 'PHONE', $command);
		$options->registerOption('phone-perso', 'Personal phone number.', null, 'PHONE', $command);
		$options->registerOption('mobile', 'Mobile phone number.', null, 'PHONE', $command);
		$options->registerOption('email', 'Email address.', null, 'EMAIL', $command);
		$options->registerOption('private', 'Create or keep as private contact.', null, false, $command);
		$options->registerOption('public', 'Make the contact shared/non-private.', null, false, $command);
	}

	private function bootstrapDolibarr(): void
	{
		$master = $this->findMasterInc();
		if ($master === null) {
			throw new RuntimeException('unable to find Dolibarr master.inc.php; run this script from an installed Dolibarr module directory');
		}

		global $conf, $db, $langs, $mysoc, $user, $hookmanager;

		if (!empty($this->globalOptions['entity'])) {
			$_ENV['dol_entity'] = (string) $this->globalOptions['entity'];
			$_SERVER['dol_entity'] = (string) $this->globalOptions['entity'];
		}

		require_once $master;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		dol_include_once('/pdpconnectfr/class/pdpconnectfr.class.php');
		dol_include_once('/pdpconnectfr/class/providers/PDPProviderManager.class.php');

		$this->db = $db;

		if (!($user instanceof User) || empty($user->id)) {
			$user = new User($db);
			$result = $user->fetch(1);
			if ($result <= 0) {
				$user->id = 0;
			}
		}
		$this->user = $user;
	}

	private function findMasterInc(): ?string
	{
		$envRoot = getenv('DOLIBARR_DOCUMENT_ROOT');
		if (is_string($envRoot) && $envRoot !== '') {
			$candidate = rtrim($envRoot, '/').'/master.inc.php';
			if (is_file($candidate)) {
				return $candidate;
			}
		}

		$dir = __DIR__;
		for ($i = 0; $i < 8; $i++) {
			$candidate = $dir.'/master.inc.php';
			if (is_file($candidate)) {
				return $candidate;
			}
			$parent = dirname($dir);
			if ($parent === $dir) {
				break;
			}
			$dir = $parent;
		}

		return null;
	}

	private function providerList(): int
	{
		$manager = new PDPProviderManager($this->db);
		$providers = array();

		foreach ($manager->getAllProviders() as $key => $config) {
			$providers[] = array(
				'key' => $key,
				'enabled' => !empty($config['is_enabled']),
				'name' => $this->stripTags((string) ($config['provider_name'] ?? $key)),
				'description' => (string) ($config['description'] ?? ''),
				'prod_account_url' => (string) ($config['prod_account_admin_url'] ?? ''),
				'test_account_url' => (string) ($config['test_account_admin_url'] ?? ''),
			);
		}

		return $this->output($providers, array('key', 'enabled', 'name', 'description'));
	}

	private function providerGet(): int
	{
		$data = array(
			'provider' => getDolGlobalString('PDPCONNECTFR_PDP'),
			'live' => (bool) getDolGlobalInt('PDPCONNECTFR_LIVE'),
			'protocol' => getDolGlobalString('PDPCONNECTFR_PROTOCOL'),
		);

		return $this->output($data);
	}

	private function providerSet(Options $options): int
	{
		global $conf;

		$provider = strtoupper((string) $options->getOpt('provider', ''));
		if ($provider === '') {
			throw new InvalidArgumentException('provider:set requires --provider NAME');
		}

		$manager = new PDPProviderManager($this->db);
		$providers = $manager->getAllProviders();
		if (!isset($providers[$provider])) {
			throw new InvalidArgumentException('unknown provider: '.$provider);
		}

		dolibarr_set_const($this->db, 'PDPCONNECTFR_PDP', $provider, 'chaine', 0, '', $conf->entity);
		if ($options->getOpt('live') && $options->getOpt('test')) {
			throw new InvalidArgumentException('use either --live or --test, not both');
		}
		if ($options->getOpt('live')) {
			dolibarr_set_const($this->db, 'PDPCONNECTFR_LIVE', '1', 'chaine', 0, '', $conf->entity);
		}
		if ($options->getOpt('test')) {
			dolibarr_set_const($this->db, 'PDPCONNECTFR_LIVE', '0', 'chaine', 0, '', $conf->entity);
		}
		if ($options->getOpt('protocol')) {
			dolibarr_set_const($this->db, 'PDPCONNECTFR_PROTOCOL', strtoupper((string) $options->getOpt('protocol')), 'chaine', 0, '', $conf->entity);
		}

		return $this->output(array(
			'provider' => getDolGlobalString('PDPCONNECTFR_PDP'),
			'live' => (bool) getDolGlobalInt('PDPCONNECTFR_LIVE'),
			'protocol' => getDolGlobalString('PDPCONNECTFR_PROTOCOL'),
		));
	}

	private function providerHealth(): int
	{
		$provider = $this->getConfiguredProvider();
		$result = $provider->checkHealth();

		return $this->output($result) === self::EXIT_OK && !empty($result['status']) ? self::EXIT_OK : self::EXIT_CONFIG;
	}

	private function tokenGet(Options $options): int
	{
		$provider = $this->getConfiguredProvider();
		$token = $provider->getAccessToken();

		if (empty($token)) {
			throw new RuntimeException('failed to retrieve access token');
		}

		$data = array('saved' => true);
		if ($options->getOpt('print-token')) {
			$data['access_token'] = $token;
		}

		return $this->output($data);
	}

	private function companyValidate(): int
	{
		$pdp = new PdpConnectFr($this->db);
		$result = $pdp->validateMyCompanyConfiguration();

		return $this->output($result) === self::EXIT_OK && ((int) $result['res']) > 0 ? self::EXIT_OK : self::EXIT_CONFIG;
	}

	private function routingList(Options $options): int
	{
		$socid = $this->requiredInt($options, 'socid');
		$type = (string) $options->getOpt('type', 'thirdparty');

		$pdp = new PdpConnectFr($this->db);
		$routings = $pdp->fetchAllRoutings($socid, $type, 0);
		if ($routings === -1) {
			throw new RuntimeException('failed to fetch routings');
		}

		return $this->output($routings, array('rowid', 'routing_id', 'source', 'info', 'is_default'));
	}

	private function routingSet(Options $options): int
	{
		$socid = $this->requiredInt($options, 'socid');
		$routingId = (string) $options->getOpt('routing-id', '');
		if ($routingId === '') {
			throw new InvalidArgumentException('routing:set requires --routing-id VALUE');
		}

		$pdp = new PdpConnectFr($this->db);
		$result = $pdp->setDefaultRouting(
			$socid,
			$routingId,
			(string) $options->getOpt('source', 'manual'),
			(string) $options->getOpt('info', ''),
			'',
			(string) $options->getOpt('type', 'thirdparty')
		);
		if ($result < 0) {
			throw new RuntimeException('failed to set routing: '.$pdp->error);
		}

		return $this->output(array('rowid' => $result, 'socid' => $socid, 'routing_id' => $routingId));
	}

	private function routingDelete(Options $options): int
	{
		$socid = $this->requiredInt($options, 'socid');
		$type = (string) $options->getOpt('type', 'thirdparty');

		$pdp = new PdpConnectFr($this->db);
		$result = $pdp->setDefaultRouting($socid, '', '', '', '', $type);
		if ($result < 0) {
			throw new RuntimeException('failed to delete routing: '.$pdp->error);
		}

		return $this->output(array('deleted' => true, 'socid' => $socid, 'type' => $type));
	}

	private function syncFlows(Options $options): int
	{
		$from = 0;
		if ($options->getOpt('from')) {
			$fromOption = (string) $options->getOpt('from');
			$from = ctype_digit($fromOption) ? (int) $fromOption : strtotime($fromOption);
			if ($from === false) {
				throw new InvalidArgumentException('invalid --from value, use timestamp or parseable date');
			}
		}
		$limit = $options->getOpt('limit') ? (int) $options->getOpt('limit') : 0;

		$provider = $this->getConfiguredProvider();
		$result = $provider->syncFlows($from, $limit);

		return $this->output($result) === self::EXIT_OK ? self::EXIT_OK : self::EXIT_SOFTWARE;
	}

	private function thirdpartyCreate(Options $options, string $forcedKind = ''): int
	{
		$name = trim((string) $options->getOpt('name', ''));
		if ($name === '') {
			throw new InvalidArgumentException('thirdparty:create requires --name NAME');
		}

		$thirdparty = new Societe($this->db);
		$thirdparty->name = $name;
		$thirdparty->nom = $name;
		$thirdparty->name_alias = trim((string) $options->getOpt('alias', ''));
		$thirdparty->client = $this->thirdpartyClientStatus($options, $forcedKind);
		$thirdparty->fournisseur = $forcedKind === 'supplier' || $options->getOpt('supplier') ? 1 : 0;
		if ($thirdparty->client > 0) {
			$thirdparty->code_client = (string) $options->getOpt('code-client', 'auto');
		}
		if ($thirdparty->fournisseur > 0) {
			$thirdparty->code_fournisseur = (string) $options->getOpt('code-supplier', 'auto');
		}
		$thirdparty->address = (string) $options->getOpt('address', '');
		$thirdparty->zip = (string) $options->getOpt('zip', '');
		$thirdparty->town = (string) $options->getOpt('town', '');
		$thirdparty->country_code = strtoupper((string) $options->getOpt('country', 'FR'));
		$thirdparty->country_id = $this->countryIdFromCode($thirdparty->country_code);
		$thirdparty->phone = (string) $options->getOpt('phone', '');
		$thirdparty->phone_mobile = (string) $options->getOpt('mobile', '');
		$thirdparty->email = (string) $options->getOpt('email', '');
		$thirdparty->url = (string) $options->getOpt('web', '');
		$thirdparty->idprof1 = (string) $options->getOpt('siren', '');
		$thirdparty->idprof2 = (string) $options->getOpt('siret', '');
		$thirdparty->idprof3 = (string) $options->getOpt('ape', '');
		$thirdparty->idprof4 = (string) $options->getOpt('rcs', '');
		$thirdparty->idprof5 = (string) $options->getOpt('eori', '');
		$thirdparty->idprof6 = (string) $options->getOpt('rna', '');
		$thirdparty->tva_intra = (string) $options->getOpt('vat', '');
		$thirdparty->status = 1;

		$result = $thirdparty->create($this->user);
		if ($result < 0) {
			throw new RuntimeException('failed to create third party: '.$this->objectError($thirdparty));
		}

		$thirdparty->fetch($result);
		return $this->output($this->thirdpartyData($thirdparty));
	}

	private function thirdpartyUpdate(Options $options, string $forcedKind = ''): int
	{
		$thirdparty = new Societe($this->db);
		$result = $thirdparty->fetch($this->requiredInt($options, 'socid'));
		if ($result <= 0) {
			throw new RuntimeException('third party not found');
		}

		if ($this->optionProvided($options, 'name')) {
			$thirdparty->name = trim((string) $options->getOpt('name'));
			$thirdparty->nom = $thirdparty->name;
		}
		if ($this->optionProvided($options, 'alias')) {
			$thirdparty->name_alias = (string) $options->getOpt('alias');
		}
		if ($forcedKind !== '') {
			$thirdparty->client = $this->thirdpartyClientStatus($options, $forcedKind);
		} elseif ($options->getOpt('customer') || $options->getOpt('prospect')) {
			$thirdparty->client = $this->thirdpartyClientStatus($options);
		}
		if ($options->getOpt('supplier')) {
			$thirdparty->fournisseur = 1;
		}
		if ($options->getOpt('no-supplier')) {
			$thirdparty->fournisseur = 0;
		}
		if ($this->optionProvided($options, 'code-client')) {
			$thirdparty->code_client = (string) $options->getOpt('code-client');
		}
		if ($this->optionProvided($options, 'code-supplier')) {
			$thirdparty->code_fournisseur = (string) $options->getOpt('code-supplier');
		}
		if ($this->optionProvided($options, 'address')) {
			$thirdparty->address = (string) $options->getOpt('address');
		}
		if ($this->optionProvided($options, 'zip')) {
			$thirdparty->zip = (string) $options->getOpt('zip');
		}
		if ($this->optionProvided($options, 'town')) {
			$thirdparty->town = (string) $options->getOpt('town');
		}
		if ($this->optionProvided($options, 'country')) {
			$thirdparty->country_code = strtoupper((string) $options->getOpt('country'));
			$thirdparty->country_id = $this->countryIdFromCode($thirdparty->country_code);
		}
		if ($this->optionProvided($options, 'phone')) {
			$thirdparty->phone = (string) $options->getOpt('phone');
		}
		if ($this->optionProvided($options, 'mobile')) {
			$thirdparty->phone_mobile = (string) $options->getOpt('mobile');
		}
		if ($this->optionProvided($options, 'email')) {
			$thirdparty->email = (string) $options->getOpt('email');
		}
		if ($this->optionProvided($options, 'web')) {
			$thirdparty->url = (string) $options->getOpt('web');
		}
		if ($this->optionProvided($options, 'siren')) {
			$thirdparty->idprof1 = (string) $options->getOpt('siren');
		}
		if ($this->optionProvided($options, 'siret')) {
			$thirdparty->idprof2 = (string) $options->getOpt('siret');
		}
		if ($this->optionProvided($options, 'ape')) {
			$thirdparty->idprof3 = (string) $options->getOpt('ape');
		}
		if ($this->optionProvided($options, 'rcs')) {
			$thirdparty->idprof4 = (string) $options->getOpt('rcs');
		}
		if ($this->optionProvided($options, 'eori')) {
			$thirdparty->idprof5 = (string) $options->getOpt('eori');
		}
		if ($this->optionProvided($options, 'rna')) {
			$thirdparty->idprof6 = (string) $options->getOpt('rna');
		}
		if ($this->optionProvided($options, 'vat')) {
			$thirdparty->tva_intra = (string) $options->getOpt('vat');
		}

		$result = $thirdparty->update($thirdparty->id, $this->user, 1, 1, 1);
		if ($result < 0) {
			throw new RuntimeException('failed to update third party: '.$this->objectError($thirdparty));
		}

		$thirdparty->fetch($thirdparty->id);
		return $this->output($this->thirdpartyData($thirdparty));
	}

	private function thirdpartyDelete(Options $options): int
	{
		if (!$options->getOpt('yes')) {
			throw new InvalidArgumentException('thirdparty:delete requires --yes');
		}

		$thirdparty = new Societe($this->db);
		$socid = $this->requiredInt($options, 'socid');
		$result = $thirdparty->fetch($socid);
		if ($result <= 0) {
			throw new RuntimeException('third party not found');
		}

		$data = $this->thirdpartyData($thirdparty);
		$result = $thirdparty->delete($thirdparty->id, $this->user, 1);
		if ($result < 0) {
			throw new RuntimeException('failed to delete third party: '.$this->objectError($thirdparty));
		}
		if ($result === 0) {
			throw new RuntimeException('third party was not deleted because it is used by other Dolibarr objects');
		}

		return $this->output(array('deleted' => true, 'thirdparty' => $data));
	}

	private function thirdpartyLogoGet(Options $options): int
	{
		$thirdparty = $this->fetchThirdpartyById($this->requiredInt($options, 'socid'));
		return $this->output($this->thirdpartyLogoData($thirdparty));
	}

	private function thirdpartyLogoSet(Options $options): int
	{
		$thirdparty = $this->fetchThirdpartyById($this->requiredInt($options, 'socid'));
		$file = (string) $options->getOpt('file', '');
		if ($file === '') {
			throw new InvalidArgumentException('thirdparty:logo:set requires --file PATH');
		}
		if (!is_readable($file)) {
			throw new RuntimeException('logo file is not readable: '.$file);
		}

		return $this->setThirdpartyLogoFromFile($thirdparty, $file, (bool) $options->getOpt('squared'));
	}

	private function thirdpartyLogoFetch(Options $options): int
	{
		$thirdparty = $this->fetchThirdpartyById($this->requiredInt($options, 'socid'));
		$url = (string) $options->getOpt('url', '');
		if ($url === '') {
			throw new InvalidArgumentException('thirdparty:logo:fetch requires --url URL');
		}
		if (!preg_match('/^https?:\/\//i', $url)) {
			throw new InvalidArgumentException('--url must start with http:// or https://');
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'docli-logo-');
		if ($tmpFile === false) {
			throw new RuntimeException('failed to create temporary file');
		}
		$content = @file_get_contents($url);
		if ($content === false || $content === '') {
			@unlink($tmpFile);
			throw new RuntimeException('failed to download logo: '.$url);
		}
		file_put_contents($tmpFile, $content);
		$path = parse_url($url, PHP_URL_PATH);
		$extension = is_string($path) ? pathinfo($path, PATHINFO_EXTENSION) : '';
		if ($extension !== '') {
			$withExtension = $tmpFile.'.'.$extension;
			rename($tmpFile, $withExtension);
			$tmpFile = $withExtension;
		}

		try {
			return $this->setThirdpartyLogoFromFile($thirdparty, $tmpFile, (bool) $options->getOpt('squared'), $url);
		} finally {
			@unlink($tmpFile);
		}
	}

	private function thirdpartyLogoDelete(Options $options): int
	{
		if (!$options->getOpt('yes')) {
			throw new InvalidArgumentException('thirdparty:logo:delete requires --yes');
		}

		$thirdparty = $this->fetchThirdpartyById($this->requiredInt($options, 'socid'));
		$data = $this->thirdpartyLogoData($thirdparty);
		$dir = $this->thirdpartyLogoDir($thirdparty);

		if (!empty($thirdparty->logo)) {
			dol_delete_file($dir.'/'.$thirdparty->logo);
		}
		if (!empty($thirdparty->logo_squarred) && $thirdparty->logo_squarred !== $thirdparty->logo) {
			dol_delete_file($dir.'/'.$thirdparty->logo_squarred);
		}
		dol_delete_dir_recursive($dir.'/thumbs');

		$thirdparty->logo = '';
		$thirdparty->logo_squarred = '';
		$result = $thirdparty->update($thirdparty->id, $this->user, 1, 1, 1);
		if ($result < 0) {
			throw new RuntimeException('failed to clear third-party logo: '.$this->objectError($thirdparty));
		}

		return $this->output(array('deleted' => true, 'previous_logo' => $data));
	}

	private function thirdpartyGet(Options $options): int
	{
		$thirdparty = new Societe($this->db);
		if ($options->getOpt('socid')) {
			$result = $thirdparty->fetch($this->requiredInt($options, 'socid'));
		} elseif ($options->getOpt('siren')) {
			$result = $thirdparty->fetch(0, '', '', '', (string) $options->getOpt('siren'));
		} elseif ($options->getOpt('siret')) {
			$result = $thirdparty->fetch(0, '', '', '', '', (string) $options->getOpt('siret'));
		} elseif ($options->getOpt('email')) {
			$result = $thirdparty->fetch(0, '', '', '', '', '', '', '', '', '', (string) $options->getOpt('email'));
		} else {
			throw new InvalidArgumentException('thirdparty:get requires --socid, --siren, --siret, or --email');
		}

		if ($result <= 0) {
			throw new RuntimeException('third party not found');
		}

		return $this->output($this->thirdpartyData($thirdparty));
	}

	private function fetchThirdpartyById(int $socid): Societe
	{
		$thirdparty = new Societe($this->db);
		$result = $thirdparty->fetch($socid);
		if ($result <= 0) {
			throw new RuntimeException('third party not found');
		}
		return $thirdparty;
	}

	private function setThirdpartyLogoFromFile(Societe $thirdparty, string $sourceFile, bool $squared = false, string $source = ''): int
	{
		if (!function_exists('image_format_supported') || image_format_supported($sourceFile) <= 0) {
			throw new InvalidArgumentException('unsupported image format: '.$sourceFile);
		}

		$dir = $this->thirdpartyLogoDir($thirdparty);
		dol_mkdir($dir);
		if (!is_dir($dir)) {
			throw new RuntimeException('failed to create logo directory: '.$dir);
		}

		$currentLogo = (string) $thirdparty->logo;
		$currentSquaredLogo = (string) $thirdparty->logo_squarred;
		$filename = dol_sanitizeFileName(basename($source ?: $sourceFile));
		if ($filename === '') {
			$filename = 'logo.'.pathinfo($sourceFile, PATHINFO_EXTENSION);
		}
		$target = $dir.'/'.$filename;

		if ($currentLogo !== '' && $currentLogo !== $filename) {
			dol_delete_file($dir.'/'.$currentLogo);
		}
		if ($currentSquaredLogo !== '' && $currentSquaredLogo !== $currentLogo && $currentSquaredLogo !== $filename) {
			dol_delete_file($dir.'/'.$currentSquaredLogo);
		}
		dol_delete_dir_recursive($dir.'/thumbs');

		if (!copy($sourceFile, $target)) {
			throw new RuntimeException('failed to copy logo to '.$target);
		}
		dolChmod($target, '0644');
		$thirdparty->addThumbs($target);
		$this->chmodThirdpartyLogoFiles($dir);

		$thirdparty->logo = $filename;
		if ($squared) {
			$thirdparty->logo_squarred = $filename;
		}
		$result = $thirdparty->update($thirdparty->id, $this->user, 1, 1, 1);
		if ($result < 0) {
			throw new RuntimeException('failed to update third-party logo: '.$this->objectError($thirdparty));
		}

		$thirdparty->fetch($thirdparty->id);
		return $this->output($this->thirdpartyLogoData($thirdparty));
	}

	private function thirdpartyLogoDir(Societe $thirdparty): string
	{
		global $conf;

		$entity = !empty($thirdparty->entity) ? (int) $thirdparty->entity : (int) $conf->entity;
		$base = $conf->societe->multidir_output[$entity] ?? $conf->societe->dir_output;
		return rtrim($base, '/').'/'.$thirdparty->id.'/logos';
	}

	private function chmodThirdpartyLogoFiles(string $dir): void
	{
		if (is_dir($dir)) {
			@chmod($dir, 0755);
		}

		$thumbsDir = $dir.'/thumbs';
		if (is_dir($thumbsDir)) {
			@chmod($thumbsDir, 0755);
		}

		foreach (glob($dir.'/*') ?: array() as $path) {
			if (is_file($path)) {
				dolChmod($path, '0644');
			}
		}
		foreach (glob($thumbsDir.'/*') ?: array() as $path) {
			if (is_file($path)) {
				dolChmod($path, '0644');
			}
		}
	}

	/** @return array<string, mixed> */
	private function thirdpartyLogoData(Societe $thirdparty): array
	{
		$dir = $this->thirdpartyLogoDir($thirdparty);
		$logo = (string) $thirdparty->logo;
		$squared = (string) $thirdparty->logo_squarred;
		return array(
			'socid' => (int) $thirdparty->id,
			'name' => (string) $thirdparty->name,
			'logo' => $logo,
			'logo_squarred' => $squared,
			'path' => $logo !== '' ? $dir.'/'.$logo : '',
			'thumb_small' => $logo !== '' ? $dir.'/'.getImageFileNameForSize($logo, '_small') : '',
			'thumb_mini' => $logo !== '' ? $dir.'/'.getImageFileNameForSize($logo, '_mini') : '',
		);
	}

	private function thirdpartyList(Options $options, string $kind): int
	{
		$kind = strtolower(trim($kind));
		$limit = $options->getOpt('limit') ? (int) $options->getOpt('limit') : 100;
		if ($limit < 1) {
			throw new InvalidArgumentException('--limit must be a positive integer');
		}

		$where = array('entity IN ('.getEntity('societe').')');
		$where = array_merge($where, $this->thirdpartyKindWhere($kind));

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE ".implode(' AND ', $where);
		$sql .= " ORDER BY nom ASC, rowid ASC";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException('failed to list third parties: '.$this->db->lasterror());
		}

		$thirdparties = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$thirdparty = new Societe($this->db);
			if ($thirdparty->fetch((int) $obj->rowid) > 0) {
				$thirdparties[] = $this->thirdpartyData($thirdparty);
			}
		}
		$this->db->free($resql);

		return $this->output($thirdparties, array('id', 'name', 'client', 'supplier', 'code_client', 'email', 'phone', 'town'));
	}

	private function contactCreate(Options $options): int
	{
		$socid = $this->requiredInt($options, 'socid');
		$lastname = trim((string) $options->getOpt('lastname', ''));
		$firstname = trim((string) $options->getOpt('firstname', ''));
		if ($lastname === '' && $firstname === '') {
			throw new InvalidArgumentException('contact:create requires --lastname NAME or --firstname NAME');
		}

		$thirdparty = new Societe($this->db);
		if ($thirdparty->fetch($socid) <= 0) {
			throw new RuntimeException('third party not found: '.$socid);
		}

		$contact = new Contact($this->db);
		$contact->socid = $socid;
		$contact->fk_soc = $socid;
		$contact->lastname = $lastname;
		$contact->name = $lastname;
		$contact->firstname = $firstname;
		$contact->name_alias = (string) $options->getOpt('alias', '');
		$contact->poste = (string) $options->getOpt('job', '');
		$contact->address = (string) $options->getOpt('address', $thirdparty->address);
		$contact->zip = (string) $options->getOpt('zip', $thirdparty->zip);
		$contact->town = (string) $options->getOpt('town', $thirdparty->town);
		$contact->country_code = strtoupper((string) $options->getOpt('country', $thirdparty->country_code ?: 'FR'));
		$contact->country_id = $this->countryIdFromCode($contact->country_code);
		$contact->phone_pro = (string) $options->getOpt('phone', '');
		$contact->phone_perso = (string) $options->getOpt('phone-perso', '');
		$contact->phone_mobile = (string) $options->getOpt('mobile', '');
		$contact->email = (string) $options->getOpt('email', '');
		$contact->priv = $options->getOpt('private') ? 1 : 0;
		$contact->status = 1;
		$contact->statut = 1;

		$result = $contact->create($this->user);
		if ($result < 0) {
			throw new RuntimeException('failed to create contact: '.$this->objectError($contact));
		}

		$contact->fetch($result);
		return $this->output($this->contactData($contact));
	}

	private function contactUpdate(Options $options): int
	{
		$contact = new Contact($this->db);
		$result = $contact->fetch($this->requiredInt($options, 'id'));
		if ($result <= 0) {
			throw new RuntimeException('contact not found');
		}

		if ($this->optionProvided($options, 'socid')) {
			$socid = $this->requiredInt($options, 'socid');
			$thirdparty = new Societe($this->db);
			if ($thirdparty->fetch($socid) <= 0) {
				throw new RuntimeException('third party not found: '.$socid);
			}
			$contact->socid = $socid;
			$contact->fk_soc = $socid;
		}
		if ($this->optionProvided($options, 'lastname')) {
			$contact->lastname = (string) $options->getOpt('lastname');
			$contact->name = $contact->lastname;
		}
		if ($this->optionProvided($options, 'firstname')) {
			$contact->firstname = (string) $options->getOpt('firstname');
		}
		if ($this->optionProvided($options, 'alias')) {
			$contact->name_alias = (string) $options->getOpt('alias');
		}
		if ($this->optionProvided($options, 'job')) {
			$contact->poste = (string) $options->getOpt('job');
		}
		if ($this->optionProvided($options, 'address')) {
			$contact->address = (string) $options->getOpt('address');
		}
		if ($this->optionProvided($options, 'zip')) {
			$contact->zip = (string) $options->getOpt('zip');
		}
		if ($this->optionProvided($options, 'town')) {
			$contact->town = (string) $options->getOpt('town');
		}
		if ($this->optionProvided($options, 'country')) {
			$contact->country_code = strtoupper((string) $options->getOpt('country'));
			$contact->country_id = $this->countryIdFromCode($contact->country_code);
		}
		if ($this->optionProvided($options, 'phone')) {
			$contact->phone_pro = (string) $options->getOpt('phone');
		}
		if ($this->optionProvided($options, 'phone-perso')) {
			$contact->phone_perso = (string) $options->getOpt('phone-perso');
		}
		if ($this->optionProvided($options, 'mobile')) {
			$contact->phone_mobile = (string) $options->getOpt('mobile');
		}
		if ($this->optionProvided($options, 'email')) {
			$contact->email = (string) $options->getOpt('email');
		}
		if ($options->getOpt('private')) {
			$contact->priv = 1;
		}
		if ($options->getOpt('public')) {
			$contact->priv = 0;
		}

		$result = $contact->update($contact->id, $this->user, 0);
		if ($result < 0) {
			throw new RuntimeException('failed to update contact: '.$this->objectError($contact));
		}

		$contact->fetch($contact->id);
		return $this->output($this->contactData($contact));
	}

	private function contactDelete(Options $options): int
	{
		if (!$options->getOpt('yes')) {
			throw new InvalidArgumentException('contact:delete requires --yes');
		}

		$contact = new Contact($this->db);
		$result = $contact->fetch($this->requiredInt($options, 'id'));
		if ($result <= 0) {
			throw new RuntimeException('contact not found');
		}

		$data = $this->contactData($contact);
		$result = $contact->delete($this->user, 0);
		if ($result < 0) {
			throw new RuntimeException('failed to delete contact: '.$this->objectError($contact));
		}

		return $this->output(array('deleted' => true, 'contact' => $data));
	}

	private function contactList(Options $options, string $kind = 'all'): int
	{
		$limit = $options->getOpt('limit') ? (int) $options->getOpt('limit') : 100;
		if ($limit < 1) {
			throw new InvalidArgumentException('--limit must be a positive integer');
		}

		$where = array('sp.entity IN ('.getEntity('contact').')');
		if ($options->getOpt('socid')) {
			$where[] = 'sp.fk_soc = '.$this->requiredInt($options, 'socid');
		}
		$where = array_merge($where, $this->thirdpartyKindWhere($kind, 's'));

		$sql = "SELECT sp.rowid FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = sp.fk_soc";
		$sql .= " WHERE ".implode(' AND ', $where);
		$sql .= " ORDER BY sp.lastname, sp.firstname, sp.rowid";
		$sql .= $this->db->plimit($limit);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException('failed to list contacts: '.$this->db->lasterror());
		}

		$contacts = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$contact = new Contact($this->db);
			if ($contact->fetch((int) $obj->rowid) > 0) {
				$contacts[] = $this->contactData($contact);
			}
		}
		$this->db->free($resql);

		return $this->output($contacts, array('id', 'socid', 'lastname', 'firstname', 'email', 'phone', 'mobile', 'town'));
	}

	private function getConfiguredProvider(): AbstractPDPProvider
	{
		$providerName = getDolGlobalString('PDPCONNECTFR_PDP');
		if ($providerName === '') {
			throw new RuntimeException('no PA provider configured; run provider:set first');
		}

		$manager = new PDPProviderManager($this->db);
		$provider = $manager->getProvider($providerName);
		if (!$provider instanceof AbstractPDPProvider) {
			throw new RuntimeException('configured provider is unavailable: '.$providerName);
		}

		return $provider;
	}

	private function requiredInt(Options $options, string $name): int
	{
		$value = $options->getOpt($name, '');
		if ($value === '' || !ctype_digit((string) $value)) {
			throw new InvalidArgumentException('--'.$name.' must be a positive integer');
		}

		return (int) $value;
	}

	private function optionProvided(Options $options, string $name): bool
	{
		return array_key_exists($name, $options->getOpt());
	}

	private function thirdpartyClientStatus(Options $options, string $forcedKind = ''): int
	{
		if ($forcedKind === 'prospect') {
			return 2;
		}
		if ($forcedKind === 'customer') {
			return 1;
		}
		$isCustomer = (bool) $options->getOpt('customer');
		$isProspect = (bool) $options->getOpt('prospect');
		if (!$isCustomer && !$isProspect && !$options->getOpt('supplier')) {
			$isProspect = true;
		}
		if ($isCustomer && $isProspect) {
			return 3;
		}
		if ($isProspect) {
			return 2;
		}
		if ($isCustomer) {
			return 1;
		}
		return 0;
	}

	/** @return array<int, string> */
	private function thirdpartyKindWhere(string $kind, string $alias = ''): array
	{
		$kind = strtolower(trim($kind));
		$prefix = $alias !== '' ? $alias.'.' : '';
		switch ($kind) {
			case 'all':
			case '':
				return array();
			case 'prospect':
			case 'prospects':
				return array($prefix.'client IN (2, 3)');
			case 'customer':
			case 'customers':
			case 'client':
			case 'clients':
				return array($prefix.'client IN (1, 3)');
			case 'supplier':
			case 'suppliers':
			case 'fournisseur':
			case 'fournisseurs':
				return array($prefix.'fournisseur = 1');
			case 'other':
			case 'others':
			case 'autre':
			case 'autres':
				return array(
					'('.$prefix.'client IS NULL OR '.$prefix.'client = 0)',
					'('.$prefix.'fournisseur IS NULL OR '.$prefix.'fournisseur = 0)',
				);
			default:
				throw new InvalidArgumentException('unknown kind value: '.$kind);
		}
	}

	private function countryIdFromCode(string $countryCode): int
	{
		$countryCode = strtoupper(trim($countryCode));
		if ($countryCode === '') {
			return 0;
		}
		$countryId = getCountry($countryCode, 3);
		return is_numeric($countryId) ? (int) $countryId : 0;
	}

	private function objectError($object): string
	{
		if (!empty($object->errors) && is_array($object->errors)) {
			return implode('; ', $object->errors);
		}
		if (!empty($object->error)) {
			return (string) $object->error;
		}
		return $this->db->lasterror() ?: 'unknown error';
	}

	/** @return array<string, mixed> */
	private function thirdpartyData(Societe $thirdparty): array
	{
		return array(
			'id' => (int) $thirdparty->id,
			'name' => (string) $thirdparty->name,
			'alias' => (string) $thirdparty->name_alias,
			'client' => (int) $thirdparty->client,
			'supplier' => (int) $thirdparty->fournisseur,
			'code_client' => (string) $thirdparty->code_client,
			'code_supplier' => (string) $thirdparty->code_fournisseur,
			'address' => (string) $thirdparty->address,
			'zip' => (string) $thirdparty->zip,
			'town' => (string) $thirdparty->town,
			'country_code' => (string) $thirdparty->country_code,
			'phone' => (string) $thirdparty->phone,
			'mobile' => (string) $thirdparty->phone_mobile,
			'email' => (string) $thirdparty->email,
			'web' => (string) $thirdparty->url,
			'siren' => (string) $thirdparty->idprof1,
			'siret' => (string) $thirdparty->idprof2,
			'ape' => (string) $thirdparty->idprof3,
			'vat' => (string) $thirdparty->tva_intra,
		);
	}

	/** @return array<string, mixed> */
	private function contactData(Contact $contact): array
	{
		return array(
			'id' => (int) $contact->id,
			'socid' => (int) $contact->socid,
			'lastname' => (string) $contact->lastname,
			'firstname' => (string) $contact->firstname,
			'job' => (string) $contact->poste,
			'address' => (string) $contact->address,
			'zip' => (string) $contact->zip,
			'town' => (string) $contact->town,
			'country_code' => (string) $contact->country_code,
			'phone' => (string) $contact->phone_pro,
			'phone_perso' => (string) $contact->phone_perso,
			'mobile' => (string) $contact->phone_mobile,
			'email' => (string) $contact->email,
			'private' => (bool) $contact->priv,
		);
	}

	/**
	 * @param mixed $data
	 * @param array<int, string> $columns
	 */
	private function output($data, array $columns = array()): int
	{
		if ($this->globalOptions['format'] === 'json') {
			$this->writeLine(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return self::EXIT_OK;
		}

		if (is_array($data) && $this->isList($data) && !empty($columns)) {
			$this->printTable($data, $columns);
			return self::EXIT_OK;
		}

		if (is_array($data)) {
			foreach ($data as $key => $value) {
				if (is_bool($value)) {
					$value = $value ? 'true' : 'false';
				} elseif (is_array($value) || is_object($value)) {
					$value = json_encode($value, JSON_UNESCAPED_SLASHES);
				}
				$this->writeLine($key.': '.$value);
			}
			return self::EXIT_OK;
		}

		$this->writeLine((string) $data);
		return self::EXIT_OK;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, string> $columns
	 */
	private function printTable(array $rows, array $columns): void
	{
		$widths = array();
		foreach ($columns as $column) {
			$widths[$column] = strlen($column);
		}
		foreach ($rows as $row) {
			foreach ($columns as $column) {
				$value = $this->stringify($row[$column] ?? '');
				$widths[$column] = max($widths[$column], strlen($value));
			}
		}

		$line = array();
		foreach ($columns as $column) {
			$line[] = str_pad($column, $widths[$column]);
		}
		$this->writeLine(implode('  ', $line));

		foreach ($rows as $row) {
			$line = array();
			foreach ($columns as $column) {
				$line[] = str_pad($this->stringify($row[$column] ?? ''), $widths[$column]);
			}
			$this->writeLine(implode('  ', $line));
		}
	}

	/** @param mixed $value */
	private function stringify($value): string
	{
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return '';
		}
		return (string) $value;
	}

	/** @param mixed $data */
	private function isList($data): bool
	{
		return is_array($data) && array_keys($data) === range(0, count($data) - 1);
	}

	private function stripTags(string $value): string
	{
		$value = strip_tags($value);
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
	}

	private function writeLine(string $line): void
	{
		if (!empty($this->globalOptions['quiet'])) {
			return;
		}
		fwrite(STDOUT, $line.PHP_EOL);
	}

	public function error($message, array $context = array())
	{
		fwrite(STDERR, 'docli: '.$message.PHP_EOL);
	}
}

$app = new Docli();
$app->run();
exit($app->getExitCode());
