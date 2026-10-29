<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Block;

class Checkout extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private \Magento\Checkout\Model\Session $session,
        private \WhollyCrypto\Payment\Model\Gateway $gateway,
        array $data = []
    ) { parent::__construct($context, $data); }

    public function summary(): array
    {
        try {
            $o = $this->gateway->order((string) $this->session->getLastOrderId());
            if ($o->getIncrementId() !== $this->session->getLastRealOrderId() || $o->getPayment()->getMethod() !== 'whollycrypto') { return []; }
            $verification = $o->getPayment()->getAdditionalInformation('whollycrypto_verification');
            return ['number' => $o->getIncrementId(), 'verification' => $verification ?: 'pending',
                'can_pay' => $o->getState() === \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT && in_array($verification, [null, '', 'pending'], true)];
        } catch (\Throwable $e) { return []; }
    }
}
