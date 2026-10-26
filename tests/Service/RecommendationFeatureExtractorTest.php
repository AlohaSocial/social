<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\RecommendationFeatureExtractor;
use OCA\Social\Service\RecommendationTopicCatalog;
use PHPUnit\Framework\TestCase;

/** Stable topic and format features from public post content. */
class RecommendationFeatureExtractorTest extends TestCase {
	private function extractor(): RecommendationFeatureExtractor {
		return new RecommendationFeatureExtractor(new RecommendationTopicCatalog());
	}

	public function testMatchesUntaggedWordsAndPropagatesNonSensitiveParents(): void {
		$post = (new Note())->setContent('<p>Nextcloud veröffentlicht heute eine neue Version.</p>')->setLanguage('de');

		$features = $this->extractor()->extract($post);

		$this->assertSame(9, $features['version']);
		$this->assertSame('de', $features['language']);
		$this->assertSame(0.72, $features['topics']['oss.nextcloud']['score']);
		$this->assertSame('text', $features['topics']['oss.nextcloud']['evidence']);
		$this->assertArrayHasKey('oss', $features['topics']);
		$this->assertArrayNotHasKey('story_terms', $features, 'only the For You candidate path asks for temporary story terms');
	}

	public function testWordBoundariesPreventSubstringMatchesAndHashtagsRemainStrongEvidence(): void {
		$extractor = $this->extractor();
		$substring = $extractor->extract((new Note())->setContent('<p>Pineapple is delicious.</p>'));
		$this->assertArrayNotHasKey('tech.apple', $substring['topics']);
		$splitTag = $extractor->extract((new Note())->setContent('<p>Next</p><p>cloud update</p>'));
		$this->assertArrayNotHasKey('oss.nextcloud', $splitTag['topics']);

		$tagged = $extractor->extract((new Note())->setContent('<p>Self-hosting.</p>')->setHashtags(['OpenSource']));
		$this->assertSame(1.0, $tagged['topics']['oss']['score']);
		$this->assertSame('hashtag', $tagged['topics']['oss']['evidence']);
	}

	public function testSensitivePolicyTopicsAreNotInferredAndFormatUsesStoredMediaFacts(): void {
		$extractor = $this->extractor();
		$politics = $extractor->extract((new Note())->setContent('<p>International politics today.</p>'));
		$health = $extractor->extract((new Note())->setContent('<p>New research on mental health and sleep science.</p>'));
		$identity = $extractor->extract((new Note())->setContent('<p>A queer community event discusses gender identity.</p>'));
		$this->assertArrayNotHasKey('politics', $politics['topics']);
		$this->assertArrayNotHasKey('politics.international', $politics['topics']);
		$this->assertArrayNotHasKey('health', $health['topics']);
		$this->assertArrayNotHasKey('health.mental', $health['topics']);
		$this->assertArrayNotHasKey('health.sleep', $health['topics']);
		$this->assertArrayNotHasKey('identity', $identity['topics']);
		$this->assertArrayNotHasKey('identity.lgbtq', $identity['topics']);

		$privateHealth = $extractor->extract((new Note())->setContent('<p>Personal research about mental health and treatment.</p>'), true);
		$taggedHealth = $extractor->extract((new Note())->setContent('<p>Personal research and treatment.</p>')->setHashtags(['Health']), true);
		$this->assertSame([], $privateHealth['story_terms']);
		$this->assertSame([], $taggedHealth['story_terms']);

		$short = (new Note())->setAttachments([
			(new MediaAttachment())->import([
				'type' => 'video',
				'meta' => ['duration' => 20, 'original' => ['width' => 720, 'height' => 1280]],
			]),
		]);
		$this->assertSame('short', $extractor->extract($short)['format']);
		$this->assertSame('poll', $extractor->extract(new Question())['format']);
	}

