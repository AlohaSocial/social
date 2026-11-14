<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Db;

use Doctrine\DBAL\Schema\Column;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Service\AccountService;
use OCA\Social\Tests\Integration\Helper\RowSeeder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a Nextcloud user takes everything their Social account left
 * behind with it, in every table, against the real database.
 *
 * The cascade's unit test proves that each step is *called*. This proves the
 * outcome: one row per table and per column that names the account is seeded
 * for a local user, the user is deleted the way an administrator deletes one
 * (`IUser::delete()`, so `UserDeletedListener` and the whole production path
 * run), and afterwards no row anywhere names them while another user's rows
 * are all still there.
 *
 * The schema guard below is what keeps it complete: a table whose columns
 * look like a reference to an account fails the suite until it is either
 * seeded here (and so cascaded) or listed with the reason it stays.
 */
class ActorCascadeTest extends TestCase {
	private const ACCOUNT_REFERENCE = '/^(actor_id|actor_id_prim|attributed_to|attributed_to_prim|author|author_prim'
		. '|author_id|author_id_prim|owner_id|owner_id_prim|account_id|account|account_prim|auth_account'
		. '|auth_user_id|user_id|tagger_id_prim|creator|moderator|assigned_to|action_taken_by)$/';

	/**
	 * The tables that name an account, by reference. One reference is one or
	 * more columns that together say "this account": the id, its md5 `_prim`,
	 * or the Nextcloud user id.
	 *
	 * @var array<string, list<array<string, 'uri'|'prim'|'uid'>>>
	 */
	private const CASCADED = [
		'social_account_note' => [['actor_id_prim' => 'prim'], ['object_id' => 'uri', 'object_id_prim' => 'prim']],
		'social_action' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_actor_relation' => [['actor_id_prim' => 'prim'], ['object_id' => 'uri', 'object_id_prim' => 'prim']],
		'social_announce_react' => [['actor_id_prim' => 'prim']],
		'social_announce_read' => [['actor_id_prim' => 'prim']],
		'social_atproto_app_password' => [['user_id' => 'uid']],
		'social_atproto_labeler' => [['user_id' => 'uid']],
		'social_atproto_move' => [['user_id' => 'uid']],
		'social_atproto_oauth_request' => [['user_id' => 'uid']],
		'social_atproto_oauth_session' => [['user_id' => 'uid']],
		'social_atproto_session' => [['user_id' => 'uid']],
		'social_cache_actor' => [['id' => 'uri', 'id_prim' => 'prim']],
		'social_cache_doc' => [['parent_id' => 'uri', 'parent_id_prim' => 'prim']],
		'social_channel' => [['owner_id' => 'uri', 'owner_id_prim' => 'prim']],
		'social_client_auth' => [['user_id' => 'uid']],
		'social_device_auth' => [['user_id' => 'uid']],
		'social_collection' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_convo_state' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_domain_block' => [['actor_id_prim' => 'prim']],
		'social_featured_tag' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_file_comment' => [['user_id' => 'uid']],
		'social_file_post' => [['user_id' => 'uid']],
		'social_filter' => [['actor_id_prim' => 'prim']],
		'social_follow' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim'], ['object_id' => 'uri', 'object_id_prim' => 'prim']],
		'social_followed_tag' => [['actor_id_prim' => 'prim']],
		'social_import' => [['user_id' => 'uid']],
		'social_import_post' => [['actor_id_prim' => 'prim']],
		'social_interest' => [['actor_id_prim' => 'prim']],
		'social_interest_hide' => [['actor_id_prim' => 'prim']],
		'social_list' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_list_member' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_media_tag' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim'], ['tagger_id_prim' => 'prim']],
		'social_mute_expiry' => [['actor_id_prim' => 'prim'], ['object_id_prim' => 'prim']],
		'social_post_hold' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_quote_grant' => [['actor_id' => 'uri']],
		'social_reaction' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_report' => [['actor_id' => 'uri'], ['account_id' => 'uri']],
		'social_req_queue' => [['author' => 'uri', 'author_prim' => 'prim']],
		'social_scheduled' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_story' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_story_react' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_story_view' => [['actor_id_prim' => 'prim']],
		'social_stream' => [['attributed_to' => 'uri', 'attributed_to_prim' => 'prim']],
		'social_stream_act' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_stream_dest' => [['actor_id' => 'prim']],
		'social_stream_view' => [['actor_id_prim' => 'prim']],
		'social_team_post' => [['author_id' => 'uri', 'author_id_prim' => 'prim']],
		'social_verification' => [['actor_id' => 'uri', 'actor_id_prim' => 'prim']],
		'social_watch' => [['actor_id_prim' => 'prim']],
	];

