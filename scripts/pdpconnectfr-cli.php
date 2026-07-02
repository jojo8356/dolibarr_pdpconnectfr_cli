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

final class PDPConnectFRCli
{
	private const VERSION = '1.0.0';

	private const EXIT_OK = 0;
	private const EXIT_USAGE = 2;
	private const EXIT_SOFTWARE = 70;
	private const EXIT_CONFIG = 78;

	/** @var array<int, string> */
	private array $argv;

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

	/**
	 * @param array<int, string> $argv
	 */
	public function __construct(array $argv)
	{
		$this->argv = $argv;
	}

	public function run(): int
	{
		try {
			$args = $this->parseGlobalOptions(array_slice($this->argv, 1));
			$command = array_shift($args);

			if ($command === null || $command === '') {
				$this->printShortHelp();
				return self::EXIT_USAGE;
			}

			if ($command === '-h' || $command === '--help' || $command === 'help') {
				$this->printHelp($args[0] ?? null);
				return self::EXIT_OK;
			}

			if ($command === '--version' || $command === 'version') {
				$this->writeLine('pdpconnectfr-cli '.self::VERSION);
				return self::EXIT_OK;
			}

			$this->bootstrapDolibarr();

			switch ($command) {
				case 'provider:list':
					return $this->providerList();
				case 'provider:get':
					return $this->providerGet();
				case 'provider:set':
					return $this->providerSet($args);
				case 'provider:health':
					return $this->providerHealth();
				case 'token:get':
					return $this->tokenGet($args);
				case 'company:validate':
					return $this->companyValidate();
				case 'routing:list':
					return $this->routingList($args);
				case 'routing:set':
					return $this->routingSet($args);
				case 'routing:delete':
					return $this->routingDelete($args);
				case 'sync:flows':
					return $this->syncFlows($args);
				default:
					$this->error("unknown command: ".$command);
					$this->error("run 'pdpconnectfr help' for usage");
					return self::EXIT_USAGE;
			}
		} catch (InvalidArgumentException $e) {
			$this->error($e->getMessage());
			return self::EXIT_USAGE;
		} catch (RuntimeException $e) {
			$this->error($e->getMessage());
			return self::EXIT_CONFIG;
		} catch (Throwable $e) {
			$this->error($e->getMessage());
			if (!empty($this->globalOptions['verbose'])) {
				$this->error($e->getTraceAsString());
			}
			return self::EXIT_SOFTWARE;
		}
	}

