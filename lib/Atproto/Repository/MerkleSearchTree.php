<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Repository;

use CBOR\Encoder;

class MstNode {
	public const FANOUT = 4;
	public const HASH_BITS_PER_LEVEL = 2;
	
	public function __construct(
		public readonly ?string $cid = null,
		public readonly array $children = [],
		public readonly array $entries = [], // [key => [cid, value]]
		public readonly int $level = 0
	) {}
	
	public function isLeaf(): bool {
		return $this->level === 0;
	}
	
	public function getLayer(): int {
		return $this->level;
	}
}

class MerkleSearchTree {
	private const FANOUT = 4;
	private const HASH_BITS_PER_LEVEL = 2;
	
	/**
	 * Build MST from records
	 * @param array<string, array{cid: string, value: array}> $records Key is collection/rkey
	 * @return string Root CID
	 */
	public static function build(array $records): string {
		if (empty($records)) {
			return self::emptyRootCid();
		}
		
		// Sort records by key
		ksort($records);
		
		// Build leaf layer (level 0)
		$leaves = [];
		foreach ($records as $key => $record) {
			$hash = hash('sha256', $key, true);
			$leadingZeros = self::countLeadingZeroBits($hash);
			$level = min($leadingZeros / self::HASH_BITS_PER_LEVEL, 54); // Max depth
			
			$leaves[] = [
				'key' => $key,
				'hash' => $hash,
				'level' => (int)$level,
				'cid' => $record['cid'],
				'value' => $record['value']
			];
		}
		
		// Group by level and build tree bottom-up
		return self::buildTree($leaves);
	}
	
	/**
	 * @param array<array{key: string, hash: string, level: int, cid: string, value: array}> $nodes
	 */
	private static function buildTree(array $nodes): string {
		if (count($nodes) === 1) {
			return self::nodeCid($nodes[0]);
		}
		
		// Group nodes by their prefix (first N bits of hash)
		$groups = [];
		foreach ($nodes as $node) {
			$prefixBits = $node['level'] * self::HASH_BITS_PER_LEVEL;
			$prefix = substr($node['hash'], 0, (int)ceil($prefixBits / 8));
			$groups[$prefix][] = $node;
		}
		
		$parentNodes = [];
		foreach ($groups as $groupNodes) {
			if (count($groupNodes) === 1 && $groupNodes[0]['level'] > 0) {
				$parentNodes[] = $groupNodes[0];
			} else {
				// Create parent node
				$parentNodes[] = self::createParentNode($groupNodes);
			}
		}
		
		return self::buildTree($parentNodes);
	}
	
	/**
	 * @param array<array{key: string, hash: string, level: int, cid: string, value: array}> $childNodes
	 */
	private static function createParentNode(array $childNodes): array {
		// Sort by hash
		usort($childNodes, fn($a, $b) => strcmp($a['hash'], $b['hash']));
		
		$parentLevel = max(array_column($childNodes, 'level')) + 1;
		$parentHash = hash('sha256', implode('', array_column($childNodes, 'hash')), true);
		
		$childrenCids = [];
		$childrenKeys = [];
		foreach ($childNodes as $child) {
			$childrenCids[] = $child['cid'];
			$childrenKeys[] = $child['key'];
		}
		
		$parentValue = [
			'children' => $childrenCids,
			'keys' => $childrenKeys
		];
		
		$parentCid = self::cidFromValue($parentValue);
		
		return [
			'key' => $childNodes[0]['key'],
			'hash' => $parentHash,
			'level' => $parentLevel,
			'cid' => $parentCid,
			'value' => $parentValue
		];
	}
	
	private static function nodeCid(array $node): string {
		if ($node['level'] === 0) {
			return $node['cid'];
		}
		return self::cidFromValue($node['value']);
	}
	
	private static function cidFromValue(array $value): string {
		$encoded = (new Encoder())->encode($value);
		$hash = hash('sha256', $encoded, true);
		// CIDv1 dag-cbor: multicodec 0x71 (dag-cbor) + multihash 0x12 (sha2-256) + length 0x20
		$multicodec = hex2bin('0171'); // varint encoding of 0x71
		$multihash = hex2bin('1220') . $hash; // sha2-256, 32 bytes
		$cidBytes = $multicodec . $multihash;
		return 'b' . self::base32Encode($cidBytes); // base32 encoding
	}
	
	private static function countLeadingZeroBits(string $hash): int {
		$bits = 0;
		for ($i = 0; $i < strlen($hash); $i++) {
			$byte = ord($hash[$i]);
			if ($byte === 0) {
				$bits += 8;
			} else {
				$bits += (int)log(($byte & -$byte), 2); // Count trailing zeros in byte
				break;
			}
		}
		return $bits;
	}
	
	private static function emptyRootCid(): string {
		// Empty tree CID
		$emptyMap = [];
		$encoded = (new Encoder())->encode($emptyMap);
		$hash = hash('sha256', $encoded, true);
		$multicodec = hex2bin('0171');
		$multihash = hex2bin('1220') . $hash;
		$cidBytes = $multicodec . $multihash;
		return 'b' . self::base32Encode($cidBytes);
	}
	
	public static function base32Encode(string $data): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$bits = '';
		foreach (str_split($data) as $char) {
			$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
		}

		$bits = str_pad($bits, (int)ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);
		$result = '';
		for ($i = 0; $i < strlen($bits); $i += 5) {
			$result .= $alphabet[bindec(substr($bits, $i, 5))];
		}

		return $result;
	}

	public static function encodeVarint(int $value): string {
		if ($value < 0) {
			throw new \InvalidArgumentException('Varints must be non-negative');
		}

		$encoded = '';
		do {
			$byte = $value & 0x7f;
			$value >>= 7;
			$encoded .= chr($value > 0 ? ($byte | 0x80) : $byte);
		} while ($value > 0);

		return $encoded;
	}

	/**
	 * Compute diff between two trees
	 * @return array{added: array, removed: array, changed: array}
	 */
	public static function diff(string $oldRootCid, string $newRootCid, array $oldRecords, array $newRecords): array {
		// Simplified diff - in practice would traverse both trees
		$added = [];
		$removed = [];
		$changed = [];
		
		foreach ($newRecords as $key => $record) {
			if (!isset($oldRecords[$key])) {
				$added[] = ['path' => $key, 'cid' => $record['cid'], 'action' => 'create'];
			} elseif ($oldRecords[$key]['cid'] !== $record['cid']) {
				$changed[] = ['path' => $key, 'cid' => $record['cid'], 'action' => 'update'];
			}
		}
		
		foreach ($oldRecords as $key => $record) {
			if (!isset($newRecords[$key])) {
				$removed[] = ['path' => $key, 'action' => 'delete'];
			}
		}
		
		return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
	}
}
