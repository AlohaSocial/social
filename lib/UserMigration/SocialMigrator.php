<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AvatarService;
use OCA\Social\Service\BannerService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\InterestService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\StreamActionService;
use OCA\Social\Tools\Csv;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use OCP\UserMigration\ISizeEstimationMigrator;
use OCP\UserMigration\TMigratorBasicVersionHandling;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * One user's Social data in a Nextcloud account export, and back out of one.
 *
 * What travels is what the account is and chose: profile and flags, follows
 * and followers, blocks and mutes, lists and blocked servers, its own posts
 * with their media and banner, bookmarks and favourites. Lists of accounts
 * use Mastodon's CSV shapes and media Mastodon's archive layout, so the
 * archive is useful outside Nextcloud too. The Bluesky account travels under
 * `bluesky/` (export only, see exportBluesky()).
 *
 * What does not travel: the private key (encrypted at rest by
 * `PrivateKeyCipher`, unrevocable in ActivityPub; an imported account gets a
 * new key pair and keeps continuity through `alsoKnownAs` and a `Move`);
 * other people's posts; and moderation decisions, reports, queued requests,
 * OAuth clients and tokens, which a user must not shed or carry over.
 *
 * The import federates nothing but the follows; see import().
 */
class SocialMigrator implements IMigrator, ISizeEstimationMigrator {
	use TMigratorBasicVersionHandling;

	/** How many rows one read asks for, everywhere in here. */
	public const PAGE = 50;

	/**
	 * The block and mute lists are read in one query (`getByActor()` takes no
	 * offset), so the read is capped and the cap reported.
	 */
	public const RELATIONS_LIMIT = 5000;

	private const PATH_ROOT = Application::APP_ID . '/';
	/**
	 * What says an archive holds Social data at all; public, since the Migration
	 * page's `ZipImportSource` must agree on it.
	 */
	public const PATH_ACTOR_PUBLIC = self::PATH_ROOT . 'actor.json';
	private const PATH_ACTOR = self::PATH_ACTOR_PUBLIC;
	private const PATH_FOLLOWING = self::PATH_ROOT . 'following_accounts.csv';
	private const PATH_FOLLOWERS = self::PATH_ROOT . 'followers.csv';
	private const PATH_BLOCKS = self::PATH_ROOT . 'blocked_accounts.csv';
	private const PATH_MUTES = self::PATH_ROOT . 'muted_accounts.csv';
	private const PATH_LISTS = self::PATH_ROOT . 'lists.csv';
	private const PATH_DOMAIN_BLOCKS = self::PATH_ROOT . 'blocked_domains.csv';
	private const PATH_BOOKMARKS = self::PATH_ROOT . 'bookmarks.csv';
	private const PATH_LIKES = self::PATH_ROOT . 'likes.csv';
	private const PATH_OUTBOX = self::PATH_ROOT . 'outbox.json';
	/** For you: what was learned and chosen, and the reader's switches. */
	private const PATH_INTERESTS = self::PATH_ROOT . 'interests.json';
	/** The Bluesky account: repository, blobs, the DID's log and document, see `BlueskyArchiveService`. */
	private const PATH_BLUESKY = self::PATH_ROOT . BlueskyArchiveService::ROOT;
	/**
	 * Where the files sit, relative to the app's folder in the archive, in
	 * Mastodon's export layout: `media_attachments/files/<id>/original.<ext>`
	 * for an attachment, `media_attachments/avatar.<ext>` and `header.<ext>` for
	 * the profile. Relative, as that is what `outbox.json` URLs are rewritten to.
	 */
	private const PATH_MEDIA = 'media_attachments/';
	private const PATH_MEDIA_FILES = self::PATH_MEDIA . 'files/';

	/**
	 * The file extension each stored type gets in the archive. Canonical rather
	 * than whatever the upload was called: the name inside the archive only has
	 * to say what the file is.
	 */
	private const EXTENSIONS = [
		'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
		'image/avif' => 'avif', 'image/heic' => 'heic', 'image/heif' => 'heif',
		'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
		'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus',
		'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac', 'audio/aac' => 'aac',
	];

	/**
	 * What one stored file of each kind is taken to weigh, in KiB, for the size
	 * estimate. The table stores no size, so this is counted rather than
	 * measured; see `mediaSize()`.
	 */
	private const SIZE_IMAGE = 512;
	private const SIZE_AUDIO = 5120;
	private const SIZE_VIDEO = 40960;

	/** The tail of a media link this instance serves: the stored copy's uuid. */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The header over `muted_accounts.csv`: Mastodon's two columns, and this
	 * app's third, when the mute runs out, which readers that go by header or
	 * position ignore.
	 */
	private const MUTES_HEADER = 'Account address,Hide notifications,Expires at';

