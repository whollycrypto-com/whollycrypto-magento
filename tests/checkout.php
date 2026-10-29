<?php
declare(strict_types=1);
// Render the actual Magento storefront, never a live installation.
$root = getenv('WHOLLY_TEST_MAGENTO_ROOT');
$output = getenv('WHOLLY_TEST_HTML_DIRECTORY');
if (!$root || !$output || !is_dir($output)) { throw new RuntimeException('Disposable Magento/output directory required.'); }
chdir($root);
$_SERVER['HTTP_HOST'] = 'shop.example.test'; $_SERVER['SERVER_NAME'] = 'shop.example.test';
$_SERVER['REQUEST_URI'] = '/checkout/'; $_SERVER['HTTPS'] = 'on'; $_SERVER['SERVER_PORT'] = 443;
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('frontend'));
$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
if ($db->fetchOne('SELECT DATABASE()') !== 'wholly_magento') { throw new RuntimeException('Not a fixture database.'); }
$stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class); $stores->setCurrentStore(1);
$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$writer->save('design/theme/theme_id', '2');
$om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$om->get(\Magento\Framework\View\DesignInterface::class)->setDesignTheme('Magento/luma');
$product = $om->create(\Magento\Catalog\Model\Product::class);
$sku = 'wholly-fixture-' . bin2hex(random_bytes(4));
$product->setSku($sku)->setUrlKey($sku)->setName('Fixture virtual product')->setTypeId('virtual')
    ->setAttributeSetId(4)->setStatus(1)->setVisibility(4)->setPrice(25)->setTaxClassId(0)->setWebsiteIds([1])
    ->setStockData(['is_in_stock' => 1, 'qty' => 100, 'manage_stock' => 1, 'use_config_manage_stock' => 0]);
$product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->save($product);
$om->get(\Magento\Framework\Indexer\IndexerRegistry::class)->get('cataloginventory_stock')->reindexRow($product->getId());
$product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get($product->getSku(), false, 1, true);
$quote = $om->create(\Magento\Quote\Model\Quote::class);
$quote->setStore($stores->getStore())->setCustomerIsGuest(true)->setCustomerEmail('buyer@example.test')->setCustomerFirstname('Fixture')->setCustomerLastname('Buyer');
$quote->addProduct($product, 1);
$quote->getBillingAddress()->addData(['firstname' => 'Fixture', 'lastname' => 'Buyer', 'email' => 'buyer@example.test', 'street' => 'Test Street 1', 'city' => 'Test', 'postcode' => '12345', 'country_id' => 'DE', 'telephone' => '123456789']);
$quote->getPayment()->setMethod('whollycrypto'); $quote->collectTotals();
$om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->save($quote);
$om->get(\Magento\Checkout\Model\Session::class)->replaceQuote($quote);
$config = $om->get(\Magento\Checkout\Model\CompositeConfigProvider::class)->getConfig();
if (!str_contains($config['payment']['whollycrypto']['startUrl'] ?? '', '/whollycrypto/payment/start/')) { throw new RuntimeException('Missing checkout config.'); }
$page = $om->get(\Magento\Framework\View\Result\PageFactory::class)->create();
$page->addHandle('checkout_index_index');
$response = $om->create(\Magento\Framework\App\Response\Http::class); $page->renderResult($response);
$html = $response->getBody();
file_put_contents($output . '/checkout.html', $html);
if (!str_contains(str_replace('\\/', '/', $html), 'WhollyCrypto_Payment/js/view/payment/register')) { throw new RuntimeException('Payment renderer absent from native checkout layout.'); }
if (str_contains($html, str_repeat('a', 40)) || str_contains($html, str_repeat('b', 40))) { throw new RuntimeException('Private settings leaked into checkout.'); }
file_put_contents($output . '/checkout.html', $html);
file_put_contents($output . '/quote.json', json_encode(['quote_id' => $quote->getId(), 'checkout_config' => $config], JSON_PRETTY_PRINT));
echo "Native Magento checkout: product/guest quote, encrypted settings, frontend DI, layout, renderer and secret-free HTML passed.\n";
