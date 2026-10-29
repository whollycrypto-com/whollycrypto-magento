# Tests and staging

Protocol and real TLS tests use synthetic local data, never a merchant account:

```sh
php tests/protocol.php
python3 tests/https-flow.py
python3 tools/verify.py
```

`tests/journal.php` requires `WHOLLY_TEST_DB_SOCKET` pointing to a disposable database containing `wholly_journal`. It tests transaction rollback, invoice uniqueness, competing connections and InnoDB guards. Do not point test scripts at a live shop.

Native tests use a disposable Magento Open Source 2.4.8-p5 installation with its actual dependencies, MariaDB 11.4 and OpenSearch 2.19. They require `WHOLLY_TEST_MAGENTO_ROOT`, a database named exactly `wholly_magento`, and the installed module. Run `WHOLLY_TEST_FLOW=platform.php python3 tests/https-flow.py`; `PHP_BINARY` can select the platform PHP with its required extensions. Test fixtures never make real blockchain payments or send customer email.

`tests/schema.php` checks every module XML file against Magento's own XSDs. `tests/checkout.php` creates a synthetic guest cart and renders Magento's actual frontend layout to `WHOLLY_TEST_HTML_DIRECTORY`. After native static-content deployment, `tests/storefront.mjs` loads that HTML and the real RequireJS/Knockout assets in Playwright at desktop/mobile sizes. Requests are intercepted at the synthetic shop origin; REST totals use the native saved cart fixture. Set `PLAYWRIGHT_MODULE` if Playwright is installed outside this checkout. The browser verifies the payment choice, redirect sequencing and absence of client errors; it does not place a real order or payment.

Before enabling production:

1. Install the ZIP in staging. Run upgrade, DI compilation, locale static deployment and cache flush. Check module discovery, configuration inheritance and encrypted secret masking.
2. Use the standard Magento checkout with guest and registered shoppers, physical and virtual products, shipping, taxes, discounts, your currencies and store views.
3. Create an order and retry the connection: verify one Wholly invoice, the unchanged decimal total and Pending Payment in Magento.
4. Verify your public callback can receive HTTPS POST without a challenge. Send a real small payment on staging: partial/unconfirmed stays pending; settled creates exactly one paid invoice. Returning early never settles.
5. Replay a callback, interrupt API access, run reconciliation, and inspect order Payment Information/comments. No duplicate native capture should appear.
6. Check expiration without payment, late/overpaid review, changed/cancelled orders and post-payment invalidation. Check your fulfillment extensions do not ship a review/pending order.
7. Test missed callback recovery with Magento cron and the CLI. Confirm configuration changes fail safely for old invoices until the original settings are restored.

The native fixture does not certify Adobe Commerce-specific modules, Hyvä/PWA/custom checkout, all themes, tax rules or shipping extensions. Test those separately before using real customer orders.
