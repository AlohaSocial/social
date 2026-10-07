<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Sync;
use OCA\Social\Atproto\{NativeFeedService, Identity\IdentityService};
use OCA\Social\Db\StreamRequest;
use OCP\{IDBConnection, IConfig};
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use React\Socket\Connector as SocketConnector;
use Ratchet\Client\Connector;
/** Optional acceleration of watched-author polling; Jetstream JSON is not treated as a signed repository. */
class JetstreamListener {
	public function __construct(private readonly IDBConnection $db, private readonly LoggerInterface $logger, private readonly IConfig $config,
		private readonly IdentityService $identities, private readonly NativeFeedService $feed, private readonly StreamRequest $streams) {}
	public function run(bool $once = false, int $maxSeconds = 0): void {
		if (!$this->identities->isEnabled()) { throw new \RuntimeException('AT Protocol is disabled'); }
		$endpoint = $this->config->getAppValue('social', 'atproto_jetstream', '');
		if (parse_url($endpoint, PHP_URL_SCHEME) !== 'wss' || !parse_url($endpoint, PHP_URL_HOST) || parse_url($endpoint, PHP_URL_USER) !== null) { throw new \InvalidArgumentException('Configure a public wss:// Jetstream subscription endpoint'); }
		$loop = Factory::create(); $connector = new Connector($loop, new SocketConnector(['timeout' => 10], $loop)); $connection = null; $stopped = false; $failure = null; $retries = 0;
		$connect = null;
		$connect = function () use (&$connect, $endpoint, $connector, $loop, $once, &$connection, &$stopped, &$failure, &$retries) {
			if ($stopped) { return; }
			$qb = $this->db->getQueryBuilder(); $qb->select('did')->from('social_atproto_watch')->orderBy('id', 'ASC')->setMaxResults(100); $dids = array_column($qb->executeQuery()->fetchAllAssociative(), 'did');
			if ($dids === []) { $stopped = true; $loop->stop(); return; }
			$params = ['wantedCollections=app.bsky.feed.post'];
			foreach ($dids as $did) { $params[] = 'wantedDids=' . rawurlencode($did); }
			$cursor = $this->config->getAppValue('social', 'atproto_jetstream_cursor', '0'); if (ctype_digit($cursor) && (int)$cursor > 0) { $params[] = 'cursor=' . $cursor; }
			$retry = function (\Throwable $e) use ($loop, $once, &$failure, &$stopped, &$connect, &$retries) {
				$failure = $e; $this->logger->warning('AT Protocol Jetstream connection interrupted', ['exception' => $e]);
				if ($once) { $stopped = true; $loop->stop(); return; }
				$loop->addTimer(min(60, 2 ** min(++$retries, 6)), $connect);
			};
			$connector($endpoint . (str_contains($endpoint, '?') ? '&' : '?') . implode('&', $params))->then(
				function ($conn) use ($loop, $once, &$connection, &$stopped, &$failure, &$retries, $retry) {
					$connection = $conn; $retries = 0;
					$conn->on('message', function ($message) use ($conn, $loop, $once, &$stopped, &$failure) {
						try {
							$bytes = (string)$message; if (strlen($bytes) > 1024 * 1024) { throw new \RuntimeException('Jetstream frame too large'); }
							$event = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR); $this->processEvent($event);
							if (isset($event['time_us']) && is_int($event['time_us']) && $event['time_us'] > 0) { $this->config->setAppValue('social', 'atproto_jetstream_cursor', (string)$event['time_us']); }
							if ($once) { $stopped = true; $conn->close(); $loop->stop(); }
						} catch (\Throwable $e) { $failure = $e; $this->logger->warning('AT Protocol Jetstream event could not be processed', ['exception' => $e]); $conn->close(); }
					});
					$conn->on('close', function () use (&$stopped, $retry) { if (!$stopped) { $retry(new \RuntimeException('Jetstream closed')); } });
					$conn->on('error', function (\Throwable $e) use ($conn) { $this->logger->warning('Jetstream transport error', ['exception' => $e]); $conn->close(); });
				}, $retry);
		};
		$connect();
		$loop->addPeriodicTimer(5, function () use ($loop, &$stopped) { if (!$this->identities->isEnabled()) { $stopped = true; $loop->stop(); } });
		$duration = $maxSeconds > 0 ? $maxSeconds : ($once ? 30 : 0);
		if ($duration > 0) { $loop->addTimer($duration, static function () use ($loop, &$stopped) { $stopped = true; $loop->stop(); }); }
		try { $loop->run(); } finally { $stopped = true; if ($connection !== null) { $connection->close(); } }
		if ($once && $failure !== null) { throw $failure; }
	}
	public function processEvent(array $event): void {
		if (!$this->identities->isEnabled() || ($event['kind'] ?? '') !== 'commit' || ($event['commit']['collection'] ?? '') !== 'app.bsky.feed.post') { return; }
		$did = (string)($event['did'] ?? ''); $qb = $this->db->getQueryBuilder(); $qb->select('id')->from('social_atproto_watch')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		if ($qb->executeQuery()->fetchOne() === false) { return; }
		$uri = 'at://' . $did . '/app.bsky.feed.post/' . ($event['commit']['rkey'] ?? ''); $id = NativeFeedService::postUrl($uri);
		if (($event['commit']['operation'] ?? '') === 'delete') { $this->streams->deleteById($id); }
		elseif (in_array($event['commit']['operation'] ?? '', ['create', 'update'], true)) { $this->feed->resolvePost($uri); }
	}
}
