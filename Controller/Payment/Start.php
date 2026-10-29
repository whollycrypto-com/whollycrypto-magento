<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Controller\Payment;

class Start implements \Magento\Framework\App\Action\HttpGetActionInterface
{
    public function __construct(private \Magento\Framework\View\Result\PageFactory $pages) {}
    public function execute() { return $this->pages->create(); }
}