	/**
	 * Tables that name an account and are meant to keep the row when it is
	 * deleted, and why.
	 *
	 * @var array<string, string>
	 */
	private const KEPT = [
		'social_actor' => 'the actor row is the tombstone: marked deleted and kept for the retention hour, so the handle cannot be taken over and a Delete can still be signed',
		'social_atproto_identity' => 'the DID is tombstoned rather than deleted, so the PLC directory and relays learn that it is gone',
		'social_client' => 'an app registration belongs to the instance; the account\'s authorizations are in social_client_auth',
		'social_ext_invite' => '`creator` is the administrator who issued the invite, an audit record of the instance',
		'social_instance' => '`account_prim` is a remote server\'s contact account, cached from its instance document',
		'social_media_block' => '`moderator` records who blocked a file, the moderation audit trail',
		'social_moderation' => 'the moderation level and its comment are the moderation record: they keep an account suspended or silenced if it comes back',
		'social_relay' => '`actor_id` is the relay\'s actor, not an account of this instance',
		'social_strike' => 'strikes are the moderation record of what the account did, kept like the moderation level',
		'social_team' => '`actor_id` is a team\'s own actor, which belongs to a Nextcloud group and goes with the group',
		'social_trend_review' => '`moderator` records who reviewed a trend, the moderation audit trail',
	];

