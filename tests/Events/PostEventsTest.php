<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Events;

use OCA\Social\Events\PostDeletedEvent;
use OCA\Social\Events\PostEditedEvent;
use OCA\Social\Events\PostPublishedEvent;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * The two things the rest of a Nextcloud server can now hear about.
 *
 * The app had no events at all: nothing else on the server could know that
 * somebody had posted, so an Activity entry, a Talk message, a Flow rule or an
 * integration of somebody's own had nothing to listen to — every one of them
 * would have had to poll this app's API as a client, from inside the same
 * server.
 */
class PostEventsTest extends TestCase {
	private function post(): Note {
		$note = new Note();
		$note->setId('https://cloud.example/apps/social/@alice/1');
		$note->setAttributedTo('https://cloud.example/apps/social/@alice');

		return $note;
	}

	public function testAPublishedPostCarriesItselfAndItsAuthor(): void {
		$post = $this->post();

		$event = new PostPublishedEvent($post);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame($post, $event->getPost());
		$this->assertSame('https://cloud.example/apps/social/@alice', $event->getAuthorId());
	}

	/**
	 * A listener that copied the post somewhere needs the id to remove its own
	 * copy, and nothing can hand that over once the row is gone.
	 */
	public function testADeletedPostStillCarriesTheIdAListenerNeeds(): void {
		$post = $this->post();

		$event = new PostDeletedEvent($post);

		$this->assertSame('https://cloud.example/apps/social/@alice/1', $event->getPost()->getId());
		$this->assertSame('https://cloud.example/apps/social/@alice', $event->getAuthorId());
	}

	public function testAnEditedPostCarriesItselfAndItsAuthor(): void {
		$post = $this->post();

		$event = new PostEditedEvent($post);

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame($post, $event->getPost());
		$this->assertSame('https://cloud.example/apps/social/@alice', $event->getAuthorId());
	}
}
