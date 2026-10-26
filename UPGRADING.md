# NVL Light migration

Requires PHP 8.4 and Laravel 13. Replace the previous checkout/contact Actions with `BillingCheckout::start` and `BillingContacts::update`. Checkout sessions and access snapshots are documented native arrays. Consumers own transport, response resources and any application DTOs.

The deprecated Tenancy bridge, package webhook controller and route are removed. Use native ownership admission and an application webhook endpoint calling `BillingWebhooks::process($rawPayload, $signature)`. Signature verification and transactional replay handling remain inside the service.

Keep existing provider identities, pending attempts and historical receipts when adopting populated storage. Billing still requires Cashier and Stripe. Configure host management and system-operation policies explicitly. See the [service reference](README.md).