	public function __construct(
		private IL10N $l10n,
		private AccountService $accountService,
		private AccountRelationService $accountRelationService,
		private MigrationService $migrationService,
		private CacheActorService $cacheActorService,
		private CacheDocumentService $cacheDocumentService,
		private DocumentService $documentService,
		private BannerService $bannerService,
		private AvatarService $avatarService,
		private FollowsRequest $followsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private StreamRequest $streamRequest,
		private StreamActionService $streamActionService,
		private InterestService $interestService,
		private IdentityService $identities,
		private BlueskyArchiveService $blueskyArchive,
		private ITempManager $tempManager,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getId(): string {
		return Application::APP_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getDisplayName(): string {
		return Application::APP_NAME_DISPLAY;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getDescription(): string {
		return $this->l10n->t(
			'Your Fediverse profile, the accounts you follow, block and mute, your own posts'
			. ' with the pictures and videos in them, your bookmarks and your favourites,'
			. ' and your Bluesky account where you have one. Your private keys stay behind.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Counted rather than measured: four cheap counts, a kilobyte of JSON per
	 * post, and the media, which decides the answer.
	 */
	#[\Override]
	public function getEstimatedExportSize(IUser $user): int|float {
		try {
			$actor = $this->accountService->getActorFromUserId($user->getUID());
		} catch (Throwable $e) {
			return 0;
		}

		$size = 4; // the actor document
		$size += $this->streamRequest->countNotesFromActorId($actor->getId());
		// a handle is a line of some 40 bytes in a csv
		$size += intdiv(
			$this->followsRequest->countFollowing($actor->getId())
			+ $this->followsRequest->countFollowers($actor->getId()), 25
		);
		$size += $this->mediaSize($actor);

		return (int)ceil($size);
	}

	/**
	 * What the account's own files are worth in the estimate, in KiB: a count
	 * per kind times a typical size, since `social_cache_documents` has no size
	 * column and the right order of magnitude is enough.
	 */
	private function mediaSize(Person $actor): int {
		try {
			$counts = $this->documentService->countStoredCopies($actor->getPreferredUsername());
		} catch (Throwable $e) {
			$this->logger->debug('cannot count the media of an account for the export estimate', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);

			return 0;
		}

		$size = 0;
		foreach ($counts as $mediaType => $count) {
			$size += $count * match (explode('/', $mediaType)[0]) {
				'video' => self::SIZE_VIDEO,
				'audio' => self::SIZE_AUDIO,
				default => self::SIZE_IMAGE,
			};
		}

		return $size;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
		try {
			$actor = $this->accountService->getActorFromUserId($user->getUID());
		} catch (Throwable $e) {
			$output->writeln($user->getUID() . ' has no Aloha Social account, nothing to export…');

			return;
		}

		$this->exportActor($actor, $exportDestination, $output);
		$this->exportFollows($actor, $exportDestination, $output);
		$this->exportRelations($actor, $exportDestination, $output);
		$this->exportLists($user->getUID(), $exportDestination, $output);
		$this->exportMarks($actor, $exportDestination, $output);
		$this->exportInterests($actor, $exportDestination, $output);
		$this->exportOutbox($actor, $exportDestination, $output);
		$this->exportBluesky($actor, $exportDestination, $output);
	}

	/**
	 * The Bluesky account, where the person has one here: the files
	 * `BlueskyArchiveService` writes, under `bluesky/`. Never read back on
	 * import, since a Bluesky account arrives by a move; they are for keeping.
	 */
	private function exportBluesky(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$identity = $this->identities->forActor($actor, false);
		} catch (Throwable $e) {
			$this->logger->notice('cannot read the Bluesky identity of an account for its export', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);

			return;
		}
		if (!BlueskyArchiveService::exportable($identity)) {
			return;
		}
		try {
			$written = $this->blueskyArchive->write($identity, $exportDestination, self::PATH_BLUESKY);
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Bluesky account', 0, $e);
		}
		$output->writeln('Exported the Bluesky account ' . $identity->did . ' with ' . $written['blobs'] . ' blob(s) to ' . self::PATH_BLUESKY . '…');
	}

	/**
	 * For you, as the JSON `InterestService::export()` writes. Not the
	 * posts the reader hid: those name posts on this server, which mean
	 * nothing anywhere else.
	 */
	private function exportInterests(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$data = $this->interestService->export($actor);
			$exportDestination->addFileContents(
				self::PATH_INTERESTS, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			);
			$output->writeln('Exported ' . count($data['interests']) . ' interest(s) to ' . self::PATH_INTERESTS . '…');
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social interests', 0, $e);
		}
	}

	/**
	 * The actor, without its private key, as JSON for the import to read back
	 * rather than as an actor document full of this server's URLs.
	 *
	 * The profile pictures travel as files beside it, read off the cached actor
	 * that `BannerService` writes: the banner always, the avatar only when it is
	 * this app's own, since a local account's is the Nextcloud account's, which
	 * core's migrator carries.
	 */
	private function exportActor(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		$output->writeln('Exporting the Aloha Social actor in ' . self::PATH_ACTOR . '…');

		$pictures = $this->profilePictures($actor);
		$avatarFile = $this->exportProfileImage($pictures->getAvatar(), 'avatar', $exportDestination);
		// `getHeader()` falls back to the avatar when there is no banner, which
		// would put the same file in the archive twice under two names
		$headerFile = ($pictures->getHeader() === $pictures->getAvatar())
			? '' : $this->exportProfileImage($pictures->getHeader(), 'header', $exportDestination);
		foreach (['avatar' => $avatarFile, 'banner' => $headerFile] as $what => $path) {
			if ($path !== '') {
				$output->writeln('Exported the ' . $what . ' to ' . self::PATH_ROOT . $path . '…');
			}
		}

		try {
			$exportDestination->addFileContents(self::PATH_ACTOR, json_encode(array_filter([
				'avatarFile' => $avatarFile,
				'headerFile' => $headerFile,
			]) + [
				'id' => $actor->getId(),
				'account' => $actor->getAccount(),
				'preferredUsername' => $actor->getPreferredUsername(),
				'name' => $actor->getName(),
				'summary' => $actor->getDescription(),
				'fields' => $actor->getFields(),
				'locked' => $actor->isLocked(),
				'discoverable' => $actor->isDiscoverable(),
				'indexable' => $actor->isIndexable(),
				'bot' => $actor->isBot(),
				'sensitive' => $actor->isSensitive(),
				'privacy' => $actor->getPrivacy(),
				'language' => $actor->getLanguage(),
				'avatar' => $pictures->getAvatar(),
				'header' => $pictures->getHeader(),
				'alsoKnownAs' => $actor->getAlsoKnownAs(),
				'movedTo' => $actor->getMovedTo(),
				// the public half only; see the class comment
				'publicKey' => $actor->getPublicKey(),
				'created' => $actor->getCreation(),
			], JSON_THROW_ON_ERROR));
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social actor', 0, $e);
		}
	}

	/**
	 * The cached copy of a local account, which carries its pictures, or the
	 * account itself where there is none to read.
	 */
	private function profilePictures(Person $actor): Person {
		try {
			return $this->accountService->getCachedLocalActor($actor->getPreferredUsername());
		} catch (Throwable $e) {
			$this->logger->notice('cannot read the cached copy of an account for its export', [
				'actor' => $actor->getId(), 'exception' => $e,
			]);

			return $actor;
		}
	}

	private function exportFollows(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$following = $this->handlesOfFollows(
				$actor,
				fn (string $cursor): array => $this->followsRequest->getFollowingByActorId(
					$actor->getId(), self::PAGE, 0, $cursor
				)
			);
			$exportDestination->addFileContents(
				self::PATH_FOLLOWING, MigrationService::exportFollowsCsv($following)
			);
			$output->writeln('Exported ' . count($following) . ' follow(s) to ' . self::PATH_FOLLOWING . '…');

			$followers = $this->handlesOfFollows(
				$actor,
				fn (string $cursor): array => $this->followsRequest->getFollowersByActorId(
					$actor->getId(), self::PAGE, 0, $cursor
				)
			);
			$exportDestination->addFileContents(
				self::PATH_FOLLOWERS, MigrationService::exportFollowsCsv($followers)
			);
			$output->writeln(
				'Exported ' . count($followers) . ' follower(s) to ' . self::PATH_FOLLOWERS
				. ' — a record, not something an import can re-create: a follower follows again,'
				. ' or their server is told by the Move of the account they followed…'
			);
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social follows', 0, $e);
		}
	}

	/**
	 * The handles of a paged follow query, in order, leaving out accounts never
	 * cached (a bare URL is no handle) and the account's own loopback follow
	 * (`FollowsRequest::generateLoopbackAccount()`), which Mastodon's importer
	 * would try to follow.
	 *
	 * @param callable(string):Follow[] $page the page after a cursor, '' for the first
	 *
	 * @return string[]
	 */
	private function handlesOfFollows(Person $actor, callable $page): array {
		$handles = [];
		$cursor = '';

		while (true) {
			$follows = $page($cursor);
			if ($follows === []) {
				return $handles;
			}

			foreach ($follows as $follow) {
				$account = $follow->hasActor() ? $follow->getActor()?->getAccount() ?? '' : '';
				if (strcasecmp($account, $actor->getAccount()) === 0) {
					continue;
				}
				if ($account === '') {
					$this->logger->debug('a follow has no cached account, leaving it out of the export', [
						'actor' => $follow->getActorId(), 'object' => $follow->getObjectId(),
					]);

					continue;
				}

				$handles[] = $account;
			}

			$cursor = FollowsRequest::cursorAfter(end($follows));
		}
	}

	/**
	 * Blocks and mutes, in Mastodon's two files: `blocked_accounts.csv`, bare
	 * handles, and `muted_accounts.csv`, with whether notifications are hidden
	 * and when the mute runs out.
	 */
	private function exportRelations(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$blocks = $this->actorRelationRequest->getByActor(
				$actor->getId(), ActorRelation::TYPE_BLOCK, self::RELATIONS_LIMIT
			);
			$lines = [];
			foreach ($blocks as $block) {
				$handle = $this->handleOf($block->getObjectId());
				if ($handle !== '') {
					$lines[] = Csv::cell($handle);
				}
			}
			$exportDestination->addFileContents(self::PATH_BLOCKS, $this->csv($lines));
			$output->writeln('Exported ' . count($lines) . ' block(s) to ' . self::PATH_BLOCKS . '…');
			$this->warnOnCap($blocks, 'blocks', $output);

			$mutes = $this->actorRelationRequest->getByActor(
				$actor->getId(), ActorRelation::TYPE_MUTE, self::RELATIONS_LIMIT
			);
			$expiries = $this->accountRelationService->muteExpiries(
				$actor->getId(),
				array_map(static fn (ActorRelation $mute): string => $mute->getObjectId(), $mutes)
			);
			$lines = [];
			foreach ($mutes as $mute) {
				$handle = $this->handleOf($mute->getObjectId());
				if ($handle !== '') {
					$expiresAt = $expiries[$mute->getObjectId()] ?? 0;
					$lines[] = Csv::cell($handle) . ',' . ($mute->isNotifications() ? 'false' : 'true')
						. ',' . ($expiresAt > 0 ? gmdate('c', $expiresAt) : '');
				}
			}
			$exportDestination->addFileContents(
				self::PATH_MUTES, $this->csv(array_merge([self::MUTES_HEADER], $lines))
			);
			$output->writeln('Exported ' . count($lines) . ' mute(s) to ' . self::PATH_MUTES . '…');
			$this->warnOnCap($mutes, 'mutes', $output);
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social blocks and mutes', 0, $e);
		}
	}

	/**
	 * The lists and the blocked servers as Mastodon's `lists.csv` and
	 * `blocked_domains.csv`, by the same code as the Migration page's downloads.
	 */
	private function exportLists(string $userId, IExportDestination $exportDestination, OutputInterface $output): void {
		try {
			foreach ([
				'lists' => self::PATH_LISTS,
				'domain_blocks' => self::PATH_DOMAIN_BLOCKS,
			] as $kind => $path) {
				$csv = $this->migrationService->exportCsv($userId, $kind)[1] ?? '';
				$exportDestination->addFileContents($path, $csv);
				$output->writeln('Exported ' . substr_count($csv, "\n") . ' line(s) to ' . $path . '…');
			}
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social lists and blocked domains', 0, $e);
		}
	}

	/**
	 * What the user kept: the bookmarked and favourited posts, as their URLs, as
	 * Mastodon's `bookmarks.csv` has them.
	 */
	private function exportMarks(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			foreach ([
				ProbeOptions::BOOKMARKS => self::PATH_BOOKMARKS,
				ProbeOptions::FAVOURITES => self::PATH_LIKES,
			] as $probe => $path) {
				$urls = [];
				foreach ($this->posts($actor, $probe) as $post) {
					$urls[] = $post->getId();
				}

				$exportDestination->addFileContents($path, $this->csv($urls));
				$output->writeln('Exported ' . count($urls) . ' entr(y|ies) to ' . $path . '…');
			}
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social bookmarks and favourites', 0, $e);
		}
	}

