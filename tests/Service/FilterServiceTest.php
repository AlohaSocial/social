<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Db\FiltersRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Filter;
use OCA\Social\Model\Client\FilterKeyword;
use OCA\Social\Model\Client\FilterStatus;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\AiContentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\FilterService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What a keyword filter does to what a viewer is shown.
 *
 * The API that stores a filter is the easy half; this is the half that decides
 * whether a status reaches the reader, and it is the half a mistake in is
 * invisible — a filter that matches nothing looks exactly like one nothing
 * matched.
 */
class FilterServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/users/alice';
	private const BOB = 'https://cloud.example/users/bob';

	private FiltersRequest|Stub $filtersRequest;
	private FilterService $service;
	/** @var array<string, Filter[]> actor id => the filters the store holds */
	private array $stored = [];
	/** @var string[] the actor ids the store was asked about */
	private array $asked = [];
	/** @var array<string, bool> user id => whether they hide posts made with AI */
	private array $hidesAi = [];

	protected function setUp(): void {
		$this->filtersRequest = $this->createStub(FiltersRequest::class);
		$this->filtersRequest->method('getActiveByActor')
			->willReturnCallback(function (string $actorId, ?int $now = null): array {
				$this->asked[] = $actorId;

				// the store answers only with what is still active, as the SQL
				// does; the service is expected not to rely on that alone
				return $this->stored[$actorId] ?? [];
			});

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getUserValue')
			->willReturnCallback(fn (string $key, string $userId = ''): string
				=> ($this->hidesAi[$userId] ?? false) ? '1' : '');
		$configService->method('getAppValue')->willReturn('');

		$this->service = new FilterService($this->filtersRequest, new AiContentService($configService));
	}

	private function viewer(string $id): Person {
		$person = new Person();
		$person->setId($id);
		// the AI switch is a user setting, so the viewer has to be somebody
		$person->setUserId(substr($id, strrpos($id, '/') + 1));

		return $person;
	}

	/**
	 * @param array<array{string, bool}> $keywords [keyword, whole word]
	 * @param string[] $contexts
	 */
	private function filter(
		string $title,
		array $keywords,
		array $contexts = [Filter::CONTEXT_HOME],
		string $action = Filter::ACTION_WARN,
		int $expiresAt = 0,
		int $id = 1,
	): Filter {
		$filter = new Filter();
		$filter->setId($id)
			->setTitle($title)
			->setContexts($contexts)
			->setAction($action)
			->setExpiresAt($expiresAt);

		foreach ($keywords as $index => [$keyword, $wholeWord]) {
			$filter->addKeyword(
				(new FilterKeyword())->setId($index + 1)->setKeyword($keyword)->setWholeWord($wholeWord)
			);
		}

		return $filter;
	}

	private function aStatus(string $content, array $extra = []): array {
		return array_merge(['id' => '1', 'content' => $content, 'spoiler_text' => ''], $extra);
	}

	public function testAStatusNothingMatchedSaysSoRatherThanSayingNothing(): void {
		// a client reads the absence of `filtered` as "nothing filtered", which
		// is the same answer for every viewer and so the wrong one: the key has
		// to be there, empty
		$this->stored[self::ALICE] = [$this->filter('spoilers', [['banana', false]])];

		$page = $this->service->apply(
			[$this->aStatus('<p>hello</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page);
		$this->assertArrayHasKey('filtered', $page[0]);
		$this->assertSame([], $page[0]['filtered']);
	}

	public function testAWarnFilterKeepsTheStatusAndSaysWhatMatched(): void {
		$this->stored[self::ALICE] = [
			$this->filter('spoilers', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_WARN),
		];

		$page = $this->service->apply(
			[$this->aStatus('<p>a banana split</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page, 'a warn filter leaves the status in the timeline');
		$this->assertCount(1, $page[0]['filtered']);
		$this->assertSame('spoilers', $page[0]['filtered'][0]['filter']['title']);
		$this->assertSame(Filter::ACTION_WARN, $page[0]['filtered'][0]['filter']['filter_action']);
		$this->assertSame(['banana'], $page[0]['filtered'][0]['keyword_matches']);
		$this->assertSame([], $page[0]['filtered'][0]['status_matches']);
	}

	public function testAHideFilterTakesTheStatusOutOfTheTimeline(): void {
		// the difference between the two actions, and the reason `hide` cannot
		// be left to the client: the status must not be sent at all
		$this->stored[self::ALICE] = [
			$this->filter('spoilers', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE),
		];

		$page = $this->service->apply(
			[$this->aStatus('<p>a banana split</p>'), $this->aStatus('<p>an apple</p>')],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page);
		$this->assertStringContainsString('apple', $page[0]['content']);
	}

	public function testWholeWordMatchesAWordAndNotAWordItStartsWith(): void {
		$this->stored[self::ALICE] = [$this->filter('pets', [['cat', true]])];

		$whole = $this->service->apply(
			[$this->aStatus('<p>look at that cat!</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);
		$part = $this->service->apply(
			[$this->aStatus('<p>read the catalogue</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $whole[0]['filtered'], 'a whole-word keyword matches the word');
		$this->assertSame([], $part[0]['filtered'], 'and nothing it is merely the start of');
	}

	public function testWithoutWholeWordTheKeywordMatchesInsideAWord(): void {
		$this->stored[self::ALICE] = [$this->filter('pets', [['cat', false]])];

		$page = $this->service->apply(
			[$this->aStatus('<p>read the catalogue</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page[0]['filtered']);
		$this->assertSame(['cat'], $page[0]['filtered'][0]['keyword_matches']);
	}

	public function testAWholeWordKeywordThatStartsWithAHashStillMatches(): void {
		// '\b' before '#' can never hold, so anchoring both sides blindly would
		// make a keyword like this match nothing at all
		$this->stored[self::ALICE] = [$this->filter('tags', [['#spoiler', true]])];

		$page = $this->service->apply(
			[$this->aStatus('<p>careful, #spoiler ahead</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page[0]['filtered']);
	}

	public function testCaseNeverMatters(): void {
		$this->stored[self::ALICE] = [$this->filter('shouting', [['banana', true]])];

		$page = $this->service->apply(
			[$this->aStatus('<p>BANANA</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page[0]['filtered']);
	}

	public function testAKeywordIsNotAPattern(): void {
		// stored as the account typed it, and compared as text: a keyword with
		// regex punctuation in it must not blow up, and must not match
		// everything
		$this->stored[self::ALICE] = [$this->filter('literal', [['c++ (beta)', false]])];

		$matching = $this->service->apply(
			[$this->aStatus('<p>about c++ (beta) today</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);
		$other = $this->service->apply(
			[$this->aStatus('<p>about cxx beta today</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $matching[0]['filtered']);
		$this->assertSame([], $other[0]['filtered']);
	}

	public function testMarkupIsNotPartOfTheText(): void {
		// a keyword must not match an attribute the reader never sees
		$this->stored[self::ALICE] = [$this->filter('markup', [['href', false]])];

		$page = $this->service->apply(
			[$this->aStatus('<p><a href="https://example.net/">a link</a></p>')],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertSame([], $page[0]['filtered']);
	}

	public function testEntitiesAreComparedAsTheReaderSeesThem(): void {
		$this->stored[self::ALICE] = [$this->filter('apostrophes', [["don't", false]])];

		$page = $this->service->apply(
			[$this->aStatus('<p>I don&apos;t think so</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page[0]['filtered']);
	}

	public function testTheContentWarningIsMatchedToo(): void {
		$this->stored[self::ALICE] = [$this->filter('spoilers', [['ending', false]])];

		$page = $this->service->apply(
			[$this->aStatus('<p>nothing here</p>', ['spoiler_text' => 'the ending'])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page[0]['filtered']);
	}

	public function testAnAttachmentDescriptionAndAPollOptionAreMatchedToo(): void {
		$this->stored[self::ALICE] = [$this->filter('food', [['banana', false]], [Filter::CONTEXT_HOME])];

		$described = $this->service->apply(
			[$this->aStatus('<p>look</p>', ['media_attachments' => [['description' => 'a banana']]])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);
		$polled = $this->service->apply(
			[$this->aStatus('<p>vote</p>', ['poll' => ['options' => [['title' => 'banana']]]])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertCount(1, $described[0]['filtered']);
		$this->assertCount(1, $polled[0]['filtered']);
	}

	public function testABoostIsFilteredOnWhatItBoosts(): void {
		// the wrapper carries no text of its own: filtering it on that would
		// filter nothing, and every filter would be escapable by boosting
		$this->stored[self::ALICE] = [
			$this->filter('spoilers', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE),
		];

		$page = $this->service->apply(
			[$this->aStatus('', ['reblog' => $this->aStatus('<p>a banana split</p>')])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertSame([], $page);
	}

	public function testTheBoostedStatusCarriesTheSameFilteredKey(): void {
		// a client renders the boosted status and reads `filtered` off that one
		$this->stored[self::ALICE] = [$this->filter('spoilers', [['banana', false]])];

		$page = $this->service->apply(
			[$this->aStatus('', ['reblog' => $this->aStatus('<p>a banana split</p>')])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page[0]['filtered']);
		$this->assertCount(1, $page[0]['reblog']['filtered']);
	}

	public function testAFilterOnlyAppliesInTheContextsItNames(): void {
		$this->stored[self::ALICE] = [
			$this->filter('home only', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE),
		];

		$home = $this->service->apply(
			[$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);
		$public = $this->service->apply(
			[$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_PUBLIC, $this->viewer(self::ALICE)
		);

		$this->assertSame([], $home);
		$this->assertCount(1, $public, 'the public timeline is not a context this filter names');
		$this->assertSame([], $public[0]['filtered']);
	}

	public function testOneAccountsFiltersNeverTouchAnothersTimeline(): void {
		// the whole feature is per viewer: a shared cache or an unscoped read
		// would mute somebody else's timeline, and they would never know why
		$this->stored[self::ALICE] = [
			$this->filter('alice hides bananas', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE),
		];
		$this->stored[self::BOB] = [];

		$alice = $this->service->apply(
			[$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);
		$bob = $this->service->apply(
			[$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_HOME, $this->viewer(self::BOB)
		);

		$this->assertSame([], $alice);
		$this->assertCount(1, $bob);
		$this->assertSame([], $bob[0]['filtered']);
		$this->assertSame([self::ALICE, self::BOB], $this->asked, 'each viewer is asked about by id');
	}

	public function testAnAnonymousReaderHasNoFiltersAndIsNotAskedAbout(): void {
		$this->stored[self::ALICE] = [$this->filter('spoilers', [['banana', false]])];

		$page = $this->service->apply([$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_PUBLIC, null);

		$this->assertCount(1, $page);
		$this->assertSame([], $page[0]['filtered']);
		$this->assertSame([], $this->asked);
	}

	public function testAnExpiredFilterStopsApplyingWithNothingRunToMakeItSo(): void {
		// there is no cleanup job, and one that failed to run would otherwise
		// go on hiding statuses the account expected back
		$this->stored[self::ALICE] = [
			$this->filter(
				'expired', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE, time() - 60
			),
		];

		$page = $this->service->apply(
			[$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page);
		$this->assertSame([], $page[0]['filtered']);
	}

	public function testAFilterThatHasNotExpiredYetStillApplies(): void {
		$this->stored[self::ALICE] = [
			$this->filter(
				'later', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE, time() + 3600
			),
		];

		$page = $this->service->apply(
			[$this->aStatus('<p>a banana</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertSame([], $page);
	}

	public function testAFilterWithNoKeywordsMatchesNothing(): void {
		// it would otherwise be a filter that hides the whole timeline
		$this->stored[self::ALICE] = [
			$this->filter('empty', [], [Filter::CONTEXT_HOME], Filter::ACTION_HIDE),
		];

		$page = $this->service->apply(
			[$this->aStatus('<p>anything at all</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page);
		$this->assertSame([], $page[0]['filtered']);
	}

	public function testEveryMatchingFilterIsReported(): void {
		$this->stored[self::ALICE] = [
			$this->filter('one', [['banana', false]], [Filter::CONTEXT_HOME], Filter::ACTION_WARN, 0, 1),
			$this->filter('two', [['split', false]], [Filter::CONTEXT_HOME], Filter::ACTION_WARN, 0, 2),
		];

		$page = $this->service->apply(
			[$this->aStatus('<p>a banana split</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertSame(
			['one', 'two'],
			array_column(array_column($page[0]['filtered'], 'filter'), 'title')
		);
	}

	public function testTheStoreIsReadOncePerViewerPerRequest(): void {
		$this->stored[self::ALICE] = [$this->filter('spoilers', [['banana', false]])];

		$this->service->apply([$this->aStatus('<p>a</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE));
		$this->service->apply([$this->aStatus('<p>b</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE));

		$this->assertSame([self::ALICE], $this->asked);
	}

	public function testASingleStatusIsAnsweredOrWithheld(): void {
		$this->stored[self::ALICE] = [
			$this->filter('spoilers', [['banana', false]], [Filter::CONTEXT_THREAD], Filter::ACTION_HIDE),
		];

		$hidden = $this->service->applyToStatus(
			$this->aStatus('<p>a banana</p>'), Filter::CONTEXT_THREAD, $this->viewer(self::ALICE)
		);
		$shown = $this->service->applyToStatus(
			$this->aStatus('<p>an apple</p>'), Filter::CONTEXT_THREAD, $this->viewer(self::ALICE)
		);

		$this->assertNull($hidden);
		$this->assertSame([], $shown['filtered']);
	}

	public function testANotificationForAHiddenStatusIsDroppedAndTheRestAreUntouched(): void {
		$this->stored[self::ALICE] = [
			$this->filter(
				'spoilers', [['banana', false]], [Filter::CONTEXT_NOTIFICATIONS], Filter::ACTION_HIDE
			),
		];

		$mentioning = new Note();
		$mentioning->setNid(1)->setContent('<p>a banana split</p>');
		$other = new Note();
		$other->setNid(2)->setContent('<p>an apple</p>');

		$kept = $this->service->applyToNotifications([$mentioning, $other], $this->viewer(self::ALICE));

		$this->assertCount(1, $kept);
		$this->assertSame($other, $kept[0], 'a notification is handed back as it came in');
	}

	public function testANotificationIsFilteredOnTheStatusItIsAbout(): void {
		$this->stored[self::ALICE] = [
			$this->filter(
				'spoilers', [['banana', false]], [Filter::CONTEXT_NOTIFICATIONS], Filter::ACTION_HIDE
			),
		];

		$note = new Note();
		$note->setNid(1)->setSpoilerText('a banana');

		$this->assertSame([], $this->service->applyToNotifications([$note], $this->viewer(self::ALICE)));
	}

	public function testAWholeWordKeywordEndingInANonAsciiLetterIsStillAWord(): void {
		// 'é' has to count as a letter, or there is a word boundary between it
		// and the 's' and a filter on "café" hides every post about cafés —
		// which is what the '/u' on the pattern buys, PCRE2's UCP flag with it
		$this->stored[self::ALICE] = [$this->filter('cafés', [['café', true]])];

		$word = $this->service->apply(
			[$this->aStatus('<p>at the café</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);
		$inside = $this->service->apply(
			[$this->aStatus('<p>about cafés</p>')], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)
		);

		$this->assertCount(1, $word[0]['filtered']);
		$this->assertSame([], $inside[0]['filtered']);
	}

	public function testKeywordRegexIsWhatTheTwoModesSay(): void {
		$plain = (new FilterKeyword())->setKeyword('cat');
		$whole = (new FilterKeyword())->setKeyword('cat')->setWholeWord(true);

		$this->assertSame('/cat/iu', FilterService::keywordRegex($plain));
		$this->assertSame('/\bcat\b/iu', FilterService::keywordRegex($whole));
	}

	// the other half of a filter: a post it covers by name

	private function covering(string $actorId, int|string $statusId, string $action = Filter::ACTION_WARN): Filter {
		$filter = (new Filter())
			->setId(9)
			->setActorId($actorId)
			->setTitle('that thread')
			->setContexts(Filter::CONTEXTS)
			->setAction($action)
			->addStatus((new FilterStatus())->setId(1)->setFilterId(9)->setStatusId($statusId));

		$this->stored[$actorId] = [$filter];

		return $filter;
	}

	/**
	 * A filter may name one post and no words at all. What it reports is the
	 * status id in `status_matches`, because there is no text to report: the
	 * filter matched the post, not something in it.
	 */
	public function testAPostAFilterNamesIsFiltered(): void {
		$this->covering(self::ALICE, 42);

		$results = $this->service->results(['id' => '42', 'content' => 'nothing to match'], $this->stored[self::ALICE]);

		$this->assertCount(1, $results);
		$this->assertSame(['42'], $results[0]['status_matches']);
		$this->assertSame([], $results[0]['keyword_matches']);
	}

	public function testAnotherPostIsNotFiltered(): void {
		$this->covering(self::ALICE, 42);

		$this->assertSame(
			[], $this->service->results(['id' => '43', 'content' => 'hello'], $this->stored[self::ALICE])
		);
	}

	/**
	 * A post covered by a `hide` filter goes, exactly as a keyword match on
	 * the same filter would.
	 */
	public function testAHidingFilterTakesTheNamedPostOut(): void {
		$this->covering(self::ALICE, 42, Filter::ACTION_HIDE);

		$this->assertTrue(
			$this->service->isHidden(
				$this->service->results(['id' => '42'], $this->stored[self::ALICE])
			)
		);
	}

	/**
	 * Boosting a filtered post must not bring it back: the boost carries no
	 * words of its own, so nothing else would catch it.
	 */
	public function testABoostOfAFilteredPostIsFilteredToo(): void {
		$this->covering(self::ALICE, 42);

		$results = $this->service->results(
			['id' => '77', 'reblog' => ['id' => '42', 'content' => 'the post']],
			$this->stored[self::ALICE]
		);

		$this->assertSame(['42'], $results[0]['status_matches']);
	}

	/**
	 * A nid wider than a PHP int is compared as the string it is. Cast, both
	 * sides would clamp to PHP_INT_MAX and a filter on one post would cover
	 * its neighbour too.
	 */
	public function testAPostIdWiderThanAPhpIntIsMatchedExactly(): void {
		$this->covering(self::ALICE, '92233720368547758070');

		$results = $this->service->results(['id' => '92233720368547758070'], $this->stored[self::ALICE]);
		$this->assertSame(['92233720368547758070'], $results[0]['status_matches']);

		$this->assertSame(
			[], $this->service->results(['id' => '92233720368547758071'], $this->stored[self::ALICE])
		);
	}

	// the switch for posts made with AI

	/** A status labelled by its hashtag, as the exporter hands it over. */
	private function aLabelledStatus(string $id = '1'): array {
		return $this->aStatus('<p>a landscape</p>', [
			'id' => $id,
			'tags' => [['name' => 'AIgenerated', 'url' => '']],
			'media_attachments' => [],
			'ai_generated' => true,
		]);
	}

	public function testPostsMadeWithAiAreShownUntilTheReaderAsksOtherwise(): void {
		$page = $this->service->apply(
			[$this->aLabelledStatus(), $this->aStatus('<p>an apple</p>', ['id' => '2'])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertCount(2, $page, 'the switch is off by default');
		$this->assertTrue($page[0]['ai_generated']);
		$this->assertSame([], $page[0]['filtered'], 'a label is not a keyword filter match');
	}

	public function testAReaderWhoHidesAiIsNotHandedALabelledPost(): void {
		$this->hidesAi['alice'] = true;

		$page = $this->service->apply(
			[$this->aLabelledStatus(), $this->aStatus('<p>an apple</p>', ['id' => '2'])],
			Filter::CONTEXT_HOME,
			$this->viewer(self::ALICE)
		);

		$this->assertCount(1, $page);
		$this->assertSame('2', $page[0]['id']);
		$this->assertArrayHasKey('filtered', $page[0], 'the keyword filters still run on what is left');
	}

	/**
	 * Unlike a keyword filter, the switch names no contexts: it applies
	 * wherever statuses are handed to a client, the list timelines that no
	 * keyword filter reaches included.
	 */
	public function testTheSwitchAppliesInEveryContext(): void {
		$this->hidesAi['alice'] = true;

		foreach (array_merge(Filter::CONTEXTS, ['']) as $context) {
			$page = $this->service->apply([$this->aLabelledStatus()], $context, $this->viewer(self::ALICE));

			$this->assertSame([], $page, 'context ' . var_export($context, true));
		}
	}

	public function testAPostIsHiddenByALabelledPictureAsWellAsByATag(): void {
		$this->hidesAi['alice'] = true;
		$status = $this->aStatus('<p>look</p>', [
			'tags' => [],
			'media_attachments' => [['id' => '5', 'ai_generated' => true]],
		]);

		$this->assertSame([], $this->service->apply([$status], Filter::CONTEXT_PUBLIC, $this->viewer(self::ALICE)));
	}

	public function testABoostOfALabelledPostIsHiddenWithIt(): void {
		$this->hidesAi['alice'] = true;
		$boost = $this->aStatus('', ['id' => '9', 'tags' => [], 'reblog' => $this->aLabelledStatus('3')]);

		$this->assertSame([], $this->service->apply([$boost], Filter::CONTEXT_HOME, $this->viewer(self::ALICE)));
	}

	public function testTheSwitchOfOneReaderHidesNothingFromAnother(): void {
		$this->hidesAi['alice'] = true;

		$page = $this->service->apply([$this->aLabelledStatus()], Filter::CONTEXT_HOME, $this->viewer(self::BOB));

		$this->assertCount(1, $page);
	}

	public function testAnAnonymousReaderHasNoSwitch(): void {
		$this->hidesAi[''] = true;

		$this->assertCount(1, $this->service->apply([$this->aLabelledStatus()], Filter::CONTEXT_PUBLIC, null));
	}

	public function testASingleLabelledStatusIsWithheldLikeAHiddenOne(): void {
		$this->hidesAi['alice'] = true;

		$this->assertNull($this->service->applyToStatus($this->aLabelledStatus(), Filter::CONTEXT_THREAD, $this->viewer(self::ALICE)));
		$this->assertNotNull($this->service->applyToStatus($this->aLabelledStatus(), Filter::CONTEXT_THREAD, $this->viewer(self::BOB)));
	}

	public function testAModelIsReadForItsLabelTheWayAnExportedStatusIs(): void {
		$this->hidesAi['alice'] = true;
		$labelled = (new Note())->setHashtags(['StableDiffusion']);
		$labelled->setNid(1)->setContent('<p>a render</p>');
		$plain = (new Note())->setHashtags(['cats']);
		$plain->setNid(2)->setContent('<p>a cat</p>');

		$page = $this->service->apply([$labelled, $plain], Filter::CONTEXT_HOME, $this->viewer(self::ALICE));

		$this->assertCount(1, $page);
		$this->assertSame('2', $page[0]['id']);
	}

	public function testANotificationAboutALabelledPostIsDroppedForAReaderWhoHidesAi(): void {
		$this->hidesAi['alice'] = true;
		$labelled = (new Note())->setHashtags(['aiart']);
		$labelled->setNid(1)->setContent('<p>a render</p>');
		$pictured = new Note();
		$pictured->setNid(2)->setContent('<p>look</p>');
		$pictured->setAttachments([(new MediaAttachment())->setAiGenerated(true)]);
		$other = new Note();
		$other->setNid(3)->setContent('<p>an apple</p>');

		$kept = $this->service->applyToNotifications([$labelled, $pictured, $other], $this->viewer(self::ALICE));

		$this->assertSame([$other], $kept);
		$this->assertCount(
			3, $this->service->applyToNotifications([$labelled, $pictured, $other], $this->viewer(self::BOB)),
			'another reader, who did not ask, is told about all three'
		);
	}

	public function testANotificationAboutABoostIsReadAsThePostItBoosts(): void {
		$this->hidesAi['alice'] = true;
		$boosted = (new Note())->setHashtags(['midjourney']);
		$boosted->setNid(1);
		$boost = new \OCA\Social\Model\ActivityPub\Object\Announce();
		$boost->setNid(2);
		$boost->setObject($boosted);

		$this->assertSame([], $this->service->applyToNotifications([$boost], $this->viewer(self::ALICE)));
	}

	public function testALabelersChoiceWarnsOrHidesABlueskyPost(): void {
		$labelers = $this->createStub(LabelerService::class);
		$labelers->method('results')->willReturnCallback(static fn (string $user, array $labels): array => array_map(static fn (array $l): array => [
			'filter' => ['id' => 'bluesky-label:' . $l['src'] . ':' . $l['val'], 'title' => $l['val'], 'context' => ['home'], 'expires_at' => null, 'filter_action' => $l['val'] === 'gore' ? 'hide' : 'warn'],
			'keyword_matches' => [], 'status_matches' => [],
		], $labels));
		$service = new FilterService($this->filtersRequest, new AiContentService($this->createStub(\OCA\Social\Service\ConfigService::class)), $labelers);
		$viewer = new Person();
		$viewer->setId('https://social.test/@alice');
		$viewer->setUserId('alice');
		$warned = ['id' => '1', 'content' => 'x', 'bluesky' => ['uri' => 'at://x', 'url' => '', 'labels' => [['src' => 'did:plc:l', 'val' => 'spoiler']]]];
		$hidden = ['id' => '2', 'content' => 'y', 'bluesky' => ['uri' => 'at://y', 'url' => '', 'labels' => [['src' => 'did:plc:l', 'val' => 'gore']]]];
		$boosted = ['id' => '3', 'content' => '', 'bluesky' => null, 'reblog' => $hidden];
		$plain = ['id' => '4', 'content' => 'z', 'bluesky' => null];

		$kept = $service->apply([$warned, $hidden, $boosted, $plain], 'home', $viewer);

		$this->assertSame(['1', '4'], array_column($kept, 'id'), 'hidden, and hidden when boosted');
		$this->assertSame('bluesky-label:did:plc:l:spoiler', $kept[0]['filtered'][0]['filter']['id']);
		$this->assertSame([], $kept[1]['filtered']);
		$this->assertCount(4, $service->apply([$warned, $hidden, $boosted, $plain], 'home', null), 'nobody signed in, nobody\'s choices');
	}
}
