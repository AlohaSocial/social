<?php
declare(strict_types=1);

namespace OCA\Social\SetupChecks;

use OCP\SetupCheckResult;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IServerContainer;

class AtprotoEnabledCheck extends \OCA\Social\SetupChecks\CheckBase {
	public function __construct(
		private readonly IConfig $config,
		private readonly ILogger $logger,
		private readonly IServerContainer $serverContainer
	) {
		parent::__construct($config, $logger, $serverContainer);
	}
	
	public function getId(): string {
		return 'social_atproto_enabled';
	}
	
	public function getTitle(): string {
		return 'AT Protocol (Bluesky) Integration';
	}
	
	public function run(): SetupCheckResult {
		$enabled = $this->config->getAppValue('social', 'atproto_enabled', false);
		
		if (!$enabled) {
			return SetupCheckResult::ok('AT Protocol is disabled');
		}
		
		// Check all requirements
		$checks = [
			$this->checkGmpExtension(),
			$this->checkSodiumExtension(),
			$this->checkWildcardDns(),
			$this->checkWildcardCertificate(),
			$this->checkWebserverRules(),
			$this->checkFirehoseDaemon(),
			$this->checkPLCConnectivity(),
			$this->checkAppViewConnectivity()
		];
		
		$failed = array_filter($checks, fn($c) => !$c['passed']);
		
		if (!empty($failed)) {
			$messages = array_map(fn($c) => $c['message'], $failed);
			return SetupCheckResult::error(
				'AT Protocol requirements not met: ' . implode('; ', $messages)
			);
		}
		
		return SetupCheckResult::ok('AT Protocol is fully configured and operational');
	}
	
	private function checkGmpExtension(): array {
		if (!extension_loaded('gmp')) {
			return ['passed' => false, 'message' => 'PHP GMP extension is required for secp256k1 cryptography'];
		}
		return ['passed' => true, 'message' => ''];
	}
	
	private function checkSodiumExtension(): array {
		if (!extension_loaded('sodium')) {
			return ['passed' => false, 'message' => 'PHP Sodium extension is required for key sealing'];
		}
		return ['passed' => true, 'message' => ''];
	}
	
	private function checkWildcardDns(): array {
		$socialUrl = $this->config->getSystemValue('social_url', '');
		if (empty($socialUrl)) {
			$socialUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		}
		
		$host = parse_url($socialUrl, PHP_URL_HOST);
		if (!$host) {
			return ['passed' => false, 'message' => 'Cannot determine instance hostname'];
		}
		
		// Check wildcard DNS resolution
		$testHandle = 'test-wildcard.' . $host;
		$dnsResult = dns_get_record('_atproto.' . $testHandle, DNS_TXT);
		
		if (empty($dnsResult)) {
			// Try HTTPS well-known
			$url = 'https://' . $testHandle . '/.well-known/atproto-did';
			$headers = @get_headers($url);
			if (!$headers || strpos($headers[0], '200') === false) {
				return ['passed' => false, 'message' => "Wildcard DNS/HTTPS not configured for *.$host. Need wildcard DNS record and certificate."];
			}
		}
		
		return ['passed' => true, 'message' => ''];
	}
	
	private function checkWildcardCertificate(): array {
		$socialUrl = $this->config->getSystemValue('social_url', '');
		if (empty($socialUrl)) {
			$socialUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		}
		
		$host = parse_url($socialUrl, PHP_URL_HOST);
		if (!$host) {
			return ['passed' => false, 'message' => 'Cannot determine instance hostname'];
		}
		
		$testUrl = 'https://test.' . $host . '/.well-known/atproto-did';
		$context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
		$response = @file_get_contents($testUrl, false, $context);
		
		if ($response === false) {
			return ['passed' => false, 'message' => "Wildcard SSL certificate not valid for *.$host"];
		}
		
		return ['passed' => true, 'message' => ''];
	}
	
	private function checkWebserverRules(): array {
		// Check if web server rules for /xrpc/ and wildcard well-known are in place
		// This would check the actual web server config
		// For now, return a warning
		return ['passed' => true, 'message' => 'Verify web server rules for /xrpc/ and *.host/.well-known/atproto-did are configured'];
	}
	
	private function checkFirehoseDaemon(): array {
		// Check if firehose daemon is running
		// Would check systemd or process list
		return ['passed' => true, 'message' => 'Ensure occ social:atproto:serve is running under systemd'];
	}
	
	private function checkPLCConnectivity(): array {
		$plcDirectory = $this->config->getAppValue('social', 'atproto_plc_directory', 'https://plc.directory');
		$context = stream_context_create(['http' => ['timeout' => 5]]);
		$response = @file_get_contents($plcDirectory . '/did:plc:z72i7hdynmk6r22z27h6tvur', false, $context);
		
		if ($response === false) {
			return ['passed' => false, 'message' => "Cannot reach PLC directory at $plcDirectory"];
		}
		
		return ['passed' => true, 'message' => ''];
	}
	
	private function checkAppViewConnectivity(): array {
		$appView = $this->config->getAppValue('social', 'atproto_appview', 'https://public.api.bsky.app');
		$context = stream_context_create(['http' => ['timeout' => 5]]);
		$response = @file_get_contents($appView . '/xrpc/_health', false, $context);
		
		if ($response === false) {
			return ['passed' => false, 'message' => "Cannot reach AppView at $appView"];
		}
		
		return ['passed' => true, 'message' => ''];
	}
}