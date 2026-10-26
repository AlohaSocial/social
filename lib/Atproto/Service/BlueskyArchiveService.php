<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use Closure;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoPlcLogRequest;
use OCA\Social\Tools\ZipStreamWriter;
use OCA\Social\UserMigration\ZipStreamDestination;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\UserMigrationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A person's Bluesky account as files, for keeping and for moving it
 * without this server: the repository as a CAR, every blob by its CID, the
 * DID's operation log and document, the offline recovery script with its
 * word list, the offline recovery tool as one HTML page, and a README
 * that says what each is.
 *
 * Every file goes through the destination as a stream, so neither the
 * repository nor the pictures are ever whole in memory; the same files go
 * into the account export (`SocialMigrator`) and into the download of the
 * Bluesky account alone.
 */
class BlueskyArchiveService {
	public const ROOT = 'bluesky/';
	private const PAGE = 100;
	private const RECOVERY_SCRIPT = __DIR__ . '/../../../resources/atproto/recovery-key.py';
	private const WORDLIST = __DIR__ . '/../../../resources/atproto/bip39-english.txt';

	public function __construct(
		private IdentityService $identities,
		private RepositoryService $repositories,
		private AtprotoBlobRequest $blobs,
		private PictureService $pictures,
		private VideoBlobService $videos,
		private PlcClient $plc,
		private AtprotoPlcLogRequest $plcLog,
		private AtprotoConfig $config,
		private ITimeFactory $time,
		private RecoveryToolPage $recoveryTool,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether there is an account to export: an identity this server still
	 * hosts, on Bluesky or switched off.
	 */
	public static function exportable(?Identity $identity): bool {
		return $identity !== null && $identity->state !== Identity::STATE_TOMBSTONED && $identity->state !== Identity::STATE_MOVED_AWAY;
	}

	/**
	 * Writes the account's files under $root.
	 *
	 * @return array{repository: bool, blobs: int, missing: list<string>, log: string, tool: bool} what went in: whether the repository did, how many blobs, the CIDs of those that could not be read, where the log came from (`directory` or `local`), and whether the recovery tool did
	 * @throws UserMigrationException when the destination refuses a file
	 */
	public function write(Identity $identity, IExportDestination $destination, string $root = self::ROOT): array {
		$repository = $this->writeRepository($identity, $destination, $root);
		[$blobs, $missing] = $this->writeBlobs($identity, $destination, $root);
		$log = $this->writeLog($identity, $destination, $root);
		self::add($destination, $root . 'did.json', self::json($this->document($identity)));
		foreach ([self::RECOVERY_SCRIPT, self::WORDLIST] as $file) {
			$stream = fopen($file, 'rb');
			if ($stream === false) {
				throw new UserMigrationException('could not read ' . basename($file));
			}
			$destination->addFileAsStream($root . 'recovery/' . basename($file), $stream);
			fclose($stream);
		}
		$tool = $this->recoveryTool->isBuilt();
		if ($tool) {
			self::add($destination, $root . 'recovery/bluesky-recovery-tool.html', $this->recoveryTool->render());
		}
		$summary = ['repository' => $repository, 'blobs' => $blobs, 'missing' => $missing, 'log' => $log, 'tool' => $tool];
		self::add($destination, $root . 'README.txt', $this->readme($identity, $summary));

		return $summary;
	}

	/**
	 * The account alone, as a zip file for the download in Settings,
	 * handed to $sink while it is written: the first bytes go out before
	 * the repository and the blobs have all been read, and none of it is
	 * kept on disk.
	 *
	 * @param Closure(string): void $sink
	 * @throws UserMigrationException
	 */
	public function zip(Identity $identity, Closure $sink): void {
		$destination = new ZipStreamDestination(new ZipStreamWriter($sink, $this->time->getDateTime()));
		$this->write($identity, $destination);
		$destination->close();
	}

	/** What the downloaded file is called: the handle and the day, in characters any file system takes. */
	public function filename(Identity $identity): string {
		return 'bluesky-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $identity->handle) . '-' . $this->time->getDateTime()->format('Y-m-d') . '.zip';
	}

	/**
	 * @throws UserMigrationException
	 */
	private function writeRepository(Identity $identity, IExportDestination $destination, string $root): bool {
		try {
			$pieces = $this->repositories->streamCar($identity->did);
		} catch (Throwable $e) {
			$this->logger->info('No repository to export', ['did' => $identity->did, 'exception' => $e]);

			return false;
		}
		if ($destination instanceof ZipStreamDestination) {
			// into the download as it is read, never first whole on disk
			$destination->addFileFromPieces($root . 'repo.car', $pieces);

			return true;
		}
		$car = RepositoryService::spill($pieces);
		try {
			$destination->addFileAsStream($root . 'repo.car', $car);
		} finally {
			fclose($car);
		}

		return true;
	}

	/**
	 * @return array{0: int, 1: list<string>} how many blobs went in, and the CIDs of those that could not be read
	 * @throws UserMigrationException
	 */
	private function writeBlobs(Identity $identity, IExportDestination $destination, string $root): array {
		$written = 0;
		$missing = [];
		$cursor = '';
		do {
			$page = $this->blobs->list($identity->did, self::PAGE, $cursor);
			foreach ($page as $blob) {
				$cid = $blob->cid->toString();
				$cursor = $cid;
				$stream = $this->open($blob);
				if ($stream === null) {
					$missing[] = $cid;
					continue;
				}
				try {
					$destination->addFileAsStream($root . 'blobs/' . $cid, $stream);
				} finally {
					fclose($stream);
				}
				$written++;
			}
		} while (count($page) === self::PAGE);

		return [$written, $missing];
	}

	/**
	 * @return resource|null the blob's bytes, or null when its file is gone or no longer the blob
	 */
	private function open(BlobRef $blob) {
		try {
			if (str_starts_with($blob->mime, 'video/')) {
				return $this->videos->open($blob)['stream'];
			}

			return self::stream($this->pictures->read($blob));
		} catch (Throwable $e) {
			$this->logger->info('Blob left out of the export', ['did' => $blob->did, 'cid' => $blob->cid->toString(), 'exception' => $e]);

			return null;
		}
	}

	/**
	 * The directory's audit log, or where it cannot be asked, the
	 * operations this server's own log has as confirmed, in its shape.
	 *
	 * @return string `directory` or `local`
	 * @throws UserMigrationException
	 */
	private function writeLog(Identity $identity, IExportDestination $destination, string $root): string {
		$source = 'directory';
		try {
			$log = $this->plc->auditLog($identity->did);
		} catch (Throwable $e) {
			$this->logger->info('PLC directory not reached for the export', ['did' => $identity->did, 'exception' => $e]);
			$log = [];
		}
		if ($log === []) {
			$source = 'local';
			foreach ($this->plcLog->getByDid($identity->did) as $row) {
				if ($row['confirmed'] !== 0 && $row['dead'] === 0) {
					$log[] = [
						'did' => $identity->did,
						'operation' => $row['operation'],
						'cid' => $row['cid'],
						'nullified' => false,
						'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z', $row['creation']),
					];
				}
			}
		}
		self::add($destination, $root . 'plc-log.json', self::json($log));

		return $source;
	}

