<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Interfaces\Activity;

use Exception;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Details;
use OCA\Social\Model\InstancePath;
use OCA\Social\Model\QuoteGrant;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use Psr\Log\LoggerInterface;

/**
 * FEP-044f's approval handshake, both ways.
 *
 * Inbound: another server asks to quote one of our posts, and the author's
 * policy decides; the answer is an `Accept` carrying the stamp the quoting
 * post federates as `quoteAuthorization`, or a `Reject`. The default policy
 * is the one `PinService::pin()` and `BoostService::create()` apply (public
 * and unlisted, yes; narrower, no) and must agree with what
 * `Stream::exportInteractionPolicy()` advertises.
 *
 * Outbound: the answer to our request arrives as an
 * `Accept`/`Reject{QuoteRequest}`, routed here by
 * `AcceptInterface`/`RejectInterface`, and moves the state stored on our
 * quoting post.
 */
class QuoteRequestInterface extends AbstractActivityPubInterface implements IActivityPubInterface {
	public function __construct(
		private StreamRequest $streamRequest,
		private CacheActorService $cacheActorService,
		private ActivityService $activityService,
		private QuoteGrantRequest $quoteGrantRequest,
		private FollowsRequest $followsRequest,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Somebody wants to quote a post. Answer if it is one of ours.
	 *
	 * @throws InvalidOriginException the quoting post is not on the asking server
	 */
	#[\Override]
	public function processIncomingRequest(ACore $item): void {
		if (!$item instanceof QuoteRequest) {
			return;
		}

		$quoting = $item->getInstrument();
		$quotedId = $item->getObjectId();
		if ($quoting === '' || $quotedId === '') {
			// nothing to approve: the request names no quoting post, or no
			// post to be quoted
			$this->logger->notice('an incomplete QuoteRequest was dropped', [
				'activity' => $item->getId(),
				'object' => $quotedId,
				'instrument' => $quoting,
			]);

			return;
		}

		// the post doing the quoting has to live on the server that asks —
		// otherwise anyone could collect an approval for somebody else's post
		// and quote with it
		$item->checkOrigin($quoting);

		try {
			$quoted = $this->streamRequest->getStreamById($quotedId);
		} catch (StreamNotFoundException $e) {
			$this->logger->notice('a QuoteRequest names a post we do not hold', [
				'activity' => $item->getId(),
				'object' => $quotedId,
			]);

			return;
		}

		if (!$quoted->isLocal()) {
			// somebody else's post is not ours to grant permission over; their
			// own server answers the request it was sent
			return;
		}

		if ($quoted->mayBeQuotedBy($item->getActorId(), $this->follows($item->getActorId(), $quoted))) {
			$this->accept($item, $quoted);
		} else {
			$this->reject($item, $quoted);
		}
	}

	/**
	 * The approval, as FEP-044f defines it: an `Accept` of the request whose
	 * `result` is the authorization the quoting post then carries.
	 */
	private function accept(QuoteRequest $request, Stream $quoted): void {
		// written down before it is sent: taking the quote back later means
		// sending a Reject that names *this* request, and without a row there
		// is nothing to name
		$grant = new QuoteGrant();
		$grant->setTargetId($quoted->getId())
			->setQuotingId($request->getInstrument())
			->setActorId($request->getActorId())
			->setRequestId($request->getId())
			->setAuthorization($this->authorizationId($request, $quoted));
		$this->quoteGrantRequest->save($grant);

		$accept = new Accept();
		$accept->generateUniqueIdFromActor($quoted->getAttributedTo(), 'accept/quote-requests');
		$accept->setActorId($quoted->getAttributedTo());
		// embedded rather than named: the peer matches the answer against the
		// request it sent, and it has nowhere to fetch the request from
		$accept->setObject($request);
		$accept->setToArray([$request->getActorId()]);
		$accept->addEntry('result', $this->authorizationId($request, $quoted));

		$this->send($accept, $request);
	}

	/**
	 * Whether the asker follows the quoted post's author, for the `followers`
	 * policy. An unaccepted follow request does not count.
	 */
	private function follows(string $askerId, Stream $quoted): bool {
		if ($askerId === '' || $quoted->getAttributedTo() === '') {
			return false;
		}

		try {
			return $this->followsRequest->getByPersons($askerId, $quoted->getAttributedTo())->isAccepted();
		} catch (Exception $e) {
			return false;
		}
	}

	private function reject(QuoteRequest $request, Stream $quoted): void {
		$reject = new Reject();
		$reject->generateUniqueIdFromActor($quoted->getAttributedTo(), 'reject/quote-requests');
		$reject->setActorId($quoted->getAttributedTo());
		$reject->setObject($request);
		$reject->setToArray([$request->getActorId()]);

		$this->send($reject, $request);
	}

	/**
	 * The approval stamp for one quote of one post, derived from both ids so
	 * a retried request gets the same stamp. It hangs off the quoted post.
	 */
	private function authorizationId(QuoteRequest $request, Stream $quoted): string {
		return $quoted->getId() . '/quote_authorizations/' . self::stamp($request->getInstrument());
	}

	/**
	 * The quoting post's id, carried in the stamp rather than hashed into it:
	 * Mastodon dereferences the stamp before rendering a quote, so the
	 * endpoint can answer from the policy alone. The id is public anyway.
	 */
	public static function stamp(string $instrument): string {
		return rtrim(strtr(base64_encode($instrument), '+/', '-_'), '=');
	}

	/**
	 * The quoting post named by a stamp, or '' if the stamp is not one we
	 * wrote. Re-encoding and comparing is the check, since base64 has several
	 * spellings and only `stamp()`'s was ever handed out.
	 */
	public static function instrumentOfStamp(string $stamp): string {
		$decoded = base64_decode(strtr($stamp, '-_', '+/'), true);
		if ($decoded === false || self::stamp($decoded) !== $stamp) {
			return '';
		}

		// the document quotes this back at the reader, so it has to be an
		// address and not an arbitrary string
		if (!str_starts_with($decoded, 'https://') && !str_starts_with($decoded, 'http://')) {
			return '';
		}

		return $decoded;
	}

	private function send(ACore $answer, QuoteRequest $request): void {
		try {
			$asker = $this->cacheActorService->getFromId($request->getActorId());
		} catch (\Exception $e) {
			$this->logger->notice('cannot answer a QuoteRequest: the asker is unknown', [
				'activity' => $request->getId(),
				'actor' => $request->getActorId(),
				'exception' => $e,
			]);

			return;
		}

		$inbox = ($asker->getSharedInbox() !== '') ? $asker->getSharedInbox() : $asker->getInbox();
		if ($inbox === '') {
			return;
		}

		$answer->addInstancePath(
			new InstancePath($inbox, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP)
		);

		$this->activityService->request($answer);
	}

	/**
	 * The answer to a request of ours.
	 *
	 * @throws InvalidOriginException the answer does not come from the quoted post's server
	 */
	#[\Override]
	public function activity(ACore $activity, ACore $item): void {
		if (!$item instanceof QuoteRequest) {
			return;
		}

		$type = $activity->getType();
		if ($type !== Accept::TYPE && $type !== Reject::TYPE) {
			return;
		}

		try {
			$post = $this->streamRequest->getStreamById($item->getInstrument());
		} catch (StreamNotFoundException $e) {
			return; // an answer about a post we never wrote
		}

		if (!$post->isLocal() || $post->getQuote() === '') {
			return;
		}

		// the answer has to be about the quote the post actually carries, and
		// has to come from the quoted post's server: an Accept from anywhere
		// else would let a stranger approve a quote on the author's behalf
		if ($post->getQuote() !== $item->getObjectId()) {
			return;
		}
		$activity->checkOrigin($post->getQuote());
		// and from the quoted post's author, not anybody on their server, once
		// the quoted post is here to say who wrote it
		try {
			$quoted = $this->streamRequest->getStreamById($post->getQuote());
			$activity->checkActor($quoted->getAttributedTo(), $activity->getActorId());
		} catch (StreamNotFoundException $e) {
		}

		if ($type === Accept::TYPE) {
			$this->approve($post, $activity);

			return;
		}

		// a refusal that follows an approval is the author taking it back,
		// which Mastodon shows as a revoked quote rather than a refused one
		$withdrawn = ($post->getQuoteAuthorization() !== '');
		$post->setQuoteState($withdrawn ? Stream::QUOTE_REVOKED : Stream::QUOTE_REJECTED);
		$post->setQuoteAuthorization('');
		$this->streamRequest->updateDetails($post, Details::QUOTE_STATE);

		if ($withdrawn) {
			// the stamp comes off the wire object too, or later deliveries keep
			// claiming a withdrawn approval
			$post->setSource(json_encode($post, JSON_UNESCAPED_SLASHES));
			$this->streamRequest->update($post);
		}
	}

	/**
	 * Writes the approval onto our quoting post: the column and the stored
	 * source, written together by `StreamRequest::update()` as for an edit,
	 * so every later delivery carries the stamp and Mastodon renders the
	 * quote inline.
	 */
	private function approve(Stream $post, ACore $accept): void {
		$post->setQuoteAuthorization($this->resultOf($accept));
		$post->setQuoteState(Stream::QUOTE_ACCEPTED);
		$post->setSource(json_encode($post, JSON_UNESCAPED_SLASHES));

		$this->streamRequest->update($post);
		$this->streamRequest->updateDetails($post, Details::QUOTE_STATE);
	}

	/**
	 * The authorization URI an `Accept` carries in `result`, read from the
	 * stored wire object since this app's Accept has no such property. An
	 * Accept without one approves the quote but leaves it a link on Mastodon.
	 */
	private function resultOf(ACore $accept): string {
		$source = json_decode($accept->getSource(), true);
		if (!is_array($source)) {
			return '';
		}

		$result = $source['result'] ?? '';
		if (is_array($result)) {
			$result = $result['id'] ?? '';
		}

		return is_string($result) ? $result : '';
	}
}
