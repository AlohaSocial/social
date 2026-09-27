<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ChannelsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Channel;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PeerTubeApiService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\SensitiveMediaService;
use OCA\Social\Service\VideoLadderService;
use OCA\Social\Service\VideoQuotaService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * This app's things in the shapes PeerTube's own clients read.
 *
 * What is asserted hardest is the two places a translation can lie: a switch
 * answered with PeerTube's default rather than with what is true here, and an
 * id invented to fill a field.
 */
#[AllowMockObjectsWithoutExpectations]
class PeerTubeApiServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const POST = 'https://cloud.example/apps/social/@alice/17';

	private SensitiveMediaService|Stub $sensitiveMediaService;
	private VideoQuotaService|Stub $videoQuotaService;
	private VideoLadderService|Stub $videoLadderService;
	private ChannelsRequest|MockObject $channelsRequest;
	private PeerTubeApiService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->sensitiveMediaService = $this->createStub(SensitiveMediaService::class);
		$this->sensitiveMediaService->method('instancePolicy')->willReturn(SensitiveMediaService::COVERED);
		$this->sensitiveMediaService->method('policyFor')->willReturn(SensitiveMediaService::HIDE_ALL);

		$this->videoQuotaService = $this->createStub(VideoQuotaService::class);
		$this->videoLadderService = $this->createStub(VideoLadderService::class);
		$this->channelsRequest = $this->createMock(ChannelsRequest::class);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string
				=> ($key === 'name') ? 'Videos at Example' : $default
		);

		$this->service = new PeerTubeApiService(
			$this->createStub(ConfigService::class),
			$this->createStub(ChannelService::class),
			$this->channelsRequest,
			$this->createStub(CacheActorService::class),
			$this->sensitiveMediaService,
			$this->videoQuotaService,
			$this->videoLadderService,
			$appConfig,
		);
	}

	private function alice(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE)->setPreferredUsername('alice')->setName('Alice');
		$actor->setNid(3);

		return $actor;
	}

	private function video(array $meta = []): Stream {
		$note = new Note();
		$note->setId(self::POST)
			->setLocal(true)
			->setAttributedTo(self::ALICE)
			->setVisibility(Stream::TYPE_PUBLIC);
		$note->setNid(17);
		$note->setContent('<p>A cat and a glass</p>');
		$note->setPublished('2025-06-01T12:00:00Z');
		$note->convertPublished();
		$note->setActor($this->alice());
		$note->setVideoMeta(array_merge([
			'title' => 'A cat and a glass',
			'duration' => 113,
			'category' => 'Science & Technology',
			'licence' => 'Attribution',
		], $meta));

		return $note;
	}

	// --- the config a client reads on launch -------------------------------

	/**
	 * An account here is a Nextcloud account and is not made through this API.
	 * Saying otherwise sends somebody to a form that does not exist.
	 */
	public function testSigningUpIsNotOfferedThroughThisApi(): void {
		$config = $this->service->config();

		$this->assertFalse($config['signup']['allowed']);
		$this->assertFalse($config['import']['videos']['http']['enabled']);
	}

	/** The real state of the ladder, not PeerTube's default. */
	public function testTheTranscodingSwitchesSayWhatIsTrueHere(): void {
		$this->videoLadderService->method('isEnabled')->willReturn(true);
		$this->videoLadderService->method('heights')->willReturn([360, 720]);

		$config = $this->service->config();

		$this->assertTrue($config['transcoding']['hls']['enabled']);
		$this->assertSame([360, 720], $config['transcoding']['enabledResolutions']);
	}

	/** PeerTube's own way of saying "no quota", and what its clients check for. */
	public function testNoQuotaIsReportedAsMinusOneRatherThanZero(): void {
		$this->videoQuotaService->method('quota')->willReturn(VideoQuotaService::UNLIMITED);

		$this->assertSame(-1, $this->service->config()['user']['videoQuota']);
	}

	public function testAQuotaIsReportedInBytes(): void {
		$this->videoQuotaService->method('quota')->willReturn(100);

		$this->assertSame(100 * 1048576, $this->service->config()['user']['videoQuota']);
	}

	/** The instance policy, translated back into PeerTube's three words. */
	public function testTheNsfwPolicyIsGivenInPeerTubesVocabulary(): void {
		$this->assertSame('blur', $this->service->config()['instance']['defaultNSFWPolicy']);
	}

	/**
	 * A client that reports the server version to its user must not be told
	 * this server runs PeerTube, so both are here: the API version its checks
	 * compare against, and the name of what is actually answering.
	 */
	public function testWhatIsActuallyRunningIsSaidBesideTheApiVersion(): void {
		$config = $this->service->config();

		$this->assertSame(PeerTubeApiService::PEERTUBE_VERSION, $config['serverVersion']);
		$this->assertSame('nextcloud-social', $config['software']['name']);
	}

	public function testTheInstanceNameIsTheOneThemingHolds(): void {
		$this->assertSame('Videos at Example', $this->service->config()['instance']['name']);
	}

	// --- a video ------------------------------------------------------------

	/**
	 * A video seen through this API and the same video seen over ActivityPub
	 * must carry one uuid, not two nothing can tell apart.
	 */
	public function testTheUuidIsTheOneThisAppAlreadyPublishesOnTheWire(): void {
		$video = $this->service->video($this->video());

		$this->assertSame(PeerTubeService::uuidFor(self::POST), $video['uuid']);
		$this->assertSame($video['uuid'], $video['shortUUID']);
		$this->assertSame(17, $video['id']);
	}

	/**
	 * PeerTube's ids are indexes into its own lists, which this app does not
	 * have: a wrong id is a client showing the wrong category with confidence.
	 */
	public function testACategoryCarriesItsLabelAndNoInventedId(): void {
		$video = $this->service->video($this->video());

		$this->assertSame(['id' => 0, 'label' => 'Science & Technology'], $video['category']);
		$this->assertSame(['id' => 0, 'label' => 'Attribution'], $video['licence']);
	}

	public function testSomethingTheVideoDoesNotSayIsNullRatherThanAnEmptyLabel(): void {
		$video = $this->service->video($this->video(['category' => '']));

		$this->assertNull($video['category']);
	}

	public function testTheListingIsShortAndTheSingleVideoCarriesTheRest(): void {
		$listed = $this->service->video($this->video());
		$detailed = $this->service->video($this->video(), true);

		$this->assertArrayNotHasKey('description', $listed);
		$this->assertArrayNotHasKey('files', $listed);
		$this->assertSame('A cat and a glass', $detailed['description']);
		$this->assertArrayHasKey('files', $detailed);
	}

	public function testAnUnlistedVideoIsReportedAsUnlisted(): void {
		$post = $this->video();
		$post->setVisibility(Stream::TYPE_UNLISTED);

		$this->assertSame(['id' => 2, 'label' => 'Unlisted'], $this->service->video($post)['privacy']);
	}

	/**
	 * Making an actor on a read path would be a write per video per page; an
	 * account that has never posted a video has no channel, and its own
	 * account stands in — which is what a client draws anyway.
	 */
	public function testAnAccountWithNoChannelIsNotGivenOne(): void {
		$this->channelsRequest->expects($this->once())->method('getByOwner')->willReturn([]);

		$video = $this->service->video($this->video());

		$this->assertSame('alice', $video['channel']['name']);
	}

	public function testTheChannelIsTheDefaultOneWhereThereIsOne(): void {
		$channel = new Channel();
		$channel->setId(1)
			->setActorId('https://cloud.example/apps/social/@alice_channel')
			->setOwnerId(self::ALICE)
			->setName('Alice on video')
			->setDefault(true);
		$this->channelsRequest->method('getByOwner')->willReturn([$channel]);

		$this->assertSame('Alice on video', $this->service->video($this->video())['channel']['displayName']);
	}

	/**
	 * A video posted before this app recorded a title and a running time has
	 * no video metadata at all, and its duration is still on the file.
	 */
	public function testTheDurationFallsBackToTheFilesOwn(): void {
		$attachmentMeta = new \OCA\Social\Model\Client\AttachmentMeta();
		$attachmentMeta->setDuration(240);

		$attachment = new \OCA\Social\Model\Client\MediaAttachment();
		$attachment->setId('9')->setType('video')->setMediaType('video/mp4')
			->setUrl('https://cloud.example/media/movie.mp4')
			->setMeta($attachmentMeta);

		$post = $this->video(['duration' => 0]);
		$post->setAttachments([$attachment]);

		$this->assertSame(240, $this->service->video($post)['duration']);
	}

	// --- the signed-in account ---------------------------------------------

	/**
	 * PeerTube's own answer carries an email; a Nextcloud account's address is
	 * not this API's to hand to whatever client holds a token.
	 */
	public function testTheAccountsEmailIsNotHandedToAClient(): void {
		$user = $this->service->user($this->alice(), 'alice');

		$this->assertNull($user['email']);
		$this->assertSame('alice', $user['username']);
	}

	public function testTheReadersOwnNsfwChoiceIsReportedInPeerTubesWords(): void {
		$this->assertSame('do_not_list', $this->service->user($this->alice(), 'alice')['nsfwPolicy']);
	}

	// --- the page wrapper ---------------------------------------------------

	/**
	 * `total` is the size of this page. This app's timelines are keyed on a
	 * cursor and have no count to give, and a number invented for the shape's
	 * sake is one a client would draw a pager from.
	 */
	public function testAPageSaysHowManyItHoldsAndDoesNotInventATotal(): void {
		$this->assertSame(['total' => 2, 'data' => ['a', 'b']], $this->service->page(['a', 'b']));
		$this->assertSame(['total' => 0, 'data' => []], $this->service->page([]));
	}
}
