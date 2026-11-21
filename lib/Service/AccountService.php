<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Cron\AtprotoPublish;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Db\ClientAuthRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\InvalidHandleException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\NoUserException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\Moderation;
use OCP\Accounts\IAccountManager;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Class ActorService
 *
 * @package OCA\Social\Service
 */
class AccountService {
	/**
	 * How long a refused write of the handle into the Nextcloud profile is
	 * left alone, in seconds; see publishHandleOnProfile().
	 */
	public const HANDLE_PUBLISH_RETRY = 86400;

	/** How many local accounts one page of the refresh walk carries. */
	private const LOCAL_ACTOR_BATCH = 200;

	/** ...and how many pages one cron pass may walk before giving the run back. */
	private const LOCAL_ACTOR_PAGES = 10;

	/**
	 * How long a soft-deleted actor is kept at least before
	 * `manageDeletedActors()` purges it; one with deliveries still queued is
	 * kept until they have gone.
	 */
	public const TIME_RETENTION = 3600;

	/** Deleted local accounts removed per cron pass; the rest wait a pass. */
	public const DELETED_ACTOR_BATCH = 500;

	/**
	 * What may appear in a `preferredUsername`: letters, digits and underscore,
	 * with dot and dash allowed inside but not at either end, which Mastodon,
	 * Pleroma and GoToSocial all accept.
	 */
	private const HANDLE_PATTERN = '/^[a-zA-Z0-9_]+([a-zA-Z0-9_.-]*[a-zA-Z0-9_])?$/';
	private const HANDLE_MAX_LENGTH = 64;

	/**
	 * How long a bio may be, in characters; Mastodon caps `note` at 500.
	 */
	private const SUMMARY_MAX_LENGTH = 500;

	/**
	 * The per-user app preference holding an account's default post visibility.
	 * It is not federated, so it is not on the actor.
	 */
	private const DEFAULT_PRIVACY = 'default_privacy';

	/**
	 * Age, in days, past which `blindKeyRotation()` renews an actor's key pair.
	 * The rotation is not scheduled.
	 */
	public const KEY_PAIR_LIFESPAN = 60;

	/** whether `changingProfile()` is collecting changes to tell about once */
	private bool $holdingProfileUpdates = false;
	private bool $profileChanged = false;
	/** @var array<string, int> the avatar version told, by user, in this request */
	private array $avatarsTold = [];

	public function __construct(
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IAccountManager $accountManager,
		private ActorsRequest $actorsRequest,
		private ChannelsRequest $channelsRequest,
		private ClientAuthRequest $clientAuthRequest,
		private FollowsRequest $followsRequest,
		private StreamRequest $streamRequest,
		private ActorService $actorService,
		private ActivityService $activityService,
		private DocumentService $documentService,
		private SignatureService $signatureService,
		private ConfigService $configService,
		private AccessBlockService $accessBlockService,
		private CacheActorService $cacheActorService,
		private CacheActorsRequest $cacheActorsRequest,
		private ModerationRequest $moderationRequest,
		private LoggerInterface $logger,
		private NotificationPolicyService $notificationPolicyService,
		private IJobList $jobList,
		private AtprotoConfig $atprotoConfig,
	) {
	}

	/**
	 * @param string $username
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getActor(string $username): Person {
		$actor = $this->actorsRequest->getFromUsername($username);

		return $actor;
	}

	/**
	 * @param string $id
	 *
	 * @return Person
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 */
	public function getFromId(string $id): Person {
		$actor = $this->actorsRequest->getFromId($id);

		return $actor;
	}

	/**
	 * @return Person
	 * @throws AccountDoesNotExistException
	 */
	public function getCurrentViewer(): Person {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new AccountDoesNotExistException('No user is currently logged in');
		}

