<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use WhollyCrypto\Payment\Core\Protocol;

class Config
{
    public function __construct(private ScopeConfigInterface $config, private EncryptorInterface $encryptor) {}

    public function settings(string $storeId): array
    {
        $result = [];
        foreach (['api_url', 'pay_url', 'project_id', 'store_id', 'api_key', 'ipn_secret'] as $key) {
            $value = (string) $this->config->getValue('payment/whollycrypto/' . $key, ScopeInterface::SCOPE_STORE, $storeId);
            $result[$key] = in_array($key, ['api_key', 'ipn_secret'], true) ? $this->encryptor->decrypt($value) : $value;
        }
        return Protocol::settings($result);
    }
}