	/**
	 * The user's own posts, as an ActivityPub `OrderedCollection`, written a page
	 * at a time through a temporary file (as `CalendarMigrator` does), with
	 * `totalItems` last.
	 *
	 * The posts' files go into the archive as they are walked, each attachment's
	 * `url` rewritten to its file and the old address kept as `originalUrl`.
	 */
	private function exportOutbox(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		$output->writeln('Exporting the Aloha Social posts to ' . self::PATH_OUTBOX . '…');

		$path = $this->tempManager->getTemporaryFile();
		$file = fopen($path, 'w+');
		if ($file === false) {
			throw new SocialMigratorException('Could not open a temporary file for ' . self::PATH_OUTBOX);
		}

		try {
			fwrite($file, '{"@context":"' . ACore::CONTEXT_ACTIVITYSTREAMS
				. '","id":' . json_encode($actor->getId() . '/outbox', JSON_THROW_ON_ERROR)
				. ',"type":"OrderedCollection","orderedItems":[');

			$count = 0;
			$files = 0;
			foreach ($this->posts($actor, ProbeOptions::ACCOUNT) as $post) {
				$item = $post->jsonSerialize();
				// the collection carries the context once
				unset($item['@context']);
				$files += $this->exportAttachments($item, $post->getAttachments(), $exportDestination);
				fwrite($file, ($count > 0 ? ',' : '') . json_encode($item, JSON_THROW_ON_ERROR));
				$count++;
			}

			fwrite($file, '],"totalItems":' . $count . '}');
			rewind($file);
			$exportDestination->addFileAsStream(self::PATH_OUTBOX, $file);
			$output->writeln('Exported ' . $count . ' post(s) and ' . $files . ' file(s) of theirs…');
		} catch (Throwable $e) {
			throw new SocialMigratorException('Could not export the Social posts', 0, $e);
		} finally {
			fclose($file);
		}
	}

