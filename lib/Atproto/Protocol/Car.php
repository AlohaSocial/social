<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Protocol;
use OCA\Social\Atproto\Repository\MerkleSearchTree;
final class Car {
	public static function encode(string $root, array $blocks): string {
		$header = DagCbor::encode(['roots' => [new Cid($root)], 'version' => 1]);
		$out = MerkleSearchTree::encodeVarint(strlen($header)) . $header;
		foreach ($blocks as $cid => $bytes) {
			$cidBytes = Cid::decode($cid);
			if (Cid::hash($bytes, ord($cidBytes[1])) !== $cid) { throw new \InvalidArgumentException('CAR block hash mismatch'); }
			$out .= MerkleSearchTree::encodeVarint(strlen($cidBytes) + strlen($bytes)) . $cidBytes . $bytes;
		}
		return $out;
	}
}