	public function testExpandedTopicAliasesMatchEnglishAndGermanContent(): void {
		$extractor = $this->extractor();
		$englishAi = $extractor->extract((new Note())->setContent('<p>Artificial intelligence and machine learning research.</p>'));
		$germanClimate = $extractor->extract((new Note())->setContent('<p>Der Klimawandel verändert die Umwelt.</p>'));
		$englishSports = $extractor->extract((new Note())->setContent('<p>Football and basketball highlights.</p>'));
		$germanGaming = $extractor->extract((new Note())->setContent('<p>Neue Videospiele erscheinen heute.</p>'));
		$germanBusiness = $extractor->extract((new Note())->setContent('<p>Wirtschaft und Unternehmen im Wandel.</p>'));
		$germanCulture = $extractor->extract((new Note())->setContent('<p>Kultur und Kunst im Museum.</p>'));
		$germanPhotography = $extractor->extract((new Note())->setContent('<p>Fotografie mit natürlichem Licht.</p>'));
		$englishEducation = $extractor->extract((new Note())->setContent('<p>New approaches to education and teaching.</p>'));
		$englishSoftware = $extractor->extract((new Note())->setContent('<p>Programming and software development.</p>'));

		$this->assertArrayHasKey('tech.ai', $englishAi['topics']);
		$this->assertArrayHasKey('technology', $englishAi['topics']);
		$this->assertArrayHasKey('environment', $germanClimate['topics']);
		$this->assertArrayHasKey('sports', $englishSports['topics']);
		$this->assertArrayHasKey('gaming', $germanGaming['topics']);
		$this->assertArrayHasKey('business', $germanBusiness['topics']);
		$this->assertArrayHasKey('culture', $germanCulture['topics']);
		$this->assertArrayHasKey('photography', $germanPhotography['topics']);
		$this->assertArrayHasKey('education', $englishEducation['topics']);
		$this->assertArrayHasKey('software', $englishSoftware['topics']);
		$this->assertSame(9, $englishAi['version']);

		$science = $extractor->extract((new Note())->setContent('<p>Climate science and marine biology findings.</p>'));
		$softwareTesting = $extractor->extract((new Note())->setContent('<p>Software testing and web accessibility matter.</p>'));
		$railTravel = $extractor->extract((new Note())->setContent('<p>Mit dem Zug reisen und Bahnreisen nachhaltig gestalten.</p>'));
		$this->assertArrayHasKey('science.climate', $science['topics']);
		$this->assertArrayHasKey('environment.oceans', $science['topics']);
		$this->assertArrayHasKey('software.testing', $softwareTesting['topics']);
		$this->assertArrayHasKey('software.accessibility', $softwareTesting['topics']);
		$this->assertArrayHasKey('travel.rail', $railTravel['topics']);
	}

	public function testAmbiguousAppleRequiresTechnologyContextAndIgnoresQuotedOrCodeText(): void {
		$extractor = $this->extractor();
		$brand = $extractor->extract((new Note())->setContent('<p>Apple unveils macOS today.</p>'));
		$fruit = $extractor->extract((new Note())->setContent('<p>I ate an apple after visiting the orchard.</p>'));
		$quoted = $extractor->extract((new Note())->setContent('<blockquote>Apple unveils macOS today.</blockquote><p>A sunny afternoon.</p>'));
		$markdownQuote = $extractor->extract((new Note())->setContent("> Apple unveils macOS today.\nA sunny afternoon."));
		$code = $extractor->extract((new Note())->setContent('<pre><code>Nextcloud and Apple iPhone</code></pre><p>A sunny afternoon.</p>'));
		$markdownCode = $extractor->extract((new Note())->setContent("A sunny afternoon.\n```\nNextcloud Apple iPhone\n```"));

		$this->assertArrayHasKey('tech.apple', $brand['topics']);
		$this->assertArrayNotHasKey('tech.apple', $fruit['topics']);
		$this->assertArrayNotHasKey('tech.apple', $quoted['topics']);
		$this->assertArrayNotHasKey('technology', $quoted['topics']);
		$this->assertArrayNotHasKey('tech.apple', $markdownQuote['topics']);
		$this->assertArrayNotHasKey('oss.nextcloud', $code['topics']);
		$this->assertArrayNotHasKey('tech.apple', $code['topics']);
		$this->assertArrayNotHasKey('oss.nextcloud', $markdownCode['topics']);
		$this->assertArrayNotHasKey('tech.apple', $markdownCode['topics']);
	}

