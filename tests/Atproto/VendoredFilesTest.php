<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The files copied from upstream, pinned to the upstream commit they were
 * copied from: the interop vectors (bluesky-social/atproto-interop-tests),
 * the lexicons the app validates records against and the query lexicons
 * the tests check the served XRPC answers against (both
 * bluesky-social/atproto). An edit to any of them fails here; refreshing
 * one means copying it again from a newer upstream commit and updating
 * both the commit and its hash below.
 */
class VendoredFilesTest extends TestCase {
	/** github.com/bluesky-social/atproto-interop-tests */
	public const INTEROP_TESTS_COMMIT = '056e5741bb330757205d6b16db5266fffcae937b';
	/** github.com/bluesky-social/atproto, main as of 2026-10-08 17:25 UTC */
	public const ATPROTO_COMMIT = 'b023046f9266b8755ad9b44928032426bb05599e';
	/** The profile and link lexicons, refreshed together for ordered profile links. */
	public const PROFILE_LINKS_COMMIT = 'a6f5a3c7f5e294e96971810d61f23b8f5998a5e2';

	/**
	 * tests/Atproto/fixtures, each file as upstream has it at
	 * INTEROP_TESTS_COMMIT (`lexicon/example.lexicon.record.json` is
	 * upstream's `lexicon/catalog/record.json`, reindented).
	 */
	private const INTEROP_VECTORS = [
		'LICENSE-CC0' => 'a2010f343487d3f7618affe54f789f5487602331c0a8d03f49e9a7c547cf0499',
		'commit-proof-fixtures.json' => '15c6823778d7e1162e1132aa85f37f1ca8a6beb751812a2869bd37537d38a83b',
		'common_prefix.json' => 'accfcc2febe2b30a5e587d4a758accaedfc30a8daef938f8c3e5ca6d8b28b2eb',
		'data-model-fixtures.json' => 'f2cf611fd5654b5ca4e2275a026bb83eb2337114466f11fe913f58c68c2b975d',
		'data-model-invalid.json' => '4511bb0b84279b1de90494e64af7a9054259ff0de0a6c79e068fed1a5ad1c887',
		'data-model-valid.json' => '601d04d7f16a271924805b9504e9bcd7d665f937c9c7ae8dd7d97adfe45ae6ec',
		'key_heights.json' => 'a9fafd06697d206aa4c28bc380610b723d93c503be7fc42565b8f24b54626bd5',
		'lexicon/example.lexicon.record.json' => 'd9513607eab8ca8d205f87ce3c9dffac363c8d3fec839c064f4d52a362f30b9a',
		'lexicon/record-data-invalid.json' => '993f4ac90ae00c5feeef2a58a9d9734ebb7fd61e5a0ca595db1ecce52b499d2f',
		'lexicon/record-data-valid.json' => 'f8f700e93244c818cdeb87ee5f550fe7627c400f64adb85777e697407f66e0d5',
		'signature-fixtures.json' => 'df784026e45c791a7bb14b49a62260b0437716b0280028e9b8c7cc6eade1b1c3',
		'syntax/atidentifier_syntax_invalid.txt' => '26014d5ffe87185bb4f915295c188366e6c068f07152ad1ea812ac4ea2428aeb',
		'syntax/atidentifier_syntax_valid.txt' => 'a83a3267623bb3dbeb04529f3b06c3ab0279f070dcc15b128d710adcbb6815d7',
		'syntax/aturi_syntax_invalid.txt' => 'f57124a9e823c0941a960005c3d7a88a3f00c8eaf28c023d4f1f1ca2229ff727',
		'syntax/aturi_syntax_valid.txt' => 'ec268ffc5f5c006c87dfa46fe2be6835b65ab941b24b4a9b0d28d249a7459c80',
		'syntax/cid_syntax_invalid.txt' => 'cdb0c58c48f0efc632316b1ec9077ffbf6a8c03be9ccfeb1ff4548b5db0e5427',
		'syntax/cid_syntax_valid.txt' => 'fd530c32e34a00e33a08c7759bb8a64926b5bf2bdf4640af52a5b0cd4ec18c46',
		'syntax/datetime_parse_invalid.txt' => '8c9d80a7a11809fb7d3a5ff5feab27c10c8e11645d010b4e5df0faa45ab497a0',
		'syntax/datetime_syntax_invalid.txt' => '3fa10c56b2380aa5c8dff89a3baee8fdd6cc26849740072d3d744437274dceba',
		'syntax/datetime_syntax_valid.txt' => 'c56878cb8bfefd95522845b5ba926bcd094e44e6e19aecfe81cb40bbfe8c2422',
		'syntax/did_syntax_invalid.txt' => '98eeae969cb8d6adcc3ffcbf72b49773c57635cdd3b873c05af71bdfcbbdd25a',
		'syntax/did_syntax_valid.txt' => 'dea2bf95d29f2141efe2f9d4762e25033bac900c5600b19bbf4120ce1442b9e6',
		'syntax/handle_syntax_invalid.txt' => '9069133ace3e34263ecd585c78a434bdc4558cb4115cb883255a2493a08a0d2f',
		'syntax/handle_syntax_valid.txt' => '6cff66b8dee405f9b516e49726f679e732beab3e275820a88ad53d0c114318cb',
		'syntax/language_parse_invalid.txt' => '120e68b87bacfd530fcdc44114596c5f4554563355f70574563458e98447a1ec',
		'syntax/language_syntax_invalid.txt' => '7150cf77a778f624264852fa25821c647d1e341ffeeb475e1e564c4eb93b72c9',
		'syntax/language_syntax_valid.txt' => '90eed8560dc1dccd3479d22762d35e0e4a45902d9b7e869dd952dd7cf6ee0ee5',
		'syntax/nsid_syntax_invalid.txt' => 'eec328b5342e38b1e070411146a14409e6bb91d0ecdc27d9d8ca668505159f42',
		'syntax/nsid_syntax_valid.txt' => 'ff2c80b7f95c37377272c724da9a5ecb9b9720e9f46e7cc9e939acc7639a21a6',
		'syntax/recordkey_syntax_invalid.txt' => 'ab813eb308bfef83d1a0c00f35f0610767c8a3489ba7d48618e94eb1f9d99aaa',
		'syntax/recordkey_syntax_valid.txt' => '47c42d524964e5c90bdae69031d4f3ab5090df99944581c33511c684055c469e',
		'syntax/tid_syntax_invalid.txt' => 'c23491943007f7504878ba6001c7b43dd80d4e177773770dede50863295b55a7',
		'syntax/tid_syntax_valid.txt' => 'df4d1ad9143dd03ae13294956489eb304a437a786564655c10b091602d8f9c47',
		'syntax/uri_syntax_invalid.txt' => '35db5d84e52bf249c0609716de9fe19f9dd8c33514ee57c150cb973ba672d42e',
		'syntax/uri_syntax_valid.txt' => '005680ce1783a02f45c5be59b97955b77d97b3b290b3f59584966d8b22bea965',
		'w3c_didkey_K256.json' => 'd940afa86f4a63b72683e15ea7f0bb2f901e1b37087c19fd1fd3c29d30a49fd7',
		'w3c_didkey_P256.json' => 'f52ce904d192075fcbd162c95e43e8bb593e1b0d7024b390066e5c5e010a54a7',
	];

