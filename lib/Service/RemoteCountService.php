<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateInterval;
use DateTime;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Asks a post's own server how many likes, boosts and replies it has now.
 *
 * The counts a stored post carries were read off its document the once, when
 * it arrived, and every server that publishes them publishes them as they were
 * at that moment: a status with five likes said five for as long as the row
 * lasted, and one answered here had a reply count that never moved. Nothing
 * federates a count — a `Like` travels to the origin and the origin totals it
 * on its own side — so the only way to know a number now is to ask again.
 *
 * That is what this does, on the cron's schedule and in its budget: the posts
 * whose last answer has fallen due, a bounded batch of them, each answered
 * from the document its own server serves at the post's `id`. A post is stamped
 * whether or not the answer arrived, so a host that is gone is asked once per
 * interval rather than on every pass, and whatever was already stored stands
 * where the answer cannot be trusted.
 *
 * The origin's total and this instance's own interactions are kept apart the
 * way `LikeInterface` and `AnnounceInterface` keep them: what the origin said
 * minus what is counted here, so that adding this instance's likes back lands
 * on the origin's number and an unlike moves it down rather than leaving it
 * where it was.
 */
class RemoteCountService {
	/**
	 * How long one post's counts stand before they are asked again, in seconds.
	 *
	 * An hour would be tighter but does not divide the pass well: at twelve
	 * minutes a pass, two hours is ten passes for a post to fall due in, which
	 * is where the batch below stops being the limit on how much gets asked.
	 */
	public const TTL = 7200;

	/** Posts asked per pass, when the caller sets no deadline of its own. */
	public const BATCH = 50;

	/** Documents asked for in one round of requests. */
	public const PARALLEL = 20;

	public function __construct(
		private StreamRequest $streamRequest,
		private ActionsRequest $actionsRequest,
		private CurlService $curlService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param bool $force ask every remote post now, whatever was last said
	 * @param int $limit how many posts to ask; 0 is every one that is due
	 * @param ?int $deadline wall clock past which this run stops asking
	 *
	 * @return array{asked: int, answered: int} the posts whose schedule was
	 *                                          written, and of those, the ones that answered with a document
	 *                                          this instance could read a count out of
	 */
	public function refresh(bool $force = false, int $limit = self::BATCH, ?int $deadline = null): array {
		$due = new DateTime('now');
		if (!$force) {
			$due->sub(new DateInterval('PT' . intdiv(self::TTL, 60) . 'M'));
		}

		$posts = $this->streamRequest->getRemoteStreamsDueForCounts($due, $limit);

		$asked = 0;
		$answered = 0;
		foreach (array_chunk($posts, self::PARALLEL) as $chunk) {
			if ($deadline !== null && $this->time->getTime() >= $deadline) {
				// the rest are still due and the next pass starts with them
				break;
			}

			$answers = $this->curlService->retrieveObjectsMany(
				array_map(static fn (Stream $post): string => $post->getId(), $chunk)
			);

			foreach ($chunk as $post) {
				$answered += $this->apply($post, $answers[$post->getId()] ?? null) ? 1 : 0;
				$asked++;
			}
		}

		if ($asked > 0 && $answered === 0) {
			// nothing was read at all, which is never one host being down: a
			// fetch that has stopped working, so it should say so rather than
			// leave every count quietly where it was
			$this->logger->debug(
				'[RemoteCountService] none of ' . $asked . ' posts answered with a count'
			);
		}

		return ['asked' => $asked, 'answered' => $answered];
	}

	/**
	 * One post: what its server said, if it said anything usable.
	 *
	 * @param ?array<string, mixed> $data the document, or null for no answer
	 *
	 * @return bool whether the document named this post and was read
	 */
	private function apply(Stream $asked, ?array $data): bool {
		$when = new DateTime('now');

		if (!is_array($data) || ($data['id'] ?? '') !== $asked->getId()) {
			// No answer, or one naming some other document: a redirect to a
			// proxy page, a server that has moved the post, a host that is not
			// answering. The stored counts stand, and the stamp keeps the host
			// from being asked again until the interval says otherwise.
			$this->streamRequest->markCountsRefreshed($asked->getId(), $when);

			return false;
		}

		try {
			// read again rather than trusted from the batch: the details the
			// selection saw may already be a post behind, and a like that
			// arrived while the batch was in flight is not this to lose
			$post = $this->streamRequest->getStreamById($asked->getId());
		} catch (StreamNotFoundException $e) {
			return false;
		}

		$localLikes = $this->actionsRequest->countActions($post->getId(), Like::TYPE);
		$localBoosts = $this->actionsRequest->countActions($post->getId(), Announce::TYPE);
		$localReplies = $this->streamRequest->countRepliesTo($post->getId());

		$this->applyCount($post, Details::LIKES, Details::REMOTE_LIKES, Stream::statedCount($data, 'likes'), $localLikes);
		$this->applyCount($post, Details::BOOSTS, Details::REMOTE_BOOSTS, Stream::statedCount($data, 'shares'), $localBoosts);
		$this->applyCount($post, Details::REPLIES, Details::REMOTE_REPLIES, Stream::statedCount($data, 'replies'), $localReplies);

		$this->streamRequest->updateDetails($post, $when);

		return true;
	}

	/**
	 * One of the three counts, written as its two halves.
	 *
	 * @param ?int $stated what the origin said in total, when it said anything
	 * @param int $local what this instance holds of it
	 *
	 * The remote half is the origin's total less what is counted here, because
	 * the two interfaces that move a count on a local action add this
	 * instance's own back on: landing on `origin - local + local` keeps the
	 * display on the origin's number and lets an unlike take one off it. Where
	 * the origin publishes no total, the half already stored is kept — the
	 * number is only as old as the last document that did say something — and
	 * for a post that has never had one, the total on the row less what is
	 * held here, which is how a reply count written before the origin's half
	 * was stored survives being read again.
	 */
	private function applyCount(Stream $post, string $total, string $remote, ?int $stated, int $local): void {
		$details = $post->getDetailsAll();

		if ($stated !== null) {
			$origin = max($stated - $local, 0);
		} elseif (array_key_exists($remote, $details)) {
			$origin = $post->getDetailInt($remote);
		} else {
			$origin = max($post->getDetailInt($total) - $local, 0);
		}

		$post->setDetailInt($remote, $origin);
		$post->setDetailInt($total, $origin + $local);
	}
}
