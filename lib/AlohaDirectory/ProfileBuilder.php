<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\AlohaDirectory;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\InstanceService;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * The listing profile of concept 6.2, as this server sends it to the
 * directory.
 *
 * What the server already knows is taken from it: the name, descriptions and
 * logo of the instance, its rules, languages and contact address, the signup
 * mode, email verification, minimum age and media quota of Social-only
 * accounts, and whether accounts are on Bluesky too. What only the operator
 * can say (jurisdiction, operator, moderation commitments) comes from the form
 * on the administration page, which may also override the rest.
 */
class ProfileBuilder {
	public const OPERATOR_TYPES = ['person', 'association', 'company', 'public_body'];

	/**
	 * The fields of the form, by group, with their type.
	 */
	public const FORM = [
		'identity' => ['name' => 'string', 'description' => 'string', 'long_description' => 'string'],
		'terms' => ['terms_url' => 'url', 'privacy_url' => 'url', 'min_age' => 'int'],
		'audience' => ['languages' => 'list', 'topic' => 'string', 'audience' => 'string'],
		'jurisdiction' => ['hosting_country' => 'country', 'operator_seat' => 'country', 'gdpr' => 'bool', 'dpa_url' => 'url'],
		'operator' => ['name' => 'string', 'type' => 'string', 'website' => 'url', 'funding' => 'string', 'commitment_until' => 'string', 'shutdown_notice_months' => 'int'],
		'moderation' => ['moderators' => 'int', 'abuse_email' => 'string', 'abuse_actor' => 'url', 'response_hours' => 'int', 'blocklist_policy' => 'string', 'policy_url' => 'url'],
		'account' => ['storage_mb' => 'int'],
	];

	/** The fields the directory requires, as `group.field`. */
	private const REQUIRED = [
		'identity.name', 'identity.description', 'terms.terms_url', 'terms.privacy_url', 'terms.rules',
		'audience.languages', 'jurisdiction.hosting_country', 'jurisdiction.operator_seat', 'jurisdiction.gdpr',
		'operator.name', 'operator.type', 'operator.funding', 'operator.commitment_until', 'operator.shutdown_notice_months',
		'moderation.moderators', 'moderation.abuse_email', 'moderation.response_hours', 'moderation.policy_url',
		'account.signup_mode', 'account.storage_mb',
	];

	public function __construct(
		private DirectoryConfig $config,
		private InstanceService $instanceService,
		private ExternalUserService $externalUserService,
		private AtprotoConfig $atprotoConfig,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * The profile, without empty fields, and with an `etag` over the rest.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$form = $this->form();
		$instance = $this->instanceService->getLocal();
		$about = rtrim($this->urlGenerator->linkToRouteAbsolute('social.Navigation.navigate'), '/') . '/about';
		$minAge = $this->externalUserService->minimumAge();
		$quota = $this->externalUserService->mediaQuota();

		$profile = [
			'identity' => [
				'name' => $form['identity']['name'] ?? $instance->getTitle(),
				'description' => $form['identity']['description'] ?? mb_substr(trim(strip_tags($instance->getShortDescription())), 0, 280),
				'long_description' => $form['identity']['long_description'] ?? mb_substr(trim(strip_tags($instance->getDescription())), 0, 5000),
				'logo_url' => $this->logo($instance->getImage()),
			],
			'terms' => [
				'terms_url' => $form['terms']['terms_url'] ?? $about,
				'privacy_url' => $form['terms']['privacy_url'] ?? $this->https($this->appConfig->getValueString('theming', 'privacyUrl', '')),
				'rules' => array_values(array_map(static fn (array $rule): string => (string)$rule['text'], $this->instanceService->rules())),
				'min_age' => $form['terms']['min_age'] ?? (($minAge >= 13 && $minAge <= 21) ? $minAge : null),
			],
			'audience' => [
				'languages' => $form['audience']['languages'] ?? array_values(array_filter($instance->getLanguages(), 'is_string')),
				'topic' => $form['audience']['topic'] ?? 'general',
				'audience' => $form['audience']['audience'] ?? null,
			],
			'jurisdiction' => $form['jurisdiction'] ?? [],
			'operator' => $form['operator'] ?? [],
			'moderation' => [
				'moderators' => $form['moderation']['moderators'] ?? null,
				'abuse_email' => $form['moderation']['abuse_email'] ?? ($instance->getEmail() !== '' ? $instance->getEmail() : null),
				'abuse_actor' => $form['moderation']['abuse_actor'] ?? null,
				'response_hours' => $form['moderation']['response_hours'] ?? null,
				'blocklist_policy' => $form['moderation']['blocklist_policy'] ?? null,
				'policy_url' => $form['moderation']['policy_url'] ?? $about,
			],
			'account' => [
				'signup_mode' => $this->signupMode(),
				'email_required' => $this->externalUserService->verifiesEmail(),
				'storage_mb' => $form['account']['storage_mb'] ?? ($quota > 0 ? $quota : null),
				'features' => ['dm', 'shorts', 'video'],
			],
		];
		if ($this->atprotoConfig->isEnabled()) {
			$profile['atproto'] = ['enabled' => true, 'handle_example' => $this->handleExample(), 'move_away' => false];
		}
		if ($instance->getEmail() !== '') {
			$profile['contact_email'] = $instance->getEmail();
		}

		$profile = self::withoutEmpty($profile);
		$profile['etag'] = 'W/"' . substr(hash('sha256', (string)json_encode($profile)), 0, 16) . '"';

		return $profile;
	}

