<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Db\AtprotoChatMessageRequest;
use OCA\Social\Db\CoreRequestBuilder;
use OCA\Social\Migration\Version1000Date20261108500000;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `social_atp_chat_message` against the real database: the local message
 * sent as a Bluesky message is found by the Bluesky id through the
 * `(did, message_prim)` index, joined to the stream row that still exists,
 * and the rows written before the column existed get theirs from the
 * migration.
 */
class AtprotoChatMessageStorageTest extends TestCase {
	private const TABLE = CoreRequestBuilder::TABLE_ATPROTO_CHAT_MESSAGE;

	private string $did;
	/** @var list<string> */
	private array $notes = [];

	protected function setUp(): void {
		$this->did = 'did:plc:' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz234567', 2)), 0, 24);
	}

	protected function tearDown(): void {
		RowSeeder::delete(self::TABLE, ['did' => $this->did]);
		RowSeeder::delete(self::TABLE, ['did' => $this->did . 'x']);
		foreach ($this->notes as $note) {
			RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => md5($note)]);
		}
		parent::tearDown();
	}

	private function note(string $suffix): string {
		$id = 'https://social.test/users/alice/statuses/' . $suffix . bin2hex(random_bytes(4));
		$this->notes[] = $id;
		RowSeeder::insert(CoreRequestBuilder::TABLE_STREAM, [
			'id' => $id, 'id_prim' => md5($id), 'type' => Note::TYPE, 'local' => true,
			'attributed_to' => 'https://social.test/users/alice', 'attributed_to_prim' => md5('https://social.test/users/alice'),
		]);

		return $id;
	}

	public function testTheLocalMessageIsFoundByItsBlueskyIdForItsAccountOnly(): void {
		$messages = Server::get(AtprotoChatMessageRequest::class);
		$note = $this->note('sent');
		$messages->remember($this->did, $note, 'convo-1', '3lmsg-abc');
		$messages->remember($this->did, $note, 'convo-1', '3lmsg-abc');

		$this->assertSame([$note], $messages->noteIds($this->did, '3lmsg-abc'));
		$this->assertSame([], $messages->noteIds($this->did . 'x', '3lmsg-abc'), 'another account');
		$this->assertSame([], $messages->noteIds($this->did, '3lmsg-other'));
		$this->assertSame('convo-1', $messages->convoOf($note));
		$this->assertSame('', $messages->convoOf('https://social.test/users/alice/statuses/none'));
		$this->assertSame(1, RowSeeder::count(self::TABLE, ['did' => $this->did]), 'remembered twice, one row');
	}

	public function testAMappingWhoseLocalMessageIsGoneFindsNothing(): void {
		$messages = Server::get(AtprotoChatMessageRequest::class);
		$note = $this->note('gone');
		$messages->remember($this->did, $note, 'convo-1', '3lmsg-gone');
		RowSeeder::delete(CoreRequestBuilder::TABLE_STREAM, ['id_prim' => md5($note)]);

		$this->assertSame([], $messages->noteIds($this->did, '3lmsg-gone'));
	}

	public function testTheMigrationComputesTheIdOfTheRowsThatHaveNone(): void {
		$note = $this->note('old');
		RowSeeder::insert(self::TABLE, [
			'stream_id_prim' => md5($note), 'did' => $this->did,
			'convo_id' => 'convo-old', 'message_id' => '3lmsg-old', 'message_prim' => null,
		]);
		$schema = $this->createStub(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);

		Server::get(Version1000Date20261108500000::class)->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
		Server::get(Version1000Date20261108500000::class)->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);

		$this->assertSame(1, RowSeeder::count(self::TABLE, ['did' => $this->did, 'message_prim' => md5('3lmsg-old')]));
		$this->assertSame([$note], Server::get(AtprotoChatMessageRequest::class)->noteIds($this->did, '3lmsg-old'));
	}
}
