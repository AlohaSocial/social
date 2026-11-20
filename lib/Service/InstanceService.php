<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Db\InstancesRequest;
use OCA\Social\Db\InstanceStatsRequest;
use OCA\Social\Exceptions\CacheContentMimeTypeException;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Instance;
use OCA\Social\Tools\Traits\TArrayTools;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Util;

class InstanceService {
	use TArrayTools;

	/**
	 * How long a post may be, in characters, as on Mastodon; a client told no
	 * limit assumes 500. `PostService` refuses longer posts rather than
	 * truncating.
	 */
	public const MAX_CHARACTERS = 5000;

	/**
	 * Every mime type this app could conceivably accept an upload of. Not the
	 * allowlist: each is put to `CacheDocumentService::filterMimeTypes()`, so a
	 * type missing here is only under-reported, never over-reported.
	 */
	private const MIME_CANDIDATES = [
		'image/jpeg', 'image/gif', 'image/png', 'image/webp', 'image/avif', 'image/heic',
		'image/bmp', 'image/tiff', 'image/svg+xml',
		'video/mp4', 'video/webm', 'video/quicktime', 'video/ogg', 'video/x-matroska',
		'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav',
		'audio/flac', 'audio/aac', 'audio/webm', 'audio/3gpp',
		'application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/zip', 'application/epub+zip',
		'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/x-msdownload', 'text/html',
	];

	/**
	 * Where the counted `user_count`, `status_count` and `domain_count` are
	 * remembered, as JSON with a `computed_at` timestamp, and for how long: five
	 * minutes keeps the table walks off the request path.
	 */
	public const STATS_CACHE_KEY = 'instance_stats';
	public const STATS_CACHE_SECONDS = 300;

	/** Weeks of history `/api/v1/instance/activity` answers; Mastodon's is 12. */
	private const ACTIVITY_WEEKS = 12;
	private const WEEK = 7 * 24 * 3600;

	/** @var string[]|null memoised: filterMimeTypes() does not change mid-request */
	private ?array $supportedMimeTypes = null;

	/** @var array{user_count: int, status_count: int, domain_count: int}|null memoised per request */
	private ?array $countedStats = null;

	public function __construct(
		private InstancesRequest $instancesRequest,
		private ConfigService $configService,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private CacheDocumentService $cacheDocumentService,
		private InstanceStatsRequest $instanceStatsRequest,
		private TranslationService $translationService,
		private AccountService $accountService,
		private VapidService $vapidService,
		private ExternalUserService $externalUserService,
	) {
	}

	public function createLocal(): Instance {
		$instance = new Instance();
		$instance->setLocal(true);
		$this->fillLocal($instance, ACore::FORMAT_LOCAL);
		$this->instancesRequest->save($instance);

		return $instance;
	}

	/**
	 * @throws InstanceDoesNotExistException
	 */
	public function getLocal(int $format = ACore::FORMAT_LOCAL): Instance {
		try {
			$instance = $this->instancesRequest->getLocal($format);
		} catch (InstanceDoesNotExistException $e) {
			return $this->createLocal();
		}

		// The stored row is written once, at install, so everything a client is
		// told is derived from the current configuration; the row only marks that
		// the instance exists.
		$this->fillLocal($instance, $format);

		return $instance;
	}

