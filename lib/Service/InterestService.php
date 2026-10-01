<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Social\Db\FeaturedTagsRequest;
use OCA\Social\Db\FollowedTagsRequest;
use OCA\Social\Db\InterestsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InterestNotRemovableException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Interest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * For you: what a reader lingers on, turned into the hashtags their feed
 * is made of.
 *
 * Everything that reads or writes a reader's interests goes through here — the
 * signals the web interface sends while they scroll, the likes, boosts,
 * replies and bookmarks that happen wherever they happen, and every change
 * they make on the settings page. The arithmetic is `InterestScorer`'s; this is
 * the part that knows whose interests they are, whether it is allowed to learn
 * at all, and where the rows live.
 *
 * Nothing learned here leaves the server or is shown to anyone but the reader.
 * The signals themselves are folded into the scores as they arrive and are not
 * kept; what is kept for a day is only which post already counted, so reading
 * the same post twice does not count twice.
 */
class InterestService {
	/** What the web interface may report. */
	public const KINDS = ['dwell', 'skip', 'open', 'media', 'link', 'mute'];
	public const CONTEXTS = ['home', 'local', 'federated', 'tag', 'explore', 'detail', 'interests', 'photos', 'videos', 'reels'];
	/**
	 * Where a dwell is time spent watching the video rather than time the post
	 * was on screen: the Shorts stack, which reports what its player played.
	 */
	public const WATCH_CONTEXTS = ['reels'];
	public const MAX_EVENTS = 100;
	/** One look is worth at most this much, however long the tab stayed open. */
	public const MAX_DWELL_MS = 30000;

	/** Past this many rows the faintest learned ones are forgotten. */
	public const MAX_ROWS = 500;
	public const MAX_LANGUAGES = 20;

	/** The actions that count wherever they come from, a Mastodon app included. */
	public const ACTION_FAVOURITE = 'favourite';
	public const ACTION_BOOST = 'boost';
	public const ACTION_REPLY = 'reply';
	public const ACTION_BOOKMARK = 'bookmark';

	private const KEY_LEARNING = 'interests_learning';
	private const KEY_PAUSED_AT = 'interests_paused_at';
	private const KEY_LANGUAGES = 'interests_languages';
	private const KEY_BASELINE = 'interests_baseline';
	private const KEY_NOTICE = 'interests_notice_ack';

	private const DEDUPE_TTL = 86400;

	private ?ICache $seen = null;