	/** lib/Atproto/lexicons at ATPROTO_COMMIT, except actor profile/link at PROFILE_LINKS_COMMIT. */
	private const LEXICONS = [
		'app.bsky.actor.defs.json' => '46ad2031e7358d9ce28f7c9782ccb02f50182e3268129762055037585448fc6e',
		'app.bsky.actor.link.json' => 'e10cff0d8b8f7ca34266a9a0c793d6c90e96da592c0a1b0165a3281896b50f48',
		'app.bsky.actor.profile.json' => '34ef6b3a0f5fbd705214c6581d4601b6e7bbb25cbc1f2d2532dad1cb69f85b7e',
		'app.bsky.embed.defs.json' => 'c9db5f67269836b6f1c147eb507685548f0cdbe2ac6bbd31f1d0c9eb796fd42e',
		'app.bsky.embed.external.json' => '0193c2f4704b4a1ad94e3c747b386530f4ec277c0d6336882e4ba5d8980c9ba1',
		'app.bsky.embed.gallery.json' => '6348fe6355898f76b6e87fdee7e19e7aa158dec657a1612dff04247e18989083',
		'app.bsky.embed.images.json' => '06491ead4a1bec37271a517d3c8ff2baa47ab5996d25819df471a6f68dd5d912',
		'app.bsky.embed.record.json' => '17dcb9f3c9fd7ee9c9e5d10547e5ae8d1ffa5f3cf43f585b3930ad102ef279f1',
		'app.bsky.embed.recordWithMedia.json' => '2b1069ecf6d65a31668f1ecb1ba54b0decff0eec3c9250df31cd3920cf8e06c1',
		'app.bsky.embed.video.json' => '789e2da1d249e7a05237885edf359387b8dcb863e007d5562a9a4166f70f1a31',
		'app.bsky.feed.defs.json' => '55e96fc7b1cde8125b9acfd26f21418ed0c519c511acaadc3e0bc33b8a278557',
		'app.bsky.feed.generator.json' => '2a031114731e71de2eacdc848fb12b3d3893f7ff6be4a01959e9fcc08564ae3c',
		'app.bsky.feed.like.json' => '4a231472b92d83030962cb9f10f2d0dcc7814e625763720fe48ecbb134ace554',
		'app.bsky.feed.post.json' => '383935b9d8425c7a4be904da95e6ab5e94ceb5d07e073c0eb8f7d39213525ce4',
		'app.bsky.feed.postgate.json' => '81eb46aa4e9de85f181ff2cdc4fdcf0c895074db9c68e34c9501ffa8d6e97266',
		'app.bsky.feed.repost.json' => 'ad7a400ee3bf3b2fc0f370e28d38871fa0a7f376fe5b2d9f773c8d8838a5afd2',
		'app.bsky.feed.threadgate.json' => '5984ec8e72a857658c7365ec2d2fded495423f41ee68c9bb421e31dad64c9607',
		'app.bsky.graph.block.json' => '27aef5410649c578cc39106df9b01e8ed7917425e27b01672530dce82a7b7d6f',
		'app.bsky.graph.defs.json' => 'c77775a8b752cfecae4310dd5d223e4cbd75b754f48ce6340c3380a6e88d2da9',
		'app.bsky.graph.follow.json' => '507790a01b646d511277f09385c6712b6e986b6fa8f60abd4ad2a4d9b01b0723',
		'app.bsky.graph.list.json' => '7ac0d373060411c99a9fb1d506ad9c8c11ae386fda22d2ac51acf727636723d2',
		'app.bsky.graph.listitem.json' => 'c006a45deec145a9bcb40fd068d66c32580666d6b4e911aabdff30f41565b6b7',
		'app.bsky.graph.starterpack.json' => 'af5c94e192de725c9781b9e97408a2670b960db01bdb5062940b8c20cf129daf',
		'app.bsky.graph.verification.json' => 'baa911bbc03cdbdf8f00d3965f51b3c9d517ba8a712fbf0a05fe65d81342b389',
		'app.bsky.notification.defs.json' => 'f6ce74e19bda3100d4c80924ab9636859ce445b6bb1016b150f0003553d09086',
		'app.bsky.richtext.facet.json' => 'ef44635c7fc3ab095bc02d29ecc2af6c410b4d5a4a82fd3a971a93674092ff8f',
		'chat.bsky.actor.declaration.json' => '9e056a303f26f705e02371f0be64e222c4158a570e0ec182d5c1924596de5a5a',
		'com.atproto.identity.signPlcOperation.json' => '33595630e559b08cb1d421ab2d3cfb8787a200ffd30676c338fd0a08ba2da266',
		'com.atproto.label.defs.json' => 'b20ed39fa5530ff62a6b4bfbbd7a3648dd57896db7c9c7ac379e1bfc64d1f724',
		'com.atproto.repo.strongRef.json' => '2d27ecc2a09a86b5065c36e36b987ef726a814023c5e198a1eda8e4c283714e0',
		'com.atproto.sync.subscribeRepos.json' => 'bfc3e22bfeae701736fbbbd68a56f0b4b8b66ef4e0e10f1c281b2de61c3328ae',
	];