	/**
	 * Everything /api/v1/instance and /api/v2/instance answer with: the first
	 * request of every Mastodon client, which reads an empty `uri` or missing
	 * `configuration` as "cannot connect to server".
	 */
	private function fillLocal(Instance $instance, int $format): void {
		$cloudHost = $this->configService->getCloudHost();
		$socialAddress = $this->configService->getSocialAddress();
		$uri = ($socialAddress !== '') ? $socialAddress : $cloudHost;
		$open = $this->externalUserService->acceptsPublicRegistrations();

		$instance->setUri($uri)
			->setVersion($this->appConfig->getValueString(Application::APP_ID, 'installed_version', '0.0'))
			->setTitle($this->appConfig->getValueString('theming', 'name', Application::APP_NAME_DISPLAY))
			->setShortDescription($this->shortDescription())
			->setDescription($this->description())
			->setEmail($this->appConfig->getValueString(Application::APP_ID, ConfigService::CONTACT_EMAIL, ''))
			->setImage($this->thumbnail())
			->setLanguages([$this->defaultLanguage()])
			// The sign-up for Social-only accounts, which the Aloha Directory
			// compares with the heartbeat. The client API cannot create the
			// account, so v2 names the page a client sends people to instead.
			->setRegistrations($open)
			->setApprovalRequired($open && $this->externalUserService->mode() === ExternalUserService::MODE_APPROVAL)
			// an invitation can be used only while there is room, whatever the
			// mode; "users may invite" alone says nothing when the sign-up is off
			->setInvitesEnabled($this->externalUserService->hasRoom() && $this->externalUserService->usersMayInvite())
			->setRegistrationsUrl($open ? $this->urlGenerator->linkToRouteAbsolute('social.Signup.page') : null)
			->setUrls($this->urls($uri))
			->setStats($this->stats())
			->setUsage($this->usage())
			->setConfiguration($this->configuration())
			// whether this Nextcloud has a translation provider at all; a
			// client reads it to decide whether to draw the button
			->setTranslationEnabled($this->translationService->isAvailable())
			->setRules($this->rules());

		$contactUserId = $this->appConfig->getValueString(
			Application::APP_ID,
			ConfigService::SOCIAL_CONTACT_ACCOUNT,
			''
		);
		if ($contactUserId !== '') {
			try {
				$account = $this->accountService->getActorFromUserId($contactUserId);
				$contact = $this->accountService->getCachedLocalActor($account->getPreferredUsername());
				$contact->setExportFormat($format);
				$instance->setContactAccount($contact);
			} catch (Exception $e) {
				// The owner can remove or delete the account after choosing it.
				// A stale contact setting must not make /api/v1/instance fail.
			}
		}
	}

	/**
	 * What this instance says it is: the description its administrator wrote
	 * under About this server, the same text the About page shows, else the
	 * slogan they gave Theming, else nothing. Never Nextcloud's own slogan,
	 * which describes Nextcloud and not this server.
	 */
	private function description(): string {
		$written = trim($this->appConfig->getValueString(Application::APP_ID, ConfigService::SOCIAL_EXTENDED_DESCRIPTION, ''));
		if ($written !== '') {
			return $written;
		}

		return trim($this->appConfig->getValueString('theming', 'slogan', ''));
	}

	/**
	 * The description's first paragraph: what a client shows in a list of
	 * servers, where the whole of a long description does not fit.
	 */
	private function shortDescription(): string {
		$paragraphs = preg_split('/\R\s*\R/u', $this->description(), 2);

		return trim(is_array($paragraphs) ? $paragraphs[0] : $this->description());
	}

	/**
	 * Mastodon's `urls`, whose only member is the streaming endpoint.
	 *
	 * The optional `social:mastodon:stream` daemon serves this address after
	 * the web server's root proxy rule is installed. Clients can fall back to
	 * polling while the daemon is unavailable.
	 */
	private function urls(string $host): array {
		return ['streaming_api' => 'wss://' . $host];
	}

	/**
	 * The instances this one has heard of, for `/api/v1/instance/peers`.
	 *
	 * @return string[]
	 */
	public function getPeers(): array {
		return $this->instanceStatsRequest->getRemoteDomains();
	}

	/**
	 * Twelve weeks of activity, newest first, in Mastodon's shape: every value
	 * a string, every week keyed by the unix time its Monday began.
	 *
	 * `logins` and `registrations` are `0` and cannot be otherwise: an account
	 * here is a Nextcloud user, so both belong to the server rather than to
	 * this app. `statuses` is real, and 0 while the administrator hides the
	 * counts.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function getWeeklyActivity(): array {
		$weeks = [];
		$startOfWeek = strtotime('monday this week 00:00:00');
		if ($startOfWeek === false) {
			return [];
		}

		$hidden = $this->countsHidden();
		for ($week = 0; $week < self::ACTIVITY_WEEKS; $week++) {
			$from = $startOfWeek - ($week * self::WEEK);
			$weeks[] = [
				'week' => (string)$from,
				'statuses' => $hidden ? '0' : (string)$this->instanceStatsRequest->countLocalStatusesBetween(
					$from, $from + self::WEEK
				),
				'logins' => '0',
				'registrations' => '0',
			];
		}

		return $weeks;
	}

	/**
	 * `stats` with all three keys, counted from the database and remembered for
	 * a few minutes (`countedStats()`). `user_count` is the local actors not
	 * deleted or suspended. Hidden counts are 0, since the client API requires
	 * numbers.
	 */
	private function stats(): array {
		$counted = $this->countedStats();
		$hidden = $this->countsHidden();

		return [
			'user_count' => $hidden ? 0 : $counted['user_count'],
			'status_count' => $hidden ? 0 : $counted['status_count'],
			'domain_count' => $counted['domain_count'],
		];
	}

