<?php
declare(strict_types=1);
namespace OCA\Social\Listeners;
use OCA\Social\Events\{PostPublishedEvent, PostDeletedEvent};
use OCA\Social\Atproto\RecordMapper\OutboundQueue;
use OCP\EventDispatcher\{Event, IEventListener};
use Psr\Log\LoggerInterface;
class AtprotoPostListener implements IEventListener {
	public function __construct(private readonly OutboundQueue $queue, private readonly LoggerInterface $logger) {}
	public function handle(Event $event): void {
		if (!$event instanceof PostPublishedEvent && !$event instanceof PostDeletedEvent) { return; }
		try {
			$post = $event->getPost();
			if ($event instanceof PostDeletedEvent) { $this->queue->queueDelete((string)$post->getNid()); }
			elseif ($post->isLocal() && $post->getVisibility() === 'public' && $post->addressesPublic()) { $this->queue->queuePost((string)$post->getNid()); }
			elseif ($post->isLocal()) { $this->queue->queueDelete((string)$post->getNid()); }
		} catch (\Throwable $e) { $this->logger->error('AT Protocol could not queue a Social post', ['exception' => $e]); }
	}
}
