<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\DocumentService;
use OCA\Social\Service\RemoteMediaPrefetcher;
use OCA\Social\Tools\Exceptions\RequestContentException;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class RemoteMediaPrefetcherTest extends TestCase {
	private const NOW = 1760000000;
	private const POST = 'https://remote.example/notes/1';

	private CacheDocumentsRequest|MockObject $cacheDocumentsRequest;
	private CacheDocumentService|MockObject $cacheService;
	private DocumentService|MockObject $documentService;
	private CurlService|MockObject $curlService;
	private ConfigService|MockObject $configService;
	private ITimeFactory|MockObject $time;
	private HostBreakerRequest|MockObject $hostBreakerRequest;
	private RemoteMediaPrefetcher $prefetcher;

	private int $clock = self::NOW;
	/** @var list<int> the timeout of every wave's downloads */
	private array $timeouts = [];
	/** @var list<list<string>> the urls of every wave, in order */
	private array $waves = [];
	/** @var array<string, array{strikes: int, open_until: int, last_failure: int}> */
	private array $breakerRows = [];
	/** @var list<string> */
	private array $temporary = [];

	protected function setUp(): void {
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);
		$this->cacheDocumentsRequest->method('claimForCaching')->willReturn(true);
		$this->cacheService = $this->createMock(CacheDocumentService::class);
		$this->cacheService->method('downloadPlan')->willReturnCallback(
			static fn (Document $document): array => ['url' => $document->getUrl(), 'max' => 1024, 'on_behalf_of' => 'remote.example']
		);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->curlService = $this->createMock(CurlService::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('withRequestTimeout')->willReturnCallback(
			function (int $timeout, callable $action, int $connectTimeout = 0): mixed {
				$this->timeouts[] = $timeout;
				$this->assertSame(ConfigService::DEFAULT_REQUEST_TIMEOUT, $connectTimeout);

				return $action();
			}
		);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturnCallback(fn (): int => $this->clock);
		$this->hostBreakerRequest = $this->createMock(HostBreakerRequest::class);
		$this->hostBreakerRequest->method('failingSince')->willReturnCallback(fn (): array => $this->breakerRows);
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$path = (string)tempnam(sys_get_temp_dir(), 'social-prefetch-');
			$this->temporary[] = $path;

			return $path;
		});

		$this->prefetcher = new RemoteMediaPrefetcher(
			$this->cacheDocumentsRequest,
			$this->cacheService,
			$this->documentService,
			$this->curlService,
			$this->configService,
			$tempManager,
			$this->time,
			new NullLogger(),
			$this->hostBreakerRequest,
		);
	}

	protected function tearDown(): void {
		foreach ($this->temporary as $path) {
			@unlink($path);
		}
	}

	private function document(int $nid, string $host = 'files.remote.example', string $localCopy = ''): Document {
		$document = new Document();
		$document->setNid($nid);
		$document->setId('https://remote.example/media/' . $nid);
		$document->setUrl('https://' . $host . '/' . $nid . '.png');
		$document->setParentId(self::POST);
		$document->setLocalCopy($localCopy);

		return $document;
	}

	/**
	 * Every download writes its file and succeeds, unless `$outcomes` names
	 * its url; every stored document runs the closure it was handed.
	 *
	 * @param array<string, \Throwable> $outcomes
	 */
	private function transport(array $outcomes = []): void {
		$this->curlService->method('downloadMany')->willReturnCallback(
			function (array $downloads) use ($outcomes): array {
				$this->waves[] = array_values(array_column($downloads, 'url'));
				$results = [];
				foreach ($downloads as $key => $download) {
					file_put_contents($download['path'], 'bytes');
					$results[$key] = $outcomes[$download['url']] ?? null;
				}

				return $results;
			}
		);
		$this->documentService->method('storeRemoteDocument')->willReturnCallback(
			function (Document $document, \Closure $store): string {
				try {
					$store($document);
				} catch (RequestNetworkException) {
					return DocumentService::RETRY;
				} catch (\Throwable) {
					return DocumentService::SETTLED;
				}

				return DocumentService::STORED;
			}
		);
		$this->cacheService->method('saveFromTempToCache')->willReturnCallback(
			function (Document $document, string $path): void {
				$this->assertSame('bytes', file_get_contents($path));
				$document->setLocalCopy('copy-' . $document->getNid());
			}
		);
	}

	/**
	 * A post's waiting attachments are downloaded together, in one wave, and
	 * each is stored from the file the download wrote; the temporary files do
	 * not outlive the wave.
	 */
	public function testAPostsAttachmentsAreFetchedTogetherAndStored(): void {
		$documents = [$this->document(1), $this->document(2), $this->document(3, 'files.remote.example', 'copy-3')];
		$this->cacheDocumentsRequest->method('getByParent')->with(self::POST)->willReturn($documents);
		$this->transport();

		$waiting = $this->prefetcher->prefetchPost(self::POST, self::NOW + 120);

		$this->assertSame(0, $waiting);
		$this->assertSame([['https://files.remote.example/1.png', 'https://files.remote.example/2.png']], $this->waves);
		$this->assertSame('copy-1', $documents[0]->getLocalCopy());
		$this->assertSame('copy-2', $documents[1]->getLocalCopy());
		foreach ($this->temporary as $path) {
			$this->assertFileDoesNotExist($path);
		}
	}

	/** The downloads may not outlast the deadline, nor the background download timeout. */
	public function testTheDownloadsOfAWaveAreHeldToTheTimeLeft(): void {
		$this->cacheDocumentsRequest->method('getByParent')->willReturnCallback(fn (): array => [$this->document(1)]);
		$this->transport();

		$this->prefetcher->prefetchPost(self::POST, self::NOW + 45);
		$this->prefetcher->prefetchPost(self::POST, self::NOW + 1000);

		$this->assertSame([45, DocumentService::BACKGROUND_FETCH_TIMEOUT], $this->timeouts);
	}

	public function testNoWaveStartsWithTooLittleTimeLeft(): void {
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([$this->document(1)]);
		$this->curlService->expects($this->never())->method('downloadMany');
		$this->cacheDocumentsRequest->expects($this->never())->method('claimForCaching');

		$this->assertSame(1, $this->prefetcher->prefetchPost(self::POST, self::NOW + RemoteMediaPrefetcher::MIN_WAVE_SECONDS - 1));
	}

	/** A wave is at most WAVE files, and at most PER_HOST of them from one host. */
	public function testAWaveIsBoundedInSizeAndPerHost(): void {
		$documents = [];
		for ($nid = 1; $nid <= 6; $nid++) {
			$documents[] = $this->document($nid, 'busy.example');
		}
		for ($nid = 7; $nid <= 12; $nid++) {
			$documents[] = $this->document($nid, 'other' . $nid . '.example');
		}
		$this->cacheDocumentsRequest->method('getByParent')->willReturn($documents);
		$this->transport();

		$this->assertSame(0, $this->prefetcher->prefetchPost(self::POST, self::NOW + 600));

		$this->assertCount(2, $this->waves);
		$this->assertCount(RemoteMediaPrefetcher::WAVE, $this->waves[0]);
		$busy = array_filter($this->waves[0], static fn (string $url): bool => str_contains($url, 'busy.example'));
		$this->assertCount(RemoteMediaPrefetcher::PER_HOST, $busy);
	}

	/** A host behind the shared breaker is not asked; its files still wait. */
	public function testAHostBehindTheBreakerIsNotAsked(): void {
		$this->breakerRows = ['down.example' => ['strikes' => 1, 'open_until' => time() + 600, 'last_failure' => time()]];
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([
			$this->document(1, 'down.example'),
			$this->document(2),
		]);
		$this->transport();

		$this->assertSame(1, $this->prefetcher->prefetchPost(self::POST, self::NOW + 120));
		$this->assertSame([['https://files.remote.example/2.png']], $this->waves);
	}

	/**
	 * A host whose download failed at the network level is not asked again in
	 * the same run, and is not put behind the shared breaker for it: a large
	 * file running out of time is not the host being down.
	 */
	public function testAHostThatFailedIsNotAskedAgainInTheSameRun(): void {
		$documents = [];
		for ($nid = 1; $nid <= 6; $nid++) {
			$documents[] = $this->document($nid, 'slow.example');
		}
		$this->cacheDocumentsRequest->method('getByParent')->willReturn($documents);
		$failures = [];
		foreach ($documents as $document) {
			$failures[$document->getUrl()] = new RequestNetworkException('timed out');
		}
		$this->transport($failures);
		$this->hostBreakerRequest->expects($this->never())->method('open');

		$this->assertSame(6, $this->prefetcher->prefetchPost(self::POST, self::NOW + 600));
		$this->assertCount(1, $this->waves);
	}

	/**
	 * A host answering 429 asked to be left alone: the file is neither
	 * refused nor forgotten, and the breaker holds the host back as long as
	 * it asked.
	 */
	public function testAHostThatAsksToBeLeftAloneIsHeldBackAndItsFileKept(): void {
		$document = $this->document(1, 'busy.example');
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([$document]);
		$this->transport([$document->getUrl() => (new RequestContentException($document->getUrl(), 429))->setRetryAfter(900)]);
		$this->documentService->expects($this->never())->method('storeRemoteDocument');
		$this->hostBreakerRequest->expects($this->once())->method('open')
			->with('busy.example', 1, $this->greaterThanOrEqual(time() + 900), $this->anything());

		$this->assertSame(1, $this->prefetcher->prefetchPost(self::POST, self::NOW + 120));
	}

	public function testADocumentAnotherWorkerTookIsLeftToIt(): void {
		$taken = $this->document(1);
		$taken->setCaching(self::NOW - 30);
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([$taken]);
		$this->curlService->expects($this->never())->method('downloadMany');

		$this->assertSame(1, $this->prefetcher->prefetchPost(self::POST, self::NOW + 120));
	}

	/**
	 * Neither a streamed file, a local avatar or header marker, a refused file
	 * nor a local upload is ever fetched here.
	 */
	public function testOnlyRemoteDocumentsWithoutACopyAreFetched(): void {
		$refused = $this->document(3);
		$refused->setError(DocumentService::ERROR_SIZE);
		$upload = $this->document(4);
		$upload->setAccount('alice');
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([
			$this->document(1, 'files.remote.example', Document::COPY_STREAMED),
			$this->document(2, 'files.remote.example', Document::COPY_LOCAL_AVATAR),
			$refused,
			$upload,
		]);
		$this->curlService->expects($this->never())->method('downloadMany');

		$this->assertSame(0, $this->prefetcher->prefetchPost(self::POST, self::NOW + 120));
	}

	/** One unusable row costs that row: the rest of the wave is still stored. */
	public function testOneUnusableRowDoesNotEndTheRun(): void {
		$poison = $this->document(1);
		$good = $this->document(2);
		$this->cacheDocumentsRequest->method('getByParent')->willReturn([$poison, $good]);
		$this->curlService->method('downloadMany')->willReturnCallback(
			static fn (array $downloads): array => array_fill_keys(array_keys($downloads), null)
		);
		$this->documentService->method('storeRemoteDocument')->willReturnCallback(
			static function (Document $document): string {
				if ($document->getNid() === 1) {
					throw new \Error('Call to a member function on null');
				}

				return DocumentService::STORED;
			}
		);

		$this->assertSame(1, $this->prefetcher->prefetchPost(self::POST, self::NOW + 120));
	}

	/**
	 * The sweep reads newest first and pages on to older documents when a
	 * page has nothing it may fetch, rather than stopping at it.
	 */
	public function testTheSweepPagesOnPastAPageOfHeldHosts(): void {
		$this->breakerRows = ['down.example' => ['strikes' => 1, 'open_until' => time() + 600, 'last_failure' => time()]];
		$held = [];
		for ($nid = 200; $nid > 200 - CacheDocumentsRequest::CACHE_BATCH; $nid--) {
			$held[] = $this->document($nid, 'down.example');
		}
		$older = [$this->document(10), $this->document(9)];
		$this->cacheDocumentsRequest->expects($this->exactly(2))->method('getNotCachedDocuments')
			->willReturnCallback(static fn (int $limit, int $before): array => match ($before) {
				0 => $held,
				151 => $older,
				default => [],
			});
		$this->transport();

		$this->assertSame(2, $this->prefetcher->prefetchPending(self::NOW + 90));
		$this->assertSame([['https://files.remote.example/10.png', 'https://files.remote.example/9.png']], $this->waves);
	}

	public function testTheSweepStopsAtTheDeadline(): void {
		$this->clock = self::NOW + 100;
		$this->cacheDocumentsRequest->expects($this->never())->method('getNotCachedDocuments');

		$this->assertSame(0, $this->prefetcher->prefetchPending(self::NOW + 90));
	}
}
