<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Sdk;
use WhollyCrypto\Payment\Sdk\Internal\JsonClient;
use WhollyCrypto\Payment\Sdk\Internal\Validation;
use WhollyCrypto\Payment\Sdk\Http\TransportInterface;

/** Trusted-server integration. Never put Marketplace payout keys in customer code. */
final class MarketplaceClient
{
    private JsonClient $http;
    public function __construct(string $baseUrl,
        #[\SensitiveParameter]
        string $apiToken, ?Options $options = null, ?TransportInterface $transport = null)
    {
        if (!preg_match('/\Awc_marketplace_[a-f0-9]{32}_[a-f0-9]{64}\z/D', $apiToken)) {
            throw new \InvalidArgumentException('Use a separate scoped Marketplace API key.');
        }
        $this->http = new JsonClient($baseUrl, $apiToken, $options, $transport);
    }
    public static function newIdempotencyKey(): string { return Client::newIdempotencyKey(); }
    public function lastResponse(): ?\WhollyCrypto\Payment\Sdk\Http\Response { return $this->http->lastResponse(); }
    public function __debugInfo(): array { return ['client' => 'MarketplaceClient']; }
    public function __serialize(): array { throw new \LogicException('API clients must not be serialized.'); }
    private function scoped(string $projectId, array $data): array
    {
        $id = Validation::uuid($projectId);
        if (array_key_exists('project_id',$data) && $data['project_id'] !== $id) throw new \InvalidArgumentException('Conflicting project_id.');
        $data['project_id'] = $id;
        return $data;
    }
    private function read(string $projectId, string $path, array $filters): array
    { return $this->http->request('GET', $path, $this->scoped($projectId, $filters)); }
    private function money(array $body): void
    {
        foreach ($body as $key => $value) {
            if ($value === null) continue;
            if (in_array($key, ['amount','gross_amount','commission_percent','exchange_rate_spread_percent','underpayment_tolerance_percent'],true)) Validation::decimal($value,(string)$key);
            if (substr((string)$key,-7) === '_atomic' && (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,77})\z/D',$value))) throw new \InvalidArgumentException('Atomic amounts must be exact nonnegative integer strings.');
        }
        foreach (['allocations','stores'] as $key) if (isset($body[$key])) {
            if (!is_array($body[$key])) throw new \InvalidArgumentException('Expected a list.');
            foreach ($body[$key] as $row) {
                if (!is_array($row)) throw new \InvalidArgumentException('Expected an object.');
                $this->money($row);
            }
        }
    }
    private function write(string $projectId, string $path, array $data, string $idempotencyKey): array
    {
        if (!preg_match('/\A[A-Za-z0-9_.-]{16,128}\z/D',$idempotencyKey)) throw new \InvalidArgumentException('Persist a 16–128 character idempotency key before sending.');
        $this->money($data);
        return $this->http->request('POST',$path,[],$this->scoped($projectId,$data),$idempotencyKey);
    }
    /** GET /v1/marketplace/capabilities; marketplace.read. */
    public function capabilities(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/capabilities', $filters);
    }
    /** GET /v1/marketplace/overview; marketplace.read. */
    public function overview(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/overview', $filters);
    }
    /** GET /v1/marketplace/settings; marketplace.read. */
    public function settings(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/settings', $filters);
    }
    /** POST /v1/marketplace/settings; policies.write. */
    public function saveSettings(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/settings', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/vendors; marketplace.read. */
    public function listVendors(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/vendors', $filters);
    }
    /** POST /v1/marketplace/vendors; vendors.write. */
    public function createVendor(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/vendors', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/vendors/{vendor_id}; marketplace.read. */
    public function getVendor(string $projectId, string $vendorId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '', $filters);
    }
    /** POST /v1/marketplace/vendors/{vendor_id}/update; vendors.write. */
    public function updateVendor(string $projectId, string $vendorId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/update', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/vendors/{vendor_id}/archive; vendors.write. */
    public function archiveVendor(string $projectId, string $vendorId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/archive', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/vendors/{vendor_id}/destinations; marketplace.read. */
    public function listDestinations(string $projectId, string $vendorId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/destinations', $filters);
    }
    /** POST /v1/marketplace/vendors/{vendor_id}/destinations; destinations.write. */
    public function addDestination(string $projectId, string $vendorId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/destinations', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/vendors/{vendor_id}/destinations/{destination_id}/approve; destinations.approve. */
    public function approveDestination(string $projectId, string $vendorId, string $destinationId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/destinations/' . Validation::uuid($destinationId) . '/approve', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/vendors/{vendor_id}/destinations/{destination_id}/disable; destinations.approve. */
    public function disableDestination(string $projectId, string $vendorId, string $destinationId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/destinations/' . Validation::uuid($destinationId) . '/disable', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/vendors/{vendor_id}/balances; marketplace.read. */
    public function vendorBalances(string $projectId, string $vendorId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/vendors/' . Validation::uuid($vendorId) . '/balances', $filters);
    }
    /** GET /v1/marketplace/invoices; marketplace.read. */
    public function listInvoices(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/invoices', $filters);
    }
    /** POST /v1/marketplace/invoices; invoices.write. */
    public function createInvoice(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/invoices', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/invoices/{invoice_id}; marketplace.read. */
    public function getInvoice(string $projectId, string $invoiceId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '', $filters);
    }
    /** GET /v1/marketplace/invoices/{invoice_id}/payments; marketplace.read. */
    public function invoicePayments(string $projectId, string $invoiceId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/payments', $filters);
    }
    /** GET /v1/marketplace/invoices/{invoice_id}/allocations; marketplace.read. */
    public function invoiceAllocations(string $projectId, string $invoiceId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/allocations', $filters);
    }
    /** POST /v1/marketplace/invoices/{invoice_id}/cancel; invoices.write. */
    public function cancelInvoice(string $projectId, string $invoiceId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/cancel', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/invoices/{invoice_id}/allocations/hold; reconciliation.write. */
    public function holdInvoice(string $projectId, string $invoiceId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/allocations/hold', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/invoices/{invoice_id}/allocations/release; reconciliation.write. */
    public function releaseInvoice(string $projectId, string $invoiceId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/allocations/release', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/invoices/{invoice_id}/allocations/{allocation_id}/destination; reconciliation.write. */
    public function rebindDestination(string $projectId, string $invoiceId, string $allocationId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/allocations/' . Validation::uuid($allocationId) . '/destination', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/invoices/{invoice_id}/refund; reconciliation.write. */
    public function refundInvoice(string $projectId, string $invoiceId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/invoices/' . Validation::uuid($invoiceId) . '/refund', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/allocations; marketplace.read. */
    public function allocations(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/allocations', $filters);
    }
    /** GET /v1/marketplace/balances; marketplace.read. */
    public function balances(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/balances', $filters);
    }
    /** GET /v1/marketplace/ledger; marketplace.read. */
    public function ledger(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/ledger', $filters);
    }
    /** GET /v1/marketplace/payouts; marketplace.read. */
    public function listPayouts(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/payouts', $filters);
    }
    /** POST /v1/marketplace/payouts/preview; payouts.write. */
    public function previewPayout(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/payouts/preview', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/payouts; payouts.write. */
    public function createPayout(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/payouts', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/payouts/{payout_id}; marketplace.read. */
    public function getPayout(string $projectId, string $payoutId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/payouts/' . Validation::uuid($payoutId) . '', $filters);
    }
    /** POST /v1/marketplace/payouts/{payout_id}/approve; payouts.approve. */
    public function approvePayout(string $projectId, string $payoutId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/payouts/' . Validation::uuid($payoutId) . '/approve', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/payouts/{payout_id}/resume; payouts.approve. */
    public function resumePayout(string $projectId, string $payoutId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/payouts/' . Validation::uuid($payoutId) . '/resume', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/payouts/{payout_id}/cancel; payouts.write. */
    public function cancelPayout(string $projectId, string $payoutId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/payouts/' . Validation::uuid($payoutId) . '/cancel', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/policies; marketplace.read. */
    public function listPolicies(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/policies', $filters);
    }
    /** POST /v1/marketplace/policies; policies.write. */
    public function savePolicy(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/policies', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/webhooks; marketplace.read. */
    public function listWebhooks(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/webhooks', $filters);
    }
    /** POST /v1/marketplace/webhooks; webhooks.write. */
    public function createWebhook(string $projectId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/webhooks', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/webhooks/{webhook_id}/update; webhooks.write. */
    public function updateWebhook(string $projectId, string $webhookId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/webhooks/' . Validation::uuid($webhookId) . '/update', $data, $idempotencyKey);
    }
    /** POST /v1/marketplace/webhooks/{webhook_id}/disable; webhooks.write. */
    public function disableWebhook(string $projectId, string $webhookId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/webhooks/' . Validation::uuid($webhookId) . '/disable', $data, $idempotencyKey);
    }
    /** GET /v1/marketplace/events; marketplace.read. */
    public function listEvents(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/events', $filters);
    }
    /** GET /v1/marketplace/events/{event_id}; marketplace.read. */
    public function getEvent(string $projectId, string $eventId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/events/' . Validation::uuid($eventId) . '', $filters);
    }
    /** GET /v1/marketplace/webhook-deliveries; marketplace.read. */
    public function listDeliveries(string $projectId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/webhook-deliveries', $filters);
    }
    /** GET /v1/marketplace/webhook-deliveries/{delivery_id}; marketplace.read. */
    public function getDelivery(string $projectId, string $deliveryId, array $filters = []): array
    {
        return $this->read($projectId, '/v1/marketplace/webhook-deliveries/' . Validation::uuid($deliveryId) . '', $filters);
    }
    /** POST /v1/marketplace/webhook-deliveries/{delivery_id}/resend; webhooks.write. */
    public function resendDelivery(string $projectId, string $deliveryId, array $data, string $idempotencyKey): array
    {
        return $this->write($projectId, '/v1/marketplace/webhook-deliveries/' . Validation::uuid($deliveryId) . '/resend', $data, $idempotencyKey);
    }
}