	public function testStoryTermsAreBoundedAndExcludeStopWordsQuotedTextAndCode(): void {
		$content = '<p>2026: The PHP maintainers disclosed a security vulnerability and released a patch for affected installations.</p>'
			. '<blockquote>copied quotation unrelated terminology hidden here</blockquote>'
			. '<pre><code>private source code secretword</code></pre>';
		$features = $this->extractor()->extract((new Note())->setContent($content), true);

		$this->assertContains('php', $features['story_terms']);
		$this->assertContains('2026', $features['story_terms']);
		$this->assertContains('vulnerability', $features['story_terms']);
		$this->assertNotContains('the', $features['story_terms']);
		$this->assertNotContains('copied', $features['story_terms']);
		$this->assertNotContains('secretword', $features['story_terms']);
		$this->assertLessThanOrEqual(60, count($features['story_terms']));
		$this->assertSame(array_slice($features['story_terms'], 0, 20), $features['story_headline_terms']);
	}

	public function testLongPostStoryTermsIncludeInformationFromTheEndOfThePost(): void {
		$terms = array_map(static fn (int $number): string => 'distinctword' . $number, range(1, 700));
		$terms[] = 'lateincidenttoken';
		$features = $this->extractor()->extract((new Note())->setContent('<p>' . implode(' ', $terms) . '</p>'), true);

		$this->assertCount(60, $features['story_terms']);
		$this->assertSame('distinctword1', $features['story_terms'][0], 'the opening remains represented');
		$this->assertContains('lateincidenttoken', $features['story_terms'], 'long-post fingerprints also cover the end');
	}

	public function testStoryFingerprintsAreOmittedWhenSensitiveEvidenceMayBeBeyondTheContentBound(): void {
		$content = str_repeat('ordinary discussion words remain within this long post. ', 500)
			. '<p>personal health treatment details are beyond the extraction bound</p>';
		$features = $this->extractor()->extract((new Note())->setContent($content), true);

		$this->assertSame([], $features['story_terms']);
	}

	public function testSecurityIncidentsAreRecognisedInEnglishAndGerman(): void {
		$english = $this->extractor()->extract((new Note())->setContent(
			'<p>PHP maintainers disclose a critical security vulnerability and release a security patch.</p>'
		));
		$german = $this->extractor()->extract((new Note())->setContent(
			'<p>Die PHP-Maintainer beheben eine kritische Sicherheitslücke.</p>'
		));

		$this->assertArrayHasKey('tech.security', $english['topics']);
		$this->assertArrayHasKey('tech.security', $german['topics']);
		$this->assertArrayHasKey('software.backend', $english['topics']);
		$this->assertArrayHasKey('software.backend', $german['topics']);
	}

	public function testLinkCardHeadlineJoinsGenericLinkPostsIntoTheSameStory(): void {
		$extractor = $this->extractor();
		$first = (new Note())->setContent('<p>Read this report: https://first.example/report</p>')
			->setCard((new StreamCard())->setTitle('PHP maintainers disclose the PHP2Shell vulnerability')
				->setDescription('A critical remote code execution flaw affects PHP servers.'));
		$second = (new Note())->setContent('<p>More details here: https://second.example/security</p>')
			->setCard((new StreamCard())->setTitle('PHP2Shell vulnerability disclosed by PHP maintainers')
				->setDescription('Critical remote code execution flaw affects PHP servers.'));

		$firstTerms = $extractor->extract($first, true)['story_terms'];
		$secondTerms = $extractor->extract($second, true)['story_terms'];

		self::assertNotEmpty(array_intersect($firstTerms, $secondTerms));
		self::assertContains('php2shell', $firstTerms);
		self::assertSame([], $extractor->extract(
			(new Note())->setContent('<p>Community update</p>')
				->setCard((new StreamCard())->setTitle('Personal mental health treatment details')),
			true
		)['story_terms'], 'sensitive evidence in card metadata suppresses the complete story fingerprint');
	}
}