	/**
	 * One timeline of this actor, page by page, oldest last, paged on the last
	 * row's `nid` (`ProbeOptions::setMaxId()`), so a post added meanwhile cannot
	 * make it loop.
	 *
	 * @return iterable<Stream>
	 */
	private function posts(Person $actor, string $probe): iterable {
		$this->streamRequest->setViewer($actor);
		$maxId = 0;

		while (true) {
			$options = new ProbeOptions();
			$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
				->setProbe($probe)
				->setAccountId($actor->getId())
				->setLimit(self::PAGE);
			if ($maxId > 0) {
				$options->setMaxId($maxId);
			}

			$posts = $this->streamRequest->getTimeline($options);
			if ($posts === []) {
				return;
			}

			foreach ($posts as $post) {
				yield $post;
			}

			$last = end($posts);
			$nid = ($last === false) ? 0 : $last->getNid();
			if ($nid <= 0 || ($maxId > 0 && $nid >= $maxId)) {
				// a row we cannot page on: stop rather than ask for the same page again
				return;
			}

			$maxId = $nid;
		}
	}

	/**
	 * One post's attachments, copied into the archive, and the post rewritten to
	 * point at them. Only what this instance has bytes of: streamed videos
	 * (`Document::COPY_STREAMED`) and swept copies keep their URL. The serialised
	 * attachments and `getAttachments()` are in the same order, so the n-th id
	 * names the n-th file.
	 *
	 * @param array<string, mixed> $item the post as it will be written
	 * @param array<MediaAttachment|array<string, mixed>> $attachments
	 *
	 * @return int how many files were written
	 */
	private function exportAttachments(
		array &$item,
		array $attachments,
		IExportDestination $exportDestination,
	): int {
		if (!isset($item['attachment']) || !is_array($item['attachment'])) {
			return 0;
		}

		$item['attachment'] = array_values($item['attachment']);
		$attachments = array_values($attachments);
		$written = 0;

		foreach ($item['attachment'] as $index => $wire) {
			if (!is_array($wire)) {
				continue;
			}

			$url = (string)($wire['url'] ?? '');
			$uuid = self::storedCopyOf($url);
			if ($uuid === '') {
				continue;
			}

			// the attachment id names the folder, and only a row number is accepted
			// in an archive path
			$media = $attachments[$index] ?? null;
			$id = ($media instanceof MediaAttachment) ? $media->getId() : '';
			$folder = (preg_match('/^[0-9]+$/', $id) === 1) ? $id : $uuid;
			$path = self::PATH_MEDIA_FILES . $folder . '/original.'
				. self::extensionOf((string)($wire['mediaType'] ?? ''), $url);

			if (!$this->copyStoredCopy($uuid, $path, $exportDestination)) {
				continue;
			}

			$item['attachment'][$index]['url'] = $path;
			$item['attachment'][$index]['originalUrl'] = $url;
			$written++;
		}

		return $written;
	}

	/**
	 * The banner or the avatar, where this app has a file of it.
	 *
	 * @return string the path in the archive, relative to the app's folder, or ''
	 */
	private function exportProfileImage(
		string $url,
		string $what,
		IExportDestination $exportDestination,
	): string {
		$uuid = self::storedCopyOf($url);
		if ($uuid === '') {
			return '';
		}

		$path = self::PATH_MEDIA . $what . '.' . self::extensionOf('', $url);

		return $this->copyStoredCopy($uuid, $path, $exportDestination) ? $path : '';
	}

	/**
	 * The bytes of one stored copy into the archive, streamed, so a video costs
	 * no more memory than a sentence. A file storage no longer has is skipped
	 * (the attachment keeps its URL); a destination that cannot be written is
	 * left to the caller to fail on.
	 *
	 * @return bool whether the file was written
	 */
	private function copyStoredCopy(
		string $uuid,
		string $path,
		IExportDestination $exportDestination,
	): bool {
		try {
			$stream = $this->cacheDocumentService->getFromUuid($uuid)->read();
		} catch (Throwable $e) {
			$this->logger->notice('a media file of the export is not in storage any more', [
				'uuid' => $uuid, 'exception' => $e,
			]);

			return false;
		}

		if (!is_resource($stream)) {
			return false;
		}

		try {
			$exportDestination->addFileAsStream(self::PATH_ROOT . $path, $stream);
		} finally {
			fclose($stream);
		}

		return true;
	}

	/**
	 * The uuid of the copy this instance stored for a media URL, or '' when the
	 * URL names no copy of ours — a streamed row, a picture on another server,
	 * the Nextcloud account avatar.
	 */
	private static function storedCopyOf(string $url): string {
		if ($url === '') {
			return '';
		}

		$path = (string)parse_url($url, PHP_URL_PATH);
		$name = pathinfo($path, PATHINFO_FILENAME);

		return (preg_match(self::UUID, $name) === 1) ? $name : '';
	}

	/**
	 * What to call the file in the archive: the extension the media type
	 * implies, else the URL's, guessed as the client entity guesses, since a
	 * profile picture is known only by its URL.
	 */
	private static function extensionOf(string $mediaType, string $url): string {
		if ($mediaType === '') {
			$mediaType = MediaAttachment::guessMediaType('', $url);
		}

		if (isset(self::EXTENSIONS[$mediaType])) {
			return self::EXTENSIONS[$mediaType];
		}

		$extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

		return (preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1) ? $extension : 'bin';
	}

	/** The handle of an actor this server has cached, or '' when it has not. */
	private function handleOf(string $actorId): string {
		try {
			return $this->cacheActorService->getFromId($actorId)->getAccount();
		} catch (Throwable $e) {
			$this->logger->debug('cannot resolve an account for the export, leaving it out', [
				'actorId' => $actorId, 'exception' => $e,
			]);

			return '';
		}
	}

