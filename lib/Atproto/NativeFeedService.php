<?php
declare(strict_types=1);
namespace OCA\Social\Atproto;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Db\{CacheActorsRequest, StreamRequest};
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Exceptions\StreamNotFoundException;
/** Import AT Protocol data into the same actor cache and stream used by the common UI. */
class NativeFeedService {
	public function __construct(private readonly AppViewClient $appview, private readonly IdentityService $identities, private readonly CacheActorsRequest $actors, private readonly StreamRequest $streams, private readonly \OCP\IDBConnection $db) {}
	public function discover(string $query): void {
		if (!$this->identities->isEnabled()) { return; }
		$data = $this->appview->get('app.bsky.actor.searchActors', ['q' => mb_substr($query, 0, 100), 'limit' => 10]);
		foreach ($data['actors'] ?? [] as $profile) { $this->cacheProfile($profile); }
	}
	public static function actorUrl(string $did): string {
		if (!preg_match('/^did:(?:plc:[a-z2-7]{24}|web:[a-zA-Z0-9.:%_-]+)$/D', $did)) { throw new \InvalidArgumentException('Invalid actor DID'); }
		return 'https://bsky.app/profile/' . $did;
	}
	public static function postUrl(string $uri): string {
		if (!preg_match('#^at://(did:(?:plc:[a-z2-7]{24}|web:[a-zA-Z0-9.:%_-]+))/app\.bsky\.feed\.post/([a-zA-Z0-9._~:-]{1,255})$#D', $uri, $parts)) { throw new \InvalidArgumentException('Invalid post URI'); }
		return self::actorUrl($parts[1]) . '/post/' . $parts[2];
	}
	public function cacheProfile(array $profile): Person {
		$owned = $this->identities->getIdentityByDid($profile['did']);
		if ($owned !== null) { return $this->actors->getFromId($owned['actor_id']); }
		$id = self::actorUrl($profile['did']);
		$person = new Person(); $person->setId($id)->setUrl($id)->setLocal(false);
		$person->setPreferredUsername($profile['handle'])->setAccount($profile['handle'] . '@bsky.app')
			->setName($profile['displayName'] ?? $profile['handle'])->setSummary(htmlspecialchars($profile['description'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		$person->setDetailArray(Details::ATPROTO, ['did' => $profile['did'], 'handle' => $profile['handle']]);
		$person->setSource(json_encode($profile, JSON_THROW_ON_ERROR));
		$this->actors->save($person); $this->actors->update($person);
		return $this->actors->getFromId($id);
	}
	public static function note(array $post): Note {
		Cid::decode($post['cid']); $id = self::postUrl($post['uri']);
		$record = $post['record'];
		if (Cid::hash(\OCA\Social\Atproto\Protocol\DagCbor::encode($record)) !== $post['cid']) { throw new \InvalidArgumentException('Post content does not match its CID'); }
		if (($record['$type'] ?? '') !== 'app.bsky.feed.post' || !is_string($record['text'] ?? null) || strlen($record['text']) > 3000) { throw new \InvalidArgumentException('Invalid post record'); }
		if (!str_starts_with($post['uri'], 'at://' . $post['author']['did'] . '/')) { throw new \InvalidArgumentException('Post author does not own URI'); }
		$note = new Note(); $note->setId($id)->setUrl($id)->setLocal(false);
		$note->setAttributedTo(self::actorUrl($post['author']['did'])); $note->setTo('https://www.w3.org/ns/activitystreams#Public')->setVisibility('public');
		$note->setContent(nl2br(htmlspecialchars($record['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
		$time = strtotime($record['createdAt'] ?? ''); if ($time === false) { throw new \InvalidArgumentException('Invalid post timestamp'); }
		$note->setPublished(gmdate('c', $time))->setPublishedTime($time);
		if (isset($record['reply']['parent']['uri'])) { $note->setInReplyTo(self::postUrl($record['reply']['parent']['uri'])); }
		$note->setDetailArray(Details::ATPROTO, ['uri' => $post['uri'], 'cid' => $post['cid'], 'indexedAt' => $post['indexedAt'] ?? '', 'reply' => $record['reply'] ?? null]);
		if (($post['labels'] ?? []) !== [] || ($record['labels']['values'] ?? []) !== []) { $note->setSensitive(true); }
		return $note;
	}
	public function importPost(array $post): bool {
		if (!$this->identities->isEnabled()) { throw new \RuntimeException('AT Protocol is disabled'); }
		$note = self::note($post);
		// Owned repositories are represented by the original Social post, including after
		// withdrawal. Never re-import an AppView's stale public copy as a remote note.
		if ($this->identities->getIdentityByDid($post['author']['did']) !== null) { return false; }
		if ($note->getInReplyTo() !== '') {
			$parent = $this->localPost($post['record']['reply']['parent']['uri'] ?? '');
			if ($parent !== null) { $note->setInReplyTo($parent->getId()); }
		}
		$this->cacheProfile($post['author']);
		try {
			$existing = $this->streams->getStreamById($note->getId());
			if (($existing->getDetails(Details::ATPROTO)['cid'] ?? '') === $post['cid']) { return false; }
			$this->streams->update($note); $this->streams->updateDetails($note); return false;
		} catch (StreamNotFoundException) { $this->streams->save($note); return true; }
	}
	public static function isPostAddress(string $uri): bool { return str_starts_with($uri, 'at://') || str_starts_with($uri, 'https://bsky.app/profile/'); }
	public function resolvePost(string $address, bool $asViewer = false): ?\OCA\Social\Model\ActivityPub\Stream {
		if (!$this->identities->isEnabled()) { return null; }
		if (str_starts_with($address, 'https://bsky.app/profile/')) {
			if (!preg_match('#^https://bsky\.app/profile/([^/?\#]+)/post/([a-zA-Z0-9._~:-]{1,255})$#D', $address, $parts)) { return null; }
			$address = 'at://' . rawurldecode($parts[1]) . '/app.bsky.feed.post/' . $parts[2];
		}
		$local = $this->localPost($address); if ($local !== null) { return $local; }
		if (preg_match('#^at://([^/]+)/#', $address, $owner) && $this->identities->getIdentityByDid($owner[1]) !== null) { return null; }
		$data = $this->appview->get('app.bsky.feed.getPostThread', ['uri' => $address, 'depth' => 3, 'parentHeight' => 6]);
		$pending = [$data['thread'] ?? []]; $seen = []; $main = null;
		while ($pending !== [] && count($seen) < 50) {
			$thread = array_shift($pending); $post = $thread['post'] ?? null;
			if ($post === null || isset($seen[$post['uri']])) { continue; }
			$seen[$post['uri']] = true; $this->importPost($post);
			$main ??= $this->localPost($post['uri'])?->getId() ?? ($this->identities->getIdentityByDid($post['author']['did']) === null ? self::postUrl($post['uri']) : null);
			if (isset($thread['parent'])) { $pending[] = $thread['parent']; }
			foreach ($thread['replies'] ?? [] as $reply) { $pending[] = $reply; }
		}
		return $main === null ? null : $this->streams->getStreamById($main, $asViewer);
	}

	private function localPost(string $uri): ?\OCA\Social\Model\ActivityPub\Stream {
		if (!preg_match('#^at://([^/]+)/app\.bsky\.feed\.post/([^/]+)$#D', $uri, $parts)) { return null; }
		$qb = $this->db->getQueryBuilder(); $qb->select('local_id')->from('social_atproto_record')->where($qb->expr()->eq('did', $qb->createNamedParameter($parts[1])))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.post')))->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($parts[2])));
		$nid = $qb->executeQuery()->fetchOne(); if ($nid === false || $nid === null || $nid === '') { return null; }
		try { $post = $this->streams->getStreamByNid((string)$nid); return $post->getVisibility() === 'public' && $post->addressesPublic() ? $post : null; } catch (\OCA\Social\Exceptions\ItemUnknownException) { return null; }
	}

	public function syncAuthor(string $did): int {
		$data = $this->appview->get('app.bsky.feed.getAuthorFeed', ['actor' => $did, 'filter' => 'posts_with_replies', 'limit' => 50]); $new = 0;
		foreach (array_reverse($data['feed'] ?? []) as $entry) { if (($entry['post']['author']['did'] ?? '') === $did) { $new += (int)$this->importPost($entry['post']); } }
		return $new;
	}
}