	/**
	 * NodeInfo's shape for the same numbers. Hidden, `users` is the empty
	 * object the schema requires and `localPosts` is left out.
	 */
	private function usage(): array {
		if ($this->countsHidden()) {
			return ['users' => new \stdClass()];
		}

		$stats = $this->stats();

		return [
			'users' => ['total' => $stats['user_count']],
			'localPosts' => $stats['status_count'],
		];
	}

	/** Whether the administrator keeps the account and post counts private. */
	public function countsHidden(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_HIDE_INSTANCE_COUNTS);
	}

	/**
	 * How many other servers this one has met: `domain_count`, as the
	 * instance entity reports it and from the same remembered count.
	 */
	public function domainCount(): int {
		return $this->countedStats()['domain_count'];
	}

	/**
	 * The counted numbers, from app config when a recent count is there, else
	 * from the database and written back for STATS_CACHE_SECONDS; memoised for
	 * the request too.
	 *
	 * @return array{user_count: int, status_count: int, domain_count: int}
	 */
	private function countedStats(): array {
		if ($this->countedStats !== null) {
			return $this->countedStats;
		}

		$remembered = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, self::STATS_CACHE_KEY, ''), true
		);
		if (is_array($remembered) && array_key_exists('user_count', $remembered)
			&& (int)($remembered['computed_at'] ?? 0) > time() - self::STATS_CACHE_SECONDS) {
			return $this->countedStats = [
				'user_count' => (int)$remembered['user_count'],
				'status_count' => (int)($remembered['status_count'] ?? 0),
				'domain_count' => (int)($remembered['domain_count'] ?? 0),
			];
		}

		$counted = [
			'user_count' => $this->instanceStatsRequest->countLocalAccounts(),
			'status_count' => $this->instanceStatsRequest->countLocalStatuses(),
			'domain_count' => $this->instanceStatsRequest->countRemoteDomains(),
		];
		$this->appConfig->setValueString(
			Application::APP_ID, self::STATS_CACHE_KEY,
			(string)json_encode($counted + ['computed_at' => time()])
		);

		return $this->countedStats = $counted;
	}

	/**
	 * The limits a client has to respect before it lets someone write a post
	 * the server would refuse.
	 */
	private function configuration(): array {
		return [
			'statuses' => [
				'max_characters' => self::MAX_CHARACTERS,
				'max_media_attachments' => Stream::MAX_ATTACHMENTS,
				'characters_reserved_per_url' => LinkifyService::URL_LENGTH,
			],
			'media_attachments' => [
				'supported_mime_types' => $this->supportedMimeTypes(),
				'image_size_limit' => $this->imageSizeLimit(),
				// its own ceiling, not the picture one, or a client would never offer a
				// video larger than that
				'video_size_limit' => $this->videoSizeLimit(),
				'image_matrix_limit' => CacheDocumentService::MAX_PIXELS,
				'video_frame_rate_limit' => 0,
				'video_matrix_limit' => 0,
			],
			'polls' => [
				'max_options' => PostService::POLL_MAX_OPTIONS,
				'max_characters_per_option' => PostService::POLL_MAX_CHARACTERS_PER_OPTION,
				'min_expiration' => PostService::POLL_MIN_EXPIRATION,
				'max_expiration' => PostService::POLL_MAX_EXPIRATION,
			],
			'accounts' => [
				'max_featured_tags' => FeaturedTagService::MAX_FEATURED_TAGS,
			],
			'vapid' => [
				'public_key' => $this->vapidService->publicKey(),
			],
			// Mastodon has this in the v2 entity only; it is in v1 too because this
			// app's web client reads v1 for the limits.
			'translation' => [
				'enabled' => $this->translationService->isAvailable(),
			],
		];
	}

	/**
	 * The mime types an upload may actually have, asked of
	 * `CacheDocumentService::filterMimeTypes()` so the two cannot drift.
	 *
	 * @return string[]
	 */
	public function supportedMimeTypes(): array {
		if ($this->supportedMimeTypes !== null) {
			return $this->supportedMimeTypes;
		}

		$supported = [];
		foreach (self::MIME_CANDIDATES as $mime) {
			try {
				$this->cacheDocumentService->filterMimeTypes($mime);
				$supported[] = $mime;
			} catch (CacheContentMimeTypeException $e) {
			}
		}

		return $this->supportedMimeTypes = $supported;
	}

	/** The upload ceiling, in bytes, from the app's own `max_size` (in MB). */
	public function maxUploadSize(): int {
		$megabytes = $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE);

		return (($megabytes > 0) ? $megabytes : 10) * 1048576;
	}

	/**
	 * The ceiling a video is held to: its own `max_video_size`, since a video is
	 * never held in memory (`CacheDocumentService::saveMediaFromTemp()`) and the
	 * picture's `max_size` would allow only seconds of it. When `max_size` was
	 * raised above it, the larger wins.
	 */
	public function maxVideoUploadSize(): int {
		$megabytes = $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_VIDEO_SIZE);
		$ceiling = (($megabytes > 0) ? $megabytes : 2048) * 1048576;

		return max($ceiling, $this->maxUploadSize());
	}

	/**
	 * The largest picture an upload can deliver, in bytes: the app's own
	 * ceiling, or PHP's when that is lower. PHP refuses a larger file before
	 * this app sees it, so this, not `maxUploadSize()`, is what a client is
	 * told.
	 */
	public function imageSizeLimit(): int {
		return min($this->maxUploadSize(), $this->phpUploadLimit());
	}

	/** The largest video an upload can deliver, as `imageSizeLimit()` is for a picture. */
	public function videoSizeLimit(): int {
		return min($this->maxVideoUploadSize(), $this->phpUploadLimit());
	}

	/**
	 * The largest file PHP accepts in an upload, in bytes: the smaller of
	 * `upload_max_filesize` and `post_max_size`, `PHP_INT_MAX` when neither is
	 * set.
	 */
	public function phpUploadLimit(): int {
		$limit = Util::uploadLimit();

		return ($limit >= PHP_INT_MAX) ? PHP_INT_MAX : (int)$limit;
	}

	/**
	 * PHP's `post_max_size` in bytes, `PHP_INT_MAX` when it is not set. A
	 * request body over it reaches the app with `$_FILES` and `$_POST` empty.
	 */
	public function postMaxSize(): int {
		$size = Util::computerFileSize((string)ini_get('post_max_size'));

		return ($size === false || $size <= 0 || $size >= PHP_INT_MAX) ? PHP_INT_MAX : (int)$size;
	}

	/**
	 * The instance rules, as Mastodon's Rule entities. Stored as one rule per
	 * line in the `rules` app value, so an admin can set them with
	 * `occ config:app:set social rules --value "..."`.
	 *
	 * @return array<array{id: string, text: string}>
	 */
	public function rules(): array {
		$stored = trim($this->appConfig->getValueString(Application::APP_ID, 'rules', ''));
		if ($stored === '') {
			return [];
		}

		$rules = [];
		foreach (preg_split('/\r\n|\r|\n/', $stored) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$rules[] = ['id' => (string)(count($rules) + 1), 'text' => $line];
		}

		return $rules;
	}

	/**
	 * The server's privacy policy, as Mastodon's entity carries it: Nextcloud's
	 * own "Privacy policy link" from Theming, which covers this app too. The link
	 * is the content; `null` where none is set, which the caller answers with 404,
	 * as Mastodon does.
	 *
	 * @return array{updated_at: string, content: string}|null
	 */
	public function privacyPolicy(): ?array {
		return $this->legalDocument('privacyUrl', 'Privacy policy');
	}

	/**
	 * The server's terms of service, from Nextcloud's "Legal notice link", like
	 * the privacy policy.
	 *
	 * @return array{updated_at: string, content: string}|null
	 */
	public function termsOfService(): ?array {
		return $this->legalDocument('imprintUrl', 'Legal notice');
	}

	/**
	 * @return array{updated_at: string, content: string}|null
	 */
	private function legalDocument(string $key, string $label): ?array {
		$url = trim($this->appConfig->getValueString('theming', $key, ''));
		if ($url === '') {
			return null;
		}

		$escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

		return [
			// no date is stored for either, and Mastodon's field is required: the
			// time it was read is what a client uses to decide whether to show it
			// again
			'updated_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
			'content' => '<p>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
				. ': <a href="' . $escaped . '">' . $escaped . '</a></p>',
		];
	}

	private function defaultLanguage(): string {
		$language = $this->config->getSystemValue('default_language', 'en');

		return is_string($language) && $language !== '' ? $language : 'en';
	}

	private function thumbnail(): string {
		try {
			return $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'social.svg')
			);
		} catch (Exception $e) {
			return '';
		}
	}
}
