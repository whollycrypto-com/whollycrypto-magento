# Public Magento payment integration

Independent public MIT repository. Read README.md and docs/testing.md.
Only this integration and its isolated public PHP SDK belong here. Never add
application backend source, private helpers, credentials, databases or wallet data.
Core/ owns the payment protocol and journal; Model/Gateway.php owns native orders.
Run protocol, local HTTPS, native Magento transaction/rollback tests and package
verification before publishing. Use disposable fixtures, never a live shop.
tools/build.py allowlists install files. SDK provenance: vendor/whollycrypto/UPSTREAM.json.
