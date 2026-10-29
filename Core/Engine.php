<?php
declare(strict_types=1);

namespace WhollyCrypto\Payment\Core;

/** Native adapters supply settings, an authoritative order snapshot and state application. */
final class Engine
{
    private Repository $repository;
    private $settings;
    private $snapshot;
    private $apply;

    public function __construct(Repository $repository, callable $settings, callable $snapshot, callable $apply)
    {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->snapshot = $snapshot;
        $this->apply = $apply;
    }

    public function process(string $id, ?array $options = null, ?string $raw = null, array $headers = []): array
    {
        return $this->repository->locked($id, function () use ($id, $options, $raw, $headers): array {
            $order = ($this->snapshot)($id, false);
            $settings = Protocol::settings(($this->settings)($order['scope']));
            $record = $this->repository->load($id);
            if (!$record) {
                if ($options === null || !$order['payable']) {
                    throw new \RuntimeException('No payable order or saved payment attempt.');
                }
                $record = Protocol::attempt($settings, $id, $order['reference'], $order['amount'], $order['currency'], $options);
                $record['transaction_id'] = $order['transaction_id'];
                $record['scope'] = $order['scope'];
                // Commit the idempotency key and exact request before any network request.
                $this->repository->save($record);
            }
            self::assertOrder($record, $order);
            if ($raw !== null) {
                Protocol::notification($record, $raw, $headers, $settings['ipn_secret']);
            }
            // A callback is only a trigger; its status never determines fulfillment.
            $verified = Protocol::fetch($settings, $record);
            return $this->repository->atomic(function () use ($id, $verified): array {
                $order = ($this->snapshot)($id, true);
                self::assertOrder($verified, $order);
                $decision = Protocol::decision($verified);
                $alreadyCancelled = $verified['applied'] === 'expired' && $decision === 'expired'
                    && $order['payment_state'] === 'canceled';
                if (!$order['payable'] && !$order['paid'] && !$alreadyCancelled) { $decision = 'review'; }
                if ($order['paid'] && $decision !== 'paid') { $decision = 'review'; }
                $verified['decision'] = $decision;
                ($this->apply)($order, $verified, $decision);
                $verified['applied'] = $decision;
                $this->repository->save($verified);
                return $verified;
            });
        });
    }

    public static function assertOrder(array $record, array $order): void
    {
        if ($record['payload']['amount'] !== $order['amount'] || $record['payload']['currency'] !== $order['currency']
            || $record['payload']['order_id'] !== $order['reference'] || $record['transaction_id'] !== $order['transaction_id']
            || $record['scope'] !== $order['scope'] || !$order['ours']) {
            throw new \RuntimeException('Order, total, currency or payment method changed. Manual reconciliation required.');
        }
    }
}
