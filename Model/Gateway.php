<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\StoreManagerInterface;
use WhollyCrypto\Payment\Core\{Engine, Protocol, Repository};

class Gateway
{
    private Repository $repository;
    private \Magento\Framework\DB\Adapter\AdapterInterface $db;

    public function __construct(
        private ResourceConnection $resource,
        private OrderFactory $orders,
        private OrderResource $orderResource,
        private Config $config,
        private StoreManagerInterface $stores
    ) {
        $this->db = $resource->getConnection();
        $this->repository = new Repository(
            fn ($sql, $params) => $this->db->query($sql, $params),
            fn ($sql, $params) => $this->db->fetchAll($sql, $params),
            function (callable $work) {
                $this->db->beginTransaction();
                try { $result = $work(); $this->db->commit(); return $result; }
                catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
            }, $resource->getTableName('whollycrypto_payments')
        );
    }

    public function repository(): Repository { return $this->repository; }

    public function order(string $id): Order
    {
        if (!ctype_digit($id) || strlen($id) > 12 || (int) $id < 1) { throw new \InvalidArgumentException('Invalid order.'); }
        $order = $this->orders->create();
        $this->orderResource->load($order, $id);
        if (!$order->getId()) { throw new \RuntimeException('Order not found.'); }
        return $order;
    }

    public function snapshot(string $id, bool $lock): array
    {
        if ($lock) {
            $table = $this->resource->getTableName('sales_order');
            $this->db->fetchOne("SELECT entity_id FROM `$table` WHERE entity_id=? FOR UPDATE", [$id]);
        }
        $o = $this->order($id);
        $p = $o->getPayment();
        // Use the persisted decimal total, including taxes, discounts and shipping.
        $amount = Protocol::decimal((string) $o->getGrandTotal());
        $received = (string) ($o->getTotalPaid() ?? '0');
        $paid = bccomp($received, $amount, 4) === 0 && bccomp($received, '0', 4) > 0
            && $p->getAdditionalInformation('whollycrypto_invoice_id');
        $hasInvoices = $o->getInvoiceCollection()->getSize() > 0;
        return [
            'id' => $id, 'scope' => (string) $o->getStoreId(), 'reference' => 'magento:' . $o->getStoreId() . ':' . $o->getIncrementId(),
            'transaction_id' => (string) $p->getId(), 'amount' => $amount, 'currency' => $o->getOrderCurrencyCode(),
            'ours' => $p->getMethod() === 'whollycrypto', 'paid' => $paid,
            'payable' => bccomp($received, '0', 4) === 0 && !$hasInvoices && $o->getState() === Order::STATE_PENDING_PAYMENT,
            'payment_state' => $o->getState(), 'native' => $o,
        ];
    }

    public function start(string $id): array
    {
        $o = $this->snapshot($id, false);
        $store = $this->stores->getStore($o['scope']);
        $return = Protocol::https($store->getUrl('whollycrypto/payment/returnpage', ['_secure' => true, '_nosid' => true]));
        $callback = Protocol::https($store->getUrl('whollycrypto/payment/notify', ['_secure' => true, '_nosid' => true, 'id' => $id]));
        return $this->process($id, ['ipn_url' => $callback, 'redirect_url' => $return, 'cancel_url' => $return,
            'description' => 'Order ' . $o['native']->getIncrementId()]);
    }

    public function process(string $id, ?array $options = null, ?string $raw = null, array $headers = []): array
    {
        // All native and journal writes must share an InnoDB connection/transaction.
        foreach (['sales_order', 'sales_order_payment', 'sales_invoice', 'sales_payment_transaction', 'whollycrypto_payments'] as $table) {
            $engine = $this->db->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->resource->getTableName($table)]);
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) { throw new \RuntimeException('InnoDB payment tables are required.'); }
        }
        return (new Engine($this->repository, $this->config->settings(...), $this->snapshot(...), $this->apply(...)))
            ->process($id, $options, $raw, $headers);
    }

    public function apply(array $order, array $record, string $decision): void
    {
        /** @var Order $o */
        $o = $order['native'];
        $p = $o->getPayment();
        $previous = $p->getAdditionalInformation('whollycrypto_verification');
        if (!$order['paid'] && $order['payable']) {
            if ($decision === 'paid') {
                $p->setTransactionId('whollycrypto-' . $record['invoice_id']);
                $p->setIsTransactionPending(false)->setIsTransactionClosed(true)->setIsFraudDetected(false);
                // Magento expects base currency for its native accounting. The API
                // matched the original order-currency total before reaching here.
                $p->setCurrencyCode($o->getBaseCurrencyCode());
                $p->registerCaptureNotification((float) $o->getBaseGrandTotal(), true);
                if (!$p->getCreatedInvoice()) { throw new \RuntimeException('Could not register the full order invoice.'); }
            } elseif ($decision === 'expired') {
                if (!$o->canCancel()) { throw new \RuntimeException('The expired order needs manual review.'); }
                $o->cancel();
            }
        }
        if ($previous !== $decision) {
            $o->addCommentToStatusHistory(__('Wholly Crypto invoice %1: %2. Browser returns do not confirm payment.', $record['invoice_id'], $decision))
                ->setIsCustomerNotified(false)->setIsVisibleOnFront(false);
        }
        $p->setAdditionalInformation('whollycrypto_invoice_id', $record['invoice_id']);
        $p->setAdditionalInformation('whollycrypto_verification', $decision);
        $p->setAdditionalInformation('whollycrypto_checkout_url', $record['checkout_url']);
        // Saving the aggregate persists related native invoice/transaction objects.
        // The enclosing journal transaction rolls everything back on failure.
        $this->orderResource->save($o);
    }

    public function reconcile(int $limit = 50): array
    {
        $result = ['checked' => 0, 'failed' => 0, 'errors' => []];
        foreach ($this->repository->next($limit) as $id) {
            try { $this->process((string) $id); ++$result['checked']; }
            catch (\Throwable $e) {
                ++$result['failed'];
                $result['errors'][] = ['order_id' => (string) $id, 'message' => Protocol::error($e)];
                // Rotate failed rows too; one unavailable connection must not starve the queue.
                $this->db->update($this->resource->getTableName('whollycrypto_payments'), ['updated_at' => gmdate('Y-m-d H:i:s')], ['order_id = ?' => $id]);
            }
        }
        return $result;
    }
}
