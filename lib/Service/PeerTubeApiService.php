<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Channel;
use OCP\IAppConfig;

/**
 * This app's posts, accounts and channels in the shapes PeerTube's own clients
 * read.
 *
 * The same move the Pixelfed routes are: a client that already exists, pointed
 * at this instance, is worth more than a client nobody has written. PeerTube's
 * API is not Mastodon's — different names, different ids, a different idea of
 * what a video is — so this is a translation layer and not an alias, and it
 * lives in one file for the same reason `PixelfedController` does: somebody
 * should be able to read the whole of what one client is promised in one place.
 *
 * **Ids.** PeerTube addresses a video by an integer and by a uuid. The integer
 * here is the post's `nid`, which is what this app's own API gives a client;
 * the uuid is the one `PeerTubeService::uuidFor()` already mints and already
 * publishes on the wire, so a video seen through this API and the same video
 * seen over ActivityPub carry the same uuid rather than two that nothing can
 * tell apart. `shortUUID` is that uuid again: PeerTube's short form is its own
 * base-62 encoding, and a made-up one would be a key that resolves nowhere.
 *
 * **What it does not do.** Nothing here writes. PeerTube's upload is a
 * resumable protocol with a transcoding state machine behind it, and a
 * half-built one that accepted a file and lost it would be worse than none.
 * Comments are read and not posted, for the same reason a client posting
 * through this route would bypass the review queue the composer goes through.
 */
class PeerTubeApiService {
	/**
	 * PeerTube's three NSFW policies, which are the three this app has — see
	 * `SensitiveMediaService` for why its names are Mastodon's.
	 */
	private const NSFW_POLICY = [
		SensitiveMediaService::SHOW_ALL => 'display',
		SensitiveMediaService::COVERED => 'blur',
		SensitiveMediaService::HIDE_ALL => 'do_not_list',
	];

	/** What this instance reports as its PeerTube version. */
	public const PEERTUBE_VERSION = '6.0.0';

	public function __construct(
		private ConfigService $configService,
		private ChannelService $channelService,
		private ChannelsRequest $channelsRequest,
		private CacheActorService $cacheActorService,
		private SensitiveMediaService $sensitiveMediaService,
		private VideoQuotaService $videoQuotaService,
		private VideoLadderService $videoLadderService,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The object a PeerTube client reads once, on launch, to decide whether it
	 * can talk to this server at all.
	 *
	 * Every switch is answered with what is **true here** rather than with
	 * PeerTube's default. Signup is `false` because an account on this instance
	 * is a Nextcloud account and is not made through this API; the import and
	 * upload switches are `false` because nothing here writes; and the quota is
	 * the real one, in bytes, with `-1` for "no quota", which is PeerTube's own
	 * way of saying it.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$quota = $this->videoQuotaService->quota();

		return [
			'instance' => [
				// the same name `InstanceService` reports, which is the one an
				// administrator set in Theming rather than one of this app's
				'name' => $this->appConfig->getValueString('theming', 'name', 'Aloha Social'),
				'shortDescription' => (string)$this->configService->getAppValue(
					ConfigService::SOCIAL_EXTENDED_DESCRIPTION
				),
				'defaultClientRoute' => '/videos/recently-added',
				'isNSFW' => false,
				'defaultNSFWPolicy' => self::NSFW_POLICY[$this->sensitiveMediaService->instancePolicy()]
					?? 'blur',
				'customizations' => ['javascript' => '', 'css' => ''],
				'avatars' => [],
				'banners' => [],
			],
			'search' => ['remoteUri' => ['users' => true, 'anonymous' => false]],
			'plugin' => ['registered' => []],
			'theme' => ['registered' => [], 'default' => 'default'],
			'email' => ['enabled' => false],
			'contactForm' => ['enabled' => false],
			// what this app *is*, said plainly. A client that reports the
			// server version to its user should not be told a PeerTube version
			// this server is not running, so both are here: the number its
			// checks compare against, and the name of what is actually
			// answering.
			'serverVersion' => self::PEERTUBE_VERSION,
			'serverCommit' => '',
			'software' => [
				'name' => 'nextcloud-social',
				'version' => $this->appConfig->getValueString('social', 'installed_version', ''),
			],
			// an account here is a Nextcloud account; there is no signing up
			// through this API and saying otherwise would send somebody to a
			// form that does not exist
			'signup' => [
				'allowed' => false,
				'allowedForCurrentIP' => false,
				'requiresEmailVerification' => false,
				'requiresApproval' => false,
			],
			'transcoding' => [
				'hls' => ['enabled' => $this->videoLadderService->isEnabled()],
				'web_videos' => ['enabled' => true],
				'enabledResolutions' => $this->videoLadderService->isEnabled()
					? $this->videoLadderService->heights() : [],
			],
			// nothing here writes: see the class comment
			'import' => [
				'videos' => [
					'http' => ['enabled' => false],
					'torrent' => ['enabled' => false],
				],
				'videoChannelSynchronization' => ['enabled' => false],
				'users' => ['enabled' => true],
			],
			'export' => ['users' => ['enabled' => false]],
			'autoBlacklist' => ['videos' => ['ofUsers' => ['enabled' => false]]],
			'avatar' => ['file' => ['size' => ['max' => 2 * 1024 * 1024]], 'extensions' => ['.png', '.jpg', '.jpeg']],
			'video' => [
				'image' => [
					'extensions' => ['.png', '.jpg', '.jpeg'],
					'size' => ['max' => $this->configService->getAppValueInt(ConfigService::SOCIAL_MAX_SIZE) * 1048576],
				],
				'file' => ['extensions' => ['.mp4', '.webm', '.mov', '.mkv', '.ogv']],
			],
			'videoCaption' => [
				'file' => ['size' => ['max' => 2 * 1024 * 1024], 'extensions' => ['.vtt', '.srt']],
			],
			'user' => [
				// PeerTube's own way of saying "no quota", and the one its
				// clients check for before they draw a bar nobody can fill
				'videoQuota' => ($quota === VideoQuotaService::UNLIMITED) ? -1 : $quota * 1048576,
				'videoQuotaDaily' => -1,
			],
			'trending' => ['videos' => ['intervalDays' => 7]],
			'tracker' => ['enabled' => false],
			'followings' => ['instance' => ['autoFollowIndex' => ['indexUrl' => '']]],
			'federation' => ['enabled' => true],
			'homepage' => ['enabled' => false],
			'views' => ['videos' => ['watchingInterval' => ['anonymous' => 5000, 'users' => 5000]]],
		];
	}

