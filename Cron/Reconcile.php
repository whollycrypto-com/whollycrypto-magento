<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Cron;

class Reconcile
{
    public function __construct(private \WhollyCrypto\Payment\Model\Gateway $gateway, private \Psr\Log\LoggerInterface $logger) {}
    public function execute(): void
    {
        $result = $this->gateway->reconcile(50);
        if ($result['failed']) { $this->logger->warning('Wholly Crypto: {count} invoice checks failed. Run whollycrypto:reconcile and review the saved orders.', ['count' => $result['failed']]); }
    }
}
