<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Exception;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\CloudAddressRules;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\SetupNotificationService;
use OCA\Social\Service\TestService;
use OCA\Social\Tools\Model\SimpleDataStore;
use OCA\Social\Tools\Traits\TNCDataResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ConfigController extends Controller {
	use TNCDataResponse;

	private TestService $testService;
	private MiscService $miscService;

	public function __construct(
		string $appName,
		IRequest $request,
		TestService $testService,
		private ConfigService $configService,
		MiscService $miscService,
		private SetupNotificationService $setupNotifications,
	) {
		parent::__construct($appName, $request);
		$this->testService = $testService;
		$this->miscService = $miscService;
	}

	/**
	 * Confirms the address every id is built from, on the first setup only.
	 *
	 * Administrators only (no `NoAdminRequired`). Once stored it is refused
	 * with 409: changing it breaks every federated id, and only
	 * `occ social:reset --uri=…` may do that, because it deletes them too.
	 * An address with a problem `CloudAddressRules` names is refused with 422
	 * unless `allowUnsafe` says the administrator accepts it, which a test
	 * instance on http or localhost needs; an address that is not a URL at all
	 * is refused either way.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/config/cloudAddress')]
	public function setCloudAddress(string $cloudAddress, bool $allowUnsafe = false): DataResponse {
		try {
			$this->configService->getCloudUrl();

			return new DataResponse(
				['error' => 'the address is already set; occ social:reset --uri changes it', 'code' => 'already-set'],
				Http::STATUS_CONFLICT
			);
		} catch (SocialAppConfigException $e) {
		}

		$cloudAddress = CloudAddressRules::normalize($cloudAddress);
		$problems = CloudAddressRules::problems($cloudAddress);
		if (in_array(CloudAddressRules::INVALID, $problems, true) || ($problems !== [] && !$allowUnsafe)) {
			return new DataResponse(
				[
					'error' => 'this address cannot be used: ' . implode(', ', $problems),
					'code' => 'address-refused',
					'problems' => $problems,
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$this->configService->setCloudUrl($cloudAddress);
		$this->setupNotifications->withdraw();

		return new DataResponse(['cloudAddress' => $this->configService->getCloudUrl()]);
	}

	/**
	 * Local Version+Setup Test
	 *
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/local/')]
	public function local(): DataResponse {
		$setup = false;
		try {
			$this->configService->getCloudUrl();
			$setup = true;
		} catch (SocialAppConfigException $e) {
		}

		return $this->success(
			[
				'version' => $this->configService->getAppValue('installed_version'),
				'setup' => $setup
			]
		);
	}

	/**
	 * Actor Test
	 *
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/test/{account}/')]
	public function remote(string $account): DataResponse {
		if ($account === '' || $this->configService->getSystemValue('social.tests') === '') {
			return $this->local();
		}

		try {
			$this->configService->getCloudUrl();
		} catch (SocialAppConfigException $e) {
			return $this->success(['error' => 'error on my side: my own Social App is not configured']);
		}

		$tests = new SimpleDataStore(
			[
				'account' => $account,
				'endpoint' => $this->configService->getSystemValue('social.tests')
			]
		);
		try {
			$this->testService->testWebfinger($tests);
		} catch (Exception $e) {
			return $this->fail($e, ['result' => $tests], Http::STATUS_OK);
		}

		return $this->success([$tests]);
	}
}
