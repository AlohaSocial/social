<?php

declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\RecordMapper\OutboundWorker;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A bounded outbox pass for local testing and operational recovery. */
class PublishCommand extends Command {
	public function __construct(
		private readonly OutboundWorker $worker,
		private readonly IdentityService $identities,
		private readonly IDBConnection $db,
	) {
		parent::__construct();
	}
	protected function configure(): void {
		$this->setName('social:atproto:publish')->setDescription('Process one due batch of native ATProto publications (up to 25)');
	}
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->identities->isEnabled()) {
			$output->writeln('<error>AT Protocol is disabled</error>');
			return Command::FAILURE;
		}
		$this->worker->run();
		$qb = $this->db->getQueryBuilder();
		$pending = (int)$qb->select($qb->func()->count('*'))->from('social_atpds_outbox')->executeQuery()->fetchOne();
		$output->writeln('Processed the due batch; pending outbox items: ' . $pending);
		if ($pending > 0) {
			$output->writeln('Deferred/retrying items remain queued; inspect the app log or administrator status endpoint.');
		}
		return Command::SUCCESS;
	}
}
