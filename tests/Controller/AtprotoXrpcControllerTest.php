<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Atproto\Client\ClientXrpc;
use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\BlobRef;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\OAuth\DpopNonce;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Controller\AtprotoXrpcController;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Response\StreamedPiecesResponse;
use OCA\Social\Response\StreamedRemoteResponse;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ModerationService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `com.atproto.sync.getBlob` as the browser gets it: a blob is served from
 * this instance's origin, so every answer is sandboxed and not sniffed, a
 * picture, a video or a sound keeps its type, and any other type is
 * served as opaque bytes to download.
 */
#[AllowMockObjectsWithoutExpectations]
class AtprotoXrpcControllerTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const SANDBOX = "default-src 'none'; sandbox";

	private ?BlobRef $blob = null;

	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new \DateTime('@1760000000'));
		\OC::$server->register(ITimeFactory::class, $clock);
		$_SERVER['QUERY_STRING'] = '';
	}

	protected function tearDown(): void {
		unset($_SERVER['QUERY_STRING']);
		\OC::$server->reset();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: ?string}> stored type, served type, disposition
	 */
	public static function blobTypes(): array {
		return [
			'a picture' => ['image/png', 'image/png', null],
			'a picture with a parameter' => ['IMAGE/JPEG; charset=binary', 'image/jpeg', null],
			'a sound' => ['audio/mpeg', 'audio/mpeg', null],
			'HTML' => ['text/html', 'application/octet-stream', 'attachment'],
			'SVG' => ['image/svg+xml', 'application/octet-stream', 'attachment'],
			'a script' => ['application/javascript', 'application/octet-stream', 'attachment'],
			'XHTML' => ['application/xhtml+xml', 'application/octet-stream', 'attachment'],
			'a PDF' => ['application/pdf', 'application/octet-stream', 'attachment'],
			'no type' => ['', 'application/octet-stream', 'attachment'],
		];
	}

	#[DataProvider('blobTypes')]
	public function testABlobIsServedSandboxedAndOnlyAMediaTypeInline(string $stored, string $served, ?string $disposition): void {
		$this->blob = new BlobRef(self::DID, Cid::forRaw('bytes'), 'https://social.test/doc/1', $stored, 5);

		$response = $this->controller()->query('com.atproto.sync.getBlob');

		$this->assertInstanceOf(DataDisplayResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('bytes', $response->render());
		$headers = $response->getHeaders();
		$this->assertSame($served, $headers['Content-Type']);
		$this->assertSame(self::SANDBOX, $headers['Content-Security-Policy']);
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		if ($disposition === null) {
			$this->assertStringStartsWith('inline', $headers['Content-Disposition']);
		} else {
			$this->assertSame($disposition, $headers['Content-Disposition']);
		}
	}

	public function testAVideoStreamIsServedSandboxed(): void {
		$this->blob = new BlobRef(self::DID, Cid::forRaw('film'), 'https://social.test/doc/2', 'video/mp4', 4);

		$response = $this->controller()->query('com.atproto.sync.getBlob');

		$this->assertInstanceOf(StreamedRemoteResponse::class, $response);
		$headers = $response->getHeaders();
		$this->assertSame('video/mp4', $headers['Content-Type']);
		$this->assertSame(self::SANDBOX, $headers['Content-Security-Policy']);
		$this->assertSame('nosniff', $headers['X-Content-Type-Options']);
		$this->assertArrayNotHasKey('Content-Disposition', $headers);
	}

	/**
	 * `cids=a&cids=b` is how XRPC spells an array in a query string, and
	 * PHP keeps the last of them: the controller reads them all.
	 */
	public function testAParameterGivenMoreThanOnceIsAllOfItsValues(): void {
		$this->assertSame(['cids' => ['a', 'b c']], AtprotoXrpcController::repeated('did=x&cids=a&cids=b%20c&limit=5'));
		$this->assertSame([], AtprotoXrpcController::repeated('did=x&cids[]=a&cids[]=b'), 'what PHP already keeps whole');
		$this->assertSame([], AtprotoXrpcController::repeated(''));
	}

	/**
	 * A CAR is not a blob: it keeps the answer it had, written out in
	 * pieces as the repository is read.
	 */
	public function testARepositoryCarIsNotGivenTheBlobHeaders(): void {
		$response = $this->controller(['did' => self::DID])->query('com.atproto.sync.getRepo');

		$this->assertInstanceOf(StreamedPiecesResponse::class, $response);
		$headers = $response->getHeaders();
		$this->assertSame('application/vnd.ipld.car', $headers['Content-Type']);
		$this->assertNotSame(self::SANDBOX, $headers['Content-Security-Policy']);
		$output = $this->createMock(IOutput::class);
		$written = '';
		$output->method('setOutput')->willReturnCallback(static function (string $piece) use (&$written): void {
			$written .= $piece;
		});
		$level = ob_get_level();
		$response->callback($output);
		while (ob_get_level() < $level) {
			ob_start();
		}
		$this->assertSame('CAR', $written);
	}

	/**
	 * @param array<string, string>|null $params
	 */
	/** A Bluesky app is told why nothing can be signed, in words its user can pass on to the administrator. */
	public function testAKeyThatDoesNotOpenIsAnsweredAsSuch(): void {
		$client = $this->createMock(ClientXrpc::class);
		$client->method('query')->willThrowException(new AtprotoKeysSealedException('signing key'));
		$client->method('procedure')->willThrowException(new AtprotoKeysSealedException('signing key'));

		foreach ([$this->controller(null, $client)->query('app.bsky.feed.getTimeline'), $this->controller(null, $client)->procedure('com.atproto.repo.createRecord')] as $response) {
			$this->assertSame(503, $response->getStatus());
			$this->assertSame('KeysSealed', $response->getData()['error']);
			$this->assertStringContainsString('occ social:atproto:keys:reseal', $response->getData()['message']);
		}
	}

	public function testAppViewRequestContextHeadersReachTheAuthenticatedProxy(): void {
		$headers = [
			'x-atproto-session-id' => 'session-123',
			'x-atproto-device-id' => 'device-456',
			'x-atproto-bsky-topics' => 'following,for-you',
			'x-bsky-topics' => 'following',
		];
		$client = $this->createMock(ClientXrpc::class);
		$client->expects($this->once())->method('query')->with('app.bsky.feed.getTimeline', '', $headers)->willReturn([]);

		$response = $this->controller(null, $client, $headers)->query('app.bsky.feed.getTimeline');

		$this->assertSame(200, $response->getStatus());
	}

	private function controller(?array $params = null, ?ClientXrpc $client = null, array $headers = []): AtprotoXrpcController {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$identities = $this->createMock(IdentityService::class);
		$identities->method('getByDid')->willReturn(new Identity(1, 'https://social.test/@alice', self::DID, 'alice.social.test', '', 'did:key:z', '', Identity::STATE_ACTIVE, '', 0));
		$repositories = $this->createMock(RepositoryService::class);
		$repositories->method('streamCar')->willReturnCallback(static function (): \Generator {
			yield 'CA';
			yield 'R';
		});
		$blobs = $this->createMock(AtprotoBlobRequest::class);
		$blobs->method('get')->willReturnCallback(fn (): ?BlobRef => $this->blob);
		$pictures = $this->createMock(PictureService::class);
		$pictures->method('read')->willReturn('bytes');
		$videos = $this->createMock(VideoBlobService::class);
		$videos->method('open')->willReturnCallback(static fn (): array => ['stream' => fopen('php://memory', 'rb'), 'size' => 4]);
		$xrpc = new XrpcService(
			$config, $identities, $repositories, $this->createMock(AtprotoRepoRequest::class), $blobs, $pictures, $videos,
			$this->createMock(ConfigService::class), $this->createMock(ModerationService::class), $this->createMock(AtprotoIdentityRequest::class), $this->createMock(HandleVerifier::class), $this->createMock(PlcClient::class), $this->createMock(CurlService::class),
		);
		if ($client === null) {
			$client = $this->createMock(ClientXrpc::class);
			$client->method('query')->willReturn(null);
		}
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params ?? ['did' => self::DID, 'cid' => Cid::forRaw('bytes')->toString()]);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $headers[strtolower($name)] ?? '');

		return new AtprotoXrpcController($request, $xrpc, $client, $this->createMock(DpopNonce::class), $this->createMock(LoggerInterface::class));
	}
}
