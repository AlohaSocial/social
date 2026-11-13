<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use InvalidArgumentException;
use OCA\Social\AlohaDirectory\DirectoryApiException;
use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\AlohaDirectory\DirectoryParticipation;
use OCA\Social\AlohaDirectory\ProfileBuilder;
use OCA\Social\Controller\AlohaDirectoryController;
use OCA\Social\Service\ExternalUserService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AlohaDirectoryControllerTest extends TestCase {
	private DirectoryParticipation $participation;
	private DirectoryConfig $config;

	private function controller(): AlohaDirectoryController {
		$this->participation->method('domain')->willReturn('cloud.example.com');
		$this->participation->method('state')->willReturn(['phase' => 'none']);
		$profile = $this->createStub(ProfileBuilder::class);
		$profile->method('build')->willReturn(['etag' => 'W/"1"']);
		$profile->method('form')->willReturn([]);
		$lists = $this->createStub(DirectoryLists::class);
		$lists->method('servers')->willReturn([['domain' => 'cloud.example.com', 'signup' => ['capacity' => 'plenty']]]);
		$lists->method('summary')->willReturn(['rootKeyConfigured' => true]);

		return new AlohaDirectoryController(
			$this->createStub(IRequest::class), $this->config, $this->participation, $lists, $profile, $this->createStub(ExternalUserService::class),
		);
	}

	protected function setUp(): void {
		$this->participation = $this->createStub(DirectoryParticipation::class);
		$this->config = $this->createStub(DirectoryConfig::class);
		$this->config->method('directoryUrl')->willReturn('https://directory.aloha.social');
		$this->config->method('listBaseUrl')->willReturn('https://directory.aloha.social');
	}

	public function testTheSectionShowsThisServersPublishedEntry(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame('cloud.example.com', $data['domain']);
		$this->assertSame('plenty', $data['publishedEntry']['signup']['capacity']);
		$this->assertSame('', $data['settings']['listMirror']);
	}

	public function testARefusalIsA422WithTheSection(): void {
		$this->participation->method('enrol')->willThrowException(new InvalidArgumentException('The profile is not complete: operator.name'));

		$response = $this->controller()->enrol();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('The profile is not complete: operator.name', $response->getData()['error']);
		$this->assertSame('cloud.example.com', $response->getData()['domain']);
	}

	public function testTheDirectorysValidationErrorsAreHandedOn(): void {
		$this->participation->method('comment')->willThrowException(new DirectoryApiException(422, 'validation_failed', 'Some fields are invalid.', ['body' => ['is required']]));

		$response = $this->controller()->comment('x');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('validation_failed', $response->getData()['errorCode']);
		$this->assertSame(['body' => ['is required']], $response->getData()['details']);
	}

	public function testAnUnreachableDirectoryIsABadGateway(): void {
		$this->participation->method('withdraw')->willThrowException(new DirectoryApiException(0, 'unreachable', 'down'));

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller()->withdraw()->getStatus());
	}

	public function testANonNumericAccountCapIsRefused(): void {
		$response = $this->controller()->settings('lots');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}
}