		try {
			return $this->getActorFromUserId($user->getUID());
		} catch (Exception $e) {
			throw new AccountDoesNotExistException('Account not found for current user: ' . $e->getMessage());
		}
	}

	/**
	 * @param string $userId
	 * @param bool $create
	 *
	 * @return Person
	 * @throws AccountAlreadyExistsException
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function getActorFromUserId(string $userId, bool $create = false): Person {
		$this->confirmUserId($userId);
		try {
			$actor = $this->actorsRequest->getFromUserId($userId);
		} catch (ActorDoesNotExistException $e) {
			if ($create) {
				$this->createActor($userId, $this->generateHandleFromUserId($userId));
				$actor = $this->actorsRequest->getFromUserId($userId);
			} else {
				throw new ActorDoesNotExistException('Actor not found for user: ' . $userId);
			}
		}

		return $actor;
	}

	/**
	 * The cached copy of a local account, rebuilt when it has gone missing. Only
	 * the cached copy holds the follower, following and post counts.
	 *
	 * @throws CacheActorDoesNotExistException the rebuild did not produce one
	 */
	public function getCachedLocalActor(string $username): Person {
		try {
			return $this->cacheActorService->getFromLocalAccount($username);
		} catch (CacheActorDoesNotExistException $e) {
			$this->logger->debug('[AccountService] rebuilding the local actor cache', [
				'username' => $username,
				'exception' => $e->getMessage(),
			]);

			try {
				$this->cacheLocalActorByUsername($username);
			} catch (Exception $cacheError) {
				$this->logger->debug('[AccountService] local actor cache rebuild failed', [
					'username' => $username,
					'exception' => $cacheError->getMessage(),
				]);
			}

			return $this->cacheActorService->getFromLocalAccount($username);
		}
	}

	/**
	 * Refuses a fediverse identity to a Nextcloud account at a blocked email
	 * domain, the internal-user counterpart of
	 * `ExternalUserService::assertEmailAvailable()`. An account with no email
	 * address is allowed.
	 *
	 * @throws InvalidHandleException
	 */
	private function assertEmailDomainIsAllowed(string $userId): void {
		$user = $this->userManager->get($userId);
		$email = ($user === null) ? '' : (string)$user->getEMailAddress();
		if ($email === '' || !$this->accessBlockService->isBlockedEmail($email)) {
			return;
		}

		$this->logger->info('refused a fediverse account to a blocked email domain', [
			'userId' => $userId,
		]);

		throw new InvalidHandleException(
			'this server does not create fediverse accounts for addresses at that domain', 'email-domain-refused'
		);
	}

	/**
	 * Creates the Social account of a user, with its key pair.
	 *
	 * @param string $userId
	 * @param string $username
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws InvalidHandleException
	 * @throws ItemAlreadyExistsException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function createActor(string $userId, string $username, string $type = Person::TYPE) {
		// Team and channel accounts live under reserved `team/` and `channel/` ids
		// (a user id cannot contain a slash) and have no person or email to check.
		if (!str_starts_with($userId, 'team/') && !str_starts_with($userId, 'channel/')) {
			$this->confirmUserId($userId);
			$this->assertEmailDomainIsAllowed($userId);
		}
		$this->checkActorUsername($username);

		try {
			$actor = $this->actorsRequest->getFromUsername($username);
			if ($actor->getDeleted() > 0) {
				throw new AccountAlreadyExistsException(
					'actor with that name was deleted but is still in retention. Please try again later', 'handle-held'
				);
			}
			throw new AccountAlreadyExistsException('actor with that name already exist', 'handle-in-use-here');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		try {
			$this->actorsRequest->getFromUserId($userId);
			throw new AccountAlreadyExistsException('account for this user already exist', 'account-exists');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		$actor = new Person();
		$actor->setUserId($userId);
		$actor->setPreferredUsername($username);
		// A channel is a `Group`, the only type PeerTube accepts as a video's owner.
		$actor->setType($type);
		$this->signatureService->generateKeys($actor);
		$this->actorsRequest->create($actor);

		// generate cache.
		$this->cacheLocalActorByUsername($username);

		// generate loopback
		$this->followsRequest->generateLoopbackAccount($actor);

		// A person's account starts with strangers and new accounts waiting for
		// review; a team or a channel is nobody's inbox.
		if (!str_starts_with($userId, 'team/') && !str_starts_with($userId, 'channel/')) {
			$this->notificationPolicyService->startCalm($userId);
		}
	}

	/**
	 * @param string $handle
	 *
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function deleteActor(string $handle): void {
		try {
			$actor = $this->actorsRequest->getFromUsername($handle);
		} catch (ActorDoesNotExistException $e) {
			return;
		}

		// The channels it owns have nobody left to run them, so each is deleted too.
		foreach ($this->channelsRequest->getByOwner($actor->getId()) as $channel) {
			if ($channel->getHandle() === $actor->getPreferredUsername()) {
				continue;
			}
			try {
				$this->deleteActor($channel->getHandle());
			} catch (Exception $e) {
				$this->logger->error('could not delete a channel of a deleted account', [
					'owner' => $actor->getId(), 'channel' => $channel->getHandle(), 'exception' => $e,
				]);
			}
		}

		// set as deleted locally
		$this->actorsRequest->setAsDeleted($actor->getPreferredUsername());

		// Revoke app authorizations and device grants first: a surviving token
		// could make a later `verify_credentials` recreate the actor. The app
		// registrations are the instance's and stay.
		if ($actor->getUserId() !== '') {
			$this->clientAuthRequest->deleteRelatedId($actor->getUserId());
		}

		// delete related data
		/** @var PersonInterface $interface */
		$interface = AP::instance()->getInterfaceFromType(Person::TYPE);
		$interface->delete($actor);

		$this->federateActorDelete($actor);
	}

	/**
	 * The account's owner deleting it themselves: the same deletion as
	 * `occ social:account:delete`, guarded by typing the handle (not a password,
	 * which SSO accounts do not have). The handle stays reserved for the
	 * retention period.
	 *
	 * @throws ActorDoesNotExistException there is nothing to delete
	 * @throws InvalidResourceException the confirmation does not name this account
	 * @throws ItemUnknownException
	 * @throws SocialAppConfigException
	 */
	public function deleteOwnAccount(string $userId, string $confirmation): void {
		$actor = $this->getConfirmedOwnAccount($userId, $confirmation);
		$this->deleteActor($actor->getPreferredUsername());
	}

	/** Validate an own-account deletion before the hosting Nextcloud account is removed. */
	public function assertOwnAccountDeletionConfirmed(string $userId, string $confirmation): void {
		$this->getConfirmedOwnAccount($userId, $confirmation);
	}

	private function getConfirmedOwnAccount(string $userId, string $confirmation): Person {
		$actor = $this->getActorFromUserId($userId);

		$typed = strtolower(ltrim(trim($confirmation), '@'));
		if ($typed === ''
			|| ($typed !== strtolower($actor->getPreferredUsername())
				&& $typed !== strtolower($actor->getAccount()))) {
			throw new InvalidResourceException(
				'type ' . $actor->getAccount() . ' to confirm that this is the account to delete'
			);
		}

		return $actor;
	}

	/**
	 * Tells the fediverse an account is gone; used by deletion and by a
	 * moderator suspending a local account.
	 */
	public function federateActorDelete(Person $actor): void {
		$delete = new Delete();
		$delete->setId($actor->getId() . '#delete');
		$delete->setActorId($actor->getId());
		$delete->setToArray([ACore::CONTEXT_PUBLIC]);
		$delete->setObjectId($actor->getId());
		$delete->addInstancePath(
			new InstancePath(
				$actor->getInbox(),
				InstancePath::TYPE_ALL,
				InstancePath::PRIORITY_LOW
			)
		);
		$this->signatureService->signObject($actor, $delete);

		$this->activityService->request($delete);
	}

	/**
	 * Stores whether new follows towards this user's actor need manual approval,
	 * and refreshes the actor cache so the flag reaches the actor document and
	 * account entity. Remote servers pick the change up when they next refresh
	 * the actor.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setLocked(string $userId, bool $locked): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setLocked($locked);
		$this->actorsRequest->updateLocked($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
		$this->federateProfile($userId);
	}

	/**
	 * Stores the directory flags of this user's actor and refreshes the actor
	 * cache so they reach the actor document and the account entity.
	 *
	 * Accepted bool keys (string forms such as `'true'` and `'1'` are read as
	 * such): `discoverable` (listed in directories and suggestions) and
	 * `indexable` (public posts may be full-text indexed elsewhere), both
	 * opt-in. A key not sent is left alone and other keys are ignored, so a
	 * whole `update_credentials` body can be passed.
	 *
	 * @param array<string, mixed> $flags
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setActorFlags(string $userId, array $flags): void {
		$actor = $this->getActorFromUserId($userId);

		$changed = false;
		if (array_key_exists('discoverable', $flags)) {
			$actor->setDiscoverable($this->flag($flags['discoverable']));
			$changed = true;
		}
		if (array_key_exists('indexable', $flags)) {
			$actor->setIndexable($this->flag($flags['indexable']));
			$changed = true;
		}
		// this one changes the actor's *type* as well — see Person::setBot()
		if (array_key_exists('bot', $flags)) {
			$actor->setBot($this->flag($flags['bot']));
			$changed = true;
		}

		if (!$changed) {
			return;
		}

		$this->actorsRequest->updateFlags($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
		$this->federateProfile($userId);
	}

	/**
	 * The account's display name, as a Mastodon client edits it.
	 *
	 * It belongs to the Nextcloud account and the actor copies it, so this writes
	 * it there and re-caches. A backend that refuses the write (LDAP, SAML) is
	 * reported to the client. Core's listener may then throw while verifying the
	 * `fediverse` property over WebFinger against this server; once the backend
	 * has stored the name, that failure is only logged.
	 *
	 * @throws InvalidActionException when the user's backend owns the name
	 * @throws NoUserException
	 */
	public function setDisplayName(string $userId, string $displayName): void {
		$user = $this->displayNameOwner($userId);

		try {
			$user->setDisplayName($displayName);
		} catch (Exception $e) {
			if ($user->getDisplayName() !== trim($displayName)) {
				throw $e;
			}

			$this->logger->warning(
				'the display name is stored, but a listener failed on the change; the Nextcloud profile may show the old one until it is saved again',
				['userId' => $userId, 'exception' => $e]
			);
		}

		$this->cacheLocalActorByUsername($this->getActorFromUserId($userId)->getPreferredUsername());
		$this->federateProfile($userId);
	}

	/**
	 * Refuses, without writing anything, a display name change the account's
	 * backend would refuse.
	 *
	 * @throws InvalidActionException when the user's backend owns the name
	 * @throws NoUserException
	 */
	public function assertDisplayNameWritable(string $userId): void {
		$this->displayNameOwner($userId);
	}

	/**
	 * @throws InvalidActionException when the user's backend owns the name
	 * @throws NoUserException
	 */
	private function displayNameOwner(string $userId): IUser {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new NoUserException();
		}

		if (!$user->canChangeDisplayName()) {
			throw new InvalidActionException(
				'the display name of this account is managed outside Nextcloud and cannot be changed here'
			);
		}

		return $user;
	}

	/** A bool from whatever form a client put in a JSON or form body. */
	private function flag(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		return in_array($value, [1, '1', 'true', 'on'], true);
	}

	/**
	 * Stores the actor ids this user's actor also answers to (`alsoKnownAs`)
	 * and refreshes the actor cache so the list reaches the actor document.
	 * A remote server accepts a Move *into* here only when this list names the
	 * moving account, so it has to be set before the move is started there.
	 *
	 * @param string[] $alsoKnownAs
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setAlsoKnownAs(string $userId, array $alsoKnownAs): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setAlsoKnownAs($alsoKnownAs);
		$this->actorsRequest->updateAlsoKnownAs($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
	}

	/**
	 * Records where this user's actor moved to (`movedTo`; empty to clear) and
	 * refreshes the actor cache so the actor document and the account entity
	 * say so. The Move itself is federated by MigrationService.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setMovedTo(string $userId, string $movedTo): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setMovedTo($movedTo);
		$this->actorsRequest->updateMovedTo($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
	}

	/**
	 * Stores the profile metadata fields (at most four name/value pairs) and
	 * refreshes the actor cache so they reach the actor document as
	 * PropertyValue attachments and the account entity as `fields`.
	 *
	 * @param array[] $fields [['name' => string, 'value' => string], …]
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setFields(string $userId, array $fields): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setFields($fields);
		$this->actorsRequest->updateFields($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
		$this->federateProfile($userId);
	}

	/**
	 * Stores the bio of this user's actor, refreshes the actor cache so it
	 * reaches the actor document and the account entity, and tells the
	 * followers about it.
	 *
	 * The bio is stored as plain text exactly as typed (`source.note` hands it
	 * back), cut to `SUMMARY_MAX_LENGTH` characters; every renderer escapes it
	 * (`Person::bioAsHtml()`), so it is not stripped of tags here.
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function setSummary(string $userId, string $summary): void {
		$actor = $this->getActorFromUserId($userId);
		$actor->setSummary($this->plainSummary($summary));
		$this->actorsRequest->updateSummary($actor);
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
		$this->federateProfile($userId);
	}

	/**
	 * The visibility a post of this account gets when the client sends none
	 * (Mastodon's `source.privacy`). A stored value that is not a valid
	 * visibility is ignored.
	 */
	public function getDefaultPrivacy(string $userId): string {
		$stored = (string)$this->configService->getValueForUser($userId, self::DEFAULT_PRIVACY);

		return Stream::isKnownClientVisibility($stored) ? $stored : Stream::TYPE_PUBLIC;
	}

	/**
	 * @throws InvalidActionException when it is not a visibility a post can have
	 */
	public function setDefaultPrivacy(string $userId, string $privacy): void {
		$this->assertDefaultPrivacy($privacy);
		$this->configService->setValueForUser($userId, self::DEFAULT_PRIVACY, strtolower(trim($privacy)));
	}

	/**
	 * Refuses, without writing anything, a visibility `setDefaultPrivacy()`
	 * would refuse.
	 *
	 * @throws InvalidActionException when it is not a visibility a post can have
	 */
	public function assertDefaultPrivacy(string $privacy): void {
		$privacy = strtolower(trim($privacy));
		if (!Stream::isKnownClientVisibility($privacy)) {
			throw new InvalidActionException(
				'unknown visibility: ' . $privacy . ' (one of '
				. implode(', ', Stream::clientVisibilities()) . ')'
			);
		}
	}

	/** A bio as it is stored: as typed, normalised newlines, length-capped. */
	private function plainSummary(string $summary): string {
		$summary = trim(str_replace(["\r\n", "\r"], "\n", $summary));

		if (mb_strlen($summary) > self::SUMMARY_MAX_LENGTH) {
			$summary = rtrim(mb_substr($summary, 0, self::SUMMARY_MAX_LENGTH));
		}

		return $summary;
	}

	/**
	 * Applies several changes to one profile and tells the followers once:
	 * Mastodon processes concurrent `Update`s of one actor in parallel, and the
	 * one that loses the race is never shown.
	 *
	 * @template T
	 * @param callable(): T $changes
	 * @return T
	 */
	public function changingProfile(string $userId, callable $changes): mixed {
		$this->holdingProfileUpdates = true;
		try {
			return $changes();
		} finally {
			$this->holdingProfileUpdates = false;
			if ($this->takeProfileChanged()) {
				$this->federateProfile($userId);
			}
		}
	}

	/**
	 * The account's Nextcloud avatar changed: the actor takes the new icon, and
	 * the followers and Bluesky are told, once at the end when inside
	 * `changingProfile()`.
	 */
	public function avatarChanged(string $userId, string $username): void {
		// The change arrives twice (from the app that made it and from Nextcloud's
		// event), so it is told once per avatar version.
		$version = $this->configService->getUserValueInt('version', $userId, 'avatar');
		if (($this->avatarsTold[$userId] ?? null) === $version) {
			return;
		}
		$this->avatarsTold[$userId] = $version;

		$this->cacheLocalActorByUsername($username);
		$this->federateProfile($userId);
	}

	/** Whether a held change is waiting to be told, forgetting it. */
	private function takeProfileChanged(): bool {
		$changed = $this->profileChanged;
		$this->profileChanged = false;

		return $changed;
	}

	/**
	 * Tells the followers about a profile change with the actor document as
	 * served: the actor row carries neither picture nor banner, and a peer reads
	 * an `Update` without `icon` as the picture removed.
	 */
	private function federateProfile(string $userId): void {
		if ($this->holdingProfileUpdates) {
			$this->profileChanged = true;

			return;
		}

		try {
			$actor = $this->getActorFromUserId($userId);
			// every change re-caches the actor before it is told
			$served = $this->cacheActorsRequest->getFromLocalAccount($actor->getPreferredUsername());
		} catch (Exception $e) {
			$this->logger->warning(
				'could not tell the followers that a local actor changed',
				['userId' => $userId, 'exception' => $e]
			);

			return;
		}

		$this->federateActorUpdate($actor, $served);
		$this->queueBlueskyProfile($actor);
	}

	/**
	 * Queues a rewrite of the account's Bluesky profile after anything it shows
	 * changed.
	 */
	public function queueBlueskyProfile(Person $actor): void {
		if ($this->atprotoConfig->isEnabled()) {
			$this->jobList->add(AtprotoPublish::class, ['action' => 'profile', 'id' => $actor->getId()]);
		}
	}

	/**
	 * Re-caches a local account and sends its followers' servers the actor as
	 * served, for a change in how the actor is published rather than in what the
	 * account holds, such as its icon address (`Cron\RepublishAvatars`).
	 */
	public function republishActor(Person $actor): void {
		$this->cacheLocalActorByUsername($actor->getPreferredUsername());
		try {
			$served = $this->cacheActorsRequest->getFromLocalAccount($actor->getPreferredUsername());
		} catch (Exception $e) {
			$this->logger->warning(
				'could not tell the followers that a local actor changed',
				['actor' => $actor->getId(), 'exception' => $e]
			);

			return;
		}

		$this->federateActorUpdate($actor, $served);
	}

	/**
	 * Sends the followers an `Update{Person}` signed with the local actor's key.
	 * A failure is logged: the change is stored and peers see it on their next
	 * refresh of the actor.
	 */
	private function federateActorUpdate(Person $actor, Person $served): void {
		try {
			$update = clone $served;
			$update->addInstancePath(
				new InstancePath(
					$actor->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
				)
			);
			$this->activityService->updateActivity($actor, $update);
		} catch (Exception $e) {
			$this->logger->warning(
				'could not tell the followers that a local actor changed',
				['actor' => $actor->getId(), 'exception' => $e]
			);
		}
	}

	/**
	 * @param string $username
	 *
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function cacheLocalActorByUsername(string $username) {
		try {
			$actor = $this->actorsRequest->getFromUsername($username);

			if ($this->moderationRequest->levelOf($actor->getId()) === Moderation::SUSPEND) {
				// A suspended account is not served, and the cached copy is what serves it,
				// so this must not recreate it.
				$this->cacheActorsRequest->deleteCacheById($actor->getId());

				return;
			}

			try {
				$this->updateCacheLocalActorName($actor);
			} catch (NoUserException $e) {
				return;
			}

			// PeerTube refuses a video whose channel `Group` has no owning `Person`.
			$owner = $this->channelsRequest->ownerOf($actor->getId());
			if ($owner !== '') {
				$actor->setAttributedToActors([['type' => Person::TYPE, 'id' => $owner]]);
			}

			// Team and channel accounts have no Nextcloud avatar to mirror; they get
			// the app's default until somebody uploads one.
			if (!str_starts_with($actor->getUserId(), 'team/')
				&& !str_starts_with($actor->getUserId(), 'channel/')
				&& $this->mayPublish($actor, IAccountManager::PROPERTY_AVATAR)) {
				try {
					$iconId = $this->documentService->cacheLocalAvatarByUsername($actor);
					$actor->setIconId($iconId);
				} catch (ItemUnknownException|ItemAlreadyExistsException $e) {
				}
			}

			$this->loadLocalActorHeader($actor);

			// The actors row carries no cached profile layout or details and the cache
			// write replaces both; ActorService carries forward the layout and
			// verified-link verdicts unless this is the explicit layout update.
			$this->addLocalActorDetailCount($actor);
			$this->actorService->cacheLocalActor($actor);

			// After the WebFinger copy is written: Nextcloud verifies the field through it.
			$this->publishHandleOnProfile($actor);
		} catch (ActorDoesNotExistException $e) {
			// Every caller passes a handle, so this points at a caller passing a user id.
			$this->logger->warning('no Social account under the handle to cache', [
				'handle' => $username, 'exception' => $e,
			]);
		}
	}

	/**
	 * Load the cached header document URL for a local actor.
	 *
	 * @param Person $actor
	 */
	private function loadLocalActorHeader(Person $actor): void {
		try {
			$headerUrl = $this->actorService->getCachedHeader($actor);
			if ($headerUrl !== '') {
				$actor->setHeader($headerUrl);
			}
		} catch (Exception $e) {
		}
	}

	/**
	 * @param string $username
	 * @param string $description
	 *
	 * @return Person
	 *
	 * @throws ActorDoesNotExistException
	 * @throws SocialAppConfigException
	 * @throws NoUserException
	 * @throws ItemAlreadyExistsException
	 * @throws UrlCloudException
	 */
	/**
	 * @param Person $actor
	 */
	public function cacheLocalActorDetailCount(Person $actor) {
		if (!$actor->isLocal()) {
			return;
		}

		$this->addLocalActorDetailCount($actor);
		$this->actorService->cacheLocalActorDetails($actor);
	}

	/**
	 * @param Person $actor
	 */
	public function addLocalActorDetailCount(Person $actor, bool $recount = true) {
		$lastPostCreation = '';
		try {
			$lastPost = $this->streamRequest->lastNoteFromActorId($actor->getId());
			$lastPostCreation = date('Y-m-d', $lastPost->getPublishedTime());
		} catch (StreamNotFoundException $e) {
		}

		$count = $recount ? null : $this->cacheActorsRequest->getCounts($actor->getId());

		if ($count === null) {
			// Aggregate queries (`countFollowers()` is a COUNT(*) over `social_follow`),
			// so this must not be on a write path.
			$count = [
				'followers' => $this->followsRequest->countFollowers($actor->getId()),
				'following' => $this->followsRequest->countFollowing($actor->getId()),
				'post' => $this->streamRequest->countNotesFromActorId($actor->getId()),
			];
			// and write them where they can be added to next time
			$this->cacheActorsRequest->setCounts($actor->getId(), $count);
		}

		$actor->setDetailArray(Details::COUNT, [
			'followers' => $count['followers'],
			'following' => $count['following'],
			// Always counted: small, and must be right when the screen opens.
			'follow_requests' => $this->followsRequest->countPendingRequests($actor->getId()),
			'post' => $count['post'],
		]);
		$actor->setDetail(Details::LAST_POST, $lastPostCreation);
	}

	/**
	 * Moves one of an account's counters by one, for the paths that know
	 * exactly what changed. A counter never counted is left alone; see
	 * `CacheActorsRequest::bumpCount()`.
	 */
	public function bumpActorCount(string $actorId, string $column, int $by = 1): void {
		if ($actorId === '') {
			return;
		}

		try {
			$this->cacheActorsRequest->bumpCount($actorId, $column, $by);
		} catch (Exception $e) {
			// Not worth failing a follow or a post over; the cron's walk reconciles it.
			$this->logger->debug('could not move an actor counter', [
				'actor' => $actorId, 'column' => $column, 'exception' => $e,
			]);
		}
	}

	/**
	 * @param Person $actor
	 *
	 * @throws NoUserException
	 */
	private function updateCacheLocalActorName(Person $actor) {
		// A team account takes its group's name; returning keeps it in the actor
		// cache, without which every read of it 404s.
		if (str_starts_with($actor->getUserId(), 'team/')) {
			if ($actor->getName() === '') {
				$actor->setName(substr($actor->getUserId(), strlen('team/')));
			}

			return;
		}

		// A channel has no Nextcloud user either; same reason as above.
		if (str_starts_with($actor->getUserId(), 'channel/')) {
			if ($actor->getName() === '') {
				$actor->setName($actor->getPreferredUsername());
			}

			return;
		}

		$user = $this->userManager->get($actor->getUserId());
		if ($user === null) {
			throw new NoUserException();
		}

		// A Fediverse actor is public by definition, so anything short of an
		// explicitly private name is published; the default scope is SCOPE_FEDERATED.
		$publishable = [
			IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::SCOPE_FEDERATED,
		];

		try {
			$account = $this->accountManager->getAccount($user);
			$displayNameProperty = $account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME);

			if (!in_array($displayNameProperty->getScope(), $publishable, true)) {
				// Private or instance-local: leave the actor's name alone.
				return;
			}
		} catch (Exception $e) {
			// Unreadable is not private: publish the name.
			$this->logger->warning(
				'could not read the account of a local actor, publishing its display name',
				['userId' => $actor->getUserId(), 'exception' => $e]
			);
		}

		// The user backend's name: the property is a copy written by core's
		// UserChangedEvent listener and can lag behind.
		$actor->setName($user->getDisplayName());
	}

	/**
	 * Whether one Nextcloud profile property may go on a Fediverse actor:
	 * anything short of an explicitly private or instance-local scope, since an
	 * actor is public by definition (the default scope is `SCOPE_FEDERATED`).
	 * Unknown properties and unreadable accounts answer true.
	 */
	public function mayPublish(Person $actor, string $property): bool {
		$user = $this->userManager->get($actor->getUserId());
		if ($user === null) {
			return true;
		}

		try {
			$scope = $this->accountManager->getAccount($user)->getProperty($property)->getScope();
		} catch (Exception $e) {
			return true;
		}

		return in_array($scope, [
			IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::SCOPE_FEDERATED,
		], true);
	}

	/**
	 * Writes the actor's handle into the `fediverse` field of the Nextcloud
	 * profile, only when that field is empty, leaving its visibility alone.
	 *
	 * The resulting `UserUpdatedEvent` re-enters the cache refresh, which then
	 * finds the field filled. Nextcloud verifies the field by WebFinger over
	 * https, so it is not tried on a plain-http server, and a refused write is
	 * left alone for `HANDLE_PUBLISH_RETRY` and taken back off core's in-memory
	 * copy, which would otherwise fail every later write to the account.
	 */
	private function publishHandleOnProfile(Person $actor): void {
		$user = $this->userManager->get($actor->getUserId());
		if ($user === null) {
			return;
		}

		$userId = $actor->getUserId();
		$failedAt = (int)$this->configService->getUserValue(ConfigService::USER_HANDLE_PUBLISH_FAILED, $userId);
		if ($failedAt > 0 && time() - $failedAt < self::HANDLE_PUBLISH_RETRY) {
			return;
		}

		$property = null;
		$previous = '';
		try {
			if (!str_starts_with((string)$this->configService->getCloudUrl(true), 'https://')) {
				$this->logger->debug('the handle is not published on the Nextcloud profile: the profile check needs https', [
					'userId' => $userId,
				]);

				return;
			}

			$account = $this->accountManager->getAccount($user);
			$property = $account->getProperty(IAccountManager::PROPERTY_FEDIVERSE);
			$previous = $property->getValue();
			if (trim($previous) !== '') {
				return;
			}

			$property->setValue($actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress());
			$this->accountManager->updateAccount($account);
			if ($failedAt > 0) {
				$this->configService->setValueForUser($userId, ConfigService::USER_HANDLE_PUBLISH_FAILED, '');
			}
		} catch (Exception $e) {
			// The profile stays as it was; the actor is fine either way.
			$property?->setValue($previous);
			$this->configService->setValueForUser($userId, ConfigService::USER_HANDLE_PUBLISH_FAILED, (string)time());
			$this->logger->debug('could not publish the handle on the Nextcloud profile', [
				'userId' => $userId, 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Whether a person may take this handle for the account they are about
	 * to create: their own user id, or anything the pattern allows that is not
	 * another Nextcloud user's id or an existing actor's handle.
	 *
	 * @throws InvalidHandleException
	 * @throws AccountAlreadyExistsException
	 */
	public function assertHandleAvailable(string $userId, string $username): void {
		$this->checkActorUsername($username);
		if (strtolower($username) !== strtolower($userId) && $this->userManager->userExists($username)) {
			throw new InvalidHandleException('that is another person\'s user name on this Nextcloud', 'handle-other-user');
		}
		try {
			$this->actorsRequest->getFromUsername($username);
		} catch (ActorDoesNotExistException $e) {
			return;
		}
		throw new AccountAlreadyExistsException('that handle is taken', 'handle-in-use-here');
	}

	/**
	 * The fediverse handle a person's Nextcloud profile names, or ''; where
	 * `ColleagueService` finds people who already have an account elsewhere.
	 */
	public function linkedHandle(string $userId): string {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return '';
		}
		try {
			$value = trim($this->accountManager->getAccount($user)->getProperty(IAccountManager::PROPERTY_FEDIVERSE)->getValue());
		} catch (Exception $e) {
			return '';
		}

		return ltrim($value, '@');
	}

	/**
	 * Writes an account elsewhere into the person's Nextcloud profile, as the
	 * `fediverse` field, and creates nothing here. Returns the handle as stored.
	 *
	 * @throws InvalidHandleException
	 * @throws NoUserException
	 */
	public function linkExternalHandle(string $userId, string $handle): string {
		$handle = ltrim(trim($handle), '@');
		if (preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,63}@([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/', $handle) !== 1) {
			throw new InvalidHandleException('a fediverse account is written user@server.example', 'linked-handle-invalid');
		}
		try {
			if (strcasecmp(substr($handle, strpos($handle, '@') + 1), $this->configService->getSocialAddress()) === 0) {
				throw new InvalidHandleException('that would be an account on this server; create one instead', 'linked-handle-local');
			}
		} catch (SocialAppConfigException $e) {
			// no address configured yet: nothing to compare against
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new NoUserException();
		}
		$account = $this->accountManager->getAccount($user);
		$property = $account->getProperty(IAccountManager::PROPERTY_FEDIVERSE);
		$property->setValue($handle);
		if ($property->getScope() === IAccountManager::SCOPE_PRIVATE || $property->getScope() === IAccountManager::SCOPE_LOCAL) {
			// the point of naming it is that the people here find it
			$property->setScope(IAccountManager::SCOPE_LOCAL);
		}
		$this->accountManager->updateAccount($account);

		return $handle;
	}

	/**
	 * A Fediverse handle ends up in `preferredUsername`, the actor URL and the
	 * `acct:` WebFinger subject, so it must be URL-safe and match what Mastodon
	 * and others accept: letters, digits and `_`, with `.` and `-` inside.
	 *
	 * @throws InvalidHandleException
	 */
	private function checkActorUsername(string $username): void {
		if ($username === '' || strlen($username) > self::HANDLE_MAX_LENGTH) {
			throw new InvalidHandleException(
				'a Fediverse handle must be between 1 and ' . self::HANDLE_MAX_LENGTH . ' characters',
				'handle-length',
				null,
				['max' => self::HANDLE_MAX_LENGTH]
			);
		}

		if (preg_match(self::HANDLE_PATTERN, $username) !== 1) {
			throw new InvalidHandleException(
				'"' . $username . '" cannot be used as a Fediverse handle: only letters, digits and '
				. 'underscore are allowed, with dot and dash inside',
				'handle-characters'
			);
		}
	}

	/**
	 * Derives a usable Fediverse handle from a Nextcloud user id, for paths
	 * that create an account without the user choosing one. A qualifying user
	 * id is used as is; otherwise it is folded down and given a numeric suffix
	 * until free.
	 */
	public function generateHandleFromUserId(string $userId): string {
		$candidate = $userId;
		try {
			$this->checkActorUsername($candidate);
		} catch (InvalidHandleException $e) {
			$candidate = strtolower($userId);
			// Disallowed characters become underscores; runs collapse and edges are trimmed.
			$candidate = preg_replace('/[^a-z0-9_.-]+/', '_', $candidate) ?? '';
			$candidate = preg_replace('/_{2,}/', '_', $candidate) ?? '';
			$candidate = trim($candidate, '_.-');
			$candidate = substr($candidate, 0, self::HANDLE_MAX_LENGTH);

			if ($candidate === '' || preg_match(self::HANDLE_PATTERN, $candidate) !== 1) {
				// Nothing usable survived (a purely non-Latin id, say).
				$candidate = 'user_' . substr(hash('sha256', $userId), 0, 12);
			}

			$this->logger->notice(
				'user id cannot be used as a Fediverse handle, derived one instead',
				['userId' => $userId, 'handle' => $candidate]
			);
		}

		return $this->firstFreeHandle($candidate);
	}

	/**
	 * `$candidate` if no actor holds it, else `$candidate` with the lowest
	 * numeric suffix that is free.
	 */
	private function firstFreeHandle(string $candidate): string {
		$handle = $candidate;
		for ($i = 2; $i < 100; $i++) {
			try {
				$this->actorsRequest->getFromUsername($handle);
			} catch (ActorDoesNotExistException $e) {
				return $handle;
			}

			$suffix = '_' . $i;
			$handle = substr($candidate, 0, self::HANDLE_MAX_LENGTH - strlen($suffix)) . $suffix;
		}

		// a hundred collisions on one derived handle is not a real install
		return substr($candidate, 0, self::HANDLE_MAX_LENGTH - 13) . '_' . substr(
			hash('sha256', $candidate . microtime()), 0, 12
		);
	}

	/**
	 * Removes the local accounts whose deletion is older than the grace
	 * period, a bounded batch per pass.
	 *
	 * An account with deliveries still queued is kept: they are signed with its
	 * key when sent. The queue gives up after `RequestQueueService::MAX_AGE`, so
	 * this is days at most.
	 *
	 * @return int
	 * @throws Exception
	 */
	public function manageDeletedActors(): int {
		$deleted = 0;
		$accounts = $this->actorsRequest->getDeletedBefore(time() - self::TIME_RETENTION, self::DELETED_ACTOR_BATCH);
		if ($accounts === []) {
			return 0;
		}
		$pending = $this->activityService->withPendingDeliveries(
			array_map(static fn (Person $account): string => $account->getId(), $accounts)
		);
		foreach ($accounts as $item) {
			if (isset($pending[$item->getId()])) {
				continue;
			}
			$this->actorsRequest->delete($item->getPreferredUsername());
			$deleted++;
		}

		return $deleted;
	}

	/**
	 * @return int
	 * @throws Exception
	 */
	/**
	 * Refreshes the cached copy of the local accounts, `LOCAL_ACTOR_BATCH` per
	 * page and at most `LOCAL_ACTOR_PAGES` pages a pass, remembering where it
	 * got to. Real changes refresh their row where they happen, so a large
	 * instance taking many passes is fine.
	 *
	 * @param int $deadline when the pass must stop, or 0 for no limit
	 *
	 * @return int how many accounts were refreshed
	 */
	public function manageCacheLocalActors(int $deadline = 0): int {
		$after = (string)$this->configService->getAppValue(ConfigService::SOCIAL_LOCAL_ACTOR_CURSOR);
		$done = 0;

		for ($page = 0; $page < self::LOCAL_ACTOR_PAGES; $page++) {
			$batch = $this->actorsRequest->getPage(self::LOCAL_ACTOR_BATCH, $after);
			if ($batch === []) {
				// the end of the table: begin again next pass
				$after = '';
				break;
			}

			foreach ($batch as $item) {
				// the column the page is ordered by, as the row stores it
				$after = ActorsRequest::rowPrimOf($item);
				try {
					$this->cacheLocalActorByUsername($item->getPreferredUsername());
				} catch (Exception $e) {
				}
				$done++;
			}

			if ($deadline > 0 && time() >= $deadline) {
				break;
			}
		}

		$this->configService->setAppValue(ConfigService::SOCIAL_LOCAL_ACTOR_CURSOR, $after);

		return $done;
	}

	/**
	 * Renews the key pair of every local account older than
	 * `KEY_PAIR_LIFESPAN` days, walking the accounts a page at a time.
	 *
	 * @return int how many key pairs were renewed
	 * @throws Exception
	 */
	public function blindKeyRotation(): int {
		$count = 0;
		foreach ($this->actorsRequest->walk() as $actor) {
			try {
				if ($actor->getCreation() < (time() - (self::KEY_PAIR_LIFESPAN * 3600 * 24))) {
					$this->signatureService->generateKeys($actor);
					$this->actorsRequest->refreshKeys($actor);
					$count++;
				}
			} catch (Exception $e) {
			}
		}

		return $count;
	}

	/**
	 * @param string $userId
	 *
	 * @return IUser
	 * @throws NoUserException
	 */
	public function confirmUserId(string &$userId): IUser {
		$user = $this->userManager->get($userId);

		if ($user === null) {
			throw new NoUserException('user does not exist');
		}

		$userId = $user->getUID();

		return $user;
	}
}
