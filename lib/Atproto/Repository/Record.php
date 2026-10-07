<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Repository;
use OCA\Social\Atproto\Protocol\{Cid, DagCbor};
final class Record {
	public function __construct(public readonly string $did, public readonly string $collection, public readonly string $rkey,
		public readonly string $cid, public readonly string $bytes, public readonly ?string $localId = null, public readonly string $createdAt = '') {}
	public static function create(string $did, string $collection, string $rkey, array $value, int|string|null $localId = null): self {
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9-]*){2,}$/D', $collection)
			|| !preg_match('/^[a-zA-Z0-9._~:-]{1,512}$/D', $rkey) || in_array($rkey, ['.', '..'], true)) { throw new \InvalidArgumentException('Invalid record path'); }
		if (($value['$type'] ?? null) !== $collection) { throw new \InvalidArgumentException('Record type must match collection'); }
		$bytes = DagCbor::encode($value);
		if (strlen($bytes) > 1024 * 1024) { throw new \InvalidArgumentException('Record exceeds size limit'); }
		return new self($did, $collection, $rkey, Cid::hash($bytes), $bytes, $localId === null ? null : (string)$localId, gmdate('Y-m-d H:i:s'));
	}
	public function getValue(): array { return DagCbor::jsonValue(DagCbor::decode($this->bytes)); }
	public static function decodeCbor(string $bytes): array { return DagCbor::jsonValue(DagCbor::decode($bytes)); }
	public function getAtUri(): string { return "at://{$this->did}/{$this->collection}/{$this->rkey}"; }
}
