<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

class QuestionTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		// Stream::import() resolves the URL generator statically for attachments
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** the wire shape Mastodon federates */
	private function mastodonPoll(string $key = 'oneOf', array $extra = []): array {
		return array_merge([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Question',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'content' => '<p>Best animal?</p>',
			'endTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
			'votersCount' => 7,
			$key => [
				['type' => 'Note', 'name' => 'Cats', 'replies' => ['type' => 'Collection', 'totalItems' => 5]],
				['type' => 'Note', 'name' => 'Dogs', 'replies' => ['type' => 'Collection', 'totalItems' => 2]],
			],
		], $extra);
	}

	public function testImportReadsASingleChoicePoll(): void {
		$question = new Question();
		$question->import($this->mastodonPoll());

		$this->assertFalse($question->isMultiple());
		$this->assertFalse($question->isExpired());
		$this->assertSame(7, $question->getVotersCount());
		$this->assertSame(
			[['title' => 'Cats', 'votes_count' => 5], ['title' => 'Dogs', 'votes_count' => 2]],
			$question->getOptions()
		);
	}

	public function testAnyOfMakesThePollMultipleChoice(): void {
		$question = new Question();
		$question->import($this->mastodonPoll('anyOf'));

		$this->assertTrue($question->isMultiple());
	}

	public function testAClosedOrPastPollIsExpired(): void {
		$closed = new Question();
		$closed->import($this->mastodonPoll('oneOf', ['closed' => gmdate('Y-m-d\TH:i:s\Z')]));
		$this->assertTrue($closed->isExpired());

		$past = new Question();
		$past->import($this->mastodonPoll('oneOf', ['endTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]));
		$this->assertTrue($past->isExpired());
	}

	public function testThePollSurvivesTheDatabaseRoundTripViaTheSource(): void {
		$question = new Question();
		$question->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'nid' => 42,
			'source' => json_encode($this->mastodonPoll()),
		]);

		$this->assertCount(2, $question->getOptions());
		$this->assertSame('Cats', $question->getOptions()[0]['title']);
	}

	public function testExportAsLocalCarriesThePollEntityWithTheViewersVotes(): void {
		$question = new Question();
		$question->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'nid' => 42,
			'source' => json_encode($this->mastodonPoll()),
		]);
		$action = new StreamAction('viewer', $question->getId());
		$action->updateValue(StreamAction::POLL_VOTES, json_encode([1]));
		$question->setAction($action);

		$poll = $question->exportAsLocal()['poll'];

		$this->assertSame('42', $poll['id']);
		$this->assertFalse($poll['expired']);
		$this->assertFalse($poll['multiple']);
		$this->assertSame(7, $poll['votes_count']);
		$this->assertSame(7, $poll['voters_count']);
		$this->assertTrue($poll['voted']);
		$this->assertSame([1], $poll['own_votes']);
		$this->assertSame('Dogs', $poll['options'][1]['title']);
	}

	public function testALocalPollSerializesTheMastodonWireShape(): void {
		$question = new Question();
		$question->setPollData(['Cats', 'Dogs'], false, 3600);
		$question->countVote(1, true);

		$wire = $question->jsonSerialize();

		$this->assertArrayHasKey('oneOf', $wire);
		$this->assertArrayNotHasKey('anyOf', $wire);
		$this->assertSame('Dogs', $wire['oneOf'][1]['name']);
		$this->assertSame(1, $wire['oneOf'][1]['replies']['totalItems']);
		$this->assertSame(1, $wire['votersCount']);
		$this->assertNotSame('', $wire['endTime']);

		// and the shape round-trips through import
		$copy = new Question();
		$copy->import(json_decode(json_encode($wire), true));
		$this->assertSame($question->getOptions(), $copy->getOptions());
	}

	public function testAMultipleChoicePollSerializesAnyOf(): void {
		$question = new Question();
		$question->setPollData(['A', 'B', 'C'], true, 3600);

		$this->assertArrayHasKey('anyOf', $question->jsonSerialize());
	}

	public function testCountVoteBumpsVotersOnlyForNewVoters(): void {
		$question = new Question();
		$question->setPollData(['A', 'B'], true, 3600);
		$question->countVote(0, true);
		$question->countVote(1, false);
		$question->countVote(7, true); // unknown option: ignored

		$this->assertSame(1, $question->getVotersCount());
		$this->assertSame(1, $question->getOptions()[0]['votes_count']);
		$this->assertSame(1, $question->getOptions()[1]['votes_count']);
	}

	/**
	 * `null`, not absent. Every status carries the key — the Stream entity sets
	 * it and a poll overwrites it — because a client should never have to test
	 * whether a key exists before reading it.
	 */
	public function testANoteWithoutPollDataExportsANullPoll(): void {
		$question = new Question();
		$question->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'source' => json_encode(['type' => 'Question']),
		]);

		$exported = $question->exportAsLocal();

		$this->assertArrayHasKey('poll', $exported);
		$this->assertNull($exported['poll']);
	}

	public function testAPollCarriesItsLanguageOnTheWireLikeANote(): void {
		$question = new Question();
		$question->setPollData(['Katzen', 'Hunde'], false, 3600);
		$question->setContent('<p>Lieblingstier?</p>')->setLanguage('de');

		$wire = $question->jsonSerialize();

		$this->assertSame(['de' => '<p>Lieblingstier?</p>'], $wire['contentMap']);
		$this->assertArrayHasKey('oneOf', $wire);

		$copy = new Question();
		$copy->import(json_decode(json_encode($wire), true));
		$this->assertSame('de', $copy->getLanguage());
		$this->assertSame($question->getOptions(), $copy->getOptions());
	}

	public function testARemotePollTakesItsLanguageFromTheContentMap(): void {
		$question = new Question();
		$question->import($this->mastodonPoll('oneOf', ['contentMap' => ['fr' => '<p>Best animal?</p>']]));

		$this->assertSame('fr', $question->getLanguage());
		$this->assertSame('fr', $question->exportAsLocal()['language']);
	}
}
