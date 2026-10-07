<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Repository;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
/** Deterministic AT Protocol MST, including intermediate empty layers. */
class MerkleSearchTree {
	public static function build(array $records): string { return self::exportCar($records)['root']; }
	public static function exportCar(array $records): array {
		ksort($records, SORT_STRING); $entries = [];
		foreach ($records as $key => $record) {
			if (!preg_match('#^[a-zA-Z0-9._~:\-]+/[a-zA-Z0-9._~:\-]+$#D', $key)) { throw new \InvalidArgumentException('Invalid repository path'); }
			$entries[] = ['key' => $key, 'cid' => $record['cid'], 'layer' => self::layer($key)];
		}
		$blocks = []; $layer = $entries === [] ? 0 : max(array_column($entries, 'layer'));
		$root = self::node($entries, $layer, $blocks);
		return ['root' => $root, 'blocks' => $blocks];
	}
	public static function layer(string $key): int {
		$zeros = 0;
		foreach (str_split(hash('sha256', $key, true)) as $char) {
			$byte = ord($char);
			for ($bit = 7; $bit >= 0; $bit--) {
				if (($byte & (1 << $bit)) !== 0) { return intdiv($zeros, 2); }
				$zeros++;
			}
		}
		return intdiv($zeros, 2);
	}
	private static function node(array $items, int $layer, array &$blocks): string {
		$entries = []; $left = null; $pending = []; $previous = '';
		foreach ($items as $item) {
			if ($item['layer'] < $layer) { $pending[] = $item; continue; }
			$child = $pending === [] ? null : new Cid(self::node($pending, $layer - 1, $blocks)); $pending = [];
			if ($entries === []) { $left = $child; } else { $entries[count($entries) - 1]['t'] = $child; }
			$prefix = 0; $key = $item['key'];
			while ($prefix < min(strlen($key), strlen($previous)) && $key[$prefix] === $previous[$prefix]) { $prefix++; }
			$entries[] = ['p' => $prefix, 'k' => new Bytes(substr($key, $prefix)), 'v' => new Cid($item['cid']), 't' => null];
			$previous = $key;
		}
		if ($pending !== []) {
			$child = new Cid(self::node($pending, $layer - 1, $blocks));
			if ($entries === []) { $left = $child; } else { $entries[count($entries) - 1]['t'] = $child; }
		}
		$bytes = DagCbor::encode(['l' => $left, 'e' => $entries]); $cid = Cid::hash($bytes); $blocks[$cid] = $bytes; return $cid;
	}
	public static function base32Encode(string $bytes): string { return Cid::base32($bytes); }
	public static function encodeVarint(int $value): string {
		if ($value < 0) { throw new \InvalidArgumentException('Invalid varint'); }
		$out = '';
		do { $byte = $value & 127; $value >>= 7; $out .= chr($value > 0 ? $byte | 128 : $byte); } while ($value > 0);
		return $out;
	}
	public static function diff(string $oldRootCid, string $newRootCid, array $oldRecords, array $newRecords): array {
		$added = []; $removed = []; $changed = [];
		foreach ($newRecords as $key => $record) {
			if (!isset($oldRecords[$key])) { $added[] = ['path' => $key, 'cid' => $record['cid'], 'action' => 'create']; }
			elseif ($oldRecords[$key]['cid'] !== $record['cid']) { $changed[] = ['path' => $key, 'cid' => $record['cid'], 'prev' => $oldRecords[$key]['cid'], 'action' => 'update']; }
		}
		foreach ($oldRecords as $key => $record) { if (!isset($newRecords[$key])) { $removed[] = ['path' => $key, 'prev' => $record['cid'], 'action' => 'delete']; } }
		return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
	}
}
