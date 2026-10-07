<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCP\IConfig;

class AtprotoDid {
	public function __construct(
		private readonly IConfig $config,
	) {
	}
	public static function operation(string $signingKey, array $rotationKeys, string $handle, string $endpoint, ?string $prev = null): array {
		return ['type' => 'plc_operation', 'verificationMethods' => ['atproto' => $signingKey],
			'rotationKeys' => $rotationKeys, 'alsoKnownAs' => ['at://' . $handle],
			'services' => ['atproto_pds' => ['type' => 'AtprotoPersonalDataServer', 'endpoint' => $endpoint]], 'prev' => $prev];
	}
	public static function signOperation(array $operation, string $privateKey, KeyManager $keys): array {
		unset($operation['sig']);
		$operation['sig'] = rtrim(strtr(base64_encode($keys->sign(DagCbor::encode($operation), $privateKey)), '+/', '-_'), '=');
		return $operation;
	}
	public static function fromSignedGenesis(array $operation): string {
		if (($operation['type'] ?? '') !== 'plc_operation' || ($operation['prev'] ?? null) !== null || empty($operation['sig'])) {
			throw new \InvalidArgumentException('Expected signed PLC genesis');
		}
		return 'did:plc:' . substr(Cid::base32(hash('sha256', DagCbor::encode($operation), true)), 0, 24);
	}
	public static function parse(string $did): ?array {
		return preg_match('/^did:plc:([a-z2-7]{24})$/D', $did, $matches) ? ['method' => 'plc', 'suffix' => $matches[1]] : null;
	}
	public function getPlcDirectory(): string {
		return $this->config->getAppValue('social', 'atproto_plc_directory', 'https://plc.directory');
	}
}
