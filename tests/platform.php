<?php
declare(strict_types=1);
$root = getenv('WHOLLY_TEST_MAGENTO_ROOT');
if (!$root || !getenv('WHOLLY_TEST_API_ORIGIN')) { throw new RuntimeException('Disposable Magento and local HTTPS fixture required.'); }
require $root . '/app/bootstrap.php';
$bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$db = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
if ($db->fetchOne('SELECT DATABASE()') !== 'wholly_magento') { throw new RuntimeException('Refusing a non-fixture database.'); }
function check($value, string $label): void { if (!$value) { throw new RuntimeException($label); } }
function reject(callable $call, string $label): void {
    $thrown = false; try { $call(); } catch (Throwable $e) { $thrown = true; }
    check($thrown, $label);
}
function control(array $values): void {
    $h = curl_init(getenv('WHOLLY_TEST_API_ORIGIN') . '/control');
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($values), CURLOPT_RETURNTRANSFER => true]);
    check(curl_exec($h) !== false, 'fixture control'); curl_close($h);
}
$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$enc = $om->get(\Magento\Framework\Encryption\EncryptorInterface::class);
$settings = ['api_url' => getenv('WHOLLY_TEST_API_ORIGIN'), 'pay_url' => 'https://pay.example.test',
    'project_id' => '11111111-1111-4111-8111-111111111111', 'store_id' => '22222222-2222-4222-8222-222222222222',
    'api_key' => str_repeat('a', 40), 'ipn_secret' => str_repeat('b', 40), 'active' => '1'];
foreach ($settings as $key => $value) { $writer->save('payment/whollycrypto/' . $key, in_array($key, ['api_key', 'ipn_secret'], true) ? $enc->encrypt($value) : $value); }
$writer->save('system/smtp/disable', '1');
$om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
$gateway = $om->get(\WhollyCrypto\Payment\Model\Gateway::class);
$orderResource = $om->get(\Magento\Sales\Model\ResourceModel\Order::class);
$create = function (string $currency = 'EUR', string $base = 'EUR') use ($om, $orderResource): string {
    $o = $om->create(\Magento\Sales\Model\Order::class);
    $baseAmount = $base === $currency ? 25 : 30;
    $o->setStoreId(1)->setIncrementId('WC' . bin2hex(random_bytes(5)))->setCustomerIsGuest(true)
        ->setCustomerEmail('buyer@example.test')->setCustomerFirstname('Fixture')->setCustomerLastname('Buyer')
        ->setOrderCurrencyCode($currency)->setBaseCurrencyCode($base)->setGlobalCurrencyCode($base)->setStoreCurrencyCode($currency)
        ->setBaseToOrderRate(25 / $baseAmount)->setStoreToOrderRate(1)->setBaseToGlobalRate(1)->setIsVirtual(true)
        ->setSubtotal(25)->setBaseSubtotal($baseAmount)->setGrandTotal(25)->setBaseGrandTotal($baseAmount)
        ->setTotalQtyOrdered(1)->setEmailSent(false);
    $item = $om->create(\Magento\Sales\Model\Order\Item::class);
    $item->setStoreId(1)->setSku('wc-fixture')->setName('Fixture item')->setProductType('virtual')->setIsVirtual(true)
        ->setQtyOrdered(1)->setPrice(25)->setBasePrice($baseAmount)->setRowTotal(25)->setBaseRowTotal($baseAmount)
        ->setPriceInclTax(25)->setBasePriceInclTax($baseAmount)->setRowTotalInclTax(25)->setBaseRowTotalInclTax($baseAmount);
    $o->addItem($item);
    $address = $om->create(\Magento\Sales\Model\Order\Address::class);
    $address->setFirstname('Fixture')->setLastname('Buyer')->setStreet('Test Street 1')->setCity('Test')->setPostcode('12345')->setCountryId('DE')->setTelephone('123456789');
    $o->setBillingAddress($address);
    $o->setPayment($om->create(\Magento\Sales\Model\Order\Payment::class)->setMethod('whollycrypto'));
    $o->place(); $orderResource->save($o);
    check($o->getState() === 'pending_payment', 'native method starts pending payment');
    return (string) $o->getId();
};
$id = $create();
$r = $gateway->start($id);
check($r['decision'] === 'pending', 'new invoice is pending');
check($r['payload']['amount'] === '25', 'persisted exact order total');
check($gateway->start($id)['invoice_id'] === $r['invoice_id'], 'customer retry reuses invoice');
check(!$gateway->snapshot($id, false)['paid'], 'creation never settles');
$event = array_replace($r['payload'], ['invoice_id' => $r['invoice_id'], 'project_id' => $settings['project_id'], 'store_id' => $settings['store_id'],
    'payload_version' => 2, 'status' => 'settled', 'sequence' => 3, 'event_type' => 'invoice.settled', 'event_id' => '44444444-4444-4444-8444-444444444444']);
