# Upgrading NVL Billing

This is a new optional package with no prior NVL Billing schema to migrate. Install it separately from `nvl/laravel-suite` and configure a central billing connection. Do not map an existing user-based Cashier customer table to Billing's tenant account model without a reviewed data migration.

Before enabling Billing in an existing application, decide whether vendor-loaded or host-owned migrations will run, create Stripe catalog entries and recurring Prices, publish the Price allowlist, bind `BillingManagementAccess`, configure Cashier credentials and a signed webhook, and run `nvl:billing:doctor --strict`. Test a complete subscription lifecycle in Stripe test mode. Cashier's model registration is application-wide; applications already using Cashier with another customer model need a deliberate migration or separate service boundary.

When changing Stripe Prices, keep historical IDs in the catalog while existing subscriptions reference them. When changing access policy, review existing tenants because effective features and limits are resolved from current configuration. Deploy price/configuration changes before offering new Checkout choices.
