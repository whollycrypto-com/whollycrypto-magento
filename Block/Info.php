<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Block;

class Info extends \Magento\Payment\Block\Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        foreach (['whollycrypto_invoice_id' => 'Wholly Crypto invoice', 'whollycrypto_verification' => 'Payment verification'] as $key => $label) {
            $value = $this->getInfo()->getAdditionalInformation($key);
            if (is_string($value) && $value !== '') { $transport->setData((string) __($label), $value); }
        }
        return $transport;
    }
}
