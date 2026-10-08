<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Object;

use OCA\Social\Db\CacheDocumentsRequest;
use OCA\Social\Exceptions\CacheDocumentDoesNotExistException;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class ImageInterfaceTest extends ActivityPubTestCase {
	/** @var CacheDocumentService&MockObject */
	private $cacheDocumentService;
	/** @var CacheDocumentsRequest&MockObject */
	private $cacheDocumentsRequest;
	private ImageInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->cacheDocumentService = $this->createMock(CacheDocumentService::class);
		$this->cacheDocumentsRequest = $this->createMock(CacheDocumentsRequest::class);

		// note the constructor takes its collaborators the other way round than DocumentInterface
		$this->handler = new ImageInterface($this->cacheDocumentsRequest, $this->cacheDocumentService, $this->configService);
	}

	private function image(): Image {
		$image = new Image();
		$image->setId(self::REMOTE_URL . '/media/1');
		$image->setUrl(self::REMOTE_URL . '/media/1.png');

		return $image;
	}

	public function testImagesAreDocuments(): void {
		$this->assertInstanceOf(DocumentInterface::class, $this->handler);
	}

	public function testNewRemoteImageIsFetchedIntoTheCacheAndStored(): void {
		$this->cacheDocumentsRequest->method('getById')->willThrowException(new CacheDocumentDoesNotExistException());
		$this->cacheDocumentsRequest->method('isDuplicate')->willReturn(false);
		$image = $this->image();

		$this->cacheDocumentService->expects($this->once())
			->method('saveRemoteFileToCache')->with($this->identicalTo($image));
		$this->cacheDocumentsRequest->expects($this->once())->method('save')->with($this->identicalTo($image));

		$this->handler->save($image);
	}

	public function testKnownImageIsUpdatedInPlace(): void {
		$image = $this->image();
		$this->cacheDocumentsRequest->method('getById')->willReturn($image);

		$this->cacheDocumentsRequest->expects($this->once())->method('update')->with($this->identicalTo($image));
		$this->cacheDocumentsRequest->expects($this->never())->method('save');
		$this->cacheDocumentService->expects($this->never())->method('saveRemoteFileToCache');

		$this->handler->save($image);
	}
}