	/**
	 * One post as PeerTube's `Video`.
	 *
	 * A post that is not a video still answers here, with a duration of zero
	 * and no file: the alternative is a list with holes in it, and a client
	 * that asked for videos is given only videos anyway — this shape is
	 * reached by a route that has already narrowed to them.
	 *
	 * @return array<string, mixed>
	 */
	public function video(Stream $post, bool $details = false): array {
		$meta = $post->getVideoMeta();
		$attachments = $post->getAttachments();
		$file = null;
		foreach ($attachments as $attachment) {
			$exported = is_array($attachment) ? $attachment : $attachment->asLocal();
			if (($exported['type'] ?? '') === 'video') {
				$file = $exported;
				break;
			}
		}

		$video = [
			'id' => $post->getNid(),
			'uuid' => PeerTubeService::uuidFor($post->getId()),
			'shortUUID' => PeerTubeService::uuidFor($post->getId()),
			'isLive' => false,
			'createdAt' => $this->when($post->getPublishedTime()),
			'publishedAt' => $this->when($post->getPublishedTime()),
			'updatedAt' => $this->when($post->getPublishedTime()),
			'originallyPublishedAt' => ($meta['originally_published_at'] ?? '') !== ''
				? (string)$meta['originally_published_at'] : null,
			'category' => $this->constant($meta['category'] ?? ''),
			'licence' => $this->constant($meta['licence'] ?? ''),
			'language' => $this->constant($meta['language'] ?? $post->getLanguage()),
			// only what this app publishes reaches here, and this app publishes
			// public and unlisted; a private post is not in any of these lists
			'privacy' => ($post->getVisibility() === Stream::TYPE_PUBLIC)
				? ['id' => 1, 'label' => 'Public'] : ['id' => 2, 'label' => 'Unlisted'],
			'name' => (string)($meta['title'] ?? $this->titleOf($post)),
			'truncatedDescription' => mb_substr(trim(strip_tags($post->getContent())), 0, 250),
			// the video meta first, and the attachment's own second: a video
			// posted before this app recorded a title and a running time has
			// no meta at all, and its duration is still on the file
			'duration' => (int)($meta['duration'] ?? 0) ?: $this->durationOf($file),
			'aspectRatio' => null,
			'isLocal' => $post->isLocal(),
			'thumbnailPath' => (string)($file['preview_url'] ?? ''),
			'previewPath' => (string)($file['preview_url'] ?? ''),
			'embedPath' => '',
			'views' => (int)($post->getViewCount() ?? ($meta['views'] ?? 0)),
			'likes' => (int)($meta['likes'] ?? 0),
			'dislikes' => (int)($meta['dislikes'] ?? 0),
			'nsfw' => $post->isSensitive(),
			'waitTranscoding' => false,
			'state' => ['id' => 1, 'label' => 'Published'],
			'scheduledUpdate' => null,
			'blacklisted' => null,
			'blacklistedReason' => null,
			'account' => $this->accountSummary($post->hasActor() ? $post->getActor() : null),
			'channel' => $this->channelSummaryFor($post),
			'userHistory' => null,
		];

		if (!$details) {
			return $video;
		}

		$video['description'] = trim(strip_tags($post->getContent()));
		$video['support'] = (string)($meta['support'] ?? '');
		$video['tags'] = $post->getHashtags();
		$video['commentsEnabled'] = true;
		$video['downloadEnabled'] = ($meta['download'] ?? true) !== false;
		$video['trackerUrls'] = [];
		$video['files'] = ($file === null) ? [] : [[
			'id' => $post->getNid(),
			'resolution' => ['id' => 0, 'label' => 'Original'],
			'size' => (int)($file['size'] ?? 0),
			'fileUrl' => (string)($file['url'] ?? ''),
			'fileDownloadUrl' => (string)($file['url'] ?? ''),
			'torrentUrl' => '',
			'magnetUri' => '',
		]];
		$video['streamingPlaylists'] = ($file === null || ($file['hls_url'] ?? null) === null) ? [] : [[
			'id' => $post->getNid(),
			'type' => 1,
			'playlistUrl' => (string)$file['hls_url'],
			'segmentsSha256Url' => '',
			'files' => [],
		]];

		return $video;
	}