	/**
	 * @param string[] $lines
	 */
	private function csv(array $lines): string {
		return $lines === [] ? '' : implode("\n", $lines) . "\n";
	}

	private function warnOnCap(array $rows, string $what, OutputInterface $output): void {
		if (count($rows) >= self::RELATIONS_LIMIT) {
			$output->writeln(
				'Only the first ' . self::RELATIONS_LIMIT . ' ' . $what . ' were exported…'
			);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Safe on a server where the account exists: the actor is created only when
	 * missing, every write is idempotent, and nothing is deleted.
	 *
	 * Only the follows federate, since a Follow is the only way a follow exists.
	 * A restored bio sends the usual Update to this account's followers (none on
	 * a fresh account). Blocks and mutes go straight to the relation table, not
	 * through `RelationshipService`, which would federate a `Block` again. A
	 * missing file is no failure: the import takes what the archive carries.
	 */
	#[\Override]
	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		if ($importSource->getMigratorVersion($this->getId()) === null) {
			$output->writeln('No version for ' . static::class . ', skipping import…');

			return;
		}

		if (!$this->archiveHasSocialData($importSource)) {
			// every migrator that ran gets a version recorded; an empty folder must
			// not create, and publish, a Fediverse identity
			$output->writeln('No Aloha Social data in this archive, nothing to import…');

			return;
		}

		$userId = $user->getUID();
		try {
			// creates one where the server has none, which is the ordinary
			// case for an account that has just been imported
			$actor = $this->accountService->getActorFromUserId($userId, true);
		} catch (Throwable $e) {
			$this->logger->warning('cannot import Social data: no actor for the user', [
				'userId' => $userId, 'exception' => $e,
			]);
			$output->writeln(
				$userId . ' has no Aloha Social account here and none could be created (' . $e->getMessage()
				. '), skipping the Aloha Social import…'
			);

			return;
		}

		$this->importActor($userId, $importSource, $output);
		$this->importFollows($userId, $importSource, $output);
		$this->importRelations($actor, $importSource, $output);
		$this->importLists($userId, $importSource, $output);
		$this->importMarks($actor, $importSource, $output);
		$this->importInterests($actor, $importSource, $output);
		$this->importOutbox($actor, $importSource, $output);
	}

	/** Takes For you back; an archive without it has none. */
	private function importInterests(Person $actor, IImportSource $importSource, OutputInterface $output): void {
		$contents = $this->optionalFile($importSource, self::PATH_INTERESTS, $output);
		if ($contents === null) {
			return;
		}

		$data = json_decode($contents, true);
		if (!is_array($data)) {
			$output->writeln(self::PATH_INTERESTS . ' is not JSON, skipping the interests…');

			return;
		}

		try {
			$output->writeln('Imported ' . $this->interestService->import($actor, $data) . ' interest(s)…');
		} catch (Throwable $e) {
			// the rest of the account is worth more than its interests
			$this->logger->warning('cannot import the interests', ['exception' => $e]);
			$output->writeln('Could not import the interests (' . $e->getMessage() . ')…');
		}
	}

	/**
	 * The profile, through the app's own paths, and the old actor id as an
	 * alias. Not the display name (the core `account` migrator carries it), the
	 * key pair (see the class comment), or `movedTo`, which would send the new
	 * account's followers elsewhere.
	 */
	private function importActor(string $userId, IImportSource $importSource, OutputInterface $output): void {
		$contents = $this->optionalFile($importSource, self::PATH_ACTOR, $output);
		if ($contents === null) {
			return;
		}

		try {
			/** @var array<string, mixed> $data */
			$data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$output->writeln('<error>' . self::PATH_ACTOR . ' is not readable JSON, skipping it…</error>');

			return;
		}

		$output->writeln('Importing the Aloha Social profile from ' . self::PATH_ACTOR . '…');

		try {
			if (array_key_exists('locked', $data)) {
				$this->accountService->setLocked($userId, (bool)$data['locked']);
			}

			$flags = [];
			foreach (['discoverable', 'indexable'] as $flag) {
				if (array_key_exists($flag, $data)) {
					$flags[$flag] = (bool)$data[$flag];
				}
			}
			if ($flags !== []) {
				$this->accountService->setActorFlags($userId, $flags);
			}

			if (isset($data['fields']) && is_array($data['fields'])) {
				$this->accountService->setFields($userId, array_values($data['fields']));
			}

			// only when the archive has one, so an archive without a bio does not
			// blank the account's
			$summary = trim((string)($data['summary'] ?? ''));
			if ($summary !== '') {
				$this->accountService->setSummary($userId, $summary);
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not restore a Social profile', [
				'userId' => $userId, 'exception' => $e,
			]);
			$output->writeln('<error>Could not restore the Aloha Social profile: ' . $e->getMessage() . '</error>');
		}

		$this->importProfileImages($userId, $data, $importSource, $output);

		$oldId = trim((string)($data['id'] ?? ''));
		if ($oldId === '') {
			return;
		}

