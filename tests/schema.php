<?php
declare(strict_types=1);
$root = getenv('WHOLLY_TEST_MAGENTO_ROOT');
if (!$root) { throw new RuntimeException('Disposable Magento root required.'); }
require $root . '/app/bootstrap.php';
$resolver = new \Magento\Framework\Config\Dom\UrnResolver();
libxml_set_external_entity_loader([$resolver, 'registerEntityLoader']);
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'xml') { continue; }
    $doc = new DOMDocument(); $doc->load($file->getPathname());
    $urn = $doc->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'noNamespaceSchemaLocation');
    if ($urn && !$doc->schemaValidate($resolver->getRealPath($urn))) { throw new RuntimeException('Invalid native XML: ' . $file->getFilename()); }
    ++$count;
}
echo "Native Magento XSDs: $count XML files passed.\n";
