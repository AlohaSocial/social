<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorRelationRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DomainBlockService;
use OCA\Social\Service\FileCommentsService;
use OCA\Social\Service\PostReviewService;
use OCA\Social\Service\PostService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tests\Helper\FakeComment;
use OCA\Social\Tests\Helper\InMemoryFileCommentsRequest;
use OCP\App\IAppManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class FileCommentsServiceTest extends TestCase {
	private const LOCAL = 'https://cloud.example/apps/social/users/';
	private const POST = 'https://cloud.example/apps/social/@alice/1';
	private const REPLY = 'https://remote.example/users/bob/statuses/7';
	private const BOB = 'https://remote.example/users/bob';
	private const FILE = 101;

	private InMemoryFileCommentsRequest $request;
	/** @var array<string, FakeComment> */
	private array $stored = [];
	private int $nextComment = 1;
	/** @var array<string, string> user id => switch value */
	private array $settings = [];
	/** @var array<string, string> "actor|object|type" => true */
	private array $relations = [];
	private bool $domainBlocked = false;
	/** @var string[] users who never opened Aloha Social */
	private array $withoutAccount = [];
	private bool $commentsEnabled = true;
	private string $review = '';
	private PostService|MockObject $postService;
	private StreamService|MockObject $streamService;
	/** @var array<string, Stream> */
	private array $streams = [];
	private FileCommentsService $service;

	protected function setUp(): void {
		$this->request = new InMemoryFileCommentsRequest();

		$comments = $this->createStub(ICommentsManager::class);
		$comments->method('create')->willReturnCallback(function (string $type, string $id, string $objectType, string $objectId): IComment {
			return (new FakeComment())->setActor($type, $id)->setObject($objectType, $objectId);
		});
		$comments->method('save')->willReturnCallback(function (IComment $comment): bool {
			if ($comment->getId() === '') {
				$comment->setId((string)$this->nextComment++);
			}
			$this->stored[$comment->getId()] = $comment;

			return true;
		});
		$comments->method('get')->willReturnCallback(function (string $id): IComment {
			return $this->stored[$id] ?? throw new NotFoundException();
		});
		$comments->method('delete')->willReturnCallback(function (string $id): bool {
			if (!isset($this->stored[$id])) {
				throw new NotFoundException();
			}
			unset($this->stored[$id]);

			return true;
		});

		$apps = $this->createStub(IAppManager::class);
		$apps->method('isEnabledForAnyone')->willReturnCallback(fn (string $app): bool => $app === 'comments' && $this->commentsEnabled);

		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid): ?IUser {
			$names = ['alice' => 'Alice Liddell', 'carol' => 'Carol Singer'];
			if (!isset($names[$uid])) {
				return null;
			}
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($names[$uid]);

			return $user;
		});

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many));
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('en');

		$config = $this->createStub(ConfigService::class);
		$config->method('getUserValue')->willReturnCallback(fn (string $key, string $uid = ''): string => $this->settings[$uid] ?? '');
		$config->method('setValueForUser')->willReturnCallback(function ($uid, $key, $value): void {
			$this->settings[$uid] = $value;
		});

		$accounts = $this->createStub(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturnCallback(function (string $uid): Person {
			if (in_array($uid, $this->withoutAccount, true)) {
				throw new ActorDoesNotExistException();
			}

			return $this->local($uid);
		});
		$accounts->method('getFromId')->willReturnCallback(function (string $id): Person {
			if (!str_starts_with($id, self::LOCAL)) {
				throw new ActorDoesNotExistException();
			}

			return $this->local(substr($id, strlen(self::LOCAL)));
		});

		$cache = $this->createStub(CacheActorsRequest::class);
		$cache->method('getFromId')->willReturnCallback(function (string $id): Person {
			$person = new Person();
			$person->setId($id);
			$person->setAccount('bob@remote.example');
			$person->setName('Bob Remote');

			return $person;
		});

		$relations = $this->createStub(ActorRelationRequest::class);
		$relations->method('exists')->willReturnCallback(fn (string $actor, string $object, string $type): bool => isset($this->relations[$actor . '|' . $object . '|' . $type]));

		$domains = $this->createStub(DomainBlockService::class);
		$domains->method('isBlocking')->willReturnCallback(fn (): bool => $this->domainBlocked);

		$this->postService = $this->createMock(PostService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->streamService->method('getStreamById')->willReturnCallback(function (string $id): Stream {
			return $this->streams[$id] ?? throw new \OCA\Social\Exceptions\StreamNotFoundException();
		});
		$review = $this->createStub(PostReviewService::class);
		$review->method('assess')->willReturnCallback(fn (): string => $this->review);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class): object => match ($class) {
			PostService::class => $this->postService,
			StreamService::class => $this->streamService,
			PostReviewService::class => $review,
		});

		$this->service = new FileCommentsService(
			$this->request, $comments, $apps, $users, $factory, $config, $accounts,
			$cache, $relations, $domains, $container, new NullLogger()
		);
	}

	public function testAPublicPostMadeFromAFileIsLinkedToIt(): void {
		$this->publishPostFromFile();

		$files = $this->request->getFilesOfPost(self::POST);
		$this->assertCount(1, $files);
		$this->assertSame(self::FILE, $files[0]->fileId);
		$this->assertSame('alice', $files[0]->userId);
	}

	public function testAFollowersOnlyPostIsNotLinked(): void {
		$this->publishPostFromFile(Stream::TYPE_FOLLOWERS);

		$this->assertSame([], $this->request->getFilesOfPost(self::POST));
	}

	public function testNothingIsLinkedForAnAuthorWhoTurnedItOff(): void {
		$this->service->setEnabled('alice', false);
		$this->publishPostFromFile();

		$this->assertSame([], $this->request->getFilesOfPost(self::POST));
		$this->assertSame(['enabled' => false], $this->service->export('alice'));
	}

	public function testNothingIsLinkedWithoutTheCommentsApp(): void {
		$this->commentsEnabled = false;
		$this->publishPostFromFile();

		$this->assertSame([], $this->request->getFilesOfPost(self::POST));
	}

	public function testAPublicReplyBecomesACommentCreditedToItsAuthor(): void {
		$this->publishPostFromFile();

		$this->service->onReply($this->reply('<p>Lovely light!</p>'));

		$comment = $this->onlyComment();
		$this->assertSame(FileCommentsService::ACTOR_TYPE, $comment->getActorType());
		$this->assertSame(md5(self::BOB), $comment->getActorId());
		$this->assertSame('files', $comment->getObjectType());
		$this->assertSame((string)self::FILE, $comment->getObjectId());
		$this->assertSame('comment', $comment->getVerb());
		$this->assertSame("Bob Remote (bob@remote.example) replied to the post:\nLovely light!", $comment->getMessage());
		$this->assertSame(1767225600, $comment->getCreationDateTime()->getTimestamp());
	}

	public function testAnUnlistedReplyIsCopiedToo(): void {
		$this->publishPostFromFile();

		$this->service->onReply($this->reply('<p>hi</p>', Stream::TYPE_UNLISTED));

		$this->assertCount(1, $this->stored);
	}

	public function testAFollowersOnlyOrDirectReplyStaysOffTheFile(): void {
		$this->publishPostFromFile();

		$this->service->onReply($this->reply('<p>for my followers</p>', Stream::TYPE_FOLLOWERS));
		$this->service->onReply($this->reply('<p>just you</p>', Stream::TYPE_DIRECT, self::REPLY . '0'));

		$this->assertSame([], $this->stored);
	}

	public function testAReplyToAPostNotMadeFromFilesIsLeftAlone(): void {
		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertSame([], $this->stored);
	}

	public function testAReplyDeliveredTwiceIsCopiedOnce(): void {
		$this->publishPostFromFile();

		$this->service->onReply($this->reply('<p>hi</p>'));
		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertCount(1, $this->stored);
	}

	public function testAReplyFromSomebodyTheAuthorBlockedIsNotCopied(): void {
		$this->publishPostFromFile();
		$this->relations[self::LOCAL . 'alice|' . self::BOB . '|' . ActorRelation::TYPE_BLOCK] = 'x';

		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertSame([], $this->stored);
	}

	public function testAReplyFromSomebodyTheAuthorMutedIsNotCopied(): void {
		$this->publishPostFromFile();
		$this->relations[self::LOCAL . 'alice|' . self::BOB . '|' . ActorRelation::TYPE_MUTE] = 'x';

		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertSame([], $this->stored);
	}

	public function testAReplyFromAServerTheAuthorBlockedIsNotCopied(): void {
		$this->publishPostFromFile();
		$this->domainBlocked = true;

		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertSame([], $this->stored);
	}

	public function testRepliesStopWhenTheAuthorTurnsItOff(): void {
		$this->publishPostFromFile();
		$this->service->setEnabled('alice', false);

		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertSame([], $this->stored);
	}

	public function testTheAuthorsOwnReplyIsTheirCommentWithoutACredit(): void {
		$this->publishPostFromFile();
		$reply = $this->reply('<p>Thanks!</p>');
		$reply->setAttributedTo(self::LOCAL . 'alice');

		$this->service->onPostPublished($reply);

		$comment = $this->onlyComment();
		$this->assertSame('users', $comment->getActorType());
		$this->assertSame('alice', $comment->getActorId());
		$this->assertSame('Thanks!', $comment->getMessage());
	}

	/**
	 * A reply saying "@alice" would be a mention in a comment, and a mention
	 * notifies the user it names: a stranger could ping anybody here.
	 */
	public function testAMentionInAReplyCannotNotifyAUserHere(): void {
		$this->publishPostFromFile();

		$this->service->onReply($this->reply('<p>@carol @"alice" look</p>'));

		$comment = $this->onlyComment();
		$this->assertSame([], $comment->getMentions());
		$this->assertStringContainsString("@\u{2060}carol", $comment->getMessage());
	}

	public function testAContentWarningIsShownInsteadOfTheText(): void {
		$this->publishPostFromFile();
		$reply = $this->reply('<p>the ending of the film</p>');
		$reply->setSpoilerText('spoilers');

		$this->service->onReply($reply);

		$message = $this->onlyComment()->getMessage();
		$this->assertStringContainsString('Content warning: spoilers', $message);
		$this->assertStringNotContainsString('the ending', $message);
		$this->assertStringEndsWith("\n" . self::REPLY, $message);
	}

	public function testAReplyWithAttachmentsSaysSoAndLinksToIt(): void {
		$this->publishPostFromFile();
		$reply = $this->reply('<p>mine</p>');
		$reply->setAttachments([new MediaAttachment(), new MediaAttachment()]);

		$this->service->onReply($reply);

		$this->assertStringEndsWith("mine\n2 attachments\n" . self::REPLY, $this->onlyComment()->getMessage());
	}

	public function testALongReplyIsCutToWhatACommentHoldsAndLinked(): void {
		$this->publishPostFromFile();

		$this->service->onReply($this->reply('<p>' . str_repeat('word ', 400) . '</p>'));

		$message = $this->onlyComment()->getMessage();
		$this->assertLessThanOrEqual(IComment::MAX_MESSAGE_LENGTH, mb_strlen($message));
		$this->assertStringEndsWith("…\n" . self::REPLY, $message);
	}

	public function testAPostWithTwoFilesHasItsRepliesOnBoth(): void {
		$this->publishPostFromFile(Stream::TYPE_PUBLIC, [self::FILE, 102]);

		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->assertSame(['101', '102'], array_values(array_map(fn (IComment $c): string => $c->getObjectId(), $this->stored)));
	}

	public function testAReplyToACopiedReplyIsCopiedToo(): void {
		$this->publishPostFromFile();
		$this->service->onReply($this->reply('<p>first</p>'));

		$answer = $this->reply('<p>second</p>', Stream::TYPE_PUBLIC, self::REPLY . '/answer');
		$answer->setInReplyTo(self::REPLY);
		$this->service->onReply($answer);

		$this->assertCount(2, $this->stored);
		$this->assertCount(2, $this->request->getCommentsOfPost(self::POST), 'both belong to the thread of the post');
	}

	public function testAReplyToAReplyThatWasNotCopiedIsNotCopied(): void {
		$this->publishPostFromFile();
		$this->service->onReply($this->reply('<p>for my followers</p>', Stream::TYPE_FOLLOWERS));

		$answer = $this->reply('<p>public answer</p>', Stream::TYPE_PUBLIC, self::REPLY . '/answer');
		$answer->setInReplyTo(self::REPLY);
		$this->service->onReply($answer);

		$this->assertSame([], $this->stored);
	}

	public function testAnEditedReplyRewritesItsComment(): void {
		$this->publishPostFromFile();
		$this->service->onReply($this->reply('<p>frist</p>'));

		$this->service->onReplyUpdated($this->reply('<p>first</p>'));

		$this->assertStringEndsWith("\nfirst", $this->onlyComment()->getMessage());
	}

	public function testADeletedReplyTakesItsCommentAlong(): void {
		$this->publishPostFromFile();
		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->service->onDeleted($this->reply('<p>hi</p>'));

		$this->assertSame([], $this->stored);
		$this->assertSame([], $this->request->comments);
	}

	public function testADeletedPostTakesTheCopiesButNotTheAuthorsComments(): void {
		$this->publishPostFromFile();
		$this->service->onReply($this->reply('<p>hi</p>'));
		$this->stored['99'] = (new FakeComment())->setId('99')->setActor('users', 'alice')->setObject('files', (string)self::FILE);
		$this->request->addComment(self::FILE, 'alice', 99, md5(self::POST), self::POST . '/reply', true);

		$this->service->onDeleted($this->post());

		$this->assertSame([99], array_keys($this->stored));
		$this->assertSame([], $this->request->getFilesOfPost(self::POST));
	}

	public function testTheAuthorsCommentGoesOutAsTheirReply(): void {
		$this->publishPostFromFile();
		$comment = $this->comment('alice', 'Shot on film');

		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post) use ($comment) {
				$this->assertSame('Shot on film', $post->getContent());
				$this->assertSame(self::POST, $post->getReplyTo());
				$this->assertSame(Stream::TYPE_PUBLIC, $post->getType());
				$this->assertSame(self::LOCAL . 'alice', $post->getActor()->getId());

				// what PostService dispatches once the reply is stored
				$reply = $this->reply('<p>Shot on film</p>');
				$reply->setAttributedTo(self::LOCAL . 'alice');
				$this->service->onPostPublished($reply);

				return null;
			});

		$this->service->onCommentAdded($comment);

		$this->assertSame([(int)$comment->getId()], array_keys($this->stored), 'the reply is not copied back onto the file it came from');
		$row = $this->request->getByComment((int)$comment->getId());
		$this->assertNotNull($row);
		$this->assertTrue($row->outbound);
		$this->assertSame(self::REPLY, $row->replyId);
	}

	public function testAMentionOfAUserHereIsSentAsAMentionOfTheirAccount(): void {
		$this->publishPostFromFile();

		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post) {
				$this->assertSame('@carol and @alice took it, ask @bob@remote.example', $post->getContent());

				return null;
			});

		$this->service->onCommentAdded($this->comment('alice', '@carol and @"alice" took it, ask @bob@remote.example'));
	}

	public function testAMentionOfAUserWithoutAnAccountIsSentAsTheirName(): void {
		$this->publishPostFromFile();
		$this->withoutAccount[] = 'carol';

		$this->postService->expects($this->once())->method('createPost')
			->willReturnCallback(function (Post $post) {
				$this->assertSame('ask Carol Singer', $post->getContent());

				return null;
			});

		$this->service->onCommentAdded($this->comment('alice', 'ask @carol'));
	}

	public function testAColleaguesCommentStaysOnTheServer(): void {
		$this->publishPostFromFile();

		$this->postService->expects($this->never())->method('createPost');

		$this->service->onCommentAdded($this->comment('carol', 'internal remark'));
	}

	public function testACommentOnAFileNeverPostedStaysOnTheServer(): void {
		$this->postService->expects($this->never())->method('createPost');

		$this->service->onCommentAdded($this->comment('alice', 'hello'));
	}

	public function testACommentTheReviewWouldHoldStaysAComment(): void {
		$this->publishPostFromFile();
		$this->review = 'spam';

		$this->postService->expects($this->never())->method('createPost');

		$this->service->onCommentAdded($this->comment('alice', 'buy now'));
	}

	public function testNoCommentGoesOutOnceTheAuthorTurnedItOff(): void {
		$this->publishPostFromFile();
		$this->service->setEnabled('alice', false);

		$this->postService->expects($this->never())->method('createPost');

		$this->service->onCommentAdded($this->comment('alice', 'hello'));
	}

	public function testACopyThisServiceWritesIsNotSentBackOut(): void {
		$this->publishPostFromFile();
		$reply = $this->reply('<p>Thanks!</p>');
		$reply->setAttributedTo(self::LOCAL . 'alice');
		$this->service->onPostPublished($reply);

		$this->postService->expects($this->never())->method('createPost');
		$this->postService->expects($this->never())->method('editPost');

		// the event the comments manager would have sent for that copy, had
		// it been asked about it while the copy was not yet recorded
		$copy = $this->onlyComment();
		$this->service->onCommentUpdated($copy);
	}

	public function testEditingTheCommentEditsTheReply(): void {
		$this->publishPostFromFile();
		$comment = $this->outboundComment('alice', 'frist');
		$comment->setMessage('first');

		$this->postService->expects($this->once())->method('editPost')
			->with(77, $this->callback(fn (Person $p): bool => $p->getId() === self::LOCAL . 'alice'), 'first')
			->willReturn($this->streams[self::REPLY]);

		$this->service->onCommentUpdated($comment);
	}

	public function testDeletingTheCommentDeletesTheReply(): void {
		$this->publishPostFromFile();
		$comment = $this->outboundComment('alice', 'oops');

		$this->streamService->expects($this->once())->method('deleteLocalItem')
			->with($this->identicalTo($this->streams[self::REPLY]));

		$this->service->onCommentDeleted($comment);

		$this->assertNull($this->request->getByComment((int)$comment->getId()));
	}

	public function testDeletingACommentThatOnlyCopiedSomebodyElsesReplyLeavesTheReply(): void {
		$this->publishPostFromFile();
		$this->service->onReply($this->reply('<p>hi</p>'));

		$this->streamService->expects($this->never())->method('deleteLocalItem');

		$this->service->onCommentDeleted($this->onlyComment());
	}

	public function testTheSwitchIsOnUnlessTurnedOff(): void {
		$this->assertTrue($this->service->isEnabled('alice'));
		$this->service->setEnabled('alice', false);
		$this->assertFalse($this->service->isEnabled('alice'));
		$this->service->setEnabled('alice', true);
		$this->assertTrue($this->service->isEnabled('alice'));
		$this->assertFalse($this->service->isEnabled(''));
	}

	public function testHtmlIsReadAsLinesOfText(): void {
		$this->assertSame(
			"one & two\nthree\nfour",
			FileCommentsService::plainText('<p>one &amp; two<br>three</p><p>four</p>')
		);
	}

	/**
	 * @param int[] $files
	 */
	private function publishPostFromFile(string $visibility = Stream::TYPE_PUBLIC, array $files = [self::FILE]): void {
		$attachments = [];
		foreach ($files as $i => $fileId) {
			$this->service->rememberAttachment('alice', $fileId, (string)(500 + $i));
			$attachments[] = (new MediaAttachment())->setId((string)(500 + $i));
		}

		$post = $this->post($visibility);
		$post->setAttachments($attachments);
		$this->streams[self::POST] = $post;
		$this->service->onPostPublished($post);
	}

	private function post(string $visibility = Stream::TYPE_PUBLIC): Note {
		$post = new Note();
		$post->setId(self::POST);
		$post->setAttributedTo(self::LOCAL . 'alice');
		$post->setVisibility($visibility);

		return $post;
	}

	private function reply(string $html, string $visibility = Stream::TYPE_PUBLIC, string $id = self::REPLY): Note {
		$reply = new Note();
		$reply->setId($id);
		$reply->setUrl($id);
		$reply->setAttributedTo(self::BOB);
		$reply->setInReplyTo(self::POST);
		$reply->setVisibility($visibility);
		$reply->setContent($html);
		$reply->setPublishedTime(1767225600);

		return $reply;
	}

	private function comment(string $userId, string $message): FakeComment {
		$comment = (new FakeComment())
			->setActor('users', $userId)
			->setObject('files', (string)self::FILE)
			->setMessage($message);
		$comment->setId((string)$this->nextComment++);
		$this->stored[$comment->getId()] = $comment;

		return $comment;
	}

	/** A comment the author wrote that went out as the reply `REPLY`, nid 77. */
	private function outboundComment(string $userId, string $message): FakeComment {
		$comment = $this->comment($userId, $message);
		$reply = $this->reply('<p>' . $message . '</p>');
		$reply->setAttributedTo(self::LOCAL . $userId);
		$reply->setNid(77);
		$this->streams[self::REPLY] = $reply;
		$this->request->addComment(self::FILE, $userId, (int)$comment->getId(), md5(self::POST), self::REPLY, true);

		return $comment;
	}

	private function onlyComment(): FakeComment {
		$this->assertCount(1, $this->stored);

		return array_values($this->stored)[0];
	}

	private function local(string $uid): Person {
		$person = new Person();
		$person->setId(self::LOCAL . $uid);
		$person->setUserId($uid);
		$person->setPreferredUsername($uid);

		return $person;
	}
}
