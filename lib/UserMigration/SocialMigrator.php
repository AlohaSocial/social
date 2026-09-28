<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\UserMigration;

use OCA\Social\AppInfo\Application;
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
 * What travels is what the account *is* and what it *chose*: the actor's
 * profile and flags, who it follows and who follows it, what it blocks and
 * mutes, what it wrote — with the pictures and videos on it, and the banner
 * over it — and what it kept (bookmarks and favourites). The lists of accounts
 * are written in the shape Mastodon exports them, and the files under
 * `media_attachments/` in the layout Mastodon's own archive uses, so the archive
 * is useful outside Nextcloud too — `following_accounts.csv` is the file
 * `MigrationService::parseFollowsCsv()` reads and the one Mastodon's "Import
 * follows" accepts.
 *
 * What deliberately does not travel:
 *
 * - **the private key.** Without it the imported account cannot sign as the
 *   *old* actor — and it should not be able to. An export archive is an
 *   ordinary file: downloaded, mailed, left in another cloud. The key that a
 *   whole instance's worth of servers accept as proof of identity has no
 *   revocation in ActivityPub, and this app goes as far as encrypting it at
 *   rest (`PrivateKeyCipher`), so putting a plaintext copy in a user-held file
 *   would undo that for the convenience of a case that does not need it: an
 *   account imported on another server is a *new* actor with a new id, and
 *   `AccountService::createActor()` gives it a fresh pair. Identity continuity
 *   is carried by `alsoKnownAs` plus a `Move` from the old server — which is
 *   why the import records the old actor id as an alias — not by copying the
 *   secret. The public key is exported, because it is public and identifies
 *   which actor this was.
 * - **other people's posts.** The local copies of remote statuses are a cache
 *   of somebody else's content; it is re-fetched wherever it is needed and is
 *   not the user's to carry. Only what the user wrote is in `outbox.json`, and
 *   only the files of those posts are copied — and only where this instance
 *   stored them, which a streamed video (`Document::COPY_STREAMED`) never was.
 * - **moderation decisions taken against the account** (`social_moderation`),
 *   reports, the outbound request queue, OAuth clients, tokens and secrets. A
 *   suspension outlives even the deletion of an actor on purpose (see
 *   `PersonInterface::delete()`), so it must not be something a user can shed
 *   by exporting and re-importing, and a credential that survived a move would
 *   be a credential nobody can revoke.
 *
 * The import federates nothing except the follows, which cannot be re-created
 * any other way — see import().
 */
class SocialMigrator implements IMigrator, ISizeEstimationMigrator {
	use TMigratorBasicVersionHandling;

	/** How many rows one read asks for, everywhere in here. */
	public const PAGE = 50;

	/**
	 * The block and mute lists are read in one query — `getByActor()` takes no
	 * offset — so the read is capped, and the cap is reported rather than
	 * silently truncating.
	 */
	public const RELATIONS_LIMIT = 5000;

