<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\StreamCardsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Db\TrendsRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\StreamCard;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Trending statuses and links, counted live over public posts only.
 */
class TrendsRequestTest extends TestCase {
	private const BASE = 'https://remote.example/trends-request';
	private const AUTHOR = self::BASE . '/users/author';
	private const LINK = 'https://example.org/itest-trends/article';
	private const OTHER_LINK = 'https://example.org/itest-trends/other';
	private const NOTES = ['busy', 'quiet', 'untouched', 'private', 'picture'];

	private TrendsRequest $trends;
	private StreamRequest $streamRequest;
	private ActionsRequest $actionsRequest;
	private StreamCardsRequest $cardsRequest;
	private CacheActorsRequest $cacheActorsRequest;
	/** @var array<string, string> suffix => nid */
	private array $nids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->trends = Server::get(TrendsRequest::class);
		$this->streamRequest = Server::get(StreamRequest::class);
		$this->actionsRequest = Server::get(ActionsRequest::class);
		$this->cardsRequest = Server::get(StreamCardsRequest::class);
		$this->cacheActorsRequest = Server::get(CacheActorsRequest::class);
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		foreach (self::NOTES as $suffix) {
			$id = self::BASE . '/notes/' . $suffix;
			$this->streamRequest->deleteById($id, Note::TYPE);
			$this->cardsRequest->deleteByStreamId($id);
			foreach ($this->actionsRequest->getByObjectId($id) as $action) {
				$this->actionsRequest->delete($action);
			}
		}
		$this->cacheActorsRequest->deleteCacheById(self::AUTHOR);
		$this->nids = [];
	}

	private function note(string $suffix, bool $public = true, bool $picture = false, int $ago = 0): string {
		$note = new Note();
		$note->setId(self::BASE . '/notes/' . $suffix);
		$note->setAttributedTo(self::AUTHOR);
		$note->setTo($public ? ACore::CONTEXT_PUBLIC : self::AUTHOR . '/followers');
		$note->setVisibility($public ? 'public' : 'private');
		$note->setContent('<p>' . $suffix . '</p>');
		if ($picture) {
			$media = new MediaAttachment();
			$media->setId($suffix . '-media');
			$media->setType('image');
			$media->setUrl(self::BASE . '/media/' . $suffix . '.jpg');
			$media->setPreviewUrl(self::BASE . '/media/' . $suffix . '.jpg');
			$note->setAttachments([$media]);
		}
		$note->setPublishedTime(time() - $ago);
		$note->setPublished(gmdate('Y-m-d\TH:i:s\Z', time() - $ago));
		$this->streamRequest->save($note);

		return $this->nids[$suffix] = (string)$note->getNid();
	}

	private function like(string $suffix, int $times): void {
		for ($i = 0; $i < $times; $i++) {
			$like = new Like();
			$like->setId(self::BASE . '/likes/' . $suffix . '/' . $i);
			$like->setActorId(self::BASE . '/users/fan' . $i);
			$like->setObjectId(self::BASE . '/notes/' . $suffix);
			$this->actionsRequest->save($like);
		}
	}

	private function card(string $suffix, string $url): void {
		$card = new StreamCard(self::BASE . '/notes/' . $suffix, $url);
		$card->setTitle('Headline of ' . $suffix);
		$this->cardsRequest->save($card);
	}

	/**
	 * @param string[] $nids
	 * @return string[] the suffixes of ours among them, in order
	 */
	private function ours(array $nids): array {
		$bySuffix = array_flip($this->nids);

		return array_values(array_map(
			fn (string $nid) => $bySuffix[$nid],
			array_filter($nids, fn (string $nid) => isset($bySuffix[$nid]))
		));
	}

	public function testOnlyInteractedPublicStatusesTrendMostInteractedFirst(): void {
		$this->note('busy');
		$this->note('quiet');
		$this->note('untouched');
		$this->note('private', false);
		$this->like('busy', 3);
		$this->like('quiet', 1);
		$this->like('private', 5);

		$trending = $this->trends->trendingStatusNids(time() - 3600, 40, 0);

		$this->assertSame(['busy', 'quiet'], $this->ours($trending));
		$this->assertSame([], $this->ours($this->trends->trendingStatusNids(time() + 3600, 40, 0)), 'nothing inside a future window');
	}

	public function testTheMediaScreensOnlySeeStatusesWithMedia(): void {
		$this->note('busy');
		$this->note('picture', true, true);
		$this->like('busy', 2);
		$this->like('picture', 1);

		$this->assertSame(['picture'], $this->ours($this->trends->trendingStatusNids(time() - 3600, 40, 0, true)));
		$this->assertSame(['picture'], $this->ours($this->trends->trendingStatusNids(time() - 3600, 40, 0, true, 'image')));
		$this->assertSame([], $this->ours($this->trends->trendingStatusNids(time() - 3600, 40, 0, true, 'video')));
	}

	public function testRecentMediaIsNewestFirstAndSkipsWhatIsAlreadyShown(): void {
		$this->note('busy');
		$picture = $this->note('picture', true, true);

		$this->assertSame(['picture'], $this->ours($this->trends->recentMediaNids(40)));
		$this->assertSame(['picture'], $this->ours($this->trends->recentMediaNids(40, 'image')));
		$this->assertSame([], $this->ours($this->trends->recentMediaNids(40, '', [$picture])));
		$this->assertSame([], $this->trends->recentMediaNids(0));
	}

	public function testRecentPublicIsNewestFirstAndSkipsWhatIsAlreadyShownOrNotPublic(): void {
		$older = $this->note('older', true, false, 120);
		$this->note('private', false);
		$newer = $this->note('newer');

		$this->assertSame(['newer', 'older'], $this->ours($this->trends->recentPublicNids(40)));
		$this->assertSame(['older'], $this->ours($this->trends->recentPublicNids(40, [$newer])));
		$this->assertSame(['newer'], $this->ours($this->trends->recentPublicNids(1)));
		$this->assertSame([], $this->trends->recentPublicNids(0));
		$this->assertNotSame('', $older);
	}

	public function testStatusesComeBackInTheOrderAsked(): void {
		$author = new Person();
		$author->setId(self::AUTHOR)->setPreferredUsername('trends-author');
		$author->setAccount('trends-author@remote.example')
			->setInbox(self::AUTHOR . '/inbox')
			->setOutbox(self::AUTHOR . '/outbox')
			->setFollowers(self::AUTHOR . '/followers')
			->setFollowing(self::AUTHOR . '/following');
		$this->cacheActorsRequest->save($author);
		$busy = $this->note('busy');
		$quiet = $this->note('quiet');

		$statuses = $this->trends->statusesByNids([$quiet, $busy, '999999999999']);

		$this->assertSame([$quiet, $busy], array_map(fn ($s) => (string)$s->getNid(), $statuses));
		$this->assertSame([], $this->trends->statusesByNids([]));
	}

	public function testALinkTrendsByHowManyPublicPostsShareIt(): void {
		$this->note('busy');
		$this->note('quiet');
		$this->note('private', false);
		$this->card('busy', self::LINK);
		$this->card('quiet', self::LINK);
		$this->card('private', self::OTHER_LINK);

		$links = array_column($this->trends->trendingLinks(time() - 3600, 40, 0), 'shares', 'url');

		$this->assertSame(2, $links[self::LINK] ?? 0);
		$this->assertArrayNotHasKey(self::OTHER_LINK, $links, 'a private post made its link trend');
	}

	public function testALinksTimelineIsItsPublicPostsNewestFirst(): void {
		$busy = $this->note('busy', ago: 120);
		$quiet = $this->note('quiet');
		$this->note('private', false);
		foreach (['busy', 'quiet', 'private'] as $suffix) {
			$this->card($suffix, self::LINK);
		}

		$this->assertSame(['quiet', 'busy'], $this->ours($this->trends->statusNidsForUrl(self::LINK, 40)));
		$this->assertSame(['busy'], $this->ours($this->trends->statusNidsForUrl(self::LINK, 40, $quiet)));
		$this->assertSame(['quiet'], $this->ours($this->trends->statusNidsForUrl(self::LINK, 40, '0', $busy)));
	}

	public function testOneCardIsKeptPerUrl(): void {
		$this->note('busy');
		$this->note('quiet');
		$this->card('busy', self::LINK);
		$this->card('quiet', self::LINK);

		$cards = $this->trends->cardsByUrls([self::LINK, self::OTHER_LINK]);

		$this->assertSame([self::LINK], array_keys($cards));
		$this->assertStringStartsWith('Headline of ', $cards[self::LINK]->getTitle());
		$this->assertSame([], $this->trends->cardsByUrls([]));
	}
}
