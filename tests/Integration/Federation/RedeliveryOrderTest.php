<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Federation;

use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\LinkedDataSignature;
use OCA\Social\Service\DurableCache;
use OCA\Social\Service\SignatureService;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the Fediverse actually does to an inbox: it delivers the same
 * activity again (a retry after a timeout, a relay and a follower both
 * forwarding it), and it delivers activities out of order (separate queues,
 * separate workers, a retry overtaking a later activity). Against the real
 * database, through the inbox handling.
 *
 * A redelivery must leave one row and raise nothing — on PostgreSQL a
 * swallowed unique violation would also poison the rest of the transaction,
 * which is why each test ends by reading through the same connection.
 */
class RedeliveryOrderTest extends InboxTestCase {
	private const CAROL = 'https://mastodon.example/users/carol';
	private const POST = self::CAROL . '/statuses/116000000000000001';

	protected function hosts(): array {
		return ['mastodon.example', 'misskey.example', 'gts.example', 'akkoma.example', 'peertube.example'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->forgetEarlyDelete();
		$this->forgetEarlyUpdate();
		$this->forgetEarlyUndo();
		$this->localPost();
		$this->remoteActor(self::CAROL, 'carol');
	}

	protected function tearDown(): void {
		$this->forgetEarlyDelete();
		$this->forgetEarlyUpdate();
		$this->forgetEarlyUndo();
		parent::tearDown();
	}

	/** An early Delete is remembered across requests, so across tests too. */
	private function forgetEarlyDelete(): void {
		Server::get(DurableCache::class)->removeShared(DeleteInterface::EARLY_DELETE_NAMESPACE, hash('sha256', self::POST));
	}

	private function forgetEarlyUpdate(): void {
		Server::get(DurableCache::class)->removeShared(
			NoteInterface::EARLY_UPDATE_NAMESPACE,
			'update:' . hash('sha256', self::POST),
		);
	}

	private function forgetEarlyUndo(): void {
		Server::get(DurableCache::class)->removeShared(
			LikeInterface::EARLY_UNDO_NAMESPACE,
			hash('sha256', self::CAROL . '#likes/1'),
		);
	}

	private function note(string $content, string $updated = ''): array {
		return array_filter([
			'id' => self::POST,
			'type' => 'Note',
			'attributedTo' => self::CAROL,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc' => [self::CAROL . '/followers'],
			'content' => $content,
			'published' => '2026-05-01T10:00:00Z',
			'updated' => $updated,
		]);
	}

	private function activity(string $type, string $id, array|string $object): string {
		return (string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $id,
			'type' => $type,
			'actor' => self::CAROL,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => $object,
		]);
	}

	private function create(string $content = '<p>first</p>'): string {
		return $this->activity('Create', self::POST . '/activity', $this->note($content));
	}

	private function update(string $content, string $updated): string {
		return $this->activity('Update', self::POST . '#updates/' . strtotime($updated), $this->note($content, $updated));
	}

	private function delete(): string {
		return $this->activity('Delete', self::POST . '#delete', ['id' => self::POST, 'type' => 'Tombstone']);
	}

	/** The connection is still good for a read after whatever happened before. */
	private function assertConnectionUsable(): void {
		$this->assertNotNull($this->stored($this->localPostId()), 'the connection still answers');
	}

	/**
	 * Fixtures of the corpus that write rows, each delivered twice: the rows
	 * after the second delivery are the rows after the first (a reply that
	 * mentions the local account writes its notification too, once).
	 *
	 * @return iterable<string, array{string, string, string, string}>
	 */
	public static function redeliveries(): iterable {
		yield 'a post' => ['misskey-create-note', 'misskey.example', CoreRequestBuilder::TABLE_STREAM, 'id'];
		yield 'a reply' => ['gotosocial-create-note', 'gts.example', CoreRequestBuilder::TABLE_STREAM, 'id'];
		yield 'a like' => ['misskey-like-reaction', 'misskey.example', CoreRequestBuilder::TABLE_ACTIONS, 'actor_id'];
		yield 'a dislike' => ['peertube-dislike', 'peertube.example', CoreRequestBuilder::TABLE_ACTIONS, 'actor_id'];
		yield 'a block' => ['mastodon-block', 'mastodon.example', CoreRequestBuilder::TABLE_ACTOR_RELATION, 'object_id'];
		yield 'a quote request' => ['mastodon-quote-request', 'mastodon.example', CoreRequestBuilder::TABLE_QUOTE_GRANTS, 'actor_id'];
	}

