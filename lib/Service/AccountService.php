<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use OCA\Social\AP;
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
	/** How many local accounts one page of the refresh walk carries. */
	private const LOCAL_ACTOR_BATCH = 200;

	/** ...and how many pages one cron pass may walk before giving the run back. */
	private const LOCAL_ACTOR_PAGES = 10;

	/** How long a soft-deleted actor is kept before `manageDeletedActors()` purges it. */
	public const TIME_RETENTION = 3600;

	/** Deleted local accounts removed per cron pass; the rest wait a pass. */
	public const DELETED_ACTOR_BATCH = 500;

	/**
	 * What may appear in a `preferredUsername`. Letters, digits and underscore,
	 * with dot and dash allowed inside but not at either end — the intersection
	 * of what Mastodon, Pleroma and GoToSocial accept.
	 */
	private const HANDLE_PATTERN = '/^[a-zA-Z0-9_]+([a-zA-Z0-9_.-]*[a-zA-Z0-9_])?$/';
	private const HANDLE_MAX_LENGTH = 64;

	/**
	 * How long a bio may be, in characters. Mastodon's `note` is capped at 500
	 * and truncates what a client sends beyond it; a longer one would be cut
	 * on every server that shows it anyway.
	 */
	private const SUMMARY_MAX_LENGTH = 500;

	/**
	 * Where an account's default post visibility is kept, as a per-user app
	 * preference. It is a preference of the person, not a property of the
	 * actor: nothing federates it, and the actor row has no column for it.
	 */
	private const DEFAULT_PRIVACY = 'default_privacy';

	/**
	 * Age, in days, past which `blindKeyRotation()` would renew an actor's key pair.
	 * The rotation is not currently scheduled; the constant exists so the method does
	 * not fatal on an undefined constant if it is ever called.
	 */
	public const KEY_PAIR_LIFESPAN = 60;

	/** whether `changingProfile()` is collecting changes to tell about once */
	private bool $holdingProfileUpdates = false;
	private bool $profileChanged = false;

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
	 * The cached copy of a local account, rebuilt when it has gone missing.
	 *
	 * `social_actor` holds what this instance decides about an account and
	 * `social_cache_actor` holds what everybody reads — including the follower,
	 * following and post counts, which live only in the cached copy. A reader
	 * therefore wants this one, and a cache row that is absent (an account that
	 * has never been looked at, or one whose row was purged) is a thing to
	 * rebuild rather than an error to report: the account exists either way.
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
	 * domain.
	 *
	 * Mastodon's email-domain block polices its sign-up. For an internal user
	 * the server decides who gets a Nextcloud account, so this is the same
	 * question one step later: whether this instance publishes a fediverse
	 * identity for whoever is behind that address. It matters on a server with
	 * open registration, which is where a throwaway-address domain turns up.
	 * A self-registered external user is asked it at the registration form as
	 * well (`ExternalUserService::assertEmailAvailable()`).
	 *
	 * An account with no email address is allowed: there is nothing to check
	 * it against, and a server that stores no addresses would otherwise hand
	 * out no fediverse accounts at all.
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
			'this server does not create fediverse accounts for addresses at that domain'
		);
	}

	/**
	 * Method should be called by the frontend and will generate a fresh Social account for
	 * the user, using the userId and the username.
	 *
	 * Pair of keys are created at this point.
	 *
	 * Return exceptions if an account already exist for this user or if the username is already
	 * taken
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
		// A team account belongs to a group rather than to a person, and a
		// channel to an account rather than to a user; both are stored under a
		// reserved `team/` or `channel/` id that no Nextcloud user can have,
		// because a user id may not contain a slash. There is nobody to confirm
		// and no address to check the domain of. Everything after this is the
		// same actor every account gets — the key pair, the cache row, the
		// loopback follow — which is what makes either of them followable from
		// Mastodon without any of it being written twice.
		if (!str_starts_with($userId, 'team/') && !str_starts_with($userId, 'channel/')) {
			$this->confirmUserId($userId);
			$this->assertEmailDomainIsAllowed($userId);
		}
		$this->checkActorUsername($username);

		try {
			$actor = $this->actorsRequest->getFromUsername($username);
			if ($actor->getDeleted() > 0) {
				throw new AccountAlreadyExistsException(
					'actor with that name was deleted but is still in retention. Please try again later'
				);
			}
			throw new AccountAlreadyExistsException('actor with that name already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		try {
			$this->actorsRequest->getFromUserId($userId);
			throw new AccountAlreadyExistsException('account for this user already exist');
		} catch (ActorDoesNotExistException $e) {
			/* we do nohtin */
		}

		$actor = new Person();
		$actor->setUserId($userId);
		$actor->setPreferredUsername($username);
		// what a peer reads to know what kind of thing this is. A channel is a
		// `Group`, which is the one type PeerTube will accept as the owner of a
		// video; everything else is the `Person` it has always been.
		$actor->setType($type);
		$this->signatureService->generateKeys($actor);
		$this->actorsRequest->create($actor);

		// generate cache.
		$this->cacheLocalActorByUsername($username);

		// generate loopback
		$this->followsRequest->generateLoopbackAccount($actor);
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

		// set as deleted locally
		$this->actorsRequest->setAsDeleted($actor->getPreferredUsername());

		// Every app this account had signed in to. A token that outlived the
		// account is a token that would bring it back: `verify_credentials`
		// creates an actor for a user who has none, so a phone left running
		// would quietly re-make the account under a derived handle a minute
		// after it was deleted. The app registrations are the instance's and
		// stay; only the authorizations go.
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
	 * The account's owner deleting it themselves.
	 *
	 * The same deletion an administrator's `occ social:account:delete` does —
	 * the posts go, the follows go, a `Delete` goes out to every server that
	 * knew the account — with one thing in front of it: the person has to type
	 * the handle they are deleting. Somebody who wants their Social presence
	 * gone but their Nextcloud account kept had to ask an administrator for
	 * this, which meant explaining to somebody else why.
	 *
	 * The typed handle is the whole of the guard, and it is deliberately not a
	 * password: an account signed in through SSO has none to give, and asking
	 * for one would have made this an administrator's job again for exactly
	 * the installations that federate most.
	 *
	 * The handle stays out of use for the retention hour, so nobody can take
	 * it the moment it is let go; the person can make a *new* account under a
	 * different one straight away.
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
	 * Tells the fediverse an account is gone.
	 *
	 * Its own method because deletion is not the only thing that ends an
	 * account here: a moderator suspending a local account deletes everything
	 * it posted and stops serving its actor, and a suspension that federated
	 * nothing left every remote instance holding a full copy of an account this
	 * one had decided to remove — the takedown stopped at our own edge.
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
	 * Accepted keys, each a bool (the string forms clients send — `'true'`,
	 * `'1'` — are read as such): `discoverable`, whether the account may be
	 * listed in directories and suggestions; `indexable`, whether its public
	 * posts may be full-text indexed by other servers. Both are opt-in, like
	 * Mastodon's. A key that is not sent is left as it is; anything else in
	 * `$flags` is ignored, so the caller can hand over a whole
	 * `update_credentials` body.
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
	 * It belongs to the Nextcloud account rather than to the actor — the actor
	 * copies it, and `updateCacheLocalActorName()` is what carries it over — so
	 * this writes it where it lives and re-caches. A backend that will not have
	 * it written (LDAP, SAML, anything provisioned from elsewhere) says so, and
	 * that is a refusal the client should see rather than a silent success: the
	 * name it shows afterwards would be the old one either way, and only one of
	 * those two outcomes tells the user why.
	 *
	 * Nextcloud stores the name with the user backend first and only then
	 * tells its listeners. Core's own listener re-validates the whole profile
	 * and throws when it cannot verify the `fediverse` property, which it does
	 * by fetching that handle's WebFinger over HTTPS — from this very server,
	 * for the handle this app writes there, which fails wherever the server
	 * cannot reach itself by its own name. A name the backend stored is kept
	 * and the listener's failure logged; only a name that was not stored is
	 * an error.
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
	 * A bio is kept as **plain text**: it is what the user typed, it is what
	 * `source.note` has to hand back to a client that opens an edit box on it,
	 * and it is the only form that cannot carry markup into a reader's
	 * timeline. It is stored exactly as it was typed — every path that renders
	 * it escapes it (`Person::bioAsHtml()` for `summary` on the wire and `note`
	 * on the client API), so there is nothing left for a stripping pass here to
	 * protect and a great deal for it to break: `strip_tags()` reads a bare
	 * `<` as the start of a tag and eats the rest of the line, which turned
	 * `Maths: a<b and b>c` into `Maths: ac`. What is stored is cut to
	 * `SUMMARY_MAX_LENGTH` characters rather than refused, the way
	 * `Person::setFields()` caps a field.
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
	 * The visibility a post of this account gets when the client sends none —
	 * Mastodon's `source.privacy`, which a client reads at login and offers as
	 * the preselected audience in its composer. A stored value that is not one
	 * this app posts with is ignored rather than guessed at.
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
	 * Applies several changes to one profile and tells the followers once.
	 *
	 * Each setter tells them on its own, and a peer handed several `Update`s
	 * of one actor at the same moment may keep any one of them: Mastodon
	 * processes them in parallel under a lock per actor, and an `Update` that
	 * loses the race is the change that never shows.
	 *
	 * @template T
	 * @param callable(): T $changes
	 * @return T
	 */
	public function changingProfile(string $userId, callable $changes): mixed {
		$this->holdingProfileUpdates = true;
		$this->profileChanged = false;
		try {
			return $changes();
		} finally {
			$this->holdingProfileUpdates = false;
			if ($this->profileChanged) {
				$this->profileChanged = false;
				$this->federateProfile($userId);
			}
		}
	}

	/**
	 * Tells the followers about a profile change, with the display name the
	 * actor document is served with: the stored actor row does not carry it.
	 */
	private function federateProfile(string $userId): void {
		if ($this->holdingProfileUpdates) {
			$this->profileChanged = true;

			return;
		}

		try {
			$actor = $this->getActorFromUserId($userId);
			$this->updateCacheLocalActorName($actor);
		} catch (Exception $e) {
			$this->logger->warning(
				'could not tell the followers that a local actor changed',
				['userId' => $userId, 'exception' => $e]
			);

			return;
		}

		$this->federateActorUpdate($actor);
	}

	/**
	 * Tells the followers that the actor document changed, with an
	 * `Update{Person}` — the path `LocalController` already uses after a new
	 * header image, signed here with the local actor's own key.
	 *
	 * A failure is logged and swallowed: the change is stored either way, and
	 * a remote server picks it up when it next refreshes the actor.
	 */
	private function federateActorUpdate(Person $actor): void {
		try {
			$update = clone $actor;
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
			$actor = $this->getActor($username);

			if ($this->moderationRequest->levelOf($actor->getId()) === Moderation::SUSPEND) {
				// A suspended account is not served from this instance, and
				// the cached copy is what serves it: the actor document, the
				// WebFinger answer and the timelines are all read from it. The
				// suspension drops that copy, and this — reached from the
				// cron, from every profile change and from the first reader
				// who finds it missing — used to put it straight back, so a
				// local account a moderator had suspended went on being served
				// a few minutes later with an empty outbox.
				$this->cacheActorsRequest->deleteCacheById($actor->getId());

				return;
			}

			try {
				$this->updateCacheLocalActorName($actor);
			} catch (NoUserException $e) {
				return;
			}

			$this->publishHandleOnProfile($actor);

			// Whose channel this is, which PeerTube demands before it will
			// take a video the channel owns: it fetches the `Group` and looks
			// for a `Person` here, refusing the video outright when there is
			// none. Read from the channel table rather than passed in, because
			// this method is reached from half a dozen places and none of the
			// others knows or should know what a channel is.
			$owner = $this->channelsRequest->ownerOf($actor->getId());
			if ($owner !== '') {
				$actor->setAttributedToActors([['type' => Person::TYPE, 'id' => $owner]]);
			}

			// A team account and a channel have no Nextcloud user and therefore
			// no avatar to mirror: the picture route would be asked for a user
			// id nothing can resolve. They get the app's default until somebody
			// uploads one, which is honest rather than a broken image.
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

			// the details this rebuilds are only the counts: the actors row
			// carries none, and the write is wholesale. What was already on the
			// cached copy and has to survive it — the verified-link verdicts —
			// is carried over by ActorService::cacheLocalActor().
			$this->addLocalActorDetailCount($actor);
			$this->actorService->cacheLocalActor($actor);
		} catch (ActorDoesNotExistException $e) {
			// every caller has an account in hand and passes its handle, so
			// there is no ordinary case for this: swallowing it in silence hid
			// a caller passing a user id instead, and with it the profile
			// updates of every account whose handle is not its user id
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
			// the four aggregate queries. They are the reason this must not be
			// on a write path: `countFollowers()` is a `COUNT(*)` over
			// `social_follow`, which for an account with a million followers
			// is a million index entries counted so that a number on a profile
			// can go up by one. Reached from the cron's local-account walk,
			// which is bounded, and from the places a person explicitly asks
			// for a refresh.
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
			// never incremental: it is bounded by how many people are waiting
			// for an answer, which is small, and it has to be right the moment
			// the screen that shows it is opened
			'follow_requests' => $this->followsRequest->countPendingRequests($actor->getId()),
			'post' => $count['post'],
		]);
		$actor->setDetail(Details::LAST_POST, $lastPostCreation);
	}

	/**
	 * Moves one of an account's counters by one, for the paths that know
	 * exactly what changed.
	 *
	 * A follow arriving is `+1 followers` on the followed account and
	 * `+1 following` on the follower; a post written is `+1 posts`. Each used
	 * to recompute all three with aggregate queries. Where the counter has
	 * never been counted the bump is skipped rather than applied to a number
	 * nobody established — see `CacheActorsRequest::bumpCount()`.
	 */
	public function bumpActorCount(string $actorId, string $column, int $by = 1): void {
		if ($actorId === '') {
			return;
		}

		try {
			$this->cacheActorsRequest->bumpCount($actorId, $column, $by);
		} catch (Exception $e) {
			// a counter on a profile is not worth failing a follow or a post
			// over; the cron's walk reconciles it
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
		// A team account belongs to a group rather than to a person, so there
		// is no Nextcloud account to read a display name off. Its name is the
		// group's, which is the only name it has — and returning here rather
		// than raising is what keeps it in the actor cache: an actor that is
		// not cached is one every read of it 404s on, which is what happened
		// the first time a team posted anything.
		if (str_starts_with($actor->getUserId(), 'team/')) {
			if ($actor->getName() === '') {
				$actor->setName(substr($actor->getUserId(), strlen('team/')));
			}

			return;
		}

		// A channel belongs to an account rather than to a person, so there is
		// no Nextcloud user here either, and the same rule applies for the same
		// reason: an actor that is not cached is one every read of it 404s on,
		// which is exactly what happened to the first channel ever made.
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

		// A Fediverse actor is public by definition — creating one is the act of
		// publishing a profile — so anything short of an explicitly private
		// display name is fair to federate. Requiring SCOPE_PUBLISHED meant
		// that on a default install (where the scope is SCOPE_FEDERATED)
		// nobody's display name ever reached their actor, and every client fell
		// back to `preferredUsername`, i.e. the raw Nextcloud user id.
		$publishable = [
			IAccountManager::SCOPE_PUBLISHED,
			IAccountManager::SCOPE_FEDERATED,
		];

		try {
			$account = $this->accountManager->getAccount($user);
			$displayNameProperty = $account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME);

			if (!in_array($displayNameProperty->getScope(), $publishable, true)) {
				// deliberately kept private or instance-local: leave whatever
				// the actor already carries alone rather than publishing it
				return;
			}

			$displayName = $displayNameProperty->getValue();
		} catch (Exception $e) {
			// The property could not be read at all, which is not the same as
			// the user having asked for privacy: fall back to the display name
			// the rest of Nextcloud already shows.
			$this->logger->warning(
				'could not read the account of a local actor, falling back to its display name',
				['userId' => $actor->getUserId(), 'exception' => $e]
			);
			$displayName = $user->getDisplayName();
		}

		if ($displayName !== '') {
			$actor->setName($displayName);
		}
	}

	/**
	 * Whether one Nextcloud profile property may go on a Fediverse actor.
	 *
	 * The same rule the display name has followed for a while, pulled out so
	 * the picture follows it too: a Fediverse actor is public by definition --
	 * creating one is the act of publishing a profile -- so anything short of
	 * an explicitly private or instance-local setting is fair to federate.
	 * Requiring `SCOPE_PUBLISHED` would mean that on a default install, where
	 * the scope is `SCOPE_FEDERATED`, nothing ever reached the actor at all.
	 *
	 * The picture was published whatever the account said, which is what
	 * somebody who sets it to "local" is asking this app not to do -- and the
	 * one thing about the setting a reader can see from another server.
	 *
	 * Unknown properties and unreadable accounts answer true: the caller then
	 * behaves as it did before this existed, which is the safe direction for a
	 * profile that is already public.
	 */
	private function mayPublish(Person $actor, string $property): bool {
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
	 * profile, where the profile has nothing there yet.
	 *
	 * The field is Nextcloud's own place for "where I am on the Fediverse", and
	 * an account created by this app is an answer to that question the person
	 * did not have to type. It is filled in only when empty: a value already
	 * there is theirs -- a Mastodon account they have had for years, most likely
	 * -- and this app is not the one to overwrite it. The field's visibility is
	 * left exactly as it was, for the same reason.
	 *
	 * Writing the account fires `UserUpdatedEvent`, whose listener calls back
	 * into the cache refresh this runs from. That second pass finds the field
	 * filled and writes nothing, so the loop is one bounce long and then stops.
	 */
	private function publishHandleOnProfile(Person $actor): void {
		$user = $this->userManager->get($actor->getUserId());
		if ($user === null) {
			return;
		}

		try {
			$account = $this->accountManager->getAccount($user);
			$property = $account->getProperty(IAccountManager::PROPERTY_FEDIVERSE);
			if (trim($property->getValue()) !== '') {
				return;
			}

			$property->setValue($actor->getPreferredUsername() . '@' . $this->configService->getSocialAddress());
			$this->accountManager->updateAccount($account);
		} catch (Exception $e) {
			// a profile that cannot be written is a profile that stays as it
			// was; the actor is fine either way
			$this->logger->debug('could not publish the handle on the Nextcloud profile', [
				'userId' => $actor->getUserId(), 'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Whether a person may take this handle for the account they are about
	 * to create. Their own user id, or anything the pattern allows that is
	 * nobody else's: not another Nextcloud user's id (which would let one
	 * person publish as another), not a handle an actor already holds.
	 *
	 * @throws InvalidHandleException
	 * @throws AccountAlreadyExistsException
	 */
	public function assertHandleAvailable(string $userId, string $username): void {
		$this->checkActorUsername($username);
		if (strtolower($username) !== strtolower($userId) && $this->userManager->userExists($username)) {
			throw new InvalidHandleException('that is another person\'s user name on this Nextcloud');
		}
		try {
			$this->actorsRequest->getFromUsername($username);
		} catch (ActorDoesNotExistException $e) {
			return;
		}
		throw new AccountAlreadyExistsException('that handle is taken');
	}

	/**
	 * The fediverse handle a person's Nextcloud profile names, or ''.
	 *
	 * Somebody who has had an account on Mastodon for years does not need one
	 * here; what their colleagues need is to find them, and the profile field
	 * is where `ColleagueService` looks.
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
			throw new InvalidHandleException('a fediverse account is written user@server.example');
		}
		try {
			if (strcasecmp(substr($handle, strpos($handle, '@') + 1), $this->configService->getSocialAddress()) === 0) {
				throw new InvalidHandleException('that would be an account on this server; create one instead');
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
	 * A Fediverse handle is not a Nextcloud user id.
	 *
	 * It ends up in `preferredUsername`, in the actor's URL and in the
	 * `acct:` WebFinger subject, so it has to survive being put in a URL and
	 * has to match what other implementations accept — Mastodon and most
	 * others allow letters, digits and `_`, with `.` and `-` inside. A user id
	 * that does not (an LDAP UUID, an e-mail address, anything with a space)
	 * produces an actor no remote server can resolve, which used to happen
	 * silently because this method validated nothing at all.
	 *
	 * @throws InvalidHandleException
	 */
	private function checkActorUsername(string $username): void {
		if ($username === '' || strlen($username) > self::HANDLE_MAX_LENGTH) {
			throw new InvalidHandleException(
				'a Fediverse handle must be between 1 and ' . self::HANDLE_MAX_LENGTH . ' characters'
			);
		}

		if (preg_match(self::HANDLE_PATTERN, $username) !== 1) {
			throw new InvalidHandleException(
				'"' . $username . '" cannot be used as a Fediverse handle: only letters, digits and '
				. 'underscore are allowed, with dot and dash inside'
			);
		}
	}

	/**
	 * Derive a usable Fediverse handle from a Nextcloud user id.
	 *
	 * Called by the paths that create an account on the user's behalf, where
	 * nobody got to choose a handle. The user id is used as-is when it already
	 * qualifies, so existing installs keep the handles they have; otherwise it
	 * is folded down to something resolvable and a numeric suffix is added
	 * until it is free.
	 */
	public function generateHandleFromUserId(string $userId): string {
		$candidate = $userId;
		try {
			$this->checkActorUsername($candidate);
		} catch (InvalidHandleException $e) {
			$candidate = strtolower($userId);
			// anything outside the allowed set becomes an underscore, then
			// runs of underscores collapse and the edges are trimmed
			$candidate = preg_replace('/[^a-z0-9_.-]+/', '_', $candidate) ?? '';
			$candidate = preg_replace('/_{2,}/', '_', $candidate) ?? '';
			$candidate = trim($candidate, '_.-');
			$candidate = substr($candidate, 0, self::HANDLE_MAX_LENGTH);

			if ($candidate === '' || preg_match(self::HANDLE_PATTERN, $candidate) !== 1) {
				// nothing usable survived (a purely non-latin id, say): fall
				// back to something stable and unique for this user
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
	 * Asks the database for those rows only: reading every local account, key
	 * pair included, to find the handful marked deleted is a memory-limit
	 * fatal on a large instance, which kills the whole cron run rather than
	 * this step.
	 *
	 * @return int
	 * @throws Exception
	 */
	public function manageDeletedActors(): int {
		$deleted = 0;
		foreach ($this->actorsRequest->getDeletedBefore(time() - self::TIME_RETENTION, self::DELETED_ACTOR_BATCH) as $item) {
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
	 * Refreshes the cached copy of the local accounts, a page at a time.
	 *
	 * It used to read **every** local account into one array on every pass,
	 * twelve minutes apart, and do a dozen queries and an avatar read for each.
	 * At a million accounts the array does not fit in memory; at a hundred
	 * thousand it does and the work behind it is hours, in a job that fires
	 * every twelve minutes — and nothing says so, the cron simply falls behind.
	 *
	 * Now it walks: `LOCAL_ACTOR_BATCH` accounts a page, at most
	 * `LOCAL_ACTOR_PAGES` pages a pass, stopping at the pass deadline, and
	 * remembering in app config where it got to. A large instance therefore
	 * takes many passes to go round, which is the correct behaviour for a
	 * refresh nobody is waiting on — and the things that actually change
	 * (a display name, an avatar, a follow, a post) already refresh the row
	 * where they happen.
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
				// the column the page is ordered by; the model carries the id
				// and the prim is its md5, as everywhere else in this app
				$after = md5($item->getId());
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
	 * @return int
	 * @throws Exception
	 */
	public function blindKeyRotation(): int {
		$update = $this->actorsRequest->getAll();
		$count = 0;
		foreach ($update as $actor) {
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
