<?php
declare(strict_types=1);
require_once __DIR__ . '/autoload.php';
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE, 'WhollyCrypto_Payment', __DIR__
);