	public function __construct(
		private ConfigService $configService,
		private InterestsRequest $interestsRequest,
		private StreamRequest $streamRequest,
		private FollowedTagsRequest $followedTagsRequest,
		private FeaturedTagsRequest $featuredTagsRequest,
		private HashtagService $hashtagService,
		private ICacheFactory $cacheFactory,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/** Whether the administrator has the feature on. */
	public function isEnabled(): bool {
		return $this->configService->getAppValueBool(ConfigService::SOCIAL_INTERESTS);
	}

	/**
	 * The scorer, tuned the way the administrator tuned it. Out-of-range
	 * values are clamped rather than trusted: they can be written with occ.
	 */
	public function scorer(): InterestScorer {
		return new InterestScorer(
			(float)max(1, min(365, $this->configService->getAppValueInt(ConfigService::SOCIAL_INTERESTS_HALF_LIFE))),
			max(0.5, min(20.0, (float)$this->configService->getAppValue(ConfigService::SOCIAL_INTERESTS_THRESHOLD))),
			max(5, min(100, $this->configService->getAppValueInt(ConfigService::SOCIAL_INTERESTS_CAP)))
		);
	}

	/**
	 * The administrator's card: the switch, the default for readers, and the
	 * four numbers.
	 *
	 * @return array{enabled: bool, learningDefault: bool, halfLife: int, threshold: float, cap: int, window: int}
	 */
	public function adminSettings(): array {
		$scorer = $this->scorer();

		return [
			'enabled' => $this->isEnabled(),
			'learningDefault' => $this->configService->getAppValueBool(ConfigService::SOCIAL_INTERESTS_DEFAULT),
			'halfLife' => max(1, min(365, $this->configService->getAppValueInt(ConfigService::SOCIAL_INTERESTS_HALF_LIFE))),
			'threshold' => $scorer->getThreshold(),
			'cap' => $scorer->getCap(),
			'window' => $this->windowDays(),
		];
	}

	/**
	 * Writes the whole card, or nothing when a value is out of range.
	 *
	 * @throws InvalidArgumentException naming the value
	 */
	public function saveAdminSettings(
		bool $enabled, bool $learningDefault, int $halfLife, float $threshold, int $cap, int $window,
	): array {
		foreach ([
			'halfLife' => [$halfLife, 7, 180],
			'threshold' => [$threshold, 0.5, 20],
			'cap' => [$cap, 5, 100],
			'window' => [$window, 1, 30],
		] as $name => [$value, $min, $max]) {
			if ($value < $min || $value > $max) {
				throw new InvalidArgumentException($name . ' must be between ' . (string)$min . ' and ' . (string)$max);
			}
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_INTERESTS, $enabled ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_INTERESTS_DEFAULT, $learningDefault ? '1' : '0');
		$this->configService->setAppValue(ConfigService::SOCIAL_INTERESTS_HALF_LIFE, (string)$halfLife);
		$this->configService->setAppValue(ConfigService::SOCIAL_INTERESTS_THRESHOLD, (string)$threshold);
		$this->configService->setAppValue(ConfigService::SOCIAL_INTERESTS_CAP, (string)$cap);
		$this->configService->setAppValue(ConfigService::SOCIAL_INTERESTS_WINDOW, (string)$window);

		return $this->adminSettings();
	}

	/** How many days back the feed looks. */
	public function windowDays(): int {
		return max(1, min(30, $this->configService->getAppValueInt(ConfigService::SOCIAL_INTERESTS_WINDOW)));
	}

	/**
	 * The reader's own switches, with the administrator's default filled in
	 * for somebody who never touched theirs.
	 *
	 * @return array{enabled: bool, learning: bool, paused: bool, languages: string[], noticeAcknowledged: bool}
	 */
	public function settingsFor(string $userId): array {
		$learning = $this->userValue($userId, self::KEY_LEARNING);
		if ($learning === '') {
			$learning = $this->configService->getAppValueBool(ConfigService::SOCIAL_INTERESTS_DEFAULT) ? '1' : '0';
		}

		$languages = json_decode($this->userValue($userId, self::KEY_LANGUAGES), true);

		return [
			'enabled' => $this->isEnabled(),
			'learning' => $learning === '1',
			'paused' => $this->userValue($userId, self::KEY_PAUSED_AT) !== '',
			'languages' => is_array($languages) ? array_values(array_filter($languages, 'is_string')) : [],
			'noticeAcknowledged' => $this->userValue($userId, self::KEY_NOTICE) === '1',
		];
	}

	/**
	 * What the page is handed before it asks for anything: the switches, and
	 * `profile`, whether reading has taught the feed anything yet — the pace
	 * it keeps is written the first time it does and cleared by a reset —
	 * which is when the Shorts stack opens on For you rather than My Feed.
	 */
	public function pageState(string $userId): array {
		$settings = $this->settingsFor($userId);
		unset($settings['languages']);
		$settings['profile'] = $this->userValue($userId, self::KEY_BASELINE) !== '';

		return $settings;
	}

	/** Whether anything this reader does may be learned from right now. */
	public function isLearning(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$settings = $this->settingsFor($userId);

		return $settings['enabled'] && $settings['learning'] && !$settings['paused'];
	}

	/**
	 * Saves whichever of the reader's switches the request names.
	 *
	 * Resuming after a pause moves every score's clock on by the length of the
	 * pause, so the list comes back as it was left rather than a pause's worth
	 * of decay weaker.
	 *
	 * @param array{learning?: mixed, paused?: mixed, languages?: mixed, noticeAcknowledged?: mixed} $patch
	 *
	 * @throws InvalidArgumentException a value that is not one
	 */
	public function saveSettings(Person $actor, array $patch): array {
		$userId = $actor->getUserId();

		if (array_key_exists('languages', $patch)) {
			$this->setUserValue($userId, self::KEY_LANGUAGES, json_encode($this->languages($patch['languages'])));
		}

		if (array_key_exists('learning', $patch)) {
			$this->setUserValue($userId, self::KEY_LEARNING, $this->bool($patch['learning']) ? '1' : '0');
		}

		if (array_key_exists('paused', $patch)) {
			$pausedAt = $this->userValue($userId, self::KEY_PAUSED_AT);
			$pause = $this->bool($patch['paused']);
			if ($pause && $pausedAt === '') {
				$this->setUserValue($userId, self::KEY_PAUSED_AT, (string)$this->now());
			} elseif (!$pause && $pausedAt !== '') {
				$rows = $this->interestsRequest->getByActor($actor->getId());
				$this->scorer()->shift($rows, $this->now() - (int)$pausedAt);
				foreach ($rows as $row) {
					$this->interestsRequest->save($actor->getId(), $row);
				}
				$this->setUserValue($userId, self::KEY_PAUSED_AT, '');
			}
		}

		if (array_key_exists('noticeAcknowledged', $patch) && $this->bool($patch['noticeAcknowledged'])) {
			$this->setUserValue($userId, self::KEY_NOTICE, '1');
		}

		return $this->state($actor);
	}

	/**
	 * Everything the settings page draws: the switches, the listed interests
	 * in rank order, the tags just under them, and whether learning is thin.
	 */
	public function state(Person $actor): array {
		$assembled = $this->scorer()->assemble(
			$this->interestsRequest->getByActor($actor->getId()),
			$this->followedTags($actor),
			$this->now()
		);

		return [
			'settings' => $this->settingsFor($actor->getUserId()),
			'interests' => $assembled['listed'],
			'candidates' => $assembled['candidates'],
			'thin' => $assembled['thin'],
			'cap' => $this->scorer()->getCap(),
		];
	}

	/**
	 * Learns from what the web interface saw the reader do.
	 *
	 * The events name posts by id and nothing else: the hashtags are read here,
	 * from the posts as the reader may see them, so a client can neither teach
	 * a tag no post carried nor learn the tags of a post it may not read.
	 *
	 * @param array<array-key, mixed> $events
	 */
	public function recordEvents(Person $actor, array $events): void {
		if (!$this->isLearning($actor->getUserId())) {
			return;
		}

		$wanted = [];
		foreach (array_slice(array_values($events), 0, self::MAX_EVENTS) as $event) {
			if (!is_array($event)) {
				continue;
			}
			$nid = (string)($event['status_id'] ?? '');
			$kind = (string)($event['kind'] ?? '');
			if ($nid === '' || !ctype_digit($nid) || !in_array($kind, self::KINDS, true)) {
				continue;
			}
			$wanted[] = [
				'nid' => $nid,
				'kind' => $kind,
				'ms' => max(0, min(self::MAX_DWELL_MS, (int)($event['ms'] ?? 0))),
				'context' => (string)($event['context'] ?? ''),
			];
		}
		if ($wanted === []) {
			return;
		}

		$this->streamRequest->setViewer($actor);
		$posts = $this->streamRequest->getVisibleByNids(array_column($wanted, 'nid'));

		$scorer = $this->scorer();
		$baseline = (float)($this->userValue($actor->getUserId(), self::KEY_BASELINE) ?: '1');
		$deltas = [];
		foreach ($wanted as $event) {
			$post = $posts[$event['nid']] ?? null;
			if ($post === null || !$this->teaches($actor, $post) || !$this->firstTime($actor, $event['kind'], $event['nid'])) {
				continue;
			}

			$videoMs = ($event['kind'] === 'dwell' && in_array($event['context'], self::WATCH_CONTEXTS, true))
				? $this->videoDurationMs($post)
				: 0;
			if ($videoMs > 0) {
				// a video is watched rather than read: how long it runs is
				// what it takes, and the reader's reading pace says nothing
				// about it and is left alone
				$signal = $scorer->classifyWatch($event['ms'], $scorer->expectedWatchMs($videoMs));
			} elseif ($event['kind'] === 'dwell') {
				[$signal, $baseline] = $scorer->classifyDwell(
					$event['ms'],
					$scorer->expectedDwellMs($this->visibleChars($post), count($post->getAttachments())),
					$baseline
				);
			} else {
				$signal = match ($event['kind']) {
					'skip' => InterestScorer::SIGNAL_SKIP,
					'open' => InterestScorer::SIGNAL_OPEN,
					'media' => InterestScorer::SIGNAL_MEDIA,
					'link' => InterestScorer::SIGNAL_LINK,
					'mute' => InterestScorer::SIGNAL_MUTE,
				};
			}

			foreach ($scorer->share($signal, $post->getHashtags()) as $tag => $delta) {
				$deltas[$tag] = ($deltas[$tag] ?? 0.0) + $delta;
			}
		}

		$this->setUserValue($actor->getUserId(), self::KEY_BASELINE, (string)round($baseline, 4));
		$this->apply($actor, $deltas);
	}

	/**
	 * Learns from a like, a boost, a reply or a bookmark.
	 *
	 * Called from where each of them happens, so it counts from a Mastodon app
	 * as much as from the web. It never throws: a feed that could not learn is
	 * a lesser feed, and a boost that failed because of it would be a bug.
	 */
	public function recordAction(Person $actor, Stream $post, string $action): void {
		try {
			if (!$this->isLearning($actor->getUserId()) || !$this->teaches($actor, $post)
				|| !$this->firstTime($actor, $action, (string)$post->getNid())) {
				return;
			}

			$signal = match ($action) {
				self::ACTION_FAVOURITE => InterestScorer::SIGNAL_FAVOURITE,
				self::ACTION_BOOST => InterestScorer::SIGNAL_BOOST,
				self::ACTION_REPLY => InterestScorer::SIGNAL_REPLY,
				self::ACTION_BOOKMARK => InterestScorer::SIGNAL_BOOKMARK,
				default => 0.0,
			};

			$this->apply($actor, $this->scorer()->share($signal, $post->getHashtags()));
		} catch (Throwable $e) {
			$this->logger->warning('[InterestService] could not learn from an action', [
				'action' => $action, 'exception' => $e,
			]);
		}
	}

	/**
	 * "Less like this": the post goes from the reader's feed, and its hashtags
	 * count for less.
	 *
	 * @throws ItemNotFoundException the reader cannot see such a post
	 */
	public function lessLikeThis(Person $actor, string $nid): void {
		$post = $this->visiblePost($actor, $nid);
		$this->interestsRequest->hide($actor->getId(), (string)$post->getNid());
		$this->apply($actor, $this->scorer()->share(InterestScorer::SIGNAL_LESS, $post->getHashtags()));
	}

	/**
	 * Takes it back: the post may be shown again and what it cost is returned.
	 * Only after a "less like this" that is still on record, so repeating the
	 * undo cannot be used to pump a tag up.
	 *
	 * @throws ItemNotFoundException
	 */
	public function undoLessLikeThis(Person $actor, string $nid): void {
		$post = $this->visiblePost($actor, $nid);
		if (!$this->interestsRequest->isHidden($actor->getId(), (string)$post->getNid())) {
			return;
		}

		$this->interestsRequest->unhide($actor->getId(), (string)$post->getNid());
		$this->apply($actor, $this->scorer()->share(-InterestScorer::SIGNAL_LESS, $post->getHashtags()));
	}

	/**
	 * Adds a hashtag the reader chose. It floats with at least the listing
	 * threshold as its score, and it does not decay.
	 *
	 * @throws InvalidArgumentException not a hashtag
	 */
	public function add(Person $actor, string $hashtag): array {
		$tag = $this->tag($hashtag);
		$row = $this->row($actor, $tag) ?? new Interest($tag, 0.0, $this->now());
		$row->setManual(true);
		$this->interestsRequest->save($actor->getId(), $row);

		return $this->state($actor);
	}

	/**
	 * Pins a tag at a rank: where the reader dragged it, one up or down, or
	 * the top. Pins at that rank and below move down one, so the tag lands
	 * exactly where it was dropped and the other pins keep their order.
	 *
	 * @throws InvalidArgumentException not a hashtag, or not one of theirs
	 */
	public function move(Person $actor, string $hashtag, int $position): array {
		$tag = $this->tag($hashtag);
		$position = max(0, $position);
		$row = $this->listedRow($actor, $tag);

		foreach ($this->interestsRequest->getByActor($actor->getId()) as $other) {
			if ($other->getHashtag() !== $tag && $other->isPinned() && $other->getPosition() >= $position) {
				$this->interestsRequest->save($actor->getId(), $other->setPosition($other->getPosition() + 1));
			}
		}

		// a pinned row does not decay, so what it is worth is fixed at what it
		// is worth now
		$row->setScore($this->scorer()->current($row, $this->now()))->setScoredAt($this->now());
		$this->interestsRequest->save($actor->getId(), $row->setPosition($position));

		return $this->state($actor);
	}

	/** Pins a tag where it stands. */
	public function pin(Person $actor, string $hashtag): array {
		$tag = $this->tag($hashtag);
		foreach ($this->state($actor)['interests'] as $entry) {
			if ($entry['tag'] === $tag) {
				return $this->move($actor, $tag, $entry['rank']);
			}
		}

		throw new InvalidArgumentException('#' . $tag . ' is not one of your interests');
	}

	/** Lets a tag float on its score again, which starts decaying from now. */
	public function unpin(Person $actor, string $hashtag): array {
		$tag = $this->tag($hashtag);
		$row = $this->row($actor, $tag);
		if ($row !== null && $row->isPinned()) {
			$this->interestsRequest->save($actor->getId(), $row->setPosition(null)->setScoredAt($this->now()));
		}

		return $this->state($actor);
	}

	/**
	 * Takes a tag off the list. Nothing remembers it was removed: reading
	 * about it again can bring it back, which is what the reader asked for.
	 *
	 * @throws InterestNotRemovableException a followed tag, which is listed
	 *                                       for as long as it is followed
	 */
	public function remove(Person $actor, string $hashtag): array {
		$tag = $this->tag($hashtag);
		if (in_array($tag, $this->followedTags($actor), true)) {
			throw new InterestNotRemovableException('Unfollow #' . $tag . ' to remove it from your interests');
		}

		$this->interestsRequest->deleteTags($actor->getId(), [$tag]);

		return $this->state($actor);
	}

	/** Forgets everything learned and chosen, and the reader's reading pace. */
	public function reset(Person $actor): array {
		$this->interestsRequest->deleteRelatedId($actor->getId());
		$this->setUserValue($actor->getUserId(), self::KEY_BASELINE, '');

		return $this->state($actor);
	}

	/**
	 * What the feed ranks by: a weight per hashtag, why each is there, and
	 * whether learning is still thin.
	 *
	 * @return array{weights: array<string, float>, reasons: array<string, string>, thin: bool, top: string[]}
	 */
	public function feedProfile(Person $actor): array {
		$scorer = $this->scorer();
		$assembled = $scorer->assemble(
			$this->interestsRequest->getByActor($actor->getId()),
			$this->followedTags($actor),
			$this->now()
		);

		$weights = [];
		$reasons = [];
		foreach ($assembled['listed'] as $entry) {
			$weights[$entry['tag']] = $scorer->weight($entry['rank']);
			$reasons[$entry['tag']] = ($entry['source'] === 'followed') ? 'followed' : 'interest';
		}

		if ($assembled['thin']) {
			// padding, at half the weight of anything the reader chose: their
			// own featured tags, then what is trending here
			foreach ($this->featuredTagsRequest->getByActor($actor->getId()) as $featured) {
				$tag = FollowedTagsRequest::normalise($featured->getHashtag());
				if ($tag !== '' && !isset($weights[$tag])) {
					$weights[$tag] = 0.5;
					$reasons[$tag] = 'interest';
				}
			}
			foreach ($this->hashtagService->getTrending(10) as $trend) {
				$tag = FollowedTagsRequest::normalise((string)($trend['hashtag'] ?? ''));
				if ($tag !== '' && !isset($weights[$tag])) {
					$weights[$tag] = 0.5;
					$reasons[$tag] = 'trending';
				}
			}
		}

		foreach ($assembled['negative'] as $tag) {
			$weights[$tag] = InterestScorer::NEGATIVE_WEIGHT;
		}

		return [
			'weights' => $weights,
			'reasons' => $reasons,
			'thin' => $assembled['thin'],
			'top' => array_slice(array_column($assembled['listed'], 'tag'), 0, 5),
		];
	}

	/** The reader's languages for the feed; empty for all of them. */
	public function languagesFor(string $userId): array {
		return $this->settingsFor($userId)['languages'];
	}

	/** The hides the feed has to leave out. */
	public function hiddenFor(Person $actor): array {
		return $this->interestsRequest->getHiddenSince(
			$actor->getId(), new DateTime('@' . ($this->now() - $this->hideDays() * 86400))
		);
	}

	/** Forgets the hides older than the feed's window, for everybody. */
	public function purgeHides(): int {
		return $this->interestsRequest->purgeHidesBefore(
			new DateTime('@' . ($this->now() - $this->hideDays() * 86400))
		);
	}

	/**
	 * How long a hide matters: as far back as the widest ranking looks, which
	 * is a photo or video one, or a hidden picture would come back into it.
	 */
	private function hideDays(): int {
		return $this->windowDays() * InterestFeedService::MEDIA_WINDOW_FACTOR;
	}

	/**
	 * Everything of the reader's that belongs in a copy of their account.
	 * The hides are left out: they name posts on this server.
	 */
	public function export(Person $actor): array {
		$userId = $actor->getUserId();
		$interests = [];
		foreach ($this->interestsRequest->getByActor($actor->getId()) as $row) {
			$interests[] = [
				'hashtag' => $row->getHashtag(),
				'score' => $row->getScore(),
				'scored_at' => $row->getScoredAt(),
				'manual' => $row->isManual(),
				'position' => $row->getPosition(),
			];
		}

		return [
			'version' => 1,
			'settings' => [
				'learning' => $this->userValue($userId, self::KEY_LEARNING),
				'languages' => $this->settingsFor($userId)['languages'],
				'baseline' => $this->userValue($userId, self::KEY_BASELINE),
			],
			'interests' => $interests,
		];
	}

	/**
	 * Takes back what `export()` wrote. The rows keep their times, so decay
	 * carries on from where it was rather than starting over.
	 */
	public function import(Person $actor, array $data): int {
		$userId = $actor->getUserId();
		$settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
		if (in_array($settings['learning'] ?? null, ['0', '1'], true)) {
			$this->setUserValue($userId, self::KEY_LEARNING, $settings['learning']);
		}
		try {
			$this->setUserValue($userId, self::KEY_LANGUAGES, json_encode($this->languages($settings['languages'] ?? [])));
		} catch (InvalidArgumentException) {
			// a list that is not one is left out, and the rest still comes over
		}
		if (is_numeric($settings['baseline'] ?? null)) {
			$this->setUserValue($userId, self::KEY_BASELINE, (string)(float)$settings['baseline']);
		}

		$imported = 0;
		foreach (array_slice(is_array($data['interests'] ?? null) ? $data['interests'] : [], 0, self::MAX_ROWS) as $entry) {
			$tag = is_array($entry) ? FollowedTagsRequest::normalise((string)($entry['hashtag'] ?? '')) : '';
			if ($tag === '') {
				continue;
			}

			$this->interestsRequest->save($actor->getId(), new Interest(
				$tag,
				max(InterestScorer::SCORE_MIN, min(InterestScorer::SCORE_MAX, (float)($entry['score'] ?? 0))),
				max(0, (int)($entry['scored_at'] ?? 0)),
				(bool)($entry['manual'] ?? false),
				isset($entry['position']) && is_numeric($entry['position']) ? max(0, (int)$entry['position']) : null,
			));
			$imported++;
		}

		return $imported;
	}

	/**
	 * Adds each delta to its tag's row, and forgets the faintest learned rows
	 * once there are too many to rank cheaply.
	 *
	 * @param array<string, float> $deltas
	 */
	private function apply(Person $actor, array $deltas): void {
		if ($deltas === []) {
			return;
		}

		$scorer = $this->scorer();
		$now = $this->now();
		$rows = [];
		foreach ($this->interestsRequest->getByActor($actor->getId()) as $row) {
			$rows[$row->getHashtag()] = $row;
		}

		$gone = [];
		foreach ($deltas as $tag => $delta) {
			$tag = (string)$tag;
			$row = $scorer->fold($rows[$tag] ?? new Interest($tag), $delta, $now);
			$rows[$tag] = $row;
			if ($row->isEmpty()) {
				$gone[] = $tag;
			} else {
				$this->interestsRequest->save($actor->getId(), $row);
			}
		}

		if (count($rows) > self::MAX_ROWS) {
			$faint = array_filter($rows, static fn (Interest $row): bool => !$row->isManual() && !$row->isPinned());
			uasort($faint, static fn (Interest $a, Interest $b): int
				=> abs($scorer->current($a, $now)) <=> abs($scorer->current($b, $now)));
			$gone = array_merge($gone, array_slice(array_keys($faint), 0, count($rows) - self::MAX_ROWS));
		}

		$this->interestsRequest->deleteTags($actor->getId(), array_values(array_unique(array_map('strval', $gone))));
	}

	/**
	 * Whether a post has anything to teach this reader: it carries hashtags,
	 * and it is not their own — writing about something is not evidence of
	 * wanting to read more of it from others.
	 */
	private function teaches(Person $actor, Stream $post): bool {
		return $post->getHashtags() !== [] && $post->getAttributedTo() !== $actor->getId();
	}

	/** Whether this is the first time today this kind of signal names this post. */
	private function firstTime(Person $actor, string $kind, string $nid): bool {
		$this->seen ??= $this->cacheFactory->createDistributed('social.interests');
		$key = md5($actor->getId()) . '/' . $kind . '/' . $nid;
		if ($this->seen->get($key) !== null) {
			return false;
		}

		$this->seen->set($key, 1, self::DEDUPE_TTL);

		return true;
	}

	/** @throws ItemNotFoundException */
	private function visiblePost(Person $actor, string $nid): Stream {
		if (!ctype_digit($nid)) {
			throw new ItemNotFoundException('Record not found');
		}

		$this->streamRequest->setViewer($actor);
		$post = $this->streamRequest->getVisibleByNids([$nid])[$nid] ?? null;
		if ($post === null) {
			throw new ItemNotFoundException('Record not found');
		}

		return $post;
	}

	/**
	 * How long a post's video runs, in milliseconds; 0 for a post that is not
	 * one, or whose video never said. The first video with a running time is
	 * the one a player starts with.
	 */
	private function videoDurationMs(Stream $post): int {
		foreach ($post->getAttachments() as $attachment) {
			if (!in_array($attachment->getType(), ['video', 'gifv'], true)) {
				continue;
			}
			$meta = $attachment->getMeta();
			$seconds = $meta?->getDuration() ?? $meta?->getOriginal()?->getDuration() ?? 0.0;
			if ($seconds > 0) {
				return (int)round($seconds * 1000.0);
			}
		}

		return 0;
	}

	private function visibleChars(Stream $post): int {
		$text = html_entity_decode(strip_tags($post->getContent()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return mb_strlen(trim($text), 'UTF-8');
	}

	/** @return string[] the hashtags the reader follows, normalised */
	private function followedTags(Person $actor): array {
		$tags = [];
		foreach ($this->followedTagsRequest->getByActor($actor->getId(), 1000) as $followed) {
			$tags[] = FollowedTagsRequest::normalise($followed['hashtag']);
		}

		return array_values(array_unique(array_filter($tags)));
	}

	private function row(Person $actor, string $tag): ?Interest {
		foreach ($this->interestsRequest->getByActor($actor->getId()) as $row) {
			if ($row->getHashtag() === $tag) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The row of a tag on the reader's list, made on the spot for a followed
	 * tag that has none yet.
	 *
	 * @throws InvalidArgumentException not on the list
	 */
	private function listedRow(Person $actor, string $tag): Interest {
		foreach ($this->state($actor)['interests'] as $entry) {
			if ($entry['tag'] === $tag) {
				return $this->row($actor, $tag) ?? new Interest($tag, 0.0, $this->now());
			}
		}

		throw new InvalidArgumentException('#' . $tag . ' is not one of your interests');
	}

	/** @throws InvalidArgumentException */
	private function tag(string $hashtag): string {
		$tag = FollowedTagsRequest::normalise($hashtag);
		if ($tag === '' || preg_match('/[\s#,]/u', $tag) === 1) {
			throw new InvalidArgumentException('not a hashtag');
		}

		return $tag;
	}

	/**
	 * @return string[]
	 * @throws InvalidArgumentException
	 */
	private function languages(mixed $languages): array {
		if (!is_array($languages)) {
			throw new InvalidArgumentException('languages must be a list');
		}

		$clean = [];
		foreach ($languages as $language) {
			if (!is_string($language) || preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $language) !== 1) {
				throw new InvalidArgumentException('not a language code: ' . (is_string($language) ? $language : gettype($language)));
			}
			$clean[$language] = true;
		}

		if (count($clean) > self::MAX_LANGUAGES) {
			throw new InvalidArgumentException('at most ' . self::MAX_LANGUAGES . ' languages');
		}

		return array_keys($clean);
	}

	private function bool(mixed $value): bool {
		return in_array($value, [true, 1, '1', 'true'], true);
	}

	private function now(): int {
		return $this->timeFactory->getTime();
	}

	private function userValue(string $userId, string $key): string {
		return ($userId === '') ? '' : (string)$this->configService->getValueForUser($userId, $key);
	}

	private function setUserValue(string $userId, string $key, string $value): void {
		if ($userId !== '') {
			$this->configService->setValueForUser($userId, $key, $value);
		}
	}
}
