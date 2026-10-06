<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\CollectionsRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Story;
use OCA\Social\Model\Instance;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\PixelfedConfigService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What is worth pinning here is not the shape but the *derivation*. This object
 * exists so a client is told the limits the server actually keeps; a number
 * restated by hand would drift, and the failure that causes is the client saying
 * yes to a post the server then refuses.
 */
class PixelfedConfigServiceTest extends TestCase {
	private InstanceService|Stub $instanceService;
	private PixelfedConfigService $service;

	protected function setUp(): void {
		parent::setUp();

		$instance = new Instance();
		$instance->setTitle('Example Social');
		$instance->setUri('cloud.example.org');
		$instance->setShortDescription('a small instance');

		$this->instanceService = $this->createStub(InstanceService::class);
		$this->instanceService->method('getLocal')->willReturn($instance);
		$this->instanceService->method('imageSizeLimit')->willReturn(10 * 1024 * 1024);
		$this->instanceService->method('supportedMimeTypes')
			->willReturn(['image/jpeg', 'image/png', 'video/mp4']);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudUrl')->willReturn('https://cloud.example.org/');

		$this->service = new PixelfedConfigService($this->instanceService, $configService);
	}

	/** The ceiling a picker enforces has to be the one the server enforces. */
	/**
	 * Nothing uploaded here is recompressed — metadata is stripped losslessly
	 * and a picture is re-encoded only to rotate it. A client that reads
	 * `optimize_image: true` may skip its own compression on the strength of
	 * a promise the server does not keep.
	 */
	public function testTheUploaderDoesNotClaimAnOptimisationItDoesNotDo(): void {
		$config = $this->service->config();

		$this->assertFalse($config['uploader']['optimize_image']);
		$this->assertSame(100, $config['uploader']['image_quality']);
	}

	public function testTheAlbumLimitIsTheOneTheServerKeeps(): void {
		$config = $this->service->config();

		$this->assertSame(Stream::MAX_ATTACHMENTS, $config['uploader']['album_limit']);
		$this->assertSame(Stream::MAX_ATTACHMENTS, $config['limits']['max_album_length']);
	}

	public function testTheCollectionCeilingIsTheOneTheServerKeeps(): void {
		$this->assertSame(
			CollectionsRequest::MAX_ITEMS,
			$this->service->config()['uploader']['max_collection_length']
		);
	}

	public function testTheStoryFactsAreTheOnesTheServerKeeps(): void {
		$config = $this->service->config();

		$this->assertSame(Story::MAX_PER_ACTOR, $config['limits']['story_length']);
		$this->assertSame(Story::LIFETIME, $config['limits']['story_lifetime']);
	}

	/**
	 * Pixelfed counts the upload ceiling in kilobytes, which is the one place
	 * its shape differs from Mastodon's bytes. Reporting bytes here would have
	 * the app believe it may send a thousand times what it may.
	 */
	public function testTheUploadCeilingIsReportedInKilobytesNotBytes(): void {
		$this->assertSame(10 * 1024, $this->service->config()['uploader']['max_photo_size']);
	}

	/**
	 * The mime list is asked of the one place that decides what an upload may
	 * be, so a client cannot offer a format the server refuses.
	 */
	public function testTheMediaTypesAreTheOnesTheServerAccepts(): void {
		$this->assertSame(
			'image/jpeg,image/png,video/mp4',
			$this->service->config()['uploader']['media_types']
		);
	}

	/**
	 * An account here exists because a Nextcloud user does. Saying otherwise
	 * would have the app offer a sign-up form that cannot work.
	 */
	public function testRegistrationIsNeverOffered(): void {
		$this->assertFalse($this->service->config()['open_registration']);
	}

	/**
	 * Announcing a screen that is not there is worse than not announcing it:
	 * the app draws the tab, somebody taps it, and it is empty for a reason
	 * nobody can see.
	 */
	public function testWhatIsNotBuiltIsSaidToBeAbsent(): void {
		$features = $this->service->config()['features'];

		$this->assertFalse($features['live_streaming']);
		$this->assertFalse($features['push_notifications']);
		$this->assertFalse($features['circles']);
	}

	public function testWhatIsBuiltIsAnnounced(): void {
		$features = $this->service->config()['features'];

		foreach (['collections', 'stories', 'places', 'albums', 'hashtags', 'polls'] as $built) {
			$this->assertTrue($features[$built], "$built is built and was not announced");
		}
	}

	public function testTheSiteBlockDescribesThisInstance(): void {
		$site = $this->service->config()['site'];

		$this->assertSame('Example Social', $site['name']);
		$this->assertSame('cloud.example.org', $site['domain']);
		$this->assertSame('https://cloud.example.org/', $site['url']);
	}

	/** The app reads these on launch; a missing key is an app that will not start. */
	public function testEveryBlockTheAppReadsIsPresent(): void {
		$config = $this->service->config();

		foreach (['open_registration', 'uploader', 'site', 'account', 'features', 'limits', 'activitypub'] as $key) {
			$this->assertArrayHasKey($key, $config);
		}
		foreach (['max_photo_size', 'max_caption_length', 'album_limit', 'media_types'] as $key) {
			$this->assertArrayHasKey($key, $config['uploader']);
		}
	}
}
