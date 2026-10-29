<?php
declare(strict_types=1);
namespace WhollyCrypto\Payment\Controller\Payment;

/** A browser return is informational only. It never creates or settles invoices. */
class Returnpage implements \Magento\Framework\App\Action\HttpGetActionInterface
{
    public function __construct(private \Magento\Framework\View\Result\PageFactory $pages) {}
    public function execute() { return $this->pages->create(); }
}
