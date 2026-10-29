<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Model\Config;

use Magento\Framework\Exception\LocalizedException;
use WhollyCrypto\Payment\Core\Protocol;

class Validate extends \Magento\Framework\App\Config\Value
{
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if ($value !== '') {
            try {
                $field = substr((string) $this->getPath(), strrpos((string) $this->getPath(), '/') + 1);
                $value = in_array($field, ['api_url', 'pay_url'], true) ? Protocol::https($value, true) : Protocol::uuid($value);
            } catch (\InvalidArgumentException $e) { throw new LocalizedException(__($e->getMessage())); }
        }
        $this->setValue($value);
        return parent::beforeSave();
    }
}
