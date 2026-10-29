<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Model;

use Magento\Sales\Model\Order;

/** Redirect method. No capture, refund or wallet credentials in Magento. */
class Method extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'whollycrypto';
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;
    protected $_canCapture = false;
    protected $_canRefund = false;
    protected $_canVoid = false;
    protected $_canCancelInvoice = false;
    protected $_infoBlockType = \WhollyCrypto\Payment\Block\Info::class;

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
        return $this;
    }
}
