<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use DateTime;
use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FileCommentsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Files\FilePost;
use OCA\Social\Model\Post;
use OCP\App\IAppManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replies to a post made from Files, shown as comments on the file.
 *
 * A picture posted from Files (with "Share to Aloha Social", or with the
 * composer's file picker) keeps a link to the file it was copied from. A
 * public or unlisted reply to that post is copied onto the file as a
 * comment, so the conversation can be read in the Files sidebar beside the
 * picture it is about. The other way, a comment the post's author writes on
 * the file goes out as their reply.
 *
 * **Only the post's author speaks for the post.** Everybody else who can see
 * the file comments on it the way they always did, and those comments stay
 * on this server: a colleague with access to a shared folder must not find
 * that a remark of theirs went out to the fediverse.
 *
 * **Only replies anybody could read are copied in.** Everybody who can open
 * the file reads its comments, and they are not the people a followers-only
 * or direct reply was addressed to.
 *
 * Nothing here may fail what it hangs off: a reply that cannot be copied is
 * still a reply, and a comment that cannot be sent is still a comment.
 */
class FileCommentsService {
	/** The per-user switch: `'0'` turns it off, anything else leaves it on. */
	public const USER_KEY = 'files_comments';

	/** How a reply by somebody other than the post's author is credited. */
	public const ACTOR_TYPE = 'social_fediverse';

	private const VISIBLE = [Stream::TYPE_PUBLIC, Stream::TYPE_UNLISTED];

	/** Set while this class writes a comment, so its own events are not answered. */
	private bool $writing = false;

	/** The comment being sent as a reply, while it is. */
	private ?IComment $publishing = null;

	public function __construct(
		private FileCommentsRequest $fileCommentsRequest,
		private ICommentsManager $commentsManager,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private ConfigService $configService,
		private AccountService $accountService,
		private CacheActorsRequest $cacheActorsRequest,
		private ActorRelationRequest $actorRelationRequest,
		private DomainBlockService $domainBlockService,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function isEnabled(string $userId): bool {
		return $userId !== '' && $this->configService->getUserValue(self::USER_KEY, $userId) !== '0';
	}

	public function setEnabled(string $userId, bool $enabled): void {
		$this->configService->setValueForUser($userId, self::USER_KEY, $enabled ? '1' : '0');
	}

	/** The switch as the client API answers it. */
	public function export(string $userId): array {
		return ['enabled' => $this->isEnabled($userId)];
	}

	/** A file was copied into an attachment, which is not posted yet. */
	public function rememberAttachment(string $userId, int $fileId, string $docNid): void {
		if (!$this->isEnabled($userId) || !$this->commentsAvailable()) {
			return;
		}

		$this->guarded('remember an attachment from Files', function () use ($fileId, $userId, $docNid): void {
			$this->fileCommentsRequest->addPending($fileId, $userId, $docNid);
		});
	}

	/** A post was written on this instance: it may be made from files, or a reply to one that was. */
	public function onPostPublished(Stream $post): void {
		if (!$this->commentsAvailable()) {
			return;
		}

		$this->linkFiles($post);
		$this->copyReplies($post);
	}

	/** A post reached the inbox: it may be a reply to a post made from files. */
	public function onReply(Stream $reply): void {
		if ($reply->getInReplyTo() === '' || !$this->commentsAvailable()) {
			return;
		}

		$this->guarded('copy a reply onto a file', fn () => $this->copyReplies($reply));
	}

	/**
	 * A reply reached the inbox, or was written here.
	 *
	 * Copied onto every file of the post it answers, or of the post at the
	 * top of the thread when it answers a reply that was copied. The comment
	 * written in Files that this reply was sent for is recorded against it
	 * rather than copied back.
	 */
	private function copyReplies(Stream $reply): void {
		$targets = $this->targetsOf($reply->getInReplyTo());
		if ($targets === []) {
			return;
		}

		$skipFile = 0;
		if ($this->publishing !== null) {
			$skipFile = (int)$this->publishing->getObjectId();
			$this->fileCommentsRequest->addComment(
				$skipFile, $this->publishing->getActorId(), (int)$this->publishing->getId(),
				FileCommentsRequest::primOf($reply->getInReplyTo()), $reply->getId(), true
			);
		}

		if (!in_array($reply->getVisibility(), self::VISIBLE, true)) {
			return;
		}

		$copied = array_map(
			static fn ($row): int => $row->fileId,
			$this->fileCommentsRequest->getCommentsOfReply($reply->getId())
		);
		foreach ($targets as [$fileId, $userId, $postIdPrim]) {
			if ($fileId === $skipFile || in_array($fileId, $copied, true)) {
				continue;
			}

			$this->copyReply($fileId, $userId, $postIdPrim, $reply);
		}
	}

	/**
	 * The files a reply to `$parentId` belongs on: the post's own files, or
	 * the files the parent was copied onto.
	 *
	 * @return list<array{0: int, 1: string, 2: string}> file id, the post's author, the post's key
	 */
	private function targetsOf(string $parentId): array {
		if ($parentId === '') {
			return [];
		}

		$targets = [];
		foreach ($this->fileCommentsRequest->getFilesOfPost($parentId) as $link) {
			$targets[] = [$link->fileId, $link->userId, FileCommentsRequest::primOf($parentId)];
		}
		if ($targets !== []) {
			return $targets;
		}

		foreach ($this->fileCommentsRequest->getCommentsOfReply($parentId) as $row) {
			$targets[] = [$row->fileId, $row->userId, $row->postIdPrim];
		}

		return $targets;
	}

	/** A reply was edited on the server it lives on. */
	public function onReplyUpdated(Stream $reply): void {
		if ($reply->getInReplyTo() === '' || !$this->commentsAvailable()) {
			return;
		}

		$this->guarded('edit the comment of a reply', fn () => $this->updateCopies($reply));
	}

	private function updateCopies(Stream $reply): void {
		foreach ($this->fileCommentsRequest->getCommentsOfReply($reply->getId()) as $row) {
			if ($row->outbound) {
				continue;
			}

			try {
				$comment = $this->commentsManager->get((string)$row->commentId);
				$comment->setMessage($this->messageOf($reply, $row->userId, $comment->getActorType() === 'users'));
				$this->save($comment);
			} catch (NotFoundException) {
				$this->fileCommentsRequest->deleteComment($row->commentId);
			}
		}
	}

	/**
	 * A post or a reply is gone.
	 *
	 * A post that was made from files takes the copied replies with it; the
	 * comments people wrote in Files themselves stay. A reply takes every
	 * comment that stands for it.
	 */
	public function onDeleted(Stream $item): void {
		if (!$this->commentsAvailable()) {
			return;
		}

		$this->guarded('remove the comments of a post', fn () => $this->deleteCopies($item));
	}

	private function deleteCopies(Stream $item): void {
		if ($this->fileCommentsRequest->getFilesOfPost($item->getId()) !== []) {
			foreach ($this->fileCommentsRequest->getCommentsOfPost($item->getId()) as $row) {
				if (!$row->outbound) {
					$this->deleteComment($row->commentId);
				}
			}
			$this->fileCommentsRequest->deletePost($item->getId());
		}

		$copies = $this->fileCommentsRequest->getCommentsOfReply($item->getId());
		foreach ($copies as $row) {
			$this->deleteComment($row->commentId);
		}
		if ($copies !== []) {
			$this->fileCommentsRequest->deleteReply($item->getId());
		}
	}

	/** A comment was written on a file: by the post's author, it is sent as their reply. */
	public function onCommentAdded(IComment $comment): void {
		if ($this->writing || !$this->isAuthoredInFiles($comment)) {
			return;
		}

		$userId = $comment->getActorId();
		$link = $this->fileCommentsRequest->getPostsOfFile((int)$comment->getObjectId(), $userId)[0] ?? null;
		if ($link === null || !$this->isEnabled($userId)) {
			return;
		}

		$this->publish($comment, $link);
	}

	/** The author edited a comment that went out as their reply: the reply is edited too. */
	public function onCommentUpdated(IComment $comment): void {
		if ($this->writing || !$this->isAuthoredInFiles($comment)) {
			return;
		}

		$row = $this->fileCommentsRequest->getByComment((int)$comment->getId());
		if ($row === null) {
			return;
		}

		$reply = $this->ownReply($row->replyId, $comment->getActorId());
		if ($reply === null) {
			return;
		}

		$actor = $this->accountService->getActorFromUserId($comment->getActorId());
		$this->postService()->editPost($reply->getNid(), $actor, $this->textOf($comment));
	}

	/** The author deleted a comment that stands for their reply: the reply is deleted too. */
	public function onCommentDeleted(IComment $comment): void {
		if ($this->writing) {
			return;
		}

		$row = $this->fileCommentsRequest->getByComment((int)$comment->getId());
		if ($row === null) {
			return;
		}
		$this->fileCommentsRequest->deleteComment($row->commentId);

		if (!$this->isAuthoredInFiles($comment)) {
			return;
		}

		$reply = $this->ownReply($row->replyId, $comment->getActorId());
		if ($reply !== null) {
			$this->streamService()->deleteLocalItem($reply, $reply->getType());
		}
	}

	/**
	 * Gives the post its files: the attachments that were copied out of Files
	 * and are waiting for a post.
	 */
	private function linkFiles(Stream $post): void {
		$docNids = array_values(array_filter(array_map(
			static fn ($attachment): string => $attachment instanceof MediaAttachment ? $attachment->getId() : '',
			$post->getAttachments()
		)));
		if ($docNids === []) {
			return;
		}

		$author = $this->localAuthor($post);
		if ($author === null) {
			return;
		}

		$pending = $this->fileCommentsRequest->getPending($author->getUserId(), $docNids);
		if ($pending === [] || !in_array($post->getVisibility(), self::VISIBLE, true)) {
			return;
		}

		foreach ($pending as $row) {
			$this->fileCommentsRequest->setPost($row->id, $post->getId());
		}
	}

	private function copyReply(int $fileId, string $userId, string $postIdPrim, Stream $reply): void {
		if (!$this->isEnabled($userId)) {
			return;
		}

		try {
			$author = $this->accountService->getActorFromUserId($userId);
		} catch (Throwable) {
			return;
		}

		$own = $reply->getAttributedTo() === $author->getId();
		if (!$own && $this->hides($author, $reply->getAttributedTo())) {
			return;
		}

		$comment = $own
			? $this->commentsManager->create('users', $userId, 'files', (string)$fileId)
			: $this->commentsManager->create(self::ACTOR_TYPE, md5($reply->getAttributedTo()), 'files', (string)$fileId);
		$comment->setVerb('comment');
		$comment->setMessage($this->messageOf($reply, $userId, $own));
		if ($reply->getPublishedTime() > 0) {
			$comment->setCreationDateTime(new DateTime('@' . $reply->getPublishedTime()));
		}
		$this->save($comment);

		$this->fileCommentsRequest->addComment(
			$fileId, $userId, (int)$comment->getId(), $postIdPrim, $reply->getId(), false
		);
	}

	/** Whether the author has blocked or muted the replier, or the replier's server. */
	private function hides(Person $author, string $replierId): bool {
		return $this->actorRelationRequest->exists($author->getId(), $replierId, ActorRelation::TYPE_BLOCK)
			|| $this->actorRelationRequest->exists($author->getId(), $replierId, ActorRelation::TYPE_MUTE)
			|| $this->domainBlockService->isBlocking($author->getId(), $replierId);
	}

	/**
	 * Sends a comment as the author's reply to the post the file was made into.
	 *
	 * With the visibility of the post it answers, and past the same review a
	 * post written in the composer goes through: a comment the review would
	 * hold stays a comment.
	 */
	private function publish(IComment $comment, FilePost $link): void {
		$actor = $this->accountService->getActorFromUserId($comment->getActorId());
		$post = $this->streamService()->getStreamById($link->postId);
		if ($post->getAttributedTo() !== $actor->getId()
			|| !in_array($post->getVisibility(), self::VISIBLE, true)) {
			return;
		}

		$text = $this->textOf($comment);
		$review = $this->container->get(PostReviewService::class);
		if ($review->assess($actor, $text, $post->getVisibility()) !== '') {
			$this->logger->info('[FileCommentsService] a comment would be held for review, so it stays a comment', [
				'comment' => $comment->getId(),
			]);

			return;
		}

		$reply = new Post($actor);
		$reply->setContent($text);
		$reply->setType($post->getVisibility());
		$reply->setReplyTo($link->postId);

		$this->publishing = $comment;
		try {
			$this->postService()->createPost($reply);
		} finally {
			$this->publishing = null;
		}
	}

	/** A local reply by the user, or null when it is not theirs or not here any more. */
	private function ownReply(string $replyId, string $userId): ?Stream {
		try {
			$reply = $this->streamService()->getStreamById($replyId);
			$actor = $this->accountService->getActorFromUserId($userId);
		} catch (Throwable) {
			return null;
		}

		return $reply->getAttributedTo() === $actor->getId() ? $reply : null;
	}

	/**
	 * What a comment on a file says as a reply: the text as written, with a
	 * mention of a user of this server turned into a mention of their account
	 * here, or into their name when they have none. A mention of a fediverse
	 * address is left for the composer's own parsing to link.
	 */
	private function textOf(IComment $comment): string {
		$text = $comment->getMessage();
		foreach ($comment->getMentions() as $mention) {
			if ($mention['type'] !== 'user') {
				continue;
			}
			$user = $this->userManager->get($mention['id']);
			if ($user === null) {
				continue;
			}

			try {
				$named = '@' . $this->accountService->getActorFromUserId($mention['id'])->getPreferredUsername();
			} catch (Throwable) {
				$named = $user->getDisplayName();
			}
			$text = str_replace('@"' . $mention['id'] . '"', $named, $text);
			$text = (string)preg_replace(
				'/(?<![\w@])@' . preg_quote($mention['id'], '/') . '(?![\w@.\-])/u', $named, $text
			);
		}

		return $text;
	}

	/**
	 * The comment that stands for a reply.
	 *
	 * Credited in its first line when it is somebody else's, since the Files
	 * app only names the authors that are users of this server. A content
	 * warning is shown in place of the text, which a comment has no way to
	 * fold away, and the reply's address follows whenever the comment does
	 * not hold all of it.
	 */
	private function messageOf(Stream $reply, string $authorUserId, bool $own): string {
		$l = $this->l10nFactory->get('social', $this->l10nFactory->getUserLanguage($this->userManager->get($authorUserId)));

		$lines = [];
		if (!$own) {
			[$name, $account] = $this->creditOf($reply->getAttributedTo());
			$lines[] = $l->t('%1$s (%2$s) replied to the post:', [$name, $account]);
		}

		$link = $reply->getUrl() !== '' ? $reply->getUrl() : $reply->getId();
		$needsLink = false;
		if ($reply->getSpoilerText() !== '') {
			$lines[] = $l->t('Content warning: %s', [$reply->getSpoilerText()]);
			$needsLink = true;
		} else {
			$lines[] = self::plainText($reply->getContent());
		}

		$attachments = count($reply->getAttachments());
		if ($attachments > 0) {
			$lines[] = $l->n('%n attachment', '%n attachments', $attachments);
			$needsLink = true;
		}

		$message = self::withoutMentions(implode("\n", $lines));
		$room = IComment::MAX_MESSAGE_LENGTH - mb_strlen($link) - 2;
		if (mb_strlen($message) > $room) {
			$message = rtrim(mb_substr($message, 0, $room - 1)) . '…';
			$needsLink = true;
		}

		return $needsLink ? $message . "\n" . $link : $message;
	}

	/**
	 * The replier's name and address, from what this server already knows.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function creditOf(string $actorId): array {
		try {
			$actor = $this->cacheActorsRequest->getFromId($actorId);
			$account = $actor->getAccount();
			$name = $actor->getName() !== '' ? $actor->getName() : $actor->getPreferredUsername();
			if ($account !== '') {
				return [$name !== '' ? $name : $account, $account];
			}
		} catch (Throwable) {
		}

		return [$actorId, (string)parse_url($actorId, PHP_URL_HOST)];
	}

	/** The text of a rendered post: a line per paragraph, entities decoded. */
	public static function plainText(string $html): string {
		$text = (string)preg_replace('/<br\s*\/?>|<\/p>\s*(?=<p)/i', "\n", $html);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return trim($text);
	}

	/**
	 * Takes the meaning out of every `@` that would start a Nextcloud mention.
	 *
	 * A comment's mentions notify the users they name. A reply from another
	 * server saying "@admin" would otherwise reach whoever is `admin` here.
	 * A word joiner after the `@` is invisible and stops the parser.
	 */
	public static function withoutMentions(string $text): string {
		return (string)preg_replace('/(?<![\p{L}\p{N}_])@(?=[\p{L}\p{N}_"\'.\-])/u', "@\u{2060}", $text);
	}

	/** A comment on a file, by a user of this server. */
	private function isAuthoredInFiles(IComment $comment): bool {
		return $comment->getObjectType() === 'files' && $comment->getActorType() === 'users';
	}

	/** The local account behind a post, or null for one that is not local. */
	private function localAuthor(Stream $post): ?Person {
		try {
			$author = $this->accountService->getFromId($post->getAttributedTo());
		} catch (Throwable) {
			return null;
		}

		return $author->getUserId() !== '' ? $author : null;
	}

	private function save(IComment $comment): void {
		$this->writing = true;
		try {
			$this->commentsManager->save($comment);
		} finally {
			$this->writing = false;
		}
	}

	private function deleteComment(int $commentId): void {
		$this->writing = true;
		try {
			$this->commentsManager->delete((string)$commentId);
		} catch (NotFoundException) {
		} finally {
			$this->writing = false;
		}
	}

	/**
	 * Runs a step that hangs off something else, which must not fail because
	 * of it: the inbox, an upload.
	 */
	private function guarded(string $what, callable $step): void {
		try {
			$step();
		} catch (Throwable $e) {
			$this->logger->warning('[FileCommentsService] could not ' . $what, ['exception' => $e]);
		}
	}

	private function commentsAvailable(): bool {
		return $this->appManager->isEnabledForAnyone('comments');
	}

	/**
	 * Resolved when used: the post services reach the inbox interfaces, one
	 * of which calls this class, and taking them in the constructor would be
	 * a cycle.
	 */
	private function postService(): PostService {
		return $this->container->get(PostService::class);
	}

	private function streamService(): StreamService {
		return $this->container->get(StreamService::class);
	}
}