$raw = json_encode($event); $ts = (string) time();
$headers = ['Wholly-Signature' => 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $raw, $settings['ipn_secret']),
    'Wholly-Event-Id' => $event['event_id'], 'Wholly-Delivery-Id' => '55555555-5555-4555-8555-555555555555'];
$request = $om->get(\Magento\Framework\App\RequestInterface::class);
$notify = function (string $body, array $httpHeaders) use ($om, $request, $id): int {
    $request->setMethod('POST')->setParam('id', $id)->setContent($body);
    foreach (['Wholly-Signature', 'Wholly-Event-Id', 'Wholly-Delivery-Id'] as $name) {
        $old = $request->getHeaders()->get($name);
        if ($old) { $request->getHeaders()->removeHeader($old); }
        $request->getHeaders()->addHeaderLine($name, $httpHeaders[$name] ?? '');
    }
    $response = $om->create(\Magento\Framework\App\Response\Http::class);
    $om->create(\WhollyCrypto\Payment\Controller\Payment\Notify::class)->execute()->renderResult($response);
    return $response->getHttpResponseCode();
};
check($notify($raw, $headers) === 200, 'native signed HTTP callback');
check($notify($raw . ' ', $headers) === 400, 'native invalid signature rejected');
check($notify(str_repeat('x', 262145), $headers) === 400, 'native body limit');
check($gateway->process($id, null, $raw, $headers)['decision'] === 'pending', 'signed trigger alone cannot settle');
reject(fn () => $gateway->process($id, null, $raw . ' ', $headers), 'invalid signature rejected');
control(['status' => 'processing', 'amount_status' => 'partial', 'sequence' => 2]);
check($gateway->process($id)['decision'] === 'pending', 'partial payment stays pending');
control(['status' => 'settled', 'amount_status' => 'paid', 'sequence' => 3]);
$db->getConnection()->exec("CREATE TRIGGER wholly_fixture_write_failure BEFORE UPDATE ON whollycrypto_payments FOR EACH ROW BEGIN IF @wholly_fail_write=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture journal failure'; END IF; END");
$db->query('SET @wholly_fail_write=1');
reject(fn () => $gateway->process($id), 'journal write failure propagates');
$db->query('SET @wholly_fail_write=0');
$db->query('DROP TRIGGER wholly_fixture_write_failure');
check(!$gateway->snapshot($id, false)['paid'], 'native capture rolls back with journal');
check((int) $db->fetchOne('SELECT COUNT(*) FROM sales_invoice WHERE order_id=?', [$id]) === 0, 'no invoice survived rollback');
$r = $gateway->process($id);
check($r['decision'] === 'paid' && $gateway->snapshot($id, false)['paid'], 'native capture settles');
$invoice = $db->fetchRow('SELECT * FROM sales_invoice WHERE order_id=?', [$id]);
check((int) $invoice['state'] === 2, 'native invoice is paid');
check(bccomp($invoice['grand_total'], '25', 4) === 0, 'native invoiced amount');
check($gateway->process($id, null, $raw, $headers)['decision'] === 'paid', 'duplicate signed event succeeds');
check((int) $db->fetchOne('SELECT COUNT(*) FROM sales_invoice WHERE order_id=?', [$id]) === 1, 'one invoice after replay');
check((int) $db->fetchOne('SELECT COUNT(*) FROM sales_payment_transaction WHERE order_id=?', [$id]) === 1, 'one native capture');
control(['status' => 'invalid', 'sequence' => 4]);
check($gateway->process($id)['decision'] === 'review', 'reorg review');
check($gateway->snapshot($id, false)['paid'], 'paid accounting preserved');