	/**
	 * Tables, or references in a table, that the cascade should empty for a
	 * deleted account and does not yet. Each is a GDPR gap with a fix pending
	 * and is not seeded above; moving the reference to CASCADED is how the fix
	 * is proven.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_GAPS = [
	];

	private IDBConnection $connection;
	private IUserManager $userManager;
	private string $prefix;
	private string $run;

	/** @var array<string, array{uid: string, uri: string}> */
	private array $accounts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->connection = Server::get(IDBConnection::class);
		$this->userManager = Server::get(IUserManager::class);
		$this->prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		// a deleted handle stays reserved for the retention hour, so each run
		// has handles of its own
		$this->run = bin2hex(random_bytes(3));
	}

	protected function tearDown(): void {
		foreach ($this->accounts as $handle => $account) {
			$this->userManager->get($account['uid'])?->delete();
			Server::get(ActorsRequest::class)->delete($handle);
			foreach (self::CASCADED as $table => $references) {
				foreach ($references as $reference) {
					RowSeeder::delete($table, self::naming($reference, $account));
				}
			}
		}
		parent::tearDown();
	}

	/** @return array{uid: string, uri: string} */
	private function account(string $name): array {
		$handle = 'cascade' . $name . $this->run;
		$this->userManager->createUser($handle, bin2hex(random_bytes(16)) . 'Aa1!');
		Server::get(AccountService::class)->createActor($handle, $handle);
		$actor = Server::get(ActorsRequest::class)->getFromUsername($handle);

		return $this->accounts[$handle] = ['uid' => $handle, 'uri' => $actor->getId()];
	}

	/** @param array{uid: string, uri: string} $account */
	private static function valueOf(string $kind, array $account): string {
		return match ($kind) {
			'uri' => $account['uri'],
			'prim' => md5($account['uri']),
			'uid' => $account['uid'],
		};
	}

	/**
	 * One row, with `$reference` naming `$account`, every other reference
	 * naming `$other`, and every other column left to RowSeeder. Nothing is
	 * added where creating the account already wrote a row naming it there
	 * (its cached actor, its loopback follow).
	 *
	 * @param array<string, string> $reference
	 * @param array{uid: string, uri: string} $account
	 * @param array{uid: string, uri: string} $other
	 */
	private function seed(string $table, array $reference, array $account, array $other): void {
		if ($this->countMatching($table, $reference, $account) > 0) {
			return;
		}

		$named = [];
		foreach (self::CASCADED[$table] as $each) {
			foreach ($each as $column => $kind) {
				$named[$column] = self::valueOf($kind, ($each === $reference) ? $account : $other);
			}
		}

		RowSeeder::insert($table, $named);
	}

	/**
	 * The reference's columns, naming the account.
	 *
	 * @param array<string, string> $reference
	 * @param array{uid: string, uri: string} $account
	 * @return array<string, string>
	 */
	private static function naming(array $reference, array $account): array {
		return array_map(static fn (string $kind): string => self::valueOf($kind, $account), $reference);
	}

	/**
	 * @param array<string, string> $reference
	 * @param array{uid: string, uri: string} $account
	 */
	private function countMatching(string $table, array $reference, array $account): int {
		return RowSeeder::count($table, self::naming($reference, $account));
	}

	public function testDeletingAUserLeavesNoRowThatNamesTheirAccountAndEveryoneElsesRowsAlone(): void {
		$alice = $this->account('alice');
		$bob = $this->account('bob');
		// a third party for the references a row about alice makes to someone else
		$carol = ['uid' => 'cascadecarol' . $this->run, 'uri' => 'https://remote.example/users/carol' . $this->run];

		$before = [];
		foreach (self::CASCADED as $table => $references) {
			foreach ($references as $reference) {
				$this->seed($table, $reference, $alice, $carol);
			}
			// bob's row names bob in every reference: nothing about alice's
			// deletion may touch it
			$this->seed($table, $references[0], $bob, $bob);
			$before[$table] = $this->countMatching($table, array_merge(...$references), $bob);
		}

		$this->userManager->get($alice['uid'])->delete();

		$left = [];
		$lost = [];
		foreach (self::CASCADED as $table => $references) {
			foreach ($references as $reference) {
				$count = $this->countMatching($table, $reference, $alice);
				if ($count > 0) {
					$left[] = $table . ' (' . implode(', ', array_keys($reference)) . '): ' . $count;
				}
			}
			if ($this->countMatching($table, array_merge(...$references), $bob) !== $before[$table]) {
				$lost[] = $table;
			}
		}

		$this->assertSame([], $left, 'rows naming a deleted account outlived it');
		$this->assertSame([], $lost, 'deleting one account took rows of another');
	}

	public function testTheDeletedAccountIsATombstoneThatNoLongerSignsIn(): void {
		$alice = $this->account('alice');

		$this->userManager->get($alice['uid'])->delete();

		$actor = Server::get(ActorsRequest::class)->getFromId($alice['uri']);
		$this->assertGreaterThan(0, $actor->getDeleted(), 'kept as a tombstone, marked deleted');
		$this->assertNull($this->userManager->get($alice['uid']));
	}

	/**
	 * Every table whose columns look like a reference to an account is either
	 * cascaded (and so seeded above) or listed with the reason it is not.
	 */
	public function testEveryTableThatNamesAnAccountIsCascadedOrExplained(): void {
		$listed = array_values(array_unique(array_merge(
			array_keys(self::CASCADED), array_keys(self::KEPT), array_keys(self::KNOWN_GAPS)
		)));
		$this->assertSame([], array_values(array_intersect(array_keys(self::CASCADED), array_keys(self::KEPT))), 'a table is either cascaded or kept');
		$this->assertSame([], array_values(array_intersect(array_keys(self::KEPT), array_keys(self::KNOWN_GAPS))), 'a table is either kept or a gap');

		$unexplained = [];
		$tables = [];
		foreach ($this->connection->createSchema()->getTables() as $table) {
			$name = substr($table->getName(), strlen($this->prefix));
			if (!str_starts_with($table->getName(), $this->prefix . 'social_')) {
				continue;
			}
			$tables[] = $name;

			$references = array_filter(
				array_map(static fn (Column $column): string => $column->getName(), $table->getColumns()),
				static fn (string $column): bool => preg_match(self::ACCOUNT_REFERENCE, $column) === 1
			);
			if ($references !== [] && !in_array($name, $listed, true)) {
				$unexplained[] = $name . ' (' . implode(', ', $references) . ')';
			}
		}

		$this->assertSame([], $unexplained, 'a table naming an account is neither cascaded nor explained');
		$this->assertSame([], array_values(array_diff($listed, $tables)), 'a listed table no longer exists');

		foreach (self::CASCADED as $table => $references) {
			$columns = array_map(static fn (Column $column): string => $column->getName(), RowSeeder::table($table)->getColumns());
			foreach ($references as $reference) {
				$this->assertSame([], array_values(array_diff(array_keys($reference), $columns)), $table . ' has the columns it is seeded by');
			}
		}
	}
}