	/**
	 * @param array<int, string> $args
	 * @return array<int, string>
	 */
	private function parseGlobalOptions(array $args): array
	{
		$remaining = array();
		foreach ($args as $arg) {
			if ($arg === '--json') {
				$this->globalOptions['format'] = 'json';
				continue;
			}
			if ($arg === '--text') {
				$this->globalOptions['format'] = 'text';
				continue;
			}
			if ($arg === '-q' || $arg === '--quiet') {
				$this->globalOptions['quiet'] = true;
				continue;
			}
			if ($arg === '-v' || $arg === '--verbose') {
				$this->globalOptions['verbose'] = true;
				continue;
			}
			$remaining[] = $arg;
		}

		return $remaining;
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

	/**
	 * @param array<int, string> $args
	 */
	private function providerSet(array $args): int
	{
		global $conf;

		$options = $this->parseOptions($args, array('provider:', 'protocol:', 'live', 'test'));
		$provider = strtoupper((string) ($options['provider'] ?? ''));
		if ($provider === '') {
			throw new InvalidArgumentException('provider:set requires --provider NAME');
		}

		$manager = new PDPProviderManager($this->db);
		$providers = $manager->getAllProviders();
		if (!isset($providers[$provider])) {
			throw new InvalidArgumentException('unknown provider: '.$provider);
		}

		dolibarr_set_const($this->db, 'PDPCONNECTFR_PDP', $provider, 'chaine', 0, '', $conf->entity);
		if (!empty($options['live']) && !empty($options['test'])) {
			throw new InvalidArgumentException('use either --live or --test, not both');
		}
		if (!empty($options['live'])) {
			dolibarr_set_const($this->db, 'PDPCONNECTFR_LIVE', '1', 'chaine', 0, '', $conf->entity);
		}
		if (!empty($options['test'])) {
			dolibarr_set_const($this->db, 'PDPCONNECTFR_LIVE', '0', 'chaine', 0, '', $conf->entity);
		}
		if (!empty($options['protocol'])) {
			dolibarr_set_const($this->db, 'PDPCONNECTFR_PROTOCOL', strtoupper((string) $options['protocol']), 'chaine', 0, '', $conf->entity);
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

	/**
	 * @param array<int, string> $args
	 */
	private function tokenGet(array $args): int
	{
		$options = $this->parseOptions($args, array('print-token'));
		$provider = $this->getConfiguredProvider();
		$token = $provider->getAccessToken();

		if (empty($token)) {
			throw new RuntimeException('failed to retrieve access token');
		}

		$data = array('saved' => true);
		if (!empty($options['print-token'])) {
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

	/**
	 * @param array<int, string> $args
	 */
	private function routingList(array $args): int
	{
		$options = $this->parseOptions($args, array('socid:', 'type:'));
		$socid = $this->requiredInt($options, 'socid');
		$type = (string) ($options['type'] ?? 'thirdparty');

		$pdp = new PdpConnectFr($this->db);
		$routings = $pdp->fetchAllRoutings($socid, $type, 0);
		if ($routings === -1) {
			throw new RuntimeException('failed to fetch routings');
		}

		return $this->output($routings, array('rowid', 'routing_id', 'source', 'info', 'is_default'));
	}

	/**
	 * @param array<int, string> $args
	 */
	private function routingSet(array $args): int
	{
		$options = $this->parseOptions($args, array('socid:', 'routing-id:', 'type:', 'source:', 'info:'));
		$socid = $this->requiredInt($options, 'socid');
		$routingId = (string) ($options['routing-id'] ?? '');
		if ($routingId === '') {
			throw new InvalidArgumentException('routing:set requires --routing-id VALUE');
		}

		$pdp = new PdpConnectFr($this->db);
		$result = $pdp->setDefaultRouting(
			$socid,
			$routingId,
			(string) ($options['source'] ?? 'manual'),
			(string) ($options['info'] ?? ''),
			'',
			(string) ($options['type'] ?? 'thirdparty')
		);
		if ($result < 0) {
			throw new RuntimeException('failed to set routing: '.$pdp->error);
		}

		return $this->output(array('rowid' => $result, 'socid' => $socid, 'routing_id' => $routingId));
	}

	/**
	 * @param array<int, string> $args
	 */
	private function routingDelete(array $args): int
	{
		$options = $this->parseOptions($args, array('socid:', 'type:'));
		$socid = $this->requiredInt($options, 'socid');
		$type = (string) ($options['type'] ?? 'thirdparty');

		$pdp = new PdpConnectFr($this->db);
		$result = $pdp->setDefaultRouting($socid, '', '', '', '', $type);
		if ($result < 0) {
			throw new RuntimeException('failed to delete routing: '.$pdp->error);
		}

		return $this->output(array('deleted' => true, 'socid' => $socid, 'type' => $type));
	}

	/**
	 * @param array<int, string> $args
	 */
	private function syncFlows(array $args): int
	{
		$options = $this->parseOptions($args, array('from:', 'limit:'));
		$from = 0;
		if (!empty($options['from'])) {
			$from = ctype_digit((string) $options['from']) ? (int) $options['from'] : strtotime((string) $options['from']);
			if ($from === false) {
				throw new InvalidArgumentException('invalid --from value, use timestamp or parseable date');
			}
		}
		$limit = isset($options['limit']) ? (int) $options['limit'] : 0;

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

	/**
	 * @param array<int, string> $args
	 * @param array<int, string> $spec
	 * @return array<string, mixed>
	 */
	private function parseOptions(array $args, array $spec): array
	{
		$options = array();
		$expectsValue = array();
		foreach ($spec as $entry) {
			if (substr($entry, -1) === ':') {
				$expectsValue[substr($entry, 0, -1)] = true;
			} else {
				$expectsValue[$entry] = false;
			}
		}

		for ($i = 0; $i < count($args); $i++) {
			$arg = $args[$i];
			if ($arg === '-h' || $arg === '--help') {
				$this->printHelp($this->argv[1] ?? null);
				exit(self::EXIT_OK);
			}
			if ($arg === '--') {
				break;
			}
			if (strpos($arg, '--') !== 0) {
				throw new InvalidArgumentException('unexpected argument: '.$arg);
			}

			$nameValue = substr($arg, 2);
			$value = true;
			if (strpos($nameValue, '=') !== false) {
				[$nameValue, $value] = explode('=', $nameValue, 2);
			}
			if (!array_key_exists($nameValue, $expectsValue)) {
				throw new InvalidArgumentException('unknown option: --'.$nameValue);
			}
			if ($expectsValue[$nameValue]) {
				if ($value === true) {
					$i++;
					if (!isset($args[$i])) {
						throw new InvalidArgumentException('missing value for --'.$nameValue);
					}
					$value = $args[$i];
				}
				$options[$nameValue] = $value;
			} else {
				if ($value !== true) {
					throw new InvalidArgumentException('--'.$nameValue.' does not accept a value');
				}
				$options[$nameValue] = true;
			}
		}

		return $options;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function requiredInt(array $options, string $name): int
	{
		if (!isset($options[$name]) || !ctype_digit((string) $options[$name])) {
			throw new InvalidArgumentException('--'.$name.' must be a positive integer');
		}

		return (int) $options[$name];
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

	private function printShortHelp(): void
	{
		$this->writeLine('pdpconnectfr - manage PDPConnectFR from the command line');
		$this->writeLine('Usage: pdpconnectfr COMMAND [OPTIONS]');
		$this->writeLine('Examples:');
		$this->writeLine('  pdpconnectfr provider:list');
		$this->writeLine('  pdpconnectfr company:validate --json');
		$this->writeLine('  pdpconnectfr routing:set --socid 2 --routing-id 322324963');
		$this->writeLine("Run 'pdpconnectfr --help' for full help.");
	}

	private function printHelp(?string $command = null): void
	{
		$help = array(
			'provider:list' => 'List configured PA providers.',
			'provider:get' => 'Show current PA provider, mode, and protocol.',
			'provider:set' => 'Set PA provider: provider:set --provider SUPERPDP [--live|--test] [--protocol CII].',
			'provider:health' => 'Call the configured provider health check endpoint.',
			'token:get' => 'Fetch and save an OAuth token for the configured provider. Use --print-token to print it.',
			'company:validate' => 'Validate Dolibarr company data required for e-invoicing.',
			'routing:list' => 'List routings: routing:list --socid ID [--type thirdparty].',
			'routing:set' => 'Set default routing: routing:set --socid ID --routing-id VALUE [--type thirdparty].',
			'routing:delete' => 'Delete default routing: routing:delete --socid ID [--type thirdparty].',
			'sync:flows' => 'Synchronize incoming flows: sync:flows [--from DATE] [--limit N].',
		);

		if ($command !== null && isset($help[$command])) {
			$this->writeLine($command);
			$this->writeLine('  '.$help[$command]);
			return;
		}

		$this->writeLine('pdpconnectfr-cli '.self::VERSION);
		$this->writeLine('');
		$this->writeLine('Usage:');
		$this->writeLine('  pdpconnectfr COMMAND [OPTIONS]');
		$this->writeLine('');
		$this->writeLine('Global options:');
		$this->writeLine('  -h, --help       Show help.');
		$this->writeLine('  --version        Show version.');
		$this->writeLine('  --json           Emit machine-readable JSON on stdout.');
		$this->writeLine('  --text           Emit human-readable text, the default.');
		$this->writeLine('  -q, --quiet      Reserved for quiet mode.');
		$this->writeLine('  -v, --verbose    Print stack traces on unexpected errors.');
		$this->writeLine('');
		$this->writeLine('Commands:');
		foreach ($help as $name => $description) {
			$this->writeLine('  '.str_pad($name, 18).' '.$description);
		}
	}

	private function writeLine(string $line): void
	{
		if (!empty($this->globalOptions['quiet'])) {
			return;
		}
		fwrite(STDOUT, $line.PHP_EOL);
	}

	private function error(string $line): void
	{
		fwrite(STDERR, 'pdpconnectfr: '.$line.PHP_EOL);
	}
}

$app = new PDPConnectFRCli($argv);
exit($app->run());
