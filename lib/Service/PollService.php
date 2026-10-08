<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Traits\TStringTools;
use Psr\Log\LoggerInterface;

/**
 * Voting on polls, whoever holds the one being voted on.
 *
 * A vote on a *remote* poll is a bare Note whose `name` is the chosen option,
 * addressed only to the poll's author — exactly what Mastodon expects;
 * authoritative counts arrive later as Update{Question} from the origin server
 * and refresh the stored source.
 *
 * A vote on a *local* poll has no origin to ask: this instance is the origin,
 * so the vote is counted here — through the same path an incoming vote takes —
 * and the new counts go out to the author's followers as Update{Question}.
 *
 * Either way the chosen indices are remembered per viewer in the stream action,
 * so the poll renders as voted.
 */
class PollService {
	use TStringTools;

	public function __construct(
		private StreamRequest $streamRequest,
		private ActionsRequest $actionsRequest,
		private AccountService $accountService,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private SignatureService $signatureService,
		private StreamActionService $streamActionService,
		private StreamActionsRequest $streamActionsRequest,
		private NotificationService $notificationService,
		private ConfigService $configService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws StreamNotFoundException when the id is not a known poll
	 */
	/**
	 * How far back a first sweep looks for closed polls.
	 *
	 * A day: long enough that a cron that missed a few runs still announces
	 * what closed while it was down, short enough that switching the feature
	 * on does not tell everybody about a poll from last year.
	 */
	public const SWEEP_FLOOR = 86400;

	public function getPoll(int|string $nid, ?Person $viewer = null): Question {
		$stream = $this->streamRequest->getStreamByNid($nid);
		if (!$stream instanceof Question) {
			throw new StreamNotFoundException('not a poll');
		}

		if ($viewer !== null && !$stream->hasAction()) {
			// single-stream fetches are viewer-less; attach the viewer's action
			// so voted/own_votes render
			try {
				$stream->setAction(
					$this->streamActionsRequest->getAction($viewer->getId(), $stream->getId())
				);
			} catch (\Exception $e) {
			}
		}

		return $stream;
	}

	/**
	 * @param int[] $choices option indices
	 *
	 * @throws StreamNotFoundException
	 * @throws InvalidActionException
	 */
	public function vote(Person $viewer, int|string $nid, array $choices): Question {
		$poll = $this->getPoll($nid, $viewer);

		if ($poll->isExpired()) {
			throw new InvalidActionException('this poll has ended');
		}

		$choices = array_values(array_unique(array_map('intval', $choices)));
		$options = $poll->getOptions();
		foreach ($choices as $choice) {
			if ($choice < 0 || $choice >= count($options)) {
				throw new InvalidActionException('invalid poll option');
			}
		}
		if ($choices === [] || (!$poll->isMultiple() && count($choices) > 1)) {
			throw new InvalidActionException('this poll accepts a single choice');
		}

		$stored = $poll->hasAction()
			? ($poll->getAction()->getValues()[StreamAction::POLL_VOTES] ?? '')
			: '';
		if ($stored !== '' && $stored !== '[]') {
			throw new InvalidActionException('you already voted on this poll');
		}

		if ($poll->isLocal()) {
			$this->countVotes($viewer->getId(), $poll, $choices);
		} else {
			$author = $this->cacheActorService->getFromId($poll->getAttributedTo());
			foreach ($choices as $choice) {
				$this->federateVote($viewer, $poll, $options[$choice]['title'], $author);
			}
		}

		$this->streamActionService->setAction(
			$viewer->getId(), $poll->getId(), StreamAction::POLL_VOTES, json_encode($choices)
		);

		// render the fresh vote without waiting for the origin's Update
		$action = new StreamAction($viewer->getId(), $poll->getId());
		$action->updateValue(StreamAction::POLL_VOTES, json_encode($choices));
		$poll->setAction($action);

		return $poll;
	}

	/**
	 * An incoming Note that is a vote on one of our polls: counted here, never
	 * stored as a timeline item. Returns whether the note was consumed.
	 *
	 * A vote is a bare Note whose name is an option of a local poll it replies
	 * to. Votes on expired polls, a second vote for an option already voted for
	 * and any further vote in a single-choice poll are swallowed without
	 * counting.
	 */
	public function handleIncomingVote(Note $note): bool {
		if ($note->getName() === '' || $note->getInReplyTo() === '' || $note->isLocal()) {
			return false;
		}

		try {
			$target = $this->streamRequest->getStreamById($note->getInReplyTo());
		} catch (StreamNotFoundException $e) {
			return false;
		}
		if (!$target instanceof Question || !$target->isLocal()) {
			return false;
		}

		$option = $target->findOption($note->getName());
		if ($option === null || $target->isExpired()) {
			return true; // a vote, but not a countable one — consume silently
		}

		$voter = $note->getAttributedTo();
		// a single-choice poll gives one vote per account, not one per option
		if (!$target->isMultiple() && $this->hasAnyVote($voter, $target)) {
			return true;
		}

		$this->countVotes($voter, $target, [$option]);

		return true;
	}

	/**
	 * Counts votes on a poll this instance holds, snapshots the new counts into
	 * the stored source and tells the author's followers about them. An option
	 * this voter already voted for is skipped, so a redelivered vote and a
	 * retried request count once.
	 *
	 * @param int[] $options option indices
	 */
	private function countVotes(string $voter, Question $poll, array $options): void {
		$counted = false;
		foreach ($options as $option) {
			if ($this->alreadyVoted($voter, $poll->getId(), $option)) {
				continue;
			}

			$newVoter = !$this->hasAnyVote($voter, $poll);
			$this->rememberVote($voter, $poll->getId(), $option);
			$poll->countVote($option, $newVoter);
			$counted = true;
		}

		if (!$counted) {
			return;
		}

		$poll->setSource(json_encode($poll, JSON_UNESCAPED_SLASHES));
		$this->streamRequest->update($poll);

		// tell the followers the new counts
		try {
			$author = $this->accountService->getFromId($poll->getAttributedTo());
			$poll->addInstancePath(new InstancePath(
				$author->getId(), InstancePath::TYPE_FOLLOWERS, InstancePath::PRIORITY_LOW
			));
			$this->activityService->updateActivity($author, $poll);
		} catch (\Exception $e) {
			$this->logger->warning('failed to federate poll counts', ['exception' => $e]);
		}
	}

	private function voteId(string $voter, string $pollId, int $option): string {
		return $pollId . '#vote-' . $option . '/' . md5($voter);
	}

	private function alreadyVoted(string $voter, string $pollId, int $option): bool {
		try {
			$this->actionsRequest->getAction($voter, $pollId . ActionsRequest::VOTE_OPTION . $option, 'Vote');

			return true;
		} catch (\Exception $e) {
			return false;
		}
	}

	private function hasAnyVote(string $voter, Question $poll): bool {
		foreach (array_keys($poll->getOptions()) as $index) {
			if ($this->alreadyVoted($voter, $poll->getId(), $index)) {
				return true;
			}
		}

		return false;
	}

	private function rememberVote(string $voter, string $pollId, int $option): void {
		$vote = new \OCA\Social\Model\ActivityPub\Object\Like();
		$vote->setType('Vote');
		$vote->setId($this->voteId($voter, $pollId, $option));
		$vote->setActorId($voter);
		$vote->setObjectId($pollId . ActionsRequest::VOTE_OPTION . $option);
		$this->actionsRequest->save($vote);
	}

	private function federateVote(Person $viewer, Question $poll, string $option, Person $author): void {
		/** @var Note $note */
		$note = AP::instance()->getItemFromType(Note::TYPE);
		$note->setId($viewer->getId() . '/vote/' . $this->uuid(8));
		$note->setName($option);
		$note->setInReplyTo($poll->getId());
		$note->setAttributedTo($viewer->getId());
		$note->setTo($author->getId());
		$note->setPublished(date('c'));
		$note->setLocal(true);

		/** @var Create $create */
		$create = AP::instance()->getItemFromType(Create::TYPE);
		$create->generateUniqueIdFromActor($viewer->getId(), 'vote');
		$create->setActor($viewer);
		$create->setObject($note);
		$create->setTo($author->getId());
		$create->addInstancePath(new InstancePath(
			$author->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
		));

		$this->signatureService->signObject($viewer, $create);
		$this->activityService->request($create);
	}

	/**
	 * The status export (with poll entity) for API responses.
	 */
	/**
	 * Tells the people who voted in a poll that it has closed.
	 *
	 * A poll closes by its own end time passing, so nothing *happens* at the
	 * moment it does: without a sweep, a voter never learns the result arrived.
	 * One notification per poll per reader — the id carries both, so a second
	 * sweep over the same poll writes nothing.
	 *
	 * @return int how many polls were announced
	 */
	public function announceClosedPolls(int $limit = 50): int {
		$announced = 0;

		// taken before the read, so a poll closing while the sweep runs is
		// inside the next window rather than between the two
		$now = time();
		$since = $this->lastSweep();
		$polls = $this->streamRequest->getPollsClosedSince($since, $limit);
		// the voters of every poll in one indexed read; a failure here throws
		// before the sweep is recorded, so the whole pass is tried again
		$voters = $this->actionsRequest->votersOfPolls(
			array_map(static fn (Question $poll): string => $poll->getId(), $polls)
		);

		foreach ($polls as $poll) {
			try {
				$this->notificationService->onPollClosed($poll, $voters[$poll->getId()] ?? []);
				$announced++;
			} catch (\Throwable $e) {
				// one poll that cannot be announced must not stop the sweep:
				// the rest of them closed too
				$this->logger->warning('could not announce a closed poll', [
					'poll' => $poll->getId(), 'exception' => $e,
				]);
			}
		}

		// moved only after the pass, so a failure mid-sweep is retried rather
		// than skipped: a duplicate notification is dropped by its id, a
		// missed one is never sent
		$this->configService->setAppValue(ConfigService::SOCIAL_POLLS_SWEPT, (string)$this->sweptTo($polls, $limit, $since, $now));

		return $announced;
	}

	/**
	 * Where the next sweep starts.
	 *
	 * Now, unless the read was cut off at its limit: the polls are read
	 * earliest end first, so the ones left over closed after the last one
	 * read, and the next sweep starts a second before that — a poll announced
	 * twice is dropped by its notification id. Unless every poll read closed
	 * in that same second, when starting there again would read the same
	 * page for ever.
	 *
	 * @param Question[] $polls
	 */
	private function sweptTo(array $polls, int $limit, int $since, int $now): int {
		if ($polls === [] || count($polls) < $limit) {
			return $now;
		}

		$last = (int)end($polls)->getEndTimestamp();

		return min($now, ($last - 1 > $since) ? $last - 1 : $last);
	}

	/**
	 * How far the last sweep got, and a floor under it.
	 *
	 * The floor matters on an instance that has never swept: without it the
	 * first run would announce every poll that ever closed, to everybody who
	 * ever voted.
	 */
	private function lastSweep(): int {
		$stored = (int)$this->configService->getAppValue(ConfigService::SOCIAL_POLLS_SWEPT);

		return max($stored, time() - self::SWEEP_FLOOR);
	}

	public function exportPoll(Question $poll): array {
		$poll->setExportFormat(ACore::FORMAT_LOCAL);

		return $poll->exportAsLocal()['poll'];
	}
}
