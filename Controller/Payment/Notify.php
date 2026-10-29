<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Controller\Payment;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

/** Only this server-to-server route bypasses form keys; HMAC authenticates raw bytes. */
class Notify implements \Magento\Framework\App\Action\HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private RequestInterface $request,
        private \Magento\Framework\Controller\Result\JsonFactory $json,
        private \WhollyCrypto\Payment\Model\Gateway $gateway
    ) {}
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException { return null; }
    public function validateForCsrf(RequestInterface $request): ?bool { return true; }
    public function execute()
    {
        $result = $this->json->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        try {
            $raw = (string) $this->request->getContent();
            if (strlen($raw) > 262144 || $raw === '') { return $result->setHttpResponseCode(400)->setData(['ok' => false]); }
            $headers = [];
            foreach (['Wholly-Signature', 'Wholly-Event-Id', 'Wholly-Delivery-Id'] as $name) { $headers[$name] = (string) $this->request->getHeader($name); }
            $this->gateway->process((string) $this->request->getParam('id'), null, $raw, $headers);
            return $result->setData(['ok' => true]);
        } catch (\WhollyCrypto\Payment\Sdk\Exception\InvalidSignatureException | \InvalidArgumentException $e) {
            return $result->setHttpResponseCode(400)->setData(['ok' => false, 'error' => 'invalid_notification']);
        } catch (\Throwable $e) {
            // Retryable non-2xx. No bodies, email addresses or credentials in logs/responses.
            return $result->setHttpResponseCode(503)->setData(['ok' => false, 'error' => 'verification_unavailable']);
        }
    }
}