// Different base currency must use the original native accounting conversion.
control(['status' => 'new', 'amount_status' => 'none', 'sequence' => 1]);
$converted = $create('EUR', 'USD'); $gateway->start($converted);
control(['status' => 'settled', 'amount_status' => 'paid', 'sequence' => 3]);
$gateway->process($converted);
$o = $gateway->order($converted);
check(bccomp((string) $o->getTotalPaid(), '25', 4) === 0, 'order currency paid');
check(bccomp((string) $o->getBaseTotalPaid(), '30', 4) === 0, 'base currency paid');

foreach ([['expired', 'none', 'expired'], ['expired', 'partial', 'review'], ['settled', 'overpaid', 'review']] as [$status, $amount, $expected]) {
    control(['status' => 'new', 'amount_status' => 'none', 'sequence' => 1]);
    $next = $create(); $gateway->start($next);
    control(['status' => $status, 'amount_status' => $amount, 'sequence' => 3]);
    check($gateway->process($next)['decision'] === $expected, 'native exceptional decision');
    check(!$gateway->snapshot($next, false)['paid'], 'exception cannot settle');
    if ($expected === 'expired') {
        check($gateway->order($next)->getState() === 'canceled', 'unpaid expiration cancels');
        check($gateway->process($next)['decision'] === 'expired', 'duplicate expiration remains cancelled');
    }
}
control(['status' => 'new', 'amount_status' => 'none', 'sequence' => 1]);
$changed = $create(); $gateway->start($changed);
$db->update('sales_order', ['grand_total' => '26'], ['entity_id = ?' => $changed]);
control(['status' => 'settled', 'amount_status' => 'paid', 'sequence' => 3]);
reject(fn () => $gateway->process($changed), 'edited order total blocked');
check(!$gateway->snapshot($changed, false)['paid'], 'edited order remains unpaid');
$db->update('whollycrypto_payments', ['updated_at' => '2000-01-01 00:00:00'], ['order_id = ?' => $changed]);
$batch = $gateway->reconcile(1);
check($batch['failed'] === 1 && $batch['errors'][0]['order_id'] === $changed, 'bounded recovery identifies the failed order safely');
check($db->fetchOne('SELECT updated_at FROM whollycrypto_payments WHERE order_id=?', [$changed]) > '2000-01-01 00:00:00', 'failed row rotates out of the queue');
// Browser actions do not settle; starting payment retains native CSRF and session guards.
$request->setParam('form_key', 'invalid');
$redirect = $om->create(\WhollyCrypto\Payment\Controller\Payment\Redirect::class)->execute();
check($redirect instanceof \Magento\Framework\Controller\Result\Redirect, 'invalid form returns safe local redirect');
$before = (int) $db->fetchOne('SELECT COUNT(*) FROM sales_invoice');
$om->create(\WhollyCrypto\Payment\Controller\Payment\Returnpage::class)->execute();
check((int) $db->fetchOne('SELECT COUNT(*) FROM sales_invoice') === $before, 'browser return cannot capture');
echo "Native Magento: order initialization, create/retry, signed trigger, partial, rollback, settlement, replay, reorg, currency conversion and exception checks passed.\n";