	private function document(Identity $identity): array {
		try {
			$document = $this->plc->document($identity->did);
			if ($document !== null) {
				return $document;
			}
		} catch (Throwable $e) {
			$this->logger->info('PLC directory not reached for the export', ['did' => $identity->did, 'exception' => $e]);
		}

		return $this->identities->document($identity);
	}

	/**
	 * @param array{repository: bool, blobs: int, missing: list<string>, log: string, tool: bool} $summary
	 */
	private function readme(Identity $identity, array $summary): string {
		$host = (string)parse_url($this->config->pdsEndpoint(), PHP_URL_HOST);
		$lines = [
			'Your Bluesky account, as ' . $host . ' held it on ' . $this->time->getDateTime()->format('Y-m-d H:i') . ' UTC',
			'',
			'DID:     ' . $identity->did,
			'Handle:  ' . $identity->handle,
			'',
			'WHAT IS IN HERE',
			'',
			$summary['repository']
				? 'repo.car        Your repository: every post, like, follow and profile record, signed, in the CAR format com.atproto.sync.getRepo answers. Another Bluesky server takes it with com.atproto.repo.importRepo (goat: `goat repo import repo.car`).'
				: 'repo.car        Not included: this server had no repository for the account.',
			'blobs/          The pictures and videos your records name, one file per blob, named by its CID (' . $summary['blobs'] . ' files). `goat blob upload blobs/<cid>` uploads one; `goat account missing-blobs` lists the ones a server still lacks.',
		];
		if ($summary['missing'] !== []) {
			$lines[] = '                ' . count($summary['missing']) . ' could not be read here and are not included: ' . implode(', ', $summary['missing']);
		}
		$lines = array_merge($lines, [
			$summary['log'] === 'directory'
				? 'plc-log.json    Every operation of your DID, as the PLC directory (' . $this->config->plcDirectory() . ') answered its audit log.'
				: 'plc-log.json    The operations of your DID as this server logged them: the PLC directory could not be asked when the archive was made.',
			'did.json        Your DID document: the handle, the signing key and the server it names.',
			$summary['tool']
				? 'recovery/       bluesky-recovery-tool.html, the recovery tool: open it in a browser. Also recovery-key.py and the BIP-39 word list it reads, which turn your recovery phrase into its key with Python.'
				: 'recovery/       recovery-key.py and the BIP-39 word list it reads: they turn your recovery phrase into its key, offline.',
			'',
			'YOUR RECOVERY PHRASE',
			'',
			'If you made a recovery phrase (Settings, Bluesky section), its key is one of the',
			'rotation keys of your DID: with it you can point the DID at another server even',
			'when this one is gone. The phrase is not in this archive; only you have it.',
			'',
			'The recovery tool does all of it in your browser, step by step: open',
			'recovery/bluesky-recovery-tool.html (or "Download the recovery tool" in Settings),',
			'give it your twelve words and your DID, and it gives the DID a signing key of your',
			'own, creates the account on the new server, uploads repo.car and blobs/ and points',
			'the DID there. Each request goes out only when you press its button.',
			'',
			'The same with goat (https://github.com/bluesky-social/goat), if you prefer:',
			'',
			'1. Turn the phrase into its key:',
			'     python3 recovery/recovery-key.py recovery/bip39-english.txt',
			'   It prints the key as hex and as multibase (z...): RECOVERY_KEY below.',
			'   `goat key inspect RECOVERY_KEY` shows its did:key, which the last operation',
			'   in plc-log.json lists under rotationKeys.',
			'',
			'2. Give the DID a signing key',
			'   of your own and drop this server\'s rotation key (SERVER_KEY: the other',
			'   did:key in rotationKeys):',
			'     goat key generate --type secp256k1      (SIGNING_KEY: the secret z..., SIGNING_DID_KEY: its did:key)',
			'     goat plc update --atproto-key SIGNING_DID_KEY --remove-rotation-key SERVER_KEY ' . $identity->did . ' > op.json',
			'     goat plc sign --plc-signing-key RECOVERY_KEY op.json > signed.json',
			'     goat plc submit --did ' . $identity->did . ' signed.json',
			'',
			'3. Create the account on the new server with your DID (NEW_SERVER_DID: its did:web,',
			'   from https://NEW_SERVER/xrpc/com.atproto.server.describeServer):',
			'     goat account service-auth-offline --atproto-signing-key SIGNING_KEY --iss ' . $identity->did . ' --aud NEW_SERVER_DID --lxm com.atproto.server.createAccount',
			'     goat account create --pds-host https://NEW_SERVER --existing-did ' . $identity->did . ' --service-auth TOKEN --handle HANDLE --email EMAIL --password PASSWORD',
			'',
			'4. Sign in there and bring your data:',
			'     goat account login -u ' . $identity->did . ' -p PASSWORD --pds-host https://NEW_SERVER',
			'     goat repo import repo.car',
			'     goat blob upload blobs/CID          (for each one `goat account missing-blobs` lists)',
			'',
			'5. Point the DID at the new server: `goat account plc recommended` lists its signing',
			'   key, rotation key, handle and address. `goat plc update` with --atproto-key, --pds',
			'   and --handle set to those; in op.json put your recovery key first in rotationKeys',
			'   and the new server\'s after it; sign it with RECOVERY_KEY and submit it as in 2.',
			'',
			'6. goat account activate',
			'',
			'The flags are goat\'s as of 2026; `goat <command> --help` shows them. The app\'s',
			'docs/Moving.md (https://github.com/alohasocial/social/blob/HEAD/docs/Moving.md)',
			'says the same with more context.',
			'',
			'While this server still exists, its own key ranks above your recovery key: for 72',
			'hours after an operation signed with your phrase, this server\'s key can undo it.',
		]);

		return implode("\n", $lines) . "\n";
	}

	/**
	 * @throws UserMigrationException
	 */
	private static function add(IExportDestination $destination, string $path, string $content): void {
		$stream = self::stream($content);
		try {
			$destination->addFileAsStream($path, $stream);
		} finally {
			fclose($stream);
		}
	}

	/** @return resource */
	private static function stream(string $content) {
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) {
			throw new UserMigrationException('could not buffer a file of the archive');
		}
		fwrite($stream, $content);
		rewind($stream);

		return $stream;
	}

	private static function json(array $value): string {
		return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