	/** tests/Atproto/lexicons, each file as upstream has it at ATPROTO_COMMIT */
	private const QUERY_LEXICONS = [
		'com.atproto.identity.defs.json' => '234dc3c841462e3c6a53df427aab5b43d67d98a0ae2f59e1334f2a79cca70d15',
		'com.atproto.identity.refreshIdentity.json' => 'e77698c19e6e9f98ea1748c91021769f92f7f88b5db85a92d6d5ce73bfd2b3e2',
		'com.atproto.identity.resolveDid.json' => 'b906fd0103c1fecfc47843298a197f4131d547b51d9c64e874110209ce275929',
		'com.atproto.identity.resolveHandle.json' => 'd1cdbbc2a1a89d905228e0dc65546a140bd96262c60c4756e51027597ec5ebea',
		'com.atproto.identity.resolveIdentity.json' => '985a4fe82cfab549e4c8748e8961391cbd729316340e5f9dd6e7ba29e80f6343',
		'com.atproto.repo.describeRepo.json' => 'e9fa5f3461cbf1e9bfae4aab4c64893a0211125855371734ea4ab387e59f621b',
		'com.atproto.repo.getRecord.json' => 'd61aee451f284dd251b3451d16826e75f2bf8a6af32705120e1f67a3db4df8ff',
		'com.atproto.repo.listRecords.json' => 'd862390bf7b25d7f41b113570380320ce96ada19a529b4f50d88f509a7b20b5c',
		'com.atproto.server.describeServer.json' => 'bfe9afbd9bd7b6e758720a3744470c94fc2bb7d611d6b46b39abccf5ad1eff88',
		'com.atproto.sync.getBlob.json' => 'aaed9c4f39fe76a0eec90afd8635e5e2a655575e85f752cb6d8ae50be4057421',
		'com.atproto.sync.getBlocks.json' => '7186125e93d5342bfafe5d8086811e9b14e9f119d750222a02f2d03b292f8fee',
		'com.atproto.sync.getLatestCommit.json' => '698b4028ce312d629948ea6efe7eebe1e543fc7d3d289ee5a7966825d1f5856c',
		'com.atproto.sync.getRecord.json' => '1d5a50caf428c87b87922e0adf021956c719af589cb89c7a3dc513875df13cdb',
		'com.atproto.sync.getRepo.json' => 'c16b1b22bef031f0456d90cd4b4f648bc12ea1b3b9918eeb53177362875ee5fa',
		'com.atproto.sync.getRepoStatus.json' => '389167313a4e806c422ff2bbbd3e9a6190ad4480b57afd97354610b4c6020927',
		'com.atproto.sync.listBlobs.json' => '26671c6ff5946f09ec633019d20dc3fe8c86af7675f3f9666397f25f8d69d7f1',
		'com.atproto.sync.listRepos.json' => 'afb3599e6075c8b413cf3431e3d0ce0e66aa7eff681bcdfd0bf88aefdf0b52d1',
	];

