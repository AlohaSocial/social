<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Identity;

use OCP\IConfig;

class HandleMapper {
	public function __construct(
		private readonly IConfig $config
	) {}
	
	public function mapUsernameToHandle(string $username): string {
		$host = $this->getHandleHost();
		$localPart = $this->sanitizeLocalPart($username);
		return $localPart . '.' . $host;
	}
	
	public function sanitizeLocalPart(string $username): string {
		// Lowercase
		$localPart = strtolower($username);
		// Replace dots and underscores with hyphens
		$localPart = str_replace(['.', '_'], '-', $localPart);
		// Collapse multiple hyphens
		$localPart = preg_replace('/-+/', '-', $localPart);
		// Trim hyphens from start/end
		$localPart = trim($localPart, '-');
		// Ensure valid DNS label (max 63 chars)
		$localPart = substr($localPart, 0, 63);
		// Must start and end with alphanumeric
		$localPart = preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $localPart);
		
		if (empty($localPart)) {
			$localPart = 'user';
		}
		
		return $localPart;
	}
	
	public function resolveCollision(string $baseHandle, callable $existsCheck): string {
		$handle = $baseHandle;
		$suffix = 1;
		
		while ($existsCheck($handle)) {
			$suffix++;
			$handle = $baseHandle . $suffix;
		}
		
		return $handle;
	}
	
	public function getHandleHost(): string {
		$socialUrl = $this->config->getSystemValue('social_url', '');
		if (empty($socialUrl)) {
			$socialUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		}
		return parse_url($socialUrl, PHP_URL_HOST) ?? 'localhost';
	}
	
	public function getWellKnownUrl(string $handle): string {
		return 'https://' . $handle . '/.well-known/atproto-did';
	}
	
	public function getDnsTxtRecord(string $handle): string {
		return '_atproto.' . $handle;
	}
}