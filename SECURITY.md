# Security Policy

Report vulnerabilities through [private GitHub reporting](https://github.com/nvl-laravel-suite/billing/security/advisories/new). Include a minimal reproduction without real Stripe keys, webhook secrets, customer details, or payment data.

Billing management denies by default until the host binds `BillingManagementAccess`. Tenant identity must come from trusted application context. Keep Stripe keys server-side, verify webhook signatures, use HTTPS, and restrict access to the billing portal action. Never trust Checkout success redirects as proof of payment. Monitor webhook failures and reconciliation errors; review trial and entitlement policy before enabling production billing.
