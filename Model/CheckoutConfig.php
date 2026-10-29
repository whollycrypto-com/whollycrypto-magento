<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Model;

class CheckoutConfig implements \Magento\Checkout\Model\ConfigProviderInterface
{
    public function __construct(private \Magento\Framework\UrlInterface $url) {}
    public function getConfig(): array
    {
        return ['payment' => ['whollycrypto' => ['startUrl' => $this->url->getUrl('whollycrypto/payment/start', ['_secure' => true])]]];
    }
}