	#[DataProvider('redeliveries')]
	public function testTheSameActivityTwiceLeavesOneRowAndRaisesNothing(string $fixture, string $host, string $table, string $column): void {
		$actor = (string)(json_decode($this->fixture($fixture), true)['actor'] ?? '');
		$this->remoteActor($actor, 'someone');

		$this->assertTrue($this->deliver($this->fixture($fixture)));
		$once = $this->rows($table, $column, 'https://' . $host . '/');
		$this->assertNotSame([], $once, 'the first delivery wrote a row');

		$this->assertTrue($this->deliver($this->fixture($fixture)));

		$this->assertSame($once, $this->rows($table, $column, 'https://' . $host . '/'), 'the second wrote nothing more');
		$this->assertConnectionUsable();
	}

	/** An Akkoma reaction delivered twice is one reaction. */
	public function testTheSameReactionTwiceIsOneReaction(): void {
		$reaction = json_decode($this->fixture('akkoma-emoji-react'), true);
		$reaction['content'] = '🍅';
		unset($reaction['tag']);
		$this->remoteActor($reaction['actor'], 'frank');

		$this->assertTrue($this->deliver((string)json_encode($reaction)));
		$this->assertTrue($this->deliver((string)json_encode($reaction)));

		$this->assertCount(1, $this->rows(CoreRequestBuilder::TABLE_REACTIONS, 'actor_id', 'https://akkoma.example/'));
		$this->assertConnectionUsable();
	}

	/** An edit that overtakes its Create is applied as soon as that post is stored. */
	public function testAnUpdateBeforeItsCreateIsAppliedWhenTheCreateArrives(): void {
		$this->assertTrue($this->deliver($this->update('<p>edited</p>', '2026-05-01T11:00:00Z')));
		$this->assertNull($this->stored(self::POST));

		$this->assertTrue($this->deliver($this->create()));

		$this->assertSame('<p>edited</p>', $this->stored(self::POST)?->getContent());
		$this->assertSame('2026-05-01T11:00:00Z', $this->stored(self::POST)?->getUpdated());
	}

	/** Only the newest pre-Create edit is retained, even if an older delivery arrives last. */
	public function testTheNewestUpdateBeforeItsCreateWins(): void {
		$this->assertTrue($this->deliver($this->update('<p>newest edit</p>', '2026-05-01T12:00:00Z')));
		$this->assertTrue($this->deliver($this->update('<p>older edit</p>', '2026-05-01T11:00:00Z')));

		$this->assertTrue($this->deliver($this->create()));

		$this->assertSame('<p>newest edit</p>', $this->stored(self::POST)?->getContent());
		$this->assertSame('2026-05-01T12:00:00Z', $this->stored(self::POST)?->getUpdated());
	}

	/** An undated poll refresh must not replace a dated edit waiting for its Create. */
	public function testUndatedUpdateDoesNotReplaceDatedUpdateBeforeCreate(): void {
		$this->assertTrue($this->deliver($this->update('<p>newest edit</p>', '2026-05-01T12:00:00Z')));
		$this->assertTrue($this->deliver($this->update('<p>poll snapshot</p>', '')));

		$this->assertTrue($this->deliver($this->create()));

		$this->assertSame('<p>newest edit</p>', $this->stored(self::POST)?->getContent());
		$this->assertSame('2026-05-01T12:00:00Z', $this->stored(self::POST)?->getUpdated());
	}

	/** An early Delete must discard an edit so a later Create cannot resurrect either. */
	public function testDeleteBeforeCreateDiscardsPendingUpdate(): void {
		$this->assertTrue($this->deliver($this->update('<p>edited</p>', '2026-05-01T11:00:00Z')));
		$this->assertTrue($this->deliver($this->delete()));

		$this->assertTrue($this->deliver($this->create()));

		$this->assertNull($this->stored(self::POST));
	}

