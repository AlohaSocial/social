<?php

declare(strict_types=1);

namespace OCA\Social\SetupChecks;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Service\ConfigService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

class AtprotoHttpsCheck implements ISetupCheck {
	public function __construct(
		private readonly IL10N $l10n,
		private readonly ConfigService $config,
		private readonly IdentityService $identities,
	) {
	}
	public function getCategory(): string {
		return 'system';
	}
	public function getName(): string {
		return $this->l10n->t('Aloha Social: native PDS HTTPS');
	}
	public function run(): SetupResult {
		if (!$this->identities->isEnabled()) {
			return SetupResult::success($this->l10n->t('AT Protocol is disabled.'));
		}
		try {
			$url = $this->config->getSocialUrl();
		} catch (\Throwable) {
			$url = '';
		}
		if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
			return SetupResult::error($this->l10n->t('AT Protocol requires a public HTTPS Social address.'), Docs::ADMIN_GUIDE);
		}
		return SetupResult::success($this->l10n->t('The configured Social address uses HTTPS.'));
	}
}
