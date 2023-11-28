<?php declare(strict_types=1);

namespace Wexo\Quickpay\Command;

use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wexo\Quickpay\Service\RefundService;

class RefundCommand extends Command
{
    public function __construct(
        protected RefundService $refundService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('orderId', InputArgument::REQUIRED, 'Order to refund')
            ->addOption('amount', 'a', InputOption::VALUE_REQUIRED, 'Refund amount');
    }

    /**
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $orderId = (string)$input->getArgument('orderId');
        if (!Uuid::isValid($orderId)) {
            throw new InvalidUuidException($orderId);
        }

        $amount = $input->hasOption('amount') ?
            (float)$input->getOption('amount') :
            null;

        $status = $this->refundService->refund($orderId, $amount, Context::createDefaultContext());
        if ($status === null) {
            $output->writeln('Invalid amount or orderId');
            return Command::INVALID;
        }

        $output->writeln(
            $status ?
                "$amount has been refunded for orderId $orderId" :
                "Failed to refund $amount on order $orderId"
        );

        return $status ? Command::SUCCESS : Command::FAILURE;
    }
}
