<?php
declare(strict_types=1);

namespace OCA\Social\SetupChecks;

use OCP\SetupCheckResult;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\IServerContainer;

class AtprotoHttpsCheck extends \OCA\Social\SetupChecks\CheckBase {
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly IServerContainer $serverContainer
	) {
		parent::__construct($config, $logger, $serverContainer);
	}
	
	public function getId(): string {
		return 'social_atproto_https';
	}
	
	public function getTitle(): string {
		return 'AT Protocol HTTPS Requirement';
	}
	
	public function run(): SetupCheckResult {
		$enabled = $this->config->getAppValue('social', 'atproto_enabled', false);
		
		if (!$enabled) {
			return SetupCheckResult::ok('AT Protocol is disabled');
		}
		
		$socialUrl = $this->config->getSystemValue('social_url', '');
		if (empty($socialUrl)) {
			$socialUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		}
		
		if (str_starts_with($socialUrl, 'http://')) {
			return SetupCheckResult::error(
				'AT Protocol requires HTTPS. Instance URL is HTTP. ' .
				'AT Protocol PDS cannot operate on HTTP. Configure a valid HTTPS URL.'
			);
		}
		
		if (!str_starts_with($socialUrl, 'https://')) {
			return SetupCheckResult::error(
				'AT Protocol requires HTTPS. Instance URL does not use HTTPS scheme.'
			);
		}
		
		return SetupCheckResult::ok('Instance uses HTTPS as required by AT Protocol');
	}
}