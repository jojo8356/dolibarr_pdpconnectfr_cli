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
 * Command line interface for PDPConnectFR.
 *
 * This script follows common POSIX/GNU CLI conventions:
 * - --help and --version are always available.
 * - Primary output goes to stdout.
 * - Diagnostics and errors go to stderr.
 * - Exit code is 0 on success and non-zero on failure.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "pdpconnectfr: this command must be run from a CLI SAPI\n");
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

final class PDPConnectFRCli extends CLI
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
	);

	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	private int $exitCode = self::EXIT_OK;

	protected function setup(Options $options)
	{
		$options->setHelp('Manage PDPConnectFR from the command line.');
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
	}

	protected function main(Options $options)
	{
		$this->globalOptions['format'] = $options->getOpt('json') ? 'json' : 'text';
		$this->globalOptions['quiet'] = (bool) $options->getOpt('quiet');
		$this->globalOptions['verbose'] = (bool) $options->getOpt('verbose');

		try {
			$command = $options->getCmd();
			if ($options->getOpt('version') || $command === 'version') {
				$this->writeLine('pdpconnectfr-cli '.self::VERSION);
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
				default:
					$this->error("unknown command: ".$command);
					$this->error("run 'pdpconnectfr help' for usage");
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
		if ($command === '') {
			$options->registerOption('version', 'Show version.', null, false, $command);
			return;
		}
		$options->registerOption('help', 'Display this help screen and exit immediately.', 'h', false, $command);
	}

	private function bootstrapDolibarr(): void
	{
		$master = $this->findMasterInc();
		if ($master === null) {
			throw new RuntimeException('unable to find Dolibarr master.inc.php; run this script from an installed Dolibarr module directory');
		}

		global $conf, $db, $langs, $mysoc, $user, $hookmanager;

		require_once $master;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
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
		fwrite(STDERR, 'pdpconnectfr: '.$message.PHP_EOL);
	}
}

$app = new PDPConnectFRCli();
$app->run();
exit($app->getExitCode());
