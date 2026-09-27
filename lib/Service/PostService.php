<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Exceptions\FederationDeliveryException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UnauthorizedFediverseException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Post;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCA\Social\Tools\Exceptions\RequestServerException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class PostService {
	public const POLL_MAX_OPTIONS = 4;

	/**
	 * How long a poll may run, in seconds, and the shortest it may run for.
	 * Mastodon's own bounds, and what `InstanceService` advertises as
	 * `polls.min_expiration`/`max_expiration`: a client offers exactly the
	 * durations it is told about, so a ceiling of its own here silently turned
	 * every longer poll a client offered into a shorter one.
	 */
	public const POLL_MIN_EXPIRATION = 300;
	public const POLL_MAX_EXPIRATION = 2629746;

	/**
	 * The languages whose region subtag names a different written language,
	 * and which Mastodon therefore offers as post languages of their own.
	 * For every other language a Nextcloud locale's region describes the UI,
	 * not the text: `en_GB` is English.
	 */
	private const REGIONAL_LANGUAGES = ['pt', 'zh'];

	public function __construct(
		private StreamService $streamService,
		private AccountService $accountService,
		private ActivityService $activityService,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private ModerationService $moderationService,
		private StatusRevisionService $revisionService,
		private NotificationService $notificationService,
		private LinkifyService $linkifyService,
		private ChannelService $channelService,
		private ConfigService $configService,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
		private InterestService $interestService,
	) {
	}

	/**
	 * @param Post $post
	 * @param string $token
	 *
	 * @return ACore
	 * @throws InvalidOriginException
	 * @throws InvalidResourceException
	 * @throws ItemUnknownException
	 * @throws MalformedArrayException
	 * @throws RedundancyLimitException
	 * @throws RequestContentException
	 * @throws RequestNetworkException
	 * @throws RequestResultNotJsonException
	 * @throws RequestResultSizeException
	 * @throws RequestServerException
	 * @throws SocialAppConfigException
	 * @throws StreamNotFoundException
	 * @throws UnauthorizedFediverseException
	 */
	public function createPost(Post $post, string &$token = ''): ?ACore {
		$this->moderationService->assertNotSuspended($post->getActor()->getId());
		$this->assertWithinLength($post->getContent(), $post->getSpoilerText());
		$this->fixRecipientAndHashtags($post);

		$note = new Note();
		if ($post->hasPoll()) {
			$poll = $post->getPoll();
			$options = array_slice(array_values(array_filter(
				array_map('strval', $poll['options'] ?? []),
				static fn (string $option): bool => trim($option) !== ''
			)), 0, self::POLL_MAX_OPTIONS);
			if (count($options) < 2) {
				throw new \InvalidArgumentException('a poll needs at least two options');
			}

			$note = new Question();
			$note->setPollData(
				$options,
				(bool)($poll['multiple'] ?? false),
				min(
					max((int)($poll['expires_in'] ?? 86400), self::POLL_MIN_EXPIRATION),
					self::POLL_MAX_EXPIRATION
				)
			);
		}
		$actor = $post->getActor();
		$this->streamService->assignItem($note, $actor, $post->getType());

		$note->setAttributedTo($actor->getId());
		// The warning rides as the object's `summary`, which is what every other
		// server reads it from — and unlike the content it is plain text
		// wherever it is read: `spoiler_text` to a client, interpolated rather
		// than rendered by this app's own frontend, escaped by the JSON encoder
		// on the wire. Stored exactly as it was typed, therefore. Encoding it
		// entity by entity federated `Bob&#039;s finale` to every other
		// instance; flattening it with `strip_tags()` was worse, since a bare
		// `<` reads as the start of a tag and ate the rest of the line — a
		// warning of `I <3 cats` was stored as `I `. editPost() agrees.
		$note->setSpoilerText(ACore::withoutMarkup($post->getSpoilerText()));
		$note->setSensitive($post->isSensitive());
		$note->setAttachments($post->getMedias());
		$note->setVisibility($post->getType());
		$note->setLanguage($this->languageFor($post->getLanguage(), $actor));
		$note->setPlaceId($post->getPlaceId());

		$this->ensureChannelForVideo($post);
		$this->streamService->replyTo($note, $post->getReplyTo());
		// who may quote this one, before it is stored: the column is written by
		// the same insert as everything else on the post
		$note->setQuotePolicy($post->getQuotePolicy());
		if ($post->getVideoMeta() !== []) {
			$note->setVideoMeta($post->getVideoMeta());
		}
		$quotedAuthor = $this->applyQuote($note, $post->getQuotedId());
		$this->streamService->addRecipients($note, $post->getType(), $post->getTo());
		$this->streamService->addHashtags($note, $post->getHashtags());
		$this->streamService->addCustomEmojis(
			$note, $post->getContent(), $post->getSpoilerText()
		);
		//		$this->streamService->addAttachments($note, $post->getDocuments());

		// Last, because the links are built out of the `tag` array the three
		// calls above assemble: a mention is only linked once it is known which
		// actor it resolved to, and only a mention the tags vouch for is linked
		// at all. Nothing here can name somebody the tags do not.
		$note->setContent($this->linkifyService->toHtml($post->getContent(), $note->getTags()));

		// the stored source is what federates on Update, and for a poll it is
		// the only place the options and their counts are kept. The language,
		// the tags, the quote and its approval are snapshotted here too and
		// written to their own columns by StreamRequest; both copies come from
		// this one assembled object, so they cannot disagree.
		$this->snapshotSource($note);

		$token = $this->activityService->createActivity($actor, $note, $activity);
		$this->learnFromReply($actor, $post->getReplyTo());
		// One counter, moved by one. This used to recompute all three with
		// aggregate queries on **every post written** — including a
		// `COUNT(*)` over `social_follow`, which for an account with a million
		// followers is a million index entries counted so that the post count
		// on a profile can go up.
		//
		// Only a post that names the public collection, because that is what
		// the recount counts (`StreamRequest::countNotesFromActorId()` joins
		// the public recipient row). Counting the others here meant the number
		// climbed on every followers-only post and fell back at the next cron
		// pass, so a profile's post count visibly wobbled.
		if ($note->addressesPublic()) {
			$this->accountService->bumpActorCount($actor->getId(), 'count_posts', 1);
		}

		// after the post exists: the request names it as the instrument, and
		// the quoted author's server dereferences both before approving
		if ($quotedAuthor !== null) {
			$this->requestQuoteApproval($actor, $note, $quotedAuthor);
		}

		$this->logger->debug('Activity: ' . json_encode($activity));

		// the one thing the rest of the server had no way to know. Dispatched
		// after the post is stored and addressed, so a listener sees what was
		// published rather than what was asked for — and never waits on
		// delivery, which is a queue and other people's servers
		$this->eventDispatcher->dispatchTyped(new PostPublishedEvent($note));

		return $activity;
	}

	/**
	 * @param ?string $language the language the client sent, null or empty to
	 *                          keep the post's; a post that never had one gets
	 *                          the poster's default
	 *
	 * @throws \Exception
	 */
	public function editPost(
		int|string $nid, Person $actor, string $content, ?string $spoilerText = null, ?bool $sensitive = null,
		?string $language = null,
	): Stream {
		$this->moderationService->assertNotSuspended($actor->getId());
		$stream = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));

		if ($stream->getAttributedTo() !== $actor->getId()) {
			throw new \Exception('Not authorized to edit this post');
		}

		$this->assertWithinLength(
			$content, $spoilerText ?? $stream->getSpoilerText()
		);

		// the revision recorded below is the version being replaced, so it has
		// to be taken before any of the fields are overwritten
		$original = clone $stream;
		// the tags first: they are what the markup below may link, and an edit
		// is a fresh parse of the text
		$this->reapplyEntities($stream, $content);
		$stream->setContent($this->linkifyService->toHtml($content, $stream->getTags()));
		if ($spoilerText !== null) {
			$stream->setSpoilerText(ACore::withoutMarkup($spoilerText));
		}

		// an edit may write a shortcode that was not there before, and the tag
		// is what makes it render — here and on every instance the Update
		// reaches. Not an address, so this does not widen the audience.
		$this->streamService->addCustomEmojis($stream, $content, $stream->getSpoilerText());
		if ($sensitive !== null) {
			$stream->setSensitive($sensitive);
		}
		$stream->setLanguage($this->languageFor((string)$language, $actor, $stream->getLanguage()));

		// `published` stays the creation time. The edit used to be stamped
		// there instead, and Mastodon — which reads an Update without
		// `updated` as an implicit one — refreshed the poll counters and threw
		// the new content away. UTC and second-resolution like `published`.
		$stream->setUpdated(gmdate('Y-m-d\TH:i:s\Z'));
		$this->snapshotSource($stream);

		// An edit can name a person who was not in the original audience.
		// Persist that new recipient in `social_stream_dest` with the updated
		// content in one transaction; otherwise the Update carries the new
		// Mention and inbox path, but no local delivery/timeline destination is
		// recorded for the newly named account.
		$this->streamService->updateStream($stream, true);
		$this->revisionService->recordEdit($original, $stream);

		$updated = $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($nid));
		// The reloaded post carries the instance paths it was created with —
		// for a direct message, the inboxes of the people it names. The
		// followers path expands to the shared inbox of every instance with a
		// follower on it, so adding it unconditionally posted the whole edited
		// text of a `direct` post to servers that were never recipients.
		if ($this->streamService->reachesFollowers($updated)) {
			$updated->addInstancePath(
				new InstancePath(
					$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
				)
			);
		}

		// Local subscribers must learn about the edit even when the remote
		// request cannot be queued. The saved revision and source are already
		// durable at this point, so tell the API client that retrying the edit
		// itself is unnecessary and expose the federation failure as 503.
		$this->notificationService->onStatusEdited($updated);

		try {
			$this->activityService->updateActivity($actor, $updated);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to federate post update', ['exception' => $e]);
			throw new FederationDeliveryException(
				'The post was saved locally, but its edit could not be sent to other instances.',
				0,
				$e
			);
		}
		return $updated;
	}

	/**
	 * Refuses a post longer than the limit this instance advertises as
	 * `configuration.statuses.max_characters`, which nothing enforced: a client
	 * that does not read the limit, or reads a different one, had its post
	 * accepted and federated at whatever length it sent.
	 *
	 * Characters, not bytes — `mb_strlen()`, as the bio cap in `AccountService`
	 * counts — and the spoiler counts towards the same budget, the way Mastodon
	 * measures a status.
	 *
	 * @throws InvalidActionException the refusal the client API answers 422 with
	 */
	private function assertWithinLength(string $content, string $spoilerText): void {
		if (mb_strlen($content) + mb_strlen($spoilerText) <= InstanceService::MAX_CHARACTERS) {
			return;
		}

		throw new InvalidActionException(
			'a post may not be longer than ' . InstanceService::MAX_CHARACTERS . ' characters'
		);
	}

	/**
	 * Gives an account a channel the first time it posts a video.
	 *
	 * PeerTube has no video without a channel and refuses a `Video` whose
	 * `attributedTo` names no `Group`, so a video published without one is a
	 * video no PeerTube can take. It is made **here**, when the post is
	 * written, rather than when the post is serialised: a serialisation happens
	 * once per instance the post is delivered to, and making an actor there
	 * would be a write on a read path, forty times over.
	 *
	 * Lazily and silently: nobody should have to learn what a channel is in
	 * order to post a video, and an account that never posts one never grows an
	 * actor it did not ask for. A failure costs the PeerTube shape and not the
	 * post — the `Note` still goes out, and Mastodon and Pixelfed both read it.
	 */
	private function ensureChannelForVideo(Post $post): void {
		if (PeerTubeService::soleVideo($post->getMedias()) === null) {
			return;
		}

		try {
			if (!$this->configService->getAppValueBool(ConfigService::SOCIAL_PUBLISH_VIDEO)) {
				return;
			}

			$this->channelService->defaultFor($post->getActor());
		} catch (Throwable $e) {
			$this->logger->notice('could not give an account a channel for its video', [
				'actor' => $post->getActor()->getId(), 'exception' => $e,
			]);
		}
	}

	/**
	 * Puts the quoted post on the note and addresses its author.
	 *
	 * The quoted post is named by whatever the caller had: the numeric status
	 * id a Mastodon client sends as `quote_id`, or an ActivityPub URI. Only a
	 * post this instance holds can be quoted — the quote has to name a real
	 * object for anyone else to resolve — and only one that is public or
	 * unlisted, which is the rule `PinService::pin()` and
	 * `BoostService::create()` apply: a quote carries the quoted post into the
	 * quoter's audience, and a followers-only post has none of those readers.
	 *
	 * The author is addressed rather than merely notified: they are a recipient
	 * of the post that carries their words, and their server is the one that
	 * has to answer the QuoteRequest that follows — unless that server is this
	 * one, in which case the approval is granted here and now.
	 *
	 * @return ?Person the quoted author, when there is a quote and they are known
	 * @throws InvalidActionException when the post may not be quoted — the
	 *                                exception the client API already answers 422 with, which is what a
	 *                                client can act on, and the one PinService::pin() raises for the
	 *                                same refusal
	 */
	private function applyQuote(Note $note, string $quotedId): ?Person {
		if ($quotedId === '') {
			return null;
		}

		try {
			$quoted = ctype_digit($quotedId)
				? $this->streamService->getStreamByNid(\OCA\Social\Tools\Nid::fromStorage($quotedId))
				: $this->streamService->getStreamById($quotedId);
		} catch (\Exception $e) {
			throw new InvalidActionException('the post to quote is unknown here');
		}

		if (!$quoted->isQuotable()) {
			throw new InvalidActionException('you can only quote a public or unlisted post');
		}

		$note->setQuote($quoted->getId());

		if ($quoted->isLocal()) {
			// this server is the quoted author's server, so the approval is
			// ours to give and there is nobody to ask: sending a QuoteRequest
			// here would be this instance posting to its own inbox and waiting
			// for its own answer. The post is quotable — that was decided two
			// lines up, by the same rule QuoteRequestInterface would apply —
			// so stamp it now, before the source is snapshotted, and the very
			// first delivery carries an approval every peer can dereference.
			$note->setQuoteAuthorization(
				$quoted->getId() . '/quote_authorizations/'
				. QuoteRequestInterface::stamp($note->getId())
			);
			$note->setQuoteState(Stream::QUOTE_ACCEPTED);

			return null;
		}

		try {
			$author = $this->streamService->getAuthorFromPostId($quoted->getId());
		} catch (\Exception $e) {
			// the post is here but its author is not cached: the quote still
			// stands, the author simply learns of it the way anyone else does
			$this->logger->notice('quoting a post whose author cannot be resolved', [
				'quoted' => $quoted->getId(),
				'exception' => $e,
			]);

			return null;
		}

		$note->addCc($author->getId());
		$inbox = ($author->getSharedInbox() !== '') ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox !== '') {
			$note->addInstancePath(
				new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
			);
		}

		return $author;
	}

	/**
	 * FEP-044f: ask the quoted author's server for permission.
	 *
	 * Mastodon 4.5 renders a quote inline only once the quoting post carries a
	 * `quoteAuthorization`, and only grants one in answer to a QuoteRequest —
	 * without this the quote federates as a bare link on every Mastodon
	 * instance. The approval arrives later, as an `Accept{QuoteRequest}`, and
	 * `QuoteRequestInterface` writes it onto the post.
	 *
	 * A failure here is not a failure of the post: it is already published.
	 */
	private function requestQuoteApproval(Person $actor, Stream $note, Person $author): void {
		$request = new QuoteRequest();
		$request->setId($note->getId() . '#quote-request');
		$request->setActor($actor);
		$request->setObjectId($note->getQuote());
		$request->setInstrument($note->getId());
		$request->setToArray([$author->getId()]);

		$inbox = ($author->getSharedInbox() !== '') ? $author->getSharedInbox() : $author->getInbox();
		if ($inbox === '') {
			return;
		}

		$request->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH)
		);

		try {
			$this->activityService->request($request);
		} catch (\Exception $e) {
			$this->logger->warning('could not ask for approval of a quote', [
				'quote' => $note->getQuote(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * The stored wire object is what a reload reads the language and the edit
	 * stamp from, and what a remote Update rewrites; a local edit has to
	 * rewrite it the same way or the next re-export federates the old text.
	 */
	private function snapshotSource(Stream $stream): void {
		$stream->setSource(json_encode($stream, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * The language a post is written in: what the client said, else what the
	 * post already had, else the poster's default.
	 */
	private function languageFor(string $requested, Person $actor, string $current = ''): string {
		$requested = Stream::normalizeLanguage($requested);
		if ($requested !== '') {
			return $requested;
		}
		if ($current !== '') {
			return $current;
		}

		return $this->defaultLanguageOf($actor);
	}

	/**
	 * The poster's Nextcloud language, which is the best guess this app has
	 * for the language they write in. `IFactory::getUserLanguage()` already
	 * falls back to the instance default and then to English; a locale's
	 * region is dropped unless it names a different written language, so
	 * Mastodon's per-language filters — keyed by plain `de`, not `de-DE` —
	 * still match the post.
	 */
	private function defaultLanguageOf(Person $actor): string {
		$user = ($actor->getUserId() !== '') ? $this->userManager->get($actor->getUserId()) : null;
		$language = Stream::normalizeLanguage($this->l10nFactory->getUserLanguage($user));
		if ($language === '') {
			return 'en';
		}

		$primary = explode('-', $language, 2)[0];

		return in_array($primary, self::REGIONAL_LANGUAGES, true) ? $language : $primary;
	}

	/**
	 * The hashtags and mentions the edited text names.
	 *
	 * An edit is a fresh parse of the text, so the `tag` array has to be one
	 * too. Without it a hashtag written into a post gained no `Hashtag` tag:
	 * it rendered as dead text, the post reached no tag timeline and no
	 * follower of that tag, and a hashtag taken out of the text kept its tag
	 * and its place in that timeline for ever.
	 *
	 * Mentions are only added. Addressing cannot be withdrawn — the post has
	 * already been delivered to everyone it named — so a mention deleted from
	 * the text keeps its tag, and only a name that is new to the post is
	 * addressed.
	 */
	private function reapplyEntities(Stream $stream, string $content): void {
		$hashtags = [];
		$mentions = [];
		foreach ($this->linkifyService->entitiesIn($content) as $entity) {
			match ($entity['type']) {
				LinkifyService::TYPE_MENTION => $mentions[] = $entity['name'],
				LinkifyService::TYPE_HASHTAG => $hashtags[] = $entity['name'],
				default => null,
			};
		}

		if ($stream instanceof Note) {
			// rebuilt rather than appended to, the way StreamService rebuilds
			// the Emoji tags
			$stream->setTags(array_values(array_filter(
				$stream->getTags(),
				static fn (array $tag): bool => ($tag['type'] ?? '') !== 'Hashtag'
			)));
			$this->streamService->addHashtags($stream, array_values(array_unique($hashtags)));
		}

		$named = [];
		foreach ($stream->getTags('Mention') as $tag) {
			$named[strtolower(ltrim((string)($tag['name'] ?? ''), '@'))] = true;
		}

		$this->streamService->addRecipients(
			$stream,
			$stream->getVisibility(),
			array_values(array_filter(
				array_unique($mentions),
				static fn (string $mention): bool => !isset($named[strtolower($mention)])
			))
		);
	}

	/**
	 * The accounts and hashtags written into the text itself.
	 *
	 * The entities come from `LinkifyService`, which is also what builds the
	 * links in the published HTML: one parse, so what the post addresses, what
	 * its `tag` array names and what its markup links can never be three
	 * different lists. The pair of regular expressions this replaced read a
	 * handle as "everything up to the next space", which swallowed the full
	 * stop at the end of a sentence and addressed `bob@example.invalid.`.
	 */
	public function fixRecipientAndHashtags(Post $post) {
		foreach ($this->linkifyService->entitiesIn($post->getContent()) as $entity) {
			match ($entity['type']) {
				LinkifyService::TYPE_MENTION => $post->addTo($entity['name']),
				LinkifyService::TYPE_HASHTAG => $post->addHashtag($entity['name']),
				default => null,
			};
		}
	}

	/**
	 * A reply is the strongest thing a reader says about a post short of
	 * writing one: its hashtags count for My interests. Read as the viewer,
	 * and never allowed to fail the post it is a side effect of.
	 */
	private function learnFromReply(Person $actor, string $replyTo): void {
		if ($replyTo === '') {
			return;
		}

		try {
			$this->interestService->recordAction(
				$actor, $this->streamService->getStreamById($replyTo, true), InterestService::ACTION_REPLY
			);
		} catch (\Throwable $e) {
			$this->logger->debug('[PostService] the post replied to is not one to learn from', ['exception' => $e]);
		}
	}
}