	public static function pinnedDirectories(): array {
		return [
			'interop vectors' => [__DIR__ . '/fixtures', self::INTEROP_VECTORS],
			'lexicons' => [__DIR__ . '/../../lib/Atproto/lexicons', self::LEXICONS],
			'query lexicons' => [__DIR__ . '/lexicons', self::QUERY_LEXICONS],
		];
	}

	/**
	 * @param array<string, string> $pinned path => SHA-256
	 */
	#[DataProvider('pinnedDirectories')]
	public function testEveryVendoredFileIsTheUpstreamOne(string $directory, array $pinned): void {
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = substr($file->getPathname(), strlen($directory) + 1);
			$found[$path] = hash_file('sha256', $file->getPathname());
		}
		ksort($found);

		$this->assertSame($pinned, $found, 'a vendored file was added, removed or edited without updating its pin');
	}

	/**
	 * A lexicon document names itself: the validator finds a document by
	 * its file name and refuses one whose `id` says otherwise.
	 */
	public function testEveryLexiconIsTheDocumentItsNameSays(): void {
		foreach ([__DIR__ . '/../../lib/Atproto/lexicons', __DIR__ . '/lexicons'] as $directory) {
			foreach (glob($directory . '/*.json') ?: [] as $path) {
				$document = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
				$this->assertSame(1, $document['lexicon'] ?? null, basename($path));
				$this->assertSame(basename($path, '.json'), $document['id'] ?? null);
			}
		}
	}

	/**
	 * Every definition a record type reaches, through a `ref` or as a
	 * member of a union, open or closed, is vendored too: the validator
	 * checks a value against the member its `$type` names, so a member
	 * that is named but missing refuses every record that uses it. A type
	 * an open union does not name passes unvalidated.
	 */
	public function testEveryDefinitionARecordReachesIsVendored(): void {
		$directory = __DIR__ . '/../../lib/Atproto/lexicons';
		$documents = [];
		foreach (glob($directory . '/*.json') ?: [] as $path) {
			$documents[basename($path, '.json')] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		}
		$records = 0;
		$missing = [];
		foreach ($documents as $nsid => $document) {
			if (($document['defs']['main']['type'] ?? '') !== 'record') {
				continue;
			}
			$records++;
			$seen = [];
			$this->reach($document['defs']['main']['record'], $nsid, $documents, $seen, $missing);
		}

		$this->assertSame(15, $records, 'post, like, repost, follow, block, profile, profile link, list, listitem, threadgate, postgate, starterpack, generator, verification, chat declaration');
		$this->assertSame([], array_values(array_unique($missing)));
	}

	/**
	 * @param array<string, array> $documents
	 * @param array<string, true> $seen
	 * @param list<string> $missing
	 */
	private function reach(mixed $schema, string $nsid, array $documents, array &$seen, array &$missing): void {
		if (!is_array($schema)) {
			return;
		}
		$type = $schema['type'] ?? null;
		$refs = match ($type) {
			'ref' => [(string)$schema['ref']],
			'union' => $schema['refs'] ?? [],
			default => [],
		};
		foreach ($refs as $ref) {
			$target = str_starts_with($ref, '#') ? $nsid . $ref : $ref;
			[$targetNsid, $fragment] = array_pad(explode('#', $target, 2), 2, 'main');
			if (isset($seen[$targetNsid . '#' . $fragment])) {
				continue;
			}
			$seen[$targetNsid . '#' . $fragment] = true;
			$definition = $documents[$targetNsid]['defs'][$fragment] ?? null;
			if (!is_array($definition)) {
				$missing[] = $targetNsid . '#' . $fragment;
				continue;
			}
			$this->reach($definition, $targetNsid, $documents, $seen, $missing);
		}
		foreach ($schema as $key => $value) {
			if ($key !== 'refs' && $key !== 'ref') {
				$this->reach($value, $nsid, $documents, $seen, $missing);
			}
		}
	}
}
