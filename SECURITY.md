# Security

Never include API credentials, signing secrets, customer data or wallet keys in a public issue. Use [Wholly Crypto contact](https://www.whollycrypto.com/contact/) for a private vulnerability report.

Credentials stay on the server and are encrypted with Magento's installation key. API requests verify TLS, reject redirects, and have bounded time/response sizes. Checkout redirects must match the configured HTTPS origin and the verified invoice UUID exactly.

IPN authenticates the raw body with Wholly's timestamped HMAC; invoice identity and amounts are then verified against an authenticated API read. Browser redirects never mark orders paid. Per-order database locks, a unique invoice association and atomic native/journal writes prevent duplicate captures and partial commits. Only the notification route bypasses form keys.

Do not expose Magento's database or configuration. Use project-restricted API credentials. Preserve the original connection for outstanding invoices. Treat ambiguous, overpaid, late and changed orders as manual review. An administrator/root user can change shop records; this plugin cannot protect a compromised host.

See README.md for supported checkout scope and docs/testing.md for staging checks. Neither this plugin nor its tests send blockchain transactions.
