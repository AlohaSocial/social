<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\Gif;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\GifPackService;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The animated emoji every instance has in its picker.
 *
 * The weight here is on the fetch: it is this app asking a third party for a
 * file and then serving the answer to everybody, so what it will accept, what
 * it will not, and how often it asks are the things worth pinning.
 */
#[AllowMockObjectsWithoutExpectations]
class GifPackServiceTest extends TestCase {
	private ISimpleFolder&MockObject $folder;
	private IClient&MockObject $client;
	private ConfigService&Stub $config;
	private GifPackService $service;

	protected function setUp(): void {
		$this->folder = $this->createMock(ISimpleFolder::class);
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);

		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://cloud.example/gif/' . $args['slug']
		);

		$this->config = $this->createStub(ConfigService::class);
		$this->config->method('getAppValueBool')->willReturn(true);

		$this->service = new GifPackService(
			$appData, $clientService, $urlGenerator, $this->config, new NullLogger()
		);
	}

	/**
	 * The manifest is a shipped file rather than a fixture: if it stops being
	 * readable, or stops being the shape this reads, every picker on every
	 * instance quietly empties and nothing else would say so.
	 */
	public function testTheShippedListIsThereAndIsRead(): void {
		$all = $this->service->all();

		$this->assertGreaterThan(500, count($all));
		$this->assertContainsOnlyInstancesOf(Gif::class, $all);
	}

	/**
	 * Every line of the shipped list is offered.
	 *
	 * The codepoint is checked before it is used, because it becomes both a
	 * URL this server fetches and a filename it writes — and the first version
	 * of that check wanted four hex digits, which silently dropped the two
	 * emoji whose codepoint has two: (C) and (R). A rule that throws entries
	 * away without a word is one that has to be counted.
	 */
	public function testNothingInTheShippedListIsSilentlyDropped(): void {
		$manifest = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/data/noto-animated-emoji.json'),
			true
		);

		$this->assertCount(count($manifest['emoji']), $this->service->all());
		$this->assertNotNull($this->service->bySlug('noto-a9_fe0f'));
	}

	public function testTheMostAskedForComeFirst(): void {
		$first = $this->service->all()[0];

		// the picker opens on the first screenful and the warm-up job fetches
		// exactly that, so the order is load-bearing rather than cosmetic
		$this->assertSame('noto-1f600', $first->getSlug());
		$this->assertSame('smile', $first->getTitle());
	}

	public function testEveryOneIsServedFromThisInstance(): void {
		foreach (array_slice($this->service->all(), 0, 20) as $gif) {
			$this->assertStringStartsWith('https://cloud.example/gif/noto-', $gif->getUrl());
			$this->assertSame('image/webp', $gif->getMediaType());
		}
	}

	public function testSearchingMatchesTheName(): void {
		$found = $this->service->search('hedgehog');

		$this->assertNotSame([], $found);
		$this->assertStringContainsString('hedgehog', $found[0]->getTitle());
	}

	/** Google's other names for a picture, and its category, are searched too. */
	public function testSearchingMatchesTheKeywords(): void {
		$this->assertNotSame([], $this->service->search('animals and nature'));
	}

	public function testSearchingIgnoresCase(): void {
		$this->assertNotSame([], $this->service->search('HEDGEHOG'));
	}

	public function testAnEmptyTermIsTheWholePack(): void {
		$this->assertCount(count($this->service->all()), $this->service->search('  '));
	}

	public function testSearchingFindsNothingRatherThanEverything(): void {
		$this->assertSame([], $this->service->search('zzzaardvarkzzz'));
	}

	public function testItOwnsItsOwnSlugsAndNobodyElseIs(): void {
		$this->assertTrue($this->service->owns('noto-1f600'));
		$this->assertFalse($this->service->owns('happy-cat'));
	}

	public function testAnUnknownCodepointIsNotInTheLibrary(): void {
		$this->assertNull($this->service->bySlug('noto-ffffff'));
		$this->assertNull($this->service->bySlug('happy-cat'));
	}

	public function testATurnedOffPackOffersNothing(): void {
		$service = $this->serviceWithPackOff();

		$this->assertSame([], $service->all());
		$this->assertSame([], $service->search('smile'));
		$this->assertNull($service->bySlug('noto-1f600'));
	}

	/** A picture this instance already has is read, not asked for again. */
	public function testACachedPictureIsNotFetched(): void {
		$file = $this->createStub(ISimpleFile::class);
		$this->folder->method('getFile')->with('1f600.webp')->willReturn($file);
		$this->client->expects($this->never())->method('get');

		$this->assertSame($file, $this->service->file('noto-1f600'));
	}

	public function testOneThatIsNotHereYetIsFetchedAndKept(): void {
		$webp = 'RIFF' . str_repeat('x', 4) . 'WEBPVP8X' . str_repeat('y', 32);
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->client->expects($this->once())
			->method('get')
			->with('https://fonts.gstatic.com/s/e/notoemoji/latest/1f600/512.webp')
			->willReturn($this->answering(200, $webp));

		$stored = $this->createStub(ISimpleFile::class);
		$this->folder->expects($this->once())
			->method('newFile')
			->with('1f600.webp', $webp)
			->willReturn($stored);

		$this->assertSame($stored, $this->service->file('noto-1f600'));
	}

	/**
	 * The bytes decide, as they do for a picture an administrator adds. This
	 * is served to everybody here and ends up attached to posts that federate,
	 * so "the URL said webp" is not good enough.
	 */
	public function testSomethingThatIsNotAWebPIsRefused(): void {
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->client->method('get')->willReturn($this->answering(200, '<html>nope</html>'));
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-1f600');
	}

	public function testASourceThatRefusesIsNotStored(): void {
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->client->method('get')->willReturn($this->answering(404, ''));
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-1f600');
	}

	/** Nothing is fetched for a slug the manifest does not have. */
	public function testAnUnknownSlugIsNeverFetched(): void {
		$this->client->expects($this->never())->method('get');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-ffffff');
	}

	public function testATurnedOffPackFetchesNothing(): void {
		$this->client->expects($this->never())->method('get');

		$this->expectException(NotFoundException::class);
		$this->serviceWithPackOff()->file('noto-1f600');
	}

	/** What CC BY asks for, read from the manifest so it cannot drift. */
	public function testTheCreditIsCarriedWithTheList(): void {
		$this->assertStringContainsString('CC BY 4.0', $this->service->attribution());
	}

	private function answering(int $status, string $body): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	private function serviceWithPackOff(): GifPackService {
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn(false);

		return new GifPackService(
			$appData,
			$clientService,
			$this->createStub(IURLGenerator::class),
			$config,
			new NullLogger()
		);
	}
}