	/**
	 * Two edits arriving in the wrong order: the older one, by its `updated`,
	 * must not overwrite the newer one already applied.
	 */
	public function testAnOlderEditArrivingLastDoesNotOverwriteANewerOne(): void {
		$this->assertTrue($this->deliver($this->create()));
		$this->assertTrue($this->deliver($this->update('<p>second edit</p>', '2026-05-01T12:00:00Z')));

		$this->assertTrue($this->deliver($this->update('<p>first edit</p>', '2026-05-01T11:00:00Z')));

		$stored = $this->stored(self::POST);
		$this->assertSame('<p>second edit</p>', $stored?->getContent());
		$this->assertSame('2026-05-01T12:00:00Z', $stored?->getUpdated());
	}

	/** The same edit twice is applied once and changes nothing the second time. */
	public function testTheSameEditTwiceIsHarmless(): void {
		$this->assertTrue($this->deliver($this->create()));
		$this->assertTrue($this->deliver($this->update('<p>edited</p>', '2026-05-01T11:00:00Z')));
		$this->assertTrue($this->deliver($this->update('<p>edited</p>', '2026-05-01T11:00:00Z')));

		$this->assertSame('<p>edited</p>', $this->stored(self::POST)?->getContent());
		$this->assertConnectionUsable();
	}

	/**
	 * A Delete that overtakes its Create: the post was taken down before it
	 * arrived, and the late Create must not put it back.
	 */
	public function testADeleteBeforeItsCreateKeepsThePostDeleted(): void {
		$this->assertTrue($this->deliver($this->delete()));

		$this->assertTrue($this->deliver($this->create()));

		$this->assertNull($this->stored(self::POST));
	}

	/**
	 * An Undo of a like that has not arrived yet is remembered, so a late Like
	 * redelivery cannot restore an interaction its actor already removed.
	 */
	public function testAnUndoOfALikeBeforeTheLikeKeepsTheLateLikeUndone(): void {
		$like = [
			'id' => self::CAROL . '#likes/1', 'type' => 'Like', 'actor' => self::CAROL, 'object' => $this->localPostId(),
		];
		$this->assertTrue($this->deliver($this->activity('Undo', self::CAROL . '#likes/1/undo', $like)));
		$this->assertSame(0, $this->statusEntity($this->localPostId())['favourites_count']);

		$this->assertTrue($this->deliver((string)json_encode(['@context' => 'https://www.w3.org/ns/activitystreams'] + $like)));

		$this->assertSame(0, $this->statusEntity($this->localPostId())['favourites_count']);
		$this->assertConnectionUsable();
	}

	/** Carol's poll as her server sends it, with the tallies so far. */
	private function poll(int $tea, int $coffee, string $content = '<p>tea or coffee?</p>'): array {
		return [
			'id' => self::POST,
			'type' => 'Question',
			'attributedTo' => self::CAROL,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'content' => $content,
			'published' => '2026-05-01T10:00:00Z',
			'endTime' => '2026-05-02T10:00:00Z',
			'votersCount' => $tea + $coffee,
			'oneOf' => [
				['type' => 'Note', 'name' => 'tea', 'replies' => ['type' => 'Collection', 'totalItems' => $tea]],
				['type' => 'Note', 'name' => 'coffee', 'replies' => ['type' => 'Collection', 'totalItems' => $coffee]],
			],
		];
	}

	/**
	 * Mastodon sends `Update{Question}` as the votes come in, without
	 * `updated`. Stored as an edit, each one would be a revision and a new
	 * "edited a post you boosted" for whoever here boosted the poll.
	 */
	public function testNewPollTalliesAreStoredWithoutCountingAsAnEdit(): void {
		$this->assertTrue($this->deliver($this->activity('Create', self::POST . '/activity', $this->poll(0, 0))));
		$boost = new Announce();
		$boost->setId($this->alice() . '/boosts/1');
		$boost->setActorId($this->alice());
		$boost->setObjectId(self::POST);
		$this->assertTrue(Server::get(ActionsRequest::class)->save($boost));

		$this->assertTrue($this->deliver($this->activity('Update', self::POST . '#updates/1', $this->poll(1, 0))));
		$this->assertTrue($this->deliver($this->activity('Update', self::POST . '#updates/2', $this->poll(1, 2))));

		$poll = $this->statusEntity(self::POST)['poll'];
		$this->assertSame([1, 2], array_column($poll['options'], 'votes_count'));
		$this->assertSame(3, $poll['voters_count']);
		$this->assertNull($this->statusEntity(self::POST)['edited_at']);
		$revisions = fn (): array => $this->rows(CoreRequestBuilder::TABLE_STATUS_REVISIONS, 'stream_id_prim', md5(self::POST));
		$notifications = fn (): array => $this->rows(CoreRequestBuilder::TABLE_STREAM, 'id', self::POST . '/notification+update/');
		$this->assertSame([], $revisions());
		$this->assertSame([], $notifications());

		$this->assertTrue($this->deliver($this->activity(
			'Update', self::POST . '#updates/3', $this->poll(1, 2, '<p>tea, or coffee?</p>') + ['updated' => '2026-05-01T11:00:00Z']
		)));

		$this->assertCount(2, $revisions(), 'the original and the edit');
		$this->assertCount(1, $notifications());
	}

