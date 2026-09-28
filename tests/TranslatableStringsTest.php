<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The strings this app offers for translation, checked against what the
 * translation pipeline can actually carry.
 *
 * `.github/workflows/l10n.yml` extracts the source strings with gettext and
 * pushes them to Transifex; `l10n-pull.yml` brings the finished translations
 * back. That pipeline is not run here — it needs `xgettext` and a network — but
 * the mistakes that make a string permanently untranslatable are all visible in
 * the source, and each of them is silent: the app builds, the tests pass, the
 * string reaches Transifex or does not, and the only way to find out is to read
 * a translated instance in a language you do not speak.
 *
 * So they are caught here instead, on the PR that introduces them.
 */
class TranslatableStringsTest extends TestCase {
	/** Directories the extractor reads, as `.l10nignore` leaves them. */
	private const SOURCES = ['lib', 'src', 'templates'];

	/**
	 * Every `t('social', '…')` in the source, by the message it translates.
	 *
	 * @return array<string, string[]> message => the places it is written
	 */
	private static function singulars(): array {
		return self::messages('/(?<![\w$>])t\(\s*[\'"]social[\'"]\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/');
	}

	/**
	 * Every `n('social', '…', '…', …)`, by its singular form.
	 *
	 * @return array<string, string[]> singular => the places it is written
	 */
	private static function plurals(): array {
		return self::messages('/(?<![\w$>])n\(\s*[\'"]social[\'"]\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/');
	}