	/**
	 * One actor as PeerTube's `AccountSummary`.
	 *
	 * @return array<string, mixed>
	 */
	public function accountSummary(?Person $actor): array {
		if ($actor === null) {
			return ['id' => 0, 'name' => '', 'displayName' => '', 'url' => '', 'host' => '', 'avatars' => []];
		}

		return [
			'id' => $actor->getNid(),
			'name' => $actor->getPreferredUsername(),
			'displayName' => ($actor->getName() !== '') ? $actor->getName() : $actor->getPreferredUsername(),
			'url' => $actor->getId(),
			'host' => (string)parse_url($actor->getId(), PHP_URL_HOST),
			'avatars' => ($actor->getAvatar() === '') ? [] : [
				['width' => 120, 'path' => $actor->getAvatar()],
			],
		];
	}

	/**
	 * One channel as PeerTube's `VideoChannel`.
	 *
	 * @return array<string, mixed>
	 */
	public function channel(Channel $channel, bool $details = false): array {
		$actor = $this->actorOf($channel->getActorId());
		$owner = $this->actorOf($channel->getOwnerId());
		$host = (string)parse_url($channel->getActorId(), PHP_URL_HOST);

		$shape = [
			'id' => $channel->getId(),
			'name' => $channel->getHandle(),
			'displayName' => ($channel->getName() !== '') ? $channel->getName() : $channel->getHandle(),
			'url' => $channel->getActorId(),
			'host' => $host,
			'avatars' => ($actor?->getAvatar() ?? '') === '' ? [] : [
				['width' => 120, 'path' => (string)$actor?->getAvatar()],
			],
		];

		if (!$details) {
			return $shape;
		}

		return $shape + [
			'description' => ($channel->getDescription() !== '') ? $channel->getDescription() : null,
			'support' => null,
			'publicEmail' => null,
			'isLocal' => true,
			'followingCount' => 0,
			// what the cached details say, or nothing: an actor whose counts
			// have not been cached yet answers 0, which is the honest number
			// for "this server has not counted"
			'followersCount' => $actor?->getDetailInt('count.followers') ?? 0,
			'createdAt' => $this->when(0),
			'updatedAt' => $this->when(0),
			'ownerAccount' => $this->accountSummary($owner),
			'banners' => [],
		];
	}