		try {
			$this->migrationService->addAlias($userId, $oldId);
			$output->writeln(
				'Recorded ' . $oldId . ' as an alias of this account, so a Move from it is accepted here…'
			);
		} catch (Throwable $e) {
			$this->logger->warning('could not record the old actor id as an alias', [
				'userId' => $userId, 'alias' => $oldId, 'exception' => $e,
			]);
			$output->writeln('<error>Could not record ' . $oldId . ' as an alias: ' . $e->getMessage() . '</error>');
		}
	}

	/**
	 * Re-follows everything in `following_accounts.csv` through the ordinary
	 * follow path, as `occ social:account:import-follows` does. An unreachable
	 * server is reported and does not stop the others.
	 */
	private function importFollows(string $userId, IImportSource $importSource, OutputInterface $output): void {
		$csv = $this->optionalFile($importSource, self::PATH_FOLLOWING, $output);
		if ($csv === null) {
			return;
		}

		$output->writeln('Following the accounts of ' . self::PATH_FOLLOWING . '…');

		try {
			$result = $this->migrationService->importFollows($userId, $csv);
		} catch (Throwable $e) {
			$this->logger->warning('could not import the follows', ['userId' => $userId, 'exception' => $e]);
			$output->writeln('<error>Could not import the follows: ' . $e->getMessage() . '</error>');

			return;
		}

		$output->writeln(
			'Followed ' . $result['followed'] . ', skipped ' . $result['skipped']
			. ', failed ' . count($result['failed']) . '…'
		);
		foreach ($result['failed'] as $handle => $reason) {
			$output->writeln('  - ' . $handle . ': ' . $reason);
		}
	}

	/**
	 * The lists and the blocked servers, through the imports the Migration
	 * page runs for the same two files. After the follows, because a list
	 * holds only accounts this one follows; and neither federates anything.
	 */
	private function importLists(string $userId, IImportSource $importSource, OutputInterface $output): void {
		$lists = $this->optionalFile($importSource, self::PATH_LISTS, $output);
		if ($lists !== null) {
			try {
				$result = $this->migrationService->importLists($userId, $lists);
				$output->writeln(
					'Restored ' . $result['lists'] . ' list(s) with ' . $result['added'] . ' member(s) from '
					. self::PATH_LISTS . '; skipped ' . $result['skipped'] . ', failed ' . count($result['failed']) . '…'
				);
			} catch (Throwable $e) {
				$this->logger->warning('could not import the lists', ['userId' => $userId, 'exception' => $e]);
				$output->writeln('<error>Could not import the lists: ' . $e->getMessage() . '</error>');
			}
		}

		$domains = $this->optionalFile($importSource, self::PATH_DOMAIN_BLOCKS, $output);
		if ($domains !== null) {
			try {
				$result = $this->migrationService->importDomainBlocks($userId, $domains);
				$output->writeln(
					'Restored ' . $result['blocked'] . ' blocked server(s) from ' . self::PATH_DOMAIN_BLOCKS
					. '; failed ' . count($result['failed']) . '…'
				);
			} catch (Throwable $e) {
				$this->logger->warning('could not import the blocked domains', ['userId' => $userId, 'exception' => $e]);
				$output->writeln('<error>Could not import the blocked domains: ' . $e->getMessage() . '</error>');
			}
		}
	}

	/**
	 * The blocks and mutes, written straight to the relation table: they were
	 * federated when made, and the other end need not hear of the move.
	 */
	private function importRelations(
		Person $actor,
		IImportSource $importSource,
		OutputInterface $output,
	): void {
		$blocks = $this->optionalFile($importSource, self::PATH_BLOCKS, $output);
		if ($blocks !== null) {
			$count = 0;
			foreach (MigrationService::parseFollowsCsv($blocks) as $handle) {
				$count += $this->relate($actor, $handle, ActorRelation::TYPE_BLOCK, true, $output) ? 1 : 0;
			}
			$output->writeln('Restored ' . $count . ' block(s) from ' . self::PATH_BLOCKS . '…');
		}

		$mutes = $this->optionalFile($importSource, self::PATH_MUTES, $output);
		if ($mutes !== null) {
			$hidden = $this->hiddenNotifications($mutes);
			$expiries = $this->muteExpiries($mutes);
			$count = 0;
			foreach (MigrationService::parseFollowsCsv($mutes) as $handle) {
				$notifications = !($hidden[strtolower($handle)] ?? false);
				$count += $this->relate(
					$actor, $handle, ActorRelation::TYPE_MUTE, $notifications, $output,
					$expiries[strtolower($handle)] ?? 0
				) ? 1 : 0;
			}
			$output->writeln('Restored ' . $count . ' mute(s) from ' . self::PATH_MUTES . '…');
		}
	}

	/**
	 * One block or mute against a handle, resolved as the app resolves any
	 * handle, which may fetch it with a plain signed GET.
	 *
	 * @param int $expiresAt when a timed mute runs out, or 0 for one that does
	 *                       not
	 *
	 * @return bool whether the relation was stored
	 */
	private function relate(
		Person $actor,
		string $handle,
		string $type,
		bool $notifications,
		OutputInterface $output,
		int $expiresAt = 0,
	): bool {
		if (strcasecmp($handle, $actor->getAccount()) === 0) {
			return false;
		}

		try {
			$target = $this->cacheActorService->getFromAccount($handle);
		} catch (Throwable $e) {
			$this->logger->notice('cannot restore a block or mute: the account did not resolve', [
				'handle' => $handle, 'type' => $type, 'exception' => $e,
			]);
			$output->writeln('  - ' . $handle . ' did not resolve, its ' . $type . ' was not restored…');

			return false;
		}

		if ($target->getId() === $actor->getId()) {
			return false;
		}

		try {
			$this->actorRelationRequest->save($actor->getId(), $target->getId(), $type, $notifications);
		} catch (Throwable $e) {
			$this->logger->warning('cannot store a restored block or mute', [
				'handle' => $handle, 'type' => $type, 'exception' => $e,
			]);

			return false;
		}

		if ($expiresAt > 0) {
			try {
				$this->accountRelationService->setMuteExpiresAt($actor, $target, $expiresAt);
			} catch (Throwable $e) {
				// the mute is stored either way, and would turn permanent without its
				// expiry
				$this->logger->warning('cannot restore when a mute runs out', [
					'handle' => $handle, 'exception' => $e,
				]);
			}
		}

		return true;
	}

	/**
	 * `handle => whether notifications are hidden`, from the second column of
	 * a `muted_accounts.csv`. The handles themselves come from the shared
	 * reader, so what is not a handle never gets here.
	 *
	 * @return array<string, bool>
	 */
	private function hiddenNotifications(string $csv): array {
		return MigrationService::parseMuteNotifications($csv);
	}

	/**
	 * `handle => when the mute runs out`, from the third column of a
	 * `muted_accounts.csv`, by lower-cased handle. Without the column (Mastodon's
	 * own file) every mute is permanent, as is any date `strtotime()` cannot read.
	 *
	 * @return array<string, int>
	 */
	private function muteExpiries(string $csv): array {
		$expiries = [];
		foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
			if (trim($line) === '') {
				continue;
			}

			$cells = str_getcsv($line, ',', '"', '');
			$handle = strtolower(ltrim(Csv::restore(trim((string)($cells[0] ?? ''))), '@'));
			$expiresAt = strtotime(trim((string)($cells[2] ?? '')));
			if ($handle !== '' && $expiresAt !== false && $expiresAt > 0) {
				$expiries[$handle] = $expiresAt;
			}
		}

		return $expiries;
	}

	/**
	 * Marks again what the user had bookmarked and favourited, on posts this
	 * server already has: others are not fetched, and are marked when they
	 * arrive. Marks are local; a favourite is not re-sent as a Like. Posts are
	 * looked up as the importing account, so one it may not read stays unmarked.
	 */
	private function importMarks(Person $actor, IImportSource $importSource, OutputInterface $output): void {
		foreach ([
			self::PATH_BOOKMARKS => StreamAction::BOOKMARKED,
			self::PATH_LIKES => StreamAction::LIKED,
		] as $path => $mark) {
			$contents = $this->optionalFile($importSource, $path, $output);
			if ($contents === null) {
				continue;
			}

			$this->streamRequest->setViewer($actor);
			$marked = 0;
			$missing = 0;
			foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
				$id = trim($line);
				if ($id === '' || !str_starts_with($id, 'http')) {
					continue;
				}

				try {
					$post = $this->streamRequest->getStreamById($id, true);
				} catch (Throwable $e) {
					$missing++;
					continue;
				}

				try {
					$this->streamActionService->setActionBool($actor->getId(), $post->getId(), $mark, true);
					$marked++;
				} catch (Throwable $e) {
					$this->logger->warning('cannot restore a mark on a post', [
						'post' => $id, 'mark' => $mark, 'exception' => $e,
					]);
				}
			}

			$output->writeln(
				'Restored ' . $marked . ' ' . $mark . ' mark(s) from ' . $path . '; ' . $missing
				. ' post(s) this server does not have or this account may not read were left…'
			);
		}
	}

	/**
	 * The banner, and the avatar where the account has none of its own. The
	 * banner goes through the path that owns it, which also tells this account's
	 * followers. The avatar is the Nextcloud account's, so `AvatarService` sets
	 * it only over generated initials.
	 *
	 * @param array<string, mixed> $data the actor file
	 */
	private function importProfileImages(
		string $userId,
		array $data,
		IImportSource $importSource,
		OutputInterface $output,
	): void {
		$header = trim((string)($data['headerFile'] ?? ''));
		if ($header !== '') {
			$path = $this->fileFromArchive($importSource, self::PATH_ROOT . $header);
			if ($path === null) {
				$output->writeln('No ' . self::PATH_ROOT . $header . ' in the archive, keeping the banner as it is…');
			} else {
				try {
					$this->bannerService->setFromTempFile($userId, $path);
					$output->writeln('Restored the profile banner from ' . self::PATH_ROOT . $header . '…');
				} catch (Throwable $e) {
					$this->logger->warning('could not restore a profile banner', [
						'userId' => $userId, 'exception' => $e,
					]);
					$output->writeln('<error>Could not restore the banner: ' . $e->getMessage() . '</error>');
				}
			}
		}

		$avatar = trim((string)($data['avatarFile'] ?? ''));
		if ($avatar === '') {
			return;
		}

		$path = $this->fileFromArchive($importSource, self::PATH_ROOT . $avatar);
		if ($path === null) {
			$output->writeln('No ' . self::PATH_ROOT . $avatar . ' in the archive, keeping the picture as it is…');

			return;
		}

		try {
			$output->writeln(
				$this->avatarService->restoreFromArchive($userId, $path)
					? 'Restored the profile picture from ' . self::PATH_ROOT . $avatar . '…'
					: 'This account already has a picture of its own; the one in the archive was left…'
			);
		} catch (Throwable $e) {
			$this->logger->warning('could not restore a profile picture', [
				'userId' => $userId, 'exception' => $e,
			]);
			$output->writeln('<error>Could not restore the profile picture: ' . $e->getMessage() . '</error>');
		}
	}

	/**
	 * The posts are not restored (their ids belong to the server they were
	 * written on; see `docs/Mastodon-Compatibility.md`), but their files are,
	 * onto the posts this server still has without them, as after a storage
	 * purge. Posts not here, or not the importer's, are left alone. The outbox
	 * is read whole: it holds only text.
	 */
	private function importOutbox(
		Person $actor,
		IImportSource $importSource,
		OutputInterface $output,
	): void {
		if (!$this->pathExists($importSource, self::PATH_OUTBOX)) {
			return;
		}

		$output->writeln(
			'The posts in ' . self::PATH_OUTBOX . ' are not restored into the timeline: their ids'
			. ' belong to the server they were written on. They are in the archive as your copy of'
			. ' what you wrote…'
		);

		$contents = $this->optionalFile($importSource, self::PATH_OUTBOX, $output);
		if ($contents === null) {
			return;
		}

		try {
			/** @var array<string, mixed> $outbox */
			$outbox = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$output->writeln('<error>' . self::PATH_OUTBOX . ' is not readable JSON, skipping it…</error>');

			return;
		}

		$items = $outbox['orderedItems'] ?? [];
		if (!is_array($items)) {
			return;
		}

		$tally = [
			'restored' => 0, 'here' => 0, 'missingFile' => 0, 'missingPost' => 0,
			'notYours' => 0, 'failed' => 0,
		];
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$archived = $this->archivedAttachments($item);
			if ($archived === []) {
				continue;
			}

			try {
				$post = $this->streamRequest->getStreamById((string)($item['id'] ?? ''));
			} catch (Throwable $e) {
				$tally['missingPost'] += count($archived);
				continue;
			}

			if (!$this->wrotePost($actor, $post)) {
				$tally['notYours'] += count($archived);
				continue;
			}

			$this->restorePostMedia($actor, $post, $archived, $importSource, $tally);
		}

		if (array_sum($tally) === 0) {
			return;
		}

		$output->writeln(
			'Restored ' . $tally['restored'] . ' file(s) onto the posts this server has; '
			. $tally['here'] . ' were already here, ' . $tally['missingFile']
			. ' were not in the archive, ' . $tally['missingPost']
			. ' belong to posts this server does not have, ' . $tally['notYours']
			. ' name posts this account did not write and ' . $tally['failed']
			. ' could not be stored…'
		);
	}

	/**
	 * Whether a post named in the archive is a local one the importing account
	 * wrote. The archive's ids are the user's to choose, so without this any
	 * post here could have its attachments rewritten; a remote post's are its
	 * origin's.
	 */
	private function wrotePost(Person $actor, Stream $post): bool {
		return $post->isLocal() && $post->getAttributedTo() === $actor->getId();
	}

	/**
	 * The attachments of one archived post that name a file in the archive, by
	 * their position; a `url` not of ours was never copied.
	 *
	 * @param array<string, mixed> $item
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function archivedAttachments(array $item): array {
		$attachments = $item['attachment'] ?? [];
		if (!is_array($attachments)) {
			return [];
		}

		$archived = [];
		foreach (array_values($attachments) as $index => $attachment) {
			if (!is_array($attachment)) {
				continue;
			}

			$url = (string)($attachment['url'] ?? '');
			// a path of ours, and only a path: a `..` or a leading slash is
			// somebody's idea of reaching out of the archive
			if (!str_starts_with($url, self::PATH_MEDIA) || str_contains($url, '..')) {
				continue;
			}

			$archived[$index] = $attachment;
		}

		return $archived;
	}

	/**
	 * The files of one post, put back through the composer's upload path
	 * (`DocumentService::storeLocalAttachment()`), so they are sniffed, filtered
	 * and stripped of metadata like any upload, but not transcoded again. The
	 * post's `attachments` column is rewritten once at the end.
	 *
	 * @param array<int, array<string, mixed>> $archived
	 * @param array<string, int> $tally
	 */
	private function restorePostMedia(
		Person $actor,
		Stream $post,
		array $archived,
		IImportSource $importSource,
		array &$tally,
	): void {
		$copies = [];
		foreach (array_values($post->getAttachments()) as $attachment) {
			$copies[] = ($attachment instanceof MediaAttachment) ? $attachment->asLocal() : (array)$attachment;
		}

		$public = in_array($post->getVisibility(), [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED], true);
		$changed = false;

		foreach ($archived as $index => $attachment) {
			if ($this->hasStoredCopy($copies[$index] ?? [])) {
				$tally['here']++;
				continue;
			}

			$path = $this->fileFromArchive($importSource, self::PATH_ROOT . (string)$attachment['url']);
			if ($path === null) {
				// the attachment keeps the address it had, which is what `originalUrl`
				// is in the archive for
				$tally['missingFile']++;
				continue;
			}

			try {
				$document = $this->documentService->storeLocalAttachment(
					$actor,
					$path,
					$post->getId(),
					(string)($attachment['name'] ?? ''),
					$public
				);
			} catch (Throwable $e) {
				$this->logger->warning('could not restore an attachment of a post', [
					'post' => $post->getId(), 'path' => $attachment['url'], 'exception' => $e,
				]);
				$tally['failed']++;
				continue;
			} finally {
				@unlink($path);
			}

			$copies[$index] = $document->convertToMediaAttachment($this->urlGenerator)->asLocal();
			$changed = true;
			$tally['restored']++;
		}

		if (!$changed) {
			return;
		}

		ksort($copies);
		$this->streamRequest->setStoredAttachmentCopies(
			$post->getId(),
			(string)json_encode(array_values($copies), JSON_UNESCAPED_SLASHES)
		);
	}

	/**
	 * Whether an attachment of a post still has the file it names. A row whose
	 * copy was swept away is exactly what an archive is being read for.
	 *
	 * @param array<string, mixed> $copy one entry of a post's stored attachments
	 */
	private function hasStoredCopy(array $copy): bool {
		$uuid = self::storedCopyOf((string)($copy['url'] ?? ''));
		if ($uuid === '') {
			// not a copy of ours to begin with — a streamed row — and not
			// something an import should replace
			return ($copy !== []);
		}

		try {
			$this->cacheDocumentService->getFromUuid($uuid);
		} catch (Throwable $e) {
			return false;
		}

		return true;
	}

	/**
	 * One file of the archive in a temporary file of its own, copied a chunk at
	 * a time, since media is stored from a path. Files larger than the largest
	 * this instance stores (`CacheDocumentService::largestFileBytes()`) stay in
	 * the archive.
	 *
	 * @return string|null the path, or null when the archive has no such file
	 *                     or it is too large to store
	 */
	private function fileFromArchive(IImportSource $importSource, string $path): ?string {
		if (!$this->pathExists($importSource, $path)) {
			return null;
		}

		$tmpPath = $this->tempManager->getTemporaryFile();
		if ($tmpPath === false) {
			return null;
		}

		try {
			$source = $importSource->getFileAsStream($path);
			$target = fopen($tmpPath, 'w');
			if (!is_resource($target)) {
				fclose($source);

				return null;
			}

			$max = $this->cacheDocumentService->largestFileBytes();
			try {
				$copied = stream_copy_to_stream($source, $target, $max + 1);
			} finally {
				fclose($source);
				fclose($target);
			}
		} catch (Throwable $e) {
			$this->logger->warning('cannot read a media file of the archive', [
				'path' => $path, 'exception' => $e,
			]);

			return null;
		}

		if ($copied === false || $copied > $max) {
			@unlink($tmpPath);
			$this->logger->warning('a media file of the archive is larger than this instance stores', [
				'path' => $path, 'limit' => $max,
			]);

			return null;
		}

		return $tmpPath;
	}

	/**
	 * The contents of a file that may not be there, or null — with a line
	 * saying so, because "nothing happened" and "there was nothing to do" look
	 * the same in a log otherwise.
	 */
	private function optionalFile(
		IImportSource $importSource,
		string $path,
		OutputInterface $output,
	): ?string {
		if (!$this->pathExists($importSource, $path)) {
			$output->writeln('No ' . $path . ' in the archive, skipping it…');

			return null;
		}

		try {
			return $importSource->getFileContents($path);
		} catch (Throwable $e) {
			$this->logger->warning('cannot read a file of the archive', [
				'path' => $path, 'exception' => $e,
			]);
			$output->writeln('<error>Could not read ' . $path . ', skipping it…</error>');

			return null;
		}
	}

	/**
	 * Whether the archive holds any file this migrator wrote. Asked file by
	 * file rather than by listing the folder, so it does not depend on how an
	 * implementation of the framework answers for a directory.
	 */
	private function archiveHasSocialData(IImportSource $importSource): bool {
		foreach ([
			self::PATH_ACTOR,
			self::PATH_FOLLOWING,
			self::PATH_FOLLOWERS,
			self::PATH_BLOCKS,
			self::PATH_MUTES,
			self::PATH_LISTS,
			self::PATH_DOMAIN_BLOCKS,
			self::PATH_BOOKMARKS,
			self::PATH_LIKES,
			self::PATH_OUTBOX,
		] as $path) {
			if ($this->pathExists($importSource, $path)) {
				return true;
			}
		}

		return false;
	}

	private function pathExists(IImportSource $importSource, string $path): bool {
		try {
			return $importSource->pathExists($path);
		} catch (Throwable $e) {
			return false;
		}
	}
}