	/**
	 * @param string $pattern a regular expression whose first group is the message
	 *
	 * @return array<string, string[]> message => the places it is written
	 */
	private static function messages(string $pattern): array {
		$found = [];
		foreach (self::sourceFiles() as $path) {
			$body = (string)file_get_contents($path);
			if (preg_match_all($pattern, $body, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $message) {
				$found[stripcslashes($message)][] = self::relative($path);
			}
		}

		return $found;
	}

	/** @return string[] every file the extractor reads */
	private static function sourceFiles(): array {
		$files = [];
		foreach (self::SOURCES as $directory) {
			$root = dirname(__DIR__) . '/' . $directory;
			if (!is_dir($root)) {
				continue;
			}

			$walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
			foreach ($walk as $file) {
				if (in_array($file->getExtension(), ['php', 'js', 'vue'], true)) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	private static function relative(string $path): string {
		return substr($path, strlen(dirname(__DIR__)) + 1);
	}

	/**
	 * A message written once as `t()` and once as the singular of `n()`.
	 *
	 * gettext cannot hold both: it folds them into one plural entry, keyed
	 * `_singular_::_plural_`, and `t()` looks the bare message up — finds
	 * nothing, and falls back to English in every language for ever. The app
	 * looks correct in English, which is the only language anybody testing it
	 * is likely to be reading.
	 *
	 * The fix is to reword one of the two. There is no message context in this
	 * pipeline to disambiguate them with.
	 */
	public function testNoMessageIsWrittenBothWithAndWithoutAPlural(): void {
		$both = array_intersect_key(self::singulars(), self::plurals());

		$this->assertSame(
			[],
			array_map(
				static fn (array $places): array => array_values(array_unique($places)),
				$both
			),
			'these messages are used as both a singular and the singular of a plural, '
			. 'so t() will never find a translation for them'
		);
	}

	/**
	 * A message that is only a URL, an address or a bare number.
	 *
	 * Ninety-eight locales are asked to translate it, every one of them can
	 * only copy it back, and any one of them that does not copies a typo into
	 * an example somebody will follow. These belong in the attribute, not in
	 * the catalogue.
	 */
	public function testNothingUntranslatableIsOfferedForTranslation(): void {
		$offered = [];
		foreach (array_merge(array_keys(self::singulars()), array_keys(self::plurals())) as $message) {
			if (preg_match('#^(https?://\S+|[\w.+-]+@[\w-]+\.\w+|[\d\s.,:/-]+)$#', $message) === 1) {
				$offered[] = $message;
			}
		}

		$this->assertSame([], $offered, 'a translator can only copy these back');
	}

	/**
	 * A message the extractor cannot see at all.
	 *
	 * `xgettext` reads the source as text, so it only finds a message written
	 * as a literal. `t('social', someVariable)` compiles, runs, and reaches
	 * translators as nothing — the string is untranslatable and nothing says
	 * so. A message built from parts belongs in one string with a placeholder.
	 */
	public function testEveryTranslatedMessageIsALiteral(): void {
		// the message argument, up to the first thing that could end it
		$pattern = '/(?<![\w$>])(?:t|n)\(\s*[\'"]social[\'"]\s*,\s*([^\s\'"][^,)]{0,40})/';

		$dynamic = [];
		foreach (self::sourceFiles() as $path) {
			$body = (string)file_get_contents($path);
			// a call broken across lines still starts its message with a quote
			$body = preg_replace('/,\s*\n\s*/', ', ', $body) ?? $body;
			if (preg_match_all($pattern, $body, $matches) === 0) {
				continue;
			}

			foreach ($matches[1] as $argument) {
				$dynamic[] = self::relative($path) . ': ' . trim($argument);
			}
		}

		$this->assertSame([], $dynamic, 'xgettext cannot read a message that is not a literal');
	}

	/**
	 * The sidebar and post action menu are high-traffic surfaces whose English
	 * fallbacks were visible in the German screenshots for issue #2282. A new
	 * label in either component must be present in both catalog formats that
	 * Nextcloud serves.
	 */
	public function testHighTrafficViewsHaveGermanCatalogEntries(): void {
		$files = [
			'src/components/Navigation.vue',
			'src/components/admin/ExternalAccountsSection.vue',
			'src/components/admin/ExternalUsersSection.vue',
			'src/components/admin/ExternalRequestsSection.vue',
			'src/views/Signup.vue',
			'src/views/AccountSetup.vue',
			'src/components/TimelinePost.vue',
			'src/views/Timeline.vue',
			'src/components/Search.vue',
			'src/components/FediverseSearch.vue',
			'src/views/Discover.vue',
			'src/components/DiscoverCategories.vue',
			'src/views/VideoReels.vue',
			'src/components/VideoHeader.vue',
			'src/components/ProfileMediaGrid.vue',
			'src/components/FollowGraphSuggestions.vue',
			'src/components/DirectMessages.vue',
			'src/components/PostMenu.vue',
			'src/components/Composer/Composer.vue',
			'src/App.vue',
			'src/views/SwitchWizard.vue',
			'src/views/Statistics.vue',
		];
		$messages = [];
		foreach (self::singulars() as $message => $paths) {
			if (array_intersect($files, $paths) !== []) {
				$messages[] = $message;
			}
		}

		$missing = [];
		foreach (['de', 'de_DE'] as $locale) {
			$catalogPath = dirname(__DIR__) . '/l10n/' . $locale . '.json';
			$catalog = json_decode((string)file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR)['translations'];
			$jsCatalog = (string)file_get_contents(dirname(__DIR__) . '/l10n/' . $locale . '.js');
			foreach ($messages as $message) {
				if (!array_key_exists($message, $catalog)) {
					$missing[] = $locale . ': ' . $message;
					continue;
				}

				$key = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				$value = json_encode($catalog[$message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				if (!str_contains($jsCatalog, $key . ' : ' . $value)) {
					$missing[] = $locale . ': ' . $message . ' (JavaScript catalog)';
				}
			}
		}

		$this->assertSame([], $missing, 'navigation, timeline, conversation, statistics, composer, setup, and app labels must exist in both German catalog formats');
	}

	public function testSwitchWizardPluralMessagesHaveGermanCatalogEntries(): void {
		$messages = [
			'_Looked up %n name so far_::_Looked up %n names so far_' => ['%n Name geprüft', 'Bisher %n Namen geprüft'],
			'_found %n_::_found %n_' => ['%n gefunden', '%n gefunden'],
			'_%n left_::_%n left_' => ['%n übrig', '%n übrig'],
			'_Follow %n account_::_Follow %n accounts_' => ['%n Konto folgen', '%n Konten folgen'],
			'_Brought over %n post._::_Brought over %n posts._' => ['%n Beitrag übernommen.', '%n Beiträge übernommen.'],
		];
		$missing = [];
		foreach (['de', 'de_DE'] as $locale) {
			$catalogPath = dirname(__DIR__) . '/l10n/' . $locale . '.json';
			$catalog = json_decode((string)file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR)['translations'];
			$jsCatalog = (string)file_get_contents(dirname(__DIR__) . '/l10n/' . $locale . '.js');
			foreach ($messages as $message => $translation) {
				if (($catalog[$message] ?? null) !== $translation) {
					$missing[] = $locale . ': ' . $message;
					continue;
				}
				$key = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				$value = json_encode($translation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				if (!str_contains($jsCatalog, $key . ' : ' . $value)) {
					$missing[] = $locale . ': ' . $message . ' (JavaScript catalog)';
				}
			}
		}

		$this->assertSame([], $missing, 'setup wizard plurals must exist in both German catalog formats');
	}

	public function testStatisticsPluralMessagesHaveGermanCatalogEntries(): void {
		$messages = [
			'_post_::_posts_' => ['Beitrag', 'Beiträge'],
			'_{days} day. Directly comparable._::_{days} days. Directly comparable._' => ['{days} Tag. Direkt vergleichbar.', '{days} Tage. Direkt vergleichbar.'],
			'_%n post_::_%n posts_' => ['%n Beitrag', '%n Beiträge'],
			'_%n day_::_%n days_' => ['%n Tag', '%n Tage'],
			'_picture posted_::_pictures posted_' => ['Bild veröffentlicht', 'Bilder veröffentlicht'],
			'_Last %n day_::_Last %n days_' => ['%n Tag zuvor', '%n Tage zuvor'],
			'_{counted} post, all of them_::_{counted} posts, all of them_' => ['Alle {counted} Beiträge', 'Alle {counted} Beiträge'],
			'_The audience of one account that boosted you is not known here, and counts as nobody._::_The audiences of {count} accounts that boosted you are not known here, and count as nobody._' => [
				'Die Reichweite des einen Kontos, das dich geboostet hat, ist hier unbekannt und wird nicht mitgezählt.',
				'Die Reichweite von {count} Konten, die dich geboostet haben, ist hier unbekannt und wird nicht mitgezählt.',
			],
			'_The one post of the window._::_All {posts} posts of the window._' => ['Der einzige Beitrag dieses Zeitraums.', 'Alle {posts} Beiträge dieses Zeitraums.'],
			'_Counted over your one post._::_Counted over all {count} of your posts._' => ['Für deinen einzigen Beitrag berechnet.', 'Für alle {count} deiner Beiträge berechnet.'],
			'_%n engagement in {month}_::_%n engagement in {month}_' => ['%n Interaktion im {month}', '%n Interaktionen im {month}'],
			'_%n follower in {month}_::_%n followers in {month}_' => ['%n Follower im {month}', '%n Follower im {month}'],
			'_%n post in {month}_::_%n posts in {month}_' => ['%n Beitrag im {month}', '%n Beiträge im {month}'],
			'_%n post at {hour}:00 UTC_::_%n posts at {hour}:00 UTC_' => ['%n Beitrag um {hour}:00 UTC', '%n Beiträge um {hour}:00 UTC'],
		];
		$missing = [];
		foreach (['de', 'de_DE'] as $locale) {
			$catalogPath = dirname(__DIR__) . '/l10n/' . $locale . '.json';
			$catalog = json_decode((string)file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR)['translations'];
			$jsCatalog = (string)file_get_contents(dirname(__DIR__) . '/l10n/' . $locale . '.js');
			foreach ($messages as $message => $translation) {
				if (($catalog[$message] ?? null) !== $translation) {
					$missing[] = $locale . ': ' . $message;
					continue;
				}
				$key = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				$value = json_encode($translation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				if (!str_contains($jsCatalog, $key . ' : ' . $value)) {
					$missing[] = $locale . ': ' . $message . ' (JavaScript catalog)';
				}
			}
		}

		$this->assertSame([], $missing, 'statistics plurals must exist in both German catalog formats');
	}

	public function testGermanJsonAndJavascriptCatalogValuesAgree(): void {
		$mismatched = [];
		foreach (['de', 'de_DE'] as $locale) {
			$catalogPath = dirname(__DIR__) . '/l10n/' . $locale . '.json';
			$catalog = json_decode((string)file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR)['translations'];
			$jsCatalog = (string)file_get_contents(dirname(__DIR__) . '/l10n/' . $locale . '.js');
			foreach ($catalog as $message => $translation) {
				$key = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
				$value = json_encode($translation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
				if (!str_contains($jsCatalog, $key . ' : ' . $value)) {
					$mismatched[] = $locale . ': ' . $message;
				}
			}
		}

		$this->assertSame([], $mismatched, 'Nextcloud must serve the same German translations from JSON and JavaScript catalogs');
	}

	public function testCoreTimelineAndDiscoveryPluralsHaveGermanCatalogEntries(): void {
		$messages = [
			'_Follow one more account and this can look at who they follow._::_Follow {count} more accounts and this can look at who they follow._' => [
				'Folge noch einem Konto, dann können wir prüfen, wem es folgt.',
				'Folge noch {count} Konten, dann können wir prüfen, wem sie folgen.',
			],
			'_%n entry_::_%n entries_' => ['%n Eintrag', '%n Einträge'],
			'_%n view_::_%n views_' => ['%n Aufruf', '%n Aufrufe'],
			'_%n like_::_%n likes_' => ['%n Like', '%n Likes'],
			'_%n dislike_::_%n dislikes_' => ['%n Ablehnung', '%n Ablehnungen'],
			'_%n new activity_::_%n new activities_' => ['%n neue Aktivität', '%n neue Aktivitäten'],
			'_{names} did not answer. What is above is the rest._::_{names} did not answer. What is above is the rest._' => [
				'Die Server von {names} haben nicht geantwortet. Die übrigen Ergebnisse stehen oben.',
				'Die Server von {names} haben nicht geantwortet. Die übrigen Ergebnisse stehen oben.',
			],
			'_%n directory_::_%n directories_' => ['%n Verzeichnis', '%n Verzeichnisse'],
			'_One account could not be reached from this server:_::_%n accounts could not be reached from this server:_' => [
				'Ein Konto konnte von diesem Server nicht erreicht werden:',
				'%n Konten konnten von diesem Server nicht erreicht werden:',
			],
			'_%n account_::_%n accounts_' => ['%n Konto', '%n Konten'],
			'_Followed %n account_::_Followed %n accounts_' => ['Du folgst jetzt %n Konto', 'Du folgst jetzt %n Konten'],
		];
		$missing = [];
		foreach (['de', 'de_DE'] as $locale) {
			$catalogPath = dirname(__DIR__) . '/l10n/' . $locale . '.json';
			$catalog = json_decode((string)file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR)['translations'];
			$jsCatalog = (string)file_get_contents(dirname(__DIR__) . '/l10n/' . $locale . '.js');
			foreach ($messages as $message => $translation) {
				if (($catalog[$message] ?? null) !== $translation) {
					$missing[] = $locale . ': ' . $message;
					continue;
				}
				$key = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				$value = json_encode($translation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				if (!str_contains($jsCatalog, $key . ' : ' . $value)) {
					$missing[] = $locale . ': ' . $message . ' (JavaScript catalog)';
				}
			}
		}

		$this->assertSame([], $missing, 'core timeline and discovery plural messages must exist in both German catalog formats');
	}

	/**
	 * The extractor has to be told what not to read, or it offers the built
	 * bundle's copy of every string a second time and every dependency's
	 * strings besides.
	 */
	public function testTheExtractorIsToldToSkipWhatIsNotSource(): void {
		$ignored = array_filter(array_map(
			'trim',
			explode("\n", (string)file_get_contents(dirname(__DIR__) . '/.l10nignore'))
		), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'));

		foreach (['js/', 'node_modules/', 'vendor/', 'tests/'] as $directory) {
			$this->assertContains($directory, $ignored, $directory . ' is not source and must not be extracted');
		}
	}
}