	/**
	 * The signed-in account as PeerTube's `User`.
	 *
	 * The email is **not** here. PeerTube's own answer carries one, and a
	 * Nextcloud account's address is not this API's to hand to whatever client
	 * holds a token: nothing a video client does needs it, and a field that is
	 * absent is read as "this server does not say" rather than as a wrong
	 * address.
	 *
	 * @return array<string, mixed>
	 */
	public function user(Person $actor, string $userId): array {
		$quota = $this->videoQuotaService->quota();

		return [
			'id' => $actor->getNid(),
			'username' => $actor->getPreferredUsername(),
			'email' => null,
			'emailVerified' => true,
			'nsfwPolicy' => self::NSFW_POLICY[$this->sensitiveMediaService->policyFor($userId)] ?? 'blur',
			'adminFlags' => 0,
			'autoPlayVideo' => true,
			'autoPlayNextVideo' => false,
			'autoPlayNextVideoPlaylist' => true,
			'p2pEnabled' => false,
			'videosHistoryEnabled' => true,
			'videoLanguages' => [],
			'language' => $actor->getLanguage(),
			'role' => ['id' => 2, 'label' => 'User'],
			'videoQuota' => ($quota === VideoQuotaService::UNLIMITED) ? -1 : $quota * 1048576,
			'videoQuotaDaily' => -1,
			'videoQuotaUsed' => $this->videoQuotaService->used($actor->getPreferredUsername()),
			'account' => $this->accountSummary($actor),
			'videoChannels' => array_map(
				fn (Channel $channel): array => $this->channel($channel),
				$this->channelService->forOwner($actor)
			),
			'blockedVideos' => [],
			'noInstanceConfigWarningModal' => true,
			'noWelcomeModal' => true,
			'noAccountSetupWarningModal' => true,
		];
	}

	/**
	 * One reply as PeerTube's `VideoComment`.
	 *
	 * @return array<string, mixed>
	 */
	public function comment(Stream $reply, int|string $videoNid): array {
		return [
			'id' => $reply->getNid(),
			'url' => $reply->getId(),
			'text' => $reply->getContent(),
			'threadId' => $videoNid,
			'inReplyToCommentId' => null,
			'videoId' => $videoNid,
			'createdAt' => $this->when($reply->getPublishedTime()),
			'updatedAt' => $this->when($reply->getPublishedTime()),
			'deletedAt' => null,
			'isDeleted' => false,
			'heldForReview' => false,
			'totalRepliesFromVideoAuthor' => 0,
			'totalReplies' => 0,
			'account' => $this->accountSummary($reply->hasActor() ? $reply->getActor() : null),
		];
	}

	/**
	 * The `{total, data}` wrapper every list route here answers in.
	 *
	 * `total` is the size of **this page** rather than of everything there is.
	 * This app's timelines are keyed on a cursor and have no count to give,
	 * and a number invented for the shape's sake is one a client would draw a
	 * pager from.
	 *
	 * @param array<int, mixed> $data
	 * @return array{total: int, data: array<int, mixed>}
	 */
	public function page(array $data): array {
		return ['total' => count($data), 'data' => array_values($data)];
	}

	/**
	 * PeerTube's `{id, label}` for a category, a licence or a language.
	 *
	 * The id is 0 throughout: PeerTube's ids are indexes into its own lists,
	 * which this app does not have and must not guess at — a wrong id is a
	 * client showing the wrong category with confidence. The label is the half
	 * that means anything off its own instance, and is what this app stores.
	 *
	 * @return array{id: int, label: string}|null
	 */
	private function constant(string $label): ?array {
		$label = trim($label);

		return ($label === '') ? null : ['id' => 0, 'label' => $label];
	}

	/**
	 * How long the file runs, where the post itself does not say.
	 *
	 * A video posted before this app recorded a title and a running time has
	 * no video metadata at all, and the duration is still on the attachment —
	 * where it was written when the file was stored. `asLocal()` hands `meta`
	 * back as an object so a client decoding a dictionary takes it, which is
	 * why this is not a plain array read.
	 *
	 * @param array<string, mixed>|null $file
	 */
	private function durationOf(?array $file): int {
		$meta = $file['meta'] ?? null;
		if (!is_object($meta) || !isset($meta->duration)) {
			return 0;
		}

		return (int)round((float)$meta->duration);
	}

	/** A title for a post that has none: the first line, as everywhere else here. */
	private function titleOf(Stream $post): string {
		$text = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $post->getContent())));

		return trim((string)strtok($text, "\n"));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function channelSummaryFor(Stream $post): array {
		// read, never created: this is a read path, and making an actor on one
		// would be a write per video per page. An account that has never posted
		// a video has no channel, and its own account stands in — which is what
		// a PeerTube client draws when a video has no channel it can resolve.
		foreach ($this->channelsRequest->getByOwner($post->getAttributedTo()) as $channel) {
			if ($channel->isDefault()) {
				return $this->channel($channel);
			}
		}

		return $this->accountSummary($post->hasActor() ? $post->getActor() : null);
	}

	private function actorOf(string $id): ?Person {
		if ($id === '') {
			return null;
		}

		try {
			return $this->cacheActorService->getFromId($id);
		} catch (\Exception $e) {
			return null;
		}
	}

	/** The ISO date PeerTube writes, with milliseconds, as its clients parse. */
	private function when(int $timestamp): string {
		return gmdate('Y-m-d\TH:i:s', ($timestamp > 0) ? $timestamp : time()) . '.000Z';
	}
}
