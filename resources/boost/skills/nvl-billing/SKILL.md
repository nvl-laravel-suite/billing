---
name: nvl-billing
description: Integrate native owner-scoped subscription billing services.
---

# NVL Billing

Requires PHP 8.4 and Laravel 13. Cashier and Stripe are intrinsic runtime dependencies. Consumers own data objects, Actions, controllers, routes and response projections.

Use BillingCheckout.start, BillingContacts.update, BillingAccess.forOwner, BillingPortal.url, BillingWebhooks.process and BillingReconciler.reconcile. Native services own authorization, canonical owner admission, transactions, locks, checkout retries, signed provider events and replay receipts. Checkout and access results are documented native arrays; account/subscription results are models.

Bind BillingManagementAccess and explicit native ownership/system-operation policies. The deprecated Tenancy bridge is removed. Preserve populated provider identities, pending attempts and historical receipts during native adoption.

Consumers provide webhook transport and pass original payload bytes plus signature to BillingWebhooks. Do not parse away signed bytes or duplicate receipt handling in a controller. Cashier model adoption and suppression of Cashier routes remain explicit. Pick one migration owner. Consult the shipped README and events reference for signatures and lifecycle semantics.

## Configured financial models

Set `models.account` and `models.subscription` to concrete package-model subclasses before boot. The frozen classes flow through services, Cashier adoption, factories, relations, native Ownership and restoration. Items and webhook records remain fixed.
Retain required UUID identity, casts, query/persistence hooks and financial ownership declarations. Subclass-only table, connection or foreign-key changes are rejected; package-level storage configuration still applies to all records. Model configuration does not replace reviewed native adoption.