	private const PATH_ROOT = Application::APP_ID . '/';
	/**
	 * What says an archive holds Social data at all.
	 *
	 * Public because the Migration page reads archives too — see
	 * `ZipImportSource` — and both halves have to agree on the one file that
	 * decides it, or an archive would be accepted by one and refused by the
	 * other.
	 */
	public const PATH_ACTOR_PUBLIC = self::PATH_ROOT . 'actor.json';
	private const PATH_ACTOR = self::PATH_ACTOR_PUBLIC;
	private const PATH_FOLLOWING = self::PATH_ROOT . 'following_accounts.csv';
	private const PATH_FOLLOWERS = self::PATH_ROOT . 'followers.csv';
	private const PATH_BLOCKS = self::PATH_ROOT . 'blocked_accounts.csv';
	private const PATH_MUTES = self::PATH_ROOT . 'muted_accounts.csv';
	private const PATH_BOOKMARKS = self::PATH_ROOT . 'bookmarks.csv';
	private const PATH_LIKES = self::PATH_ROOT . 'likes.csv';
	private const PATH_OUTBOX = self::PATH_ROOT . 'outbox.json';
	/** For you: what was learned and chosen, and the reader's switches. */
	private const PATH_INTERESTS = self::PATH_ROOT . 'interests.json';
	/**
	 * Where the files sit, relative to the app's folder in the archive, in the
	 * layout Mastodon's own export uses: `media_attachments/files/<id>/original.<ext>`
	 * for an attachment, `media_attachments/avatar.<ext>` and `header.<ext>` for
	 * the profile. Written relative because that is what a `url` in `outbox.json`
	 * is rewritten to, and a relative path is the one thing that still means
	 * something once the archive has left this server.
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
	 * The header over `muted_accounts.csv`: Mastodon's two columns, and when
	 * the mute runs out.
	 *
	 * The third column is this app's own. Every reader of the file finds its
	 * columns by the header or by position and ignores what it does not know,
	 * so the file is still the one Mastodon's "Import muted accounts" takes —
	 * and a timed mute that travelled as a permanent one was a mute the user
	 * never asked for and would have to find and undo by hand.
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
		return $this->l10n->t('Social');
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getDescription(): string {
		return $this->l10n->t(
			'Your Fediverse profile, the accounts you follow, block and mute, your own posts'
			. ' with the pictures and videos in them, your bookmarks and your favourites.'
			. ' Your private key stays behind.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Counted rather than measured: four cheap counts, and a post costs about a
	 * kilobyte of JSON. The media is the part that decides the answer — an
	 * account with one video outweighs everything else in the archive put
	 * together — so leaving it out, as this did while the files were not
	 * exported, understated a real export by orders of magnitude.
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
	 * What the account's own files are worth in the estimate, in KiB.
	 *
	 * `social_cache_documents` has no size column, so this is a count per kind
	 * rather than a measurement: a stored picture has been through the resize
	 * and is a few hundred kilobytes, a sound file a few megabytes, a video
	 * anything up to the instance's ceiling. One grouped count whose answer is
	 * the right order of magnitude is worth more here than an exact figure that
	 * would cost a stat of every file the export is about to copy anyway.
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
			$output->writeln($user->getUID() . ' has no Social account, nothing to export…');

			return;
		}

		$this->exportActor($actor, $exportDestination, $output);
		$this->exportFollows($actor, $exportDestination, $output);
		$this->exportRelations($actor, $exportDestination, $output);
		$this->exportMarks($actor, $exportDestination, $output);
		$this->exportInterests($actor, $exportDestination, $output);
		$this->exportOutbox($actor, $exportDestination, $output);
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
	 * The actor, without its private key. Exported as JSON rather than as the
	 * actor document itself: this file is what the import reads back, and an
	 * actor document is full of URLs of the server it is leaving.
	 *
	 * The pictures of the profile travel as files next to it, where this app
	 * has files of them: the banner always, the avatar only when it is one of
	 * this app's own — the picture of a local account is the Nextcloud
	 * account's, which core's own migrator carries and which this would only
	 * duplicate.
	 */
	private function exportActor(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		$output->writeln('Exporting the Social actor in ' . self::PATH_ACTOR . '…');

		$avatarFile = $this->exportProfileImage($actor->getAvatar(), 'avatar', $exportDestination);
		// `getHeader()` falls back to the avatar when there is no banner, which
		// would put the same file in the archive twice under two names
		$headerFile = ($actor->getHeader() === $actor->getAvatar())
			? '' : $this->exportProfileImage($actor->getHeader(), 'header', $exportDestination);
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
				'avatar' => $actor->getAvatar(),
				'header' => $actor->getHeader(),
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

	private function exportFollows(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		try {
			$following = $this->handlesOfFollows(
				$actor,
				fn (int $offset): array => $this->followsRequest->getFollowingByActorId(
					$actor->getId(), self::PAGE, $offset
				)
			);
			$exportDestination->addFileContents(
				self::PATH_FOLLOWING, MigrationService::exportFollowsCsv($following)
			);
			$output->writeln('Exported ' . count($following) . ' follow(s) to ' . self::PATH_FOLLOWING . '…');

			$followers = $this->handlesOfFollows(
				$actor,
				fn (int $offset): array => $this->followsRequest->getFollowersByActorId(
					$actor->getId(), self::PAGE, $offset
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
	 * The handles of a paged follow query, in order, without the ones whose
	 * account this server never cached — a bare actor URL is not a handle, and
	 * writing one into the address column would produce a row every reader of
	 * the format skips anyway.
	 *
	 * The account's own handle is left out too: every local actor holds a
	 * loopback follow of itself (`FollowsRequest::generateLoopbackAccount()`),
	 * so both lists named the exporter, and a `following_accounts.csv` naming
	 * you is a row Mastodon's importer tries to follow you with.
	 *
	 * @param callable(int):Follow[] $page
	 *
	 * @return string[]
	 */
	private function handlesOfFollows(Person $actor, callable $page): array {
		$handles = [];
		$offset = 0;

		while (true) {
			$follows = $page($offset);
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

			$offset += count($follows);
		}
	}

	/**
	 * Blocks and mutes, in the two files Mastodon writes them to.
	 *
	 * `blocked_accounts.csv` is a bare list of handles; `muted_accounts.csv`
	 * has a header and carries the two things a mute stores besides its
	 * target: whether notifications are hidden, and when it runs out.
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
					$lines[] = $handle;
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
					$lines[] = $handle . ',' . ($mute->isNotifications() ? 'false' : 'true')
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
	 * What the user kept: the bookmarked and the favourited posts, as the URLs
	 * of those posts — the shape Mastodon's `bookmarks.csv` has, and the only
	 * shape that survives leaving this server, where the local ids mean nothing.
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
	 * The user's own posts, as an ActivityPub `OrderedCollection`.
	 *
	 * Written through a temporary file a page at a time, the way
	 * `CalendarMigrator` does it: an account with ten years of posts must not
	 * have to fit in memory to be exportable. `totalItems` is written last,
	 * once counting is done — JSON does not care about key order and it saves
	 * a second pass over the rows.
	 *
	 * The files the posts point at go into the archive as the posts are walked,
	 * and each attachment's `url` is rewritten to where its file was put. The
	 * address it had here is kept beside it as `originalUrl`: a reader that
	 * cannot use the copy — or an import that finds the file missing — still
	 * knows where the picture was served from.
	 */
	private function exportOutbox(
		Person $actor,
		IExportDestination $exportDestination,
		OutputInterface $output,
	): void {
		$output->writeln('Exporting the Social posts to ' . self::PATH_OUTBOX . '…');

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
	 * One timeline of this actor, page by page, oldest last.
	 *
	 * The cursor is the `nid` of the last row of the page, which is what
	 * `ProbeOptions::setMaxId()` pages on, so a post added while the export
	 * runs cannot make it loop.
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
	 * One post's attachments, copied into the archive, and the post rewritten
	 * to point at them.
	 *
	 * Only what this instance has bytes of: a streamed video is somebody else's
	 * file that was deliberately never copied here (`Document::COPY_STREAMED`),
	 * and an attachment whose stored copy has been swept away has nothing to
	 * put in the archive. Both keep the URL they had, which is all there ever
	 * was of them.
	 *
	 * The serialised attachments and `getAttachments()` are the same list in
	 * the same order — one is `array_map()`ed from the other — so the id of the
	 * n-th attachment names the n-th file.
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

			// the attachment id, which is a row number, names the folder — and
			// only ever a row number: what goes into a path in an archive is
			// checked rather than trusted, whatever wrote the row
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
	 * The bytes of one stored copy into the archive, without the file ever
	 * being held: a video is gigabytes, and an export of one must cost no more
	 * memory than an export of a sentence.
	 *
	 * A file the row names and storage no longer has is not an error — the
	 * sweep that removed it was somebody's retention setting — so the export
	 * carries on and the attachment keeps its URL. A destination that cannot be
	 * written to is a different matter and is left to the caller: an archive
	 * quietly missing half its pictures is worse than an export that failed.
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
	 * implies, else the one the URL carried, else none anybody will guess from.
	 *
	 * A profile picture is known only by its URL — the actor carries the link,
	 * not the row — so where there is no type the URL is read for one, through
	 * the same guess the client entity makes.
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
	 * Safe to run on a server where the account already exists: the actor is
	 * taken as it is found (and only created when there is none), every write
	 * below is idempotent, and nothing is deleted — an import adds what the
	 * archive knows to what is here.
	 *
	 * Federation: an import sends no Delete, no Move and no Update{Person}
	 * except the one that follows a restored bio, which goes to the followers
	 * this account has here — on a freshly imported account, nobody — and is
	 * the same Update the user's own edit of the bio sends. Blocks and mutes
	 * are written straight to the relation table rather than through
	 * `RelationshipService`, which would federate a `Block` to an account that
	 * was already blocked before the move. The **follows** are the deliberate
	 * exception: a Follow towards each account is the only way a follow can
	 * exist at all, and it is what the user asked for by bringing the file.
	 *
	 * A missing file is not a failure: an archive from an older version of this
	 * app, or one a user assembled by hand, imports whatever it does carry.
	 */
	#[\Override]
	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		if ($importSource->getMigratorVersion($this->getId()) === null) {
			$output->writeln('No version for ' . static::class . ', skipping import…');

			return;
		}

		if (!$this->archiveHasSocialData($importSource)) {
			// every migrator that ran gets a version recorded, including for a
			// user who never used this app: creating an account here would hand
			// them a Fediverse identity, and publish it, on the strength of an
			// empty folder
			$output->writeln('No Social data in this archive, nothing to import…');

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
				$userId . ' has no Social account here and none could be created (' . $e->getMessage()
				. '), skipping the Social import…'
			);

			return;
		}

		$this->importActor($userId, $importSource, $output);
		$this->importFollows($userId, $importSource, $output);
		$this->importRelations($actor, $importSource, $output);
		$this->importMarks($actor, $importSource, $output);
		$this->importInterests($actor, $importSource, $output);
		$this->importOutbox($actor, $importSource, $output);
	}

	/** Takes For you back; an archive from before they existed has none. */
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
	 * The profile, through the paths the app uses itself, and the old actor id
	 * as an alias.
	 *
	 * The display name is not among them: it belongs to the Nextcloud account,
	 * the core `account` migrator carries it, and `AccountService` re-derives
	 * the actor's name from it. Neither is the key pair — see the class
	 * comment — nor `movedTo`, which would point the new account's own
	 * followers somewhere else.
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

		$output->writeln('Importing the Social profile from ' . self::PATH_ACTOR . '…');

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

			// only when the archive has one: an archive written before bios
			// existed must not blank the bio of the account importing it
			$summary = trim((string)($data['summary'] ?? ''));
			if ($summary !== '') {
				$this->accountService->setSummary($userId, $summary);
			}
		} catch (Throwable $e) {
			$this->logger->warning('could not restore a Social profile', [
				'userId' => $userId, 'exception' => $e,
			]);
			$output->writeln('<error>Could not restore the Social profile: ' . $e->getMessage() . '</error>');
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
	 * Re-follows everything in `following_accounts.csv`, through the ordinary
	 * follow path — which resolves each handle and queues a Follow, exactly as
	 * `occ social:account:import-follows` does with the same file. One handle
	 * whose server is unreachable is reported and does not stop the others.
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
	 * The blocks and mutes, written straight to the relation table: a block
	 * that was federated once when it was made does not need federating again,
	 * and the account on the other end was never told the user moved.
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
	 * One block or mute against a handle. The account is resolved the way the
	 * app resolves any handle — which may fetch it, a plain signed GET; it
	 * tells the other end nothing it was not going to learn from a follower
	 * anyway, and without an actor id there is no relation to store.
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
				// the mute is stored either way; without its expiry it is a
				// permanent one, which is what this whole column is here to
				// stop happening silently
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
	 * `muted_accounts.csv`, by lower-cased handle. A file without the column —
	 * Mastodon's own, or one written before it existed — has no expiries in
	 * it, and every mute in it is permanent.
	 *
	 * Anything `strtotime()` cannot read is no expiry rather than a mute that
	 * ended in 1970: a mute the file could not describe is left as the
	 * permanent one it will be read as anyway.
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
			$handle = strtolower(ltrim(trim((string)($cells[0] ?? '')), '@'));
			$expiresAt = strtotime(trim((string)($cells[2] ?? '')));
			if ($handle !== '' && $expiresAt !== false && $expiresAt > 0) {
				$expiries[$handle] = $expiresAt;
			}
		}

		return $expiries;
	}

	/**
	 * Marks again what the user had bookmarked and favourited — on the posts
	 * this server already has.
	 *
	 * A post nobody here has ever seen is skipped rather than fetched: pulling
	 * a decade of remote statuses out of other people's servers during an
	 * import is a cost they did not agree to, and a mark is restored the moment
	 * the post arrives here by any ordinary route. The mark is written locally
	 * only — a favourite is not re-federated as a Like, which the author
	 * already received once.
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

			$marked = 0;
			$missing = 0;
			foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
				$id = trim($line);
				if ($id === '' || !str_starts_with($id, 'http')) {
					continue;
				}

				try {
					$post = $this->streamRequest->getStreamById($id);
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
				. ' post(s) this server does not have were left…'
			);
		}
	}

	/**
	 * The banner, and the avatar where the account has none of its own.
	 *
	 * The banner is this app's picture and is restored through the path that
	 * owns it — which stores the bytes, points the cached actor at them and
	 * tells the followers of this account, who on a freshly imported account
	 * are nobody. The avatar is the Nextcloud account's rather than this app's,
	 * so `AvatarService` decides: a picture that is already there is left
	 * alone, and only an account still showing its generated initials gets the
	 * one out of the archive.
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
	 * The posts are not restored — their ids belong to the server they were
	 * written on, and writing statuses back is a piece of work of its own (see
	 * `docs/Mastodon-Compatibility.md`) — but their **files** are, onto the
	 * posts this server does have.
	 *
	 * That is the case an archive is read in after the pictures were lost and
	 * the rows were not: a purge of the storage, a database restored from a
	 * backup the files did not survive. Where the post is here and its picture
	 * still is too, nothing happens; where the post is not here at all, or
	 * where it is somebody else's, the file stays in the archive rather than
	 * becoming a row nothing can show or one somebody else has to look at.
	 *
	 * The outbox is read whole, which it can be: it is the text of the posts,
	 * and the files it names were never in it.
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
	 * Whether a post named in the archive is one the importing account wrote
	 * here.
	 *
	 * The ids in `outbox.json` are the user's: an archive is a file the user
	 * hands the server, and every id in it is chosen by whoever wrote the file.
	 * Without this, an id naming any other post on the instance — a cached
	 * remote status, another local account's post — resolved, and its
	 * attachments were rewritten to files out of the archive, for every viewer
	 * of this instance.
	 *
	 * Local as well as the author, because the attachments of a remote post are
	 * that server's to change: what is stored here is a copy, and rewriting it
	 * would make this instance show something its origin never published.
	 */
	private function wrotePost(Person $actor, Stream $post): bool {
		return $post->isLocal() && $post->getAttributedTo() === $actor->getId();
	}

	/**
	 * The attachments of one archived post that name a file in the archive,
	 * by their position in the post.
	 *
	 * A `url` that is not one of ours is an attachment this export could not
	 * copy — it kept the address it had — and there is nothing here to read.
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
	 * The files of one post, put back through the path an upload takes.
	 *
	 * `DocumentService::storeLocalAttachment()` is what the composer's own
	 * upload uses, so the type is sniffed from the bytes, anything this app
	 * does not store is refused and the metadata comes off — an archive is a
	 * file a user hands the server, and it gets no shorter a route in than a
	 * picture picked in the browser. Nothing is transcoded on the way: the file
	 * in the archive is the one that was stored here, already converted and
	 * resized when it was first uploaded, and putting it through a second
	 * generation of JPEG would lose quality for nothing.
	 *
	 * The post's own copy of its attachments (the `attachments` column, which
	 * is what a timeline renders from) is rewritten once, at the end, rather
	 * than per file.
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
				// nothing is invented in its place: the attachment keeps the
				// address the file had where it was written, which is what
				// `originalUrl` is in the archive for
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
	 * a time.
	 *
	 * Everything that stores media here takes a path rather than a string, on
	 * purpose — a video is never held whole — so the import has to hand it one.
	 *
	 * @return string|null the path, or null when the archive has no such file
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

			try {
				stream_copy_to_stream($source, $target);
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
