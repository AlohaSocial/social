<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCP\IAppConfig;

/**
 * What this server says about itself to the people on it: its rules, who to
 * contact, its privacy policy and legal notice, and the address an app signs
 * in with.
 *
 * Read from the settings that already hold each of them — the rules and the
 * contact from this app, the two links from Nextcloud's Theming — so the
 * About page, the Mastodon instance entity and the sign-up page cannot
 * disagree. The same facts decide whether the server is ready to be public.
 */
class AboutService {
	public const MISSING_CONTACT = 'contact';
	public const MISSING_RULES = 'rules';
	public const MISSING_PRIVACY = 'privacy_policy';
	public const MISSING_LEGAL_NOTICE = 'legal_notice';

	public function __construct(
		private ConfigService $configService,
		private InstanceService $instanceService,
		private ActorsRequest $actorsRequest,
		private IAppConfig $appConfig,
		private CheckService $checkService,
		private AtprotoConfig $atprotoConfig,
	) {
	}

	/**
	 * @return array{
	 *     name: string,
	 *     description: string,
	 *     rules: array<array{id: string, text: string}>,
	 *     contact: array{email: string, account: array{username: string, display_name: string}|null},
	 *     privacy_policy_url: string,
	 *     legal_notice_url: string,
	 *     apps: array{mastodon: string, bluesky: string}
	 * }
	 */
	public function about(): array {
		return [
			'name' => $this->appConfig->getValueString('theming', 'name', 'Nextcloud'),
			'description' => trim($this->configService->getAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION)),
			'rules' => $this->instanceService->rules(),
			'contact' => [
				'email' => trim($this->configService->getAppValue(ConfigService::CONTACT_EMAIL)),
				'account' => $this->contactAccount(),
			],
			'privacy_policy_url' => $this->webUrl('privacyUrl'),
			'legal_notice_url' => $this->webUrl('imprintUrl'),
			'apps' => $this->appAddresses(),
		];
	}

	/**
	 * What to type into a phone or desktop app to sign in here, '' where that
	 * kind of app cannot.
	 *
	 * A Mastodon app is given a domain and asks `/api/v1/instance` at its
	 * root, so the domain is offered only once the setup check has found the
	 * client API answering there; the stored result is read, never probed,
	 * because anybody signed in may ask. A Bluesky app is given the PDS, which
	 * exists while Bluesky is switched on.
	 *
	 * @return array{mastodon: string, bluesky: string}
	 */
	public function appAddresses(): array {
		$mastodon = '';
		$bluesky = '';
		try {
			if ($this->checkService->clientApiRootIsKnownGood()) {
				$mastodon = $this->configService->getCloudAuthority();
			}
			if ($this->atprotoConfig->isEnabled()) {
				$bluesky = $this->atprotoConfig->pdsEndpoint();
			}
		} catch (SocialAppConfigException $e) {
			// no address confirmed yet: there is nothing an app could reach
		}

		return ['mastodon' => $mastodon, 'bluesky' => $bluesky];
	}

	/**
	 * What a server open to other people should say and does not, as the
	 * `MISSING_*` names, in that order.
	 *
	 * @return list<string>
	 */
	public function missingBasics(): array {
		$about = $this->about();
		$missing = [];
		if ($about['contact']['email'] === '' && $about['contact']['account'] === null) {
			$missing[] = self::MISSING_CONTACT;
		}
		if ($about['rules'] === []) {
			$missing[] = self::MISSING_RULES;
		}
		if ($about['privacy_policy_url'] === '') {
			$missing[] = self::MISSING_PRIVACY;
		}
		if ($about['legal_notice_url'] === '') {
			$missing[] = self::MISSING_LEGAL_NOTICE;
		}

		return $missing;
	}

	/** Whether people without a Nextcloud account may sign up here. */
	public function isOpenForSignUp(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_EXTERNAL_ENABLED);
	}

	/**
	 * Whether posts from here reach other servers: on Bluesky, or on the
	 * fediverse unless the allow list is empty.
	 */
	public function isFederating(): bool {
		if ($this->configService->getAppValueBool(ConfigService::ATPROTO_ENABLED)) {
			return true;
		}

		if ($this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_TYPE) !== ($this->configService->accessTypeList['WHITELIST'] ?? 'none_but')) {
			return true;
		}

		$allowed = json_decode($this->configService->getAppValue(ConfigService::SOCIAL_ACCESS_LIST), true);

		return is_array($allowed) && $allowed !== [];
	}

	/** The contact account, while it is still an account here. */
	private function contactAccount(): ?array {
		$userId = trim($this->configService->getAppValue(ConfigService::SOCIAL_CONTACT_ACCOUNT));
		if ($userId === '') {
			return null;
		}

		try {
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException|SocialAppConfigException $e) {
			return null;
		}
		if (!$actor->isLocal()) {
			return null;
		}

		return ['username' => $actor->getPreferredUsername(), 'display_name' => $actor->getName()];
	}

	/** A Theming link, when it is a web address. */
	private function webUrl(string $key): string {
		$url = trim($this->appConfig->getValueString('theming', $key, ''));

		return preg_match('#^https?://#i', $url) === 1 ? $url : '';
	}
}
