<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Console;

use Symfony\Component\Console\{Command\Command, Input\InputInterface, Input\InputOption, Output\OutputInterface};

class Reconcile extends Command
{
    public function __construct(private \WhollyCrypto\Payment\Model\Gateway $gateway, private \Magento\Framework\App\State $state) { parent::__construct(); }
    protected function configure()
    {
        $this->setName('whollycrypto:reconcile')->setDescription('Verify saved Wholly Crypto invoices without creating duplicates.')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'Magento numeric order entity ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Oldest records to check (1–200)', '50');
        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            try { $this->state->getAreaCode(); } catch (\Magento\Framework\Exception\LocalizedException $e) { $this->state->setAreaCode('adminhtml'); }
            $id = $input->getOption('order');
            if ($id !== null) {
                $result = $this->gateway->process((string) $id);
                $output->writeln('Verification: ' . $result['decision']);
                return 0;
            }
            $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
            if ($limit === false) { throw new \InvalidArgumentException('Invalid batch size.'); }
            $result = $this->gateway->reconcile($limit);
            $output->writeln(sprintf('Checked: %d; failed: %d.', $result['checked'], $result['failed']));
            foreach ($result['errors'] as $error) { $output->writeln('Order ' . $error['order_id'] . ': ' . $error['message']); }
            return $result['failed'] ? 1 : 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . \WhollyCrypto\Payment\Core\Protocol::error($e) . '</error>');
            return 1;
        }
    }
}