	/** An Akkoma reaction taken back by an Undo that names it by its id alone. */
	public function testAnUndoNamingAReactionByItsIdTakesItBack(): void {
		$reaction = json_decode($this->fixture('akkoma-emoji-react'), true);
		$reaction['content'] = '🍅';
		unset($reaction['tag']);
		$this->remoteActor($reaction['actor'], 'frank');
		$this->assertTrue($this->deliver((string)json_encode($reaction)));
		$this->assertCount(1, $this->rows(CoreRequestBuilder::TABLE_REACTIONS, 'actor_id', 'https://akkoma.example/'));

		$this->assertTrue($this->deliver((string)json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => $reaction['id'] . '/undo',
			'type' => 'Undo',
			'actor' => $reaction['actor'],
			'object' => $reaction['id'],
		])));

		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_REACTIONS, 'actor_id', 'https://akkoma.example/'));
	}

	/** A block lifted by an Undo that names the Block by its id alone. */
	public function testAnUndoNamingABlockByItsIdLiftsIt(): void {
		$block = json_decode($this->fixture('mastodon-block'), true);
		$this->assertTrue($this->deliver($this->fixture('mastodon-block')));
		$this->assertCount(1, $this->rows(CoreRequestBuilder::TABLE_ACTOR_RELATION, 'object_id', self::CAROL));

		$this->assertTrue($this->deliver($this->activity('Undo', $block['id'] . '/undo', $block['id'])));

		$this->assertSame([], $this->rows(CoreRequestBuilder::TABLE_ACTOR_RELATION, 'object_id', self::CAROL));
	}

	/**
	 * An account rotating its key announces the new one in an Update of
	 * itself; the cached copy takes it, and the next signature made with the
	 * new key verifies.
	 */
	public function testARotatedKeyFromAnUpdateOfThePersonVerifiesTheNextSignature(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $private);
		$public = openssl_pkey_get_details($key)['key'];

		$person = [
			'id' => self::CAROL, 'type' => 'Person', 'preferredUsername' => 'carol', 'name' => 'Carol',
			'inbox' => self::CAROL . '/inbox', 'outbox' => self::CAROL . '/outbox',
			'followers' => self::CAROL . '/followers', 'following' => self::CAROL . '/following',
			'endpoints' => ['sharedInbox' => 'https://mastodon.example/inbox'],
			'publicKey' => ['id' => self::CAROL . '#main-key', 'owner' => self::CAROL, 'publicKeyPem' => $public],
		];
		$this->assertTrue($this->deliver($this->activity('Update', self::CAROL . '#updates/1', $person)));

		$this->assertSame($public, $this->cacheActorsRequest->getFromId(self::CAROL)->getPublicKey());

		$note = $this->note('<p>signed with the new key</p>');
		$note['@context'] = 'https://www.w3.org/ns/activitystreams';
		$signature = new LinkedDataSignature();
		$signature->setType('RsaSignature2017')
			->setCreator(self::CAROL . '#main-key')
			->setCreated(gmdate('Y-m-d\TH:i:s\Z'))
			->setPrivateKey($private)
			->setObject($note);
		$signature->sign();
		$note['signature'] = $signature->jsonSerialize();

		$received = new Note();
		$received->setSource((string)json_encode($note, JSON_UNESCAPED_SLASHES));
		$received->setActorId(self::CAROL);

		$this->assertTrue(Server::get(SignatureService::class)->checkObject($received));
	}
}