	/**
	 * The required fields the profile does not fill yet, as `group.field`.
	 *
	 * @param ?array<string, mixed> $profile from build(), built when not given
	 *
	 * @return list<string>
	 */
	public function missing(?array $profile = null): array {
		$profile ??= $this->build();
		$missing = [];
		foreach (self::REQUIRED as $path) {
			[$group, $field] = explode('.', $path);
			$value = $profile[$group][$field] ?? null;
			if ($value === null || $value === '' || $value === []) {
				$missing[] = $path;
			}
		}

		return $missing;
	}

	/**
	 * The form as the administrator last saved it, by group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function form(): array {
		return self::clean($this->config->json(DirectoryConfig::PROFILE));
	}

	/**
	 * Keeps the known fields of $input, typed, and drops empty ones.
	 *
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function saveForm(array $input): array {
		$form = self::clean($input);
		$this->config->setJson(DirectoryConfig::PROFILE, $form);

		return $form;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function clean(array $input): array {
		$form = [];
		foreach (self::FORM as $group => $fields) {
			$values = is_array($input[$group] ?? null) ? $input[$group] : [];
			foreach ($fields as $field => $type) {
				$value = self::typed($values[$field] ?? null, $type);
				if ($value !== null) {
					$form[$group][$field] = $value;
				}
			}
		}
		if (isset($form['operator']['type']) && !in_array($form['operator']['type'], self::OPERATOR_TYPES, true)) {
			unset($form['operator']['type']);
		}

		return $form;
	}

	/**
	 * @param mixed $value
	 *
	 * @return string|int|bool|list<string>|null
	 */
	private static function typed($value, string $type) {
		switch ($type) {
			case 'int':
				return (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) ? (int)$value : null;
			case 'bool':
				return is_bool($value) ? $value : null;
			case 'list':
				$items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
				$items = array_values(array_unique(array_filter(array_map(
					static fn ($item): string => is_string($item) ? strtolower(trim($item)) : '',
					$items
				), static fn (string $item): bool => preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $item) === 1)));

				return $items === [] ? null : array_slice($items, 0, 10);
			case 'country':
				$code = is_string($value) ? strtoupper(trim($value)) : '';

				return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
			case 'url':
				$url = is_string($value) ? trim($value) : '';

				return (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false) ? $url : null;
			default:
				$text = is_string($value) ? trim($value) : '';

				return $text === '' ? null : mb_substr($text, 0, 5000);
		}
	}

	/** `open` or `approval`; a server that takes invitations only is not listed. */
	private function signupMode(): ?string {
		return match ($this->externalUserService->mode()) {
			ExternalUserService::MODE_OPEN => 'open',
			ExternalUserService::MODE_APPROVAL => 'approval',
			default => null,
		};
	}

	private function handleExample(): ?string {
		try {
			return 'alice.' . $this->atprotoConfig->handleHost();
		} catch (SocialAppConfigException) {
			return null;
		}
	}

	/** The directory re-encodes PNG, JPEG, WebP and GIF; the app's default SVG is not offered. */
	private function logo(string $url): ?string {
		$path = strtolower((string)parse_url($url, PHP_URL_PATH));

		return (str_starts_with($url, 'https://') && !str_ends_with($path, '.svg')) ? $url : null;
	}

	private function https(string $url): ?string {
		return str_starts_with($url, 'https://') ? $url : null;
	}

	/**
	 * @param array<string, mixed> $values
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutEmpty(array $values): array {
		return array_filter(
			array_map(static fn (mixed $value): mixed => (is_array($value) && !array_is_list($value)) ? self::withoutEmpty($value) : $value, $values),
			static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
		);
	}
}
