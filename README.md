# Wholly Crypto for Magento 2

Accept Bitcoin, stablecoins and the payment methods enabled in your Wholly Crypto store. Customers choose **Wholly Crypto** in Magento checkout, then pay on your own hosted checkout. Wallet keys stay in Wholly Crypto.

**Magento 2.4, PHP 8.2+, HTTPS, InnoDB and the standard Magento/Luma checkout.** Native integration tests use Magento Open Source 2.4.8-p5 / PHP 8.3. Use the PHP version required by your Magento release. Magento 1, Hyvä Checkout, PWA/headless checkout and Adobe Commerce-specific extensions are not covered by this release's tests.

## Install

1. Back up your shop and test on staging first. Download `whollycrypto-magento-1.0.0.zip` and `SHA256SUMS` from [Releases](https://github.com/whollycrypto-com/whollycrypto-magento/releases). Verify the checksum. Use the install ZIP, not GitHub's automatic source ZIP.
2. Extract the ZIP in your Magento root. It contains `app/code/WhollyCrypto/Payment/`.
3. Run as your Magento filesystem owner:

```sh
php bin/magento module:enable WhollyCrypto_Payment
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f en_US
php bin/magento cache:flush
```

Replace `en_US` with your storefront locales. The public PHP SDK is bundled with an isolated namespace; no extra Composer dependency or frontend build is needed. Normal Magento compilation and static asset deployment still apply.

Alternatively add this Git repository as a Composer VCS repository and require `whollycrypto/module-payment:1.0.0`. Do not install both copies at once. This package is distributed on GitHub; it is not a Magento Marketplace listing.

## Connection settings

Open **Stores → Configuration → Sales → Payment Methods → Wholly Crypto**. Choose the intended Website/Store View scope, then enable the method.

| Setting | Value |
| --- | --- |
| API URL | `https://api.example.com`, without `/v1`. |
| Checkout URL | `https://pay.example.com`, matching your Wholly store's default checkout domain. |
| Project API ID / Store API ID | The UUIDs in Wholly's Project → Stores → Basic → API IDs. |
| API credential | A read/write API credential restricted to the intended project. |
| Store IPN signing secret | Enable Store → IPN and copy its signing secret. No second webhook is required. |

Magento encrypts the credential and signing secret using its installation encryption key and excludes them from configuration dumps. Keep your Magento encryption key/database protected. Do not enter wallet keys or recovery phrases.

Enable your Wholly project/store and its accepted payment methods. Configure Magento's secure Base URL correctly. The plugin generates an HTTPS callback like `https://shop.example.com/whollycrypto/payment/notify/id/123/`, including the correct store view path. Allow this route through firewalls without a login, Basic Auth, CAPTCHA or cached responses. Only this HMAC-authenticated callback bypasses Magento form keys; checkout actions retain CSRF protection.

## Order and payment flow

Magento creates the order in **Pending Payment** before redirecting. The plugin saves the exact decimal order total, currency, order reference and idempotency key before contacting Wholly Crypto. Retries reuse that saved invoice. The Wholly `order_id` is `magento:<Magento store ID>:<order number>`.

| Verified result | Magento action |
| --- | --- |
| New, processing or partial payment | Remain pending; do not ship. |
| Settled, fully paid, on time | Register one native paid Magento invoice/capture transaction. |
| Expired or cancelled, nothing received | Cancel the unpaid order. |
| Overpaid, late, invalid or changed order | Mark the verification for manual review; do not automatically fulfill. |

A signed IPN is a trigger. The plugin also reads the invoice with your API credential and checks its invoice ID, project, store, reference, amount and currency. It does not trust a callback's status or the browser return. Duplicate notifications cannot create a second paid invoice. Magento records both the order currency and its original base-currency accounting amounts.

Order **Payment Information** shows the Wholly invoice ID and verification result. Status changes also appear in order comments. If an already-paid invoice later becomes invalid, the plugin records **review** and preserves the existing Magento accounting; it never silently reverses a capture or refunds funds. Resolve the exception in both systems before shipping/refunding.

Customers who lose their checkout session should contact the shop instead of creating another order. A failed connection offers a retry for the existing order; do not create a replacement invoice manually while the original attempt is uncertain.

## Recover missed updates

With Magento cron running, the plugin checks up to 50 oldest saved records every five minutes. Paid records remain in the rotation for later exceptions. Failed records rotate too. For larger stores, schedule a larger bounded batch or reconcile a particular order:

```sh
php bin/magento whollycrypto:reconcile --limit=100
php bin/magento whollycrypto:reconcile --order=123
```

`--order` uses Magento's numeric order **entity ID**, not the visible increment/order number. The command exits 1 when a check fails. Per-order locks prevent overlapping cron/callback/customer retries from creating duplicate payments. Reconciliation verifies existing attempts, including uncertain creation using the original request; it does not create invoices for unrelated orders.

Keep the original connection settings until outstanding orders are reconciled. Changing API/checkout origins or Project/Store IDs deliberately stops old orders from being fulfilled against a different installation. A known invoice can use a rotated API credential; an uncertain creation requires its original credential for idempotency.

## Fees, scope and updates

The plugin adds no percentage fee. Your Wholly Crypto processing-credit policy still applies. Low credit can pause IPN while customers can still pay: restore credit, resend delivery attempts, or use reconciliation. [Credits](https://www.whollycrypto.com/documentation/#credits) · [IPN and delivery history](https://www.whollycrypto.com/documentation/#delivery-history).

Hosted checkout only. No automated refunds, subscriptions/recurring debits, partial-order invoicing, split-order payments, Magento admin-created payments or embedded checkout. Do not manually capture/invoice a pending Wholly order: that interrupts automatic fulfillment and needs review. Test custom checkout, tax, shipping, currency and fulfillment integrations on staging.

For an update, replace the module files and rerun the install commands. Keep payment records and your installation encryption key. Disabling/removing the module stops callbacks/reconciliation; reconcile all open orders first. **Do not run destructive declarative-schema cleanup or uninstall its journal while payment history is needed.** Back up the database before any module change.

[Testing and staging checklist](docs/testing.md) · [API documentation](https://www.whollycrypto.com/api/) · [Issues](https://github.com/whollycrypto-com/whollycrypto-magento/issues) · [Wholly Crypto](https://www.whollycrypto.com/)

MIT licensed. Magento and Adobe Commerce are separate products and trademarks. This is an independent integration, not an Adobe endorsement.
