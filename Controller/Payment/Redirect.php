<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use WhollyCrypto\Payment\Core\Protocol;

/** Native form-key CSRF validation stays enabled. Only the last checkout order is eligible. */
class Redirect implements HttpPostActionInterface
{
    public function __construct(
        private \Magento\Checkout\Model\Session $session,
        private \WhollyCrypto\Payment\Model\Gateway $gateway,
        private \Magento\Framework\Controller\Result\RedirectFactory $redirects,
        private \Magento\Framework\Message\ManagerInterface $messages,
        private \Magento\Framework\App\RequestInterface $request,
        private \Magento\Framework\Data\Form\FormKey\Validator $formKey
    ) {}
    public function execute()
    {
        $result = $this->redirects->create();
        try {
            if (!$this->formKey->validate($this->request)) { throw new \RuntimeException('Invalid form key.'); }
            $id = (string) $this->session->getLastOrderId();
            $order = $this->gateway->order($id);
            if ($order->getIncrementId() !== $this->session->getLastRealOrderId()
                || $order->getPayment()->getMethod() !== 'whollycrypto') { throw new \RuntimeException('Order session mismatch.'); }
            $record = $this->gateway->start($id);
            if ($record['decision'] !== 'pending') { return $result->setPath('whollycrypto/payment/returnpage', ['_secure' => true]); }
            return $result->setUrl($record['checkout_url']);
        } catch (\Throwable $e) {
            $this->messages->addErrorMessage(__(Protocol::error($e)));
            return $result->setPath('whollycrypto/payment/start', ['_secure' => true, 'retry' => 1]);
        }
    }
}
