---
name: nvl-billing
description: Use when integrating owner-scoped subscription checkout, billing contacts, entitlements, customer portal, Stripe subscription webhooks or reconciliation with nvl/billing.
---

# NVL Billing

Subscription billing targets PHP 8.4 and Laravel 13. Cashier and Stripe are intrinsic runtime dependencies.
Consumers own application DTOs, Actions, controllers, routes, response resources and presentation.
Native services own canonical admission, permissions, local transactions, provider identity and recovery.

## Read the relevant reference

- [Installation and publication](references/installation.md): providers, publish tags, migration ownership and deployment configuration.
- [Native API](references/api.md): complete signatures, contracts, models, result shapes and extension points.
- [Usage](references/usage.md): service selection, host policy, configuration, end-to-end checkout and recovery.

Read installation for a new consumer or adoption change. Read usage for implementation.
Look up the exact operation in the API before choosing arguments or projecting results.
These references travel with the published skill; no repository checkout is required.

## Select the capability

- `BillingCheckout::start`: resolve a configured plan/interval into a hosted checkout session.
- `BillingContacts::update`: create or synchronize the owner's billing contact.
- `BillingAccess::forOwner`: read synchronized plan, state, features and limits.
- `BillingPortal::url`: create a host-authorized customer portal URL.
- `BillingWebhooks::process`: verify original bytes, deduplicate and synchronize current provider facts.
- `BillingReconciler::reconcile`: refresh one explicit owner's current subscriptions.
- `BillingDoctor`: diagnose readiness and adoption without inventing a consumer route.

## Supply trusted authority

Bind `BillingManagementAccess` to the host's billing-management policy; its default denies.
Pass the complete native `OwnerReference` and explicit `Authenticatable` actor.
Services reload Eloquent actors before the host permission decision.
Non-Eloquent actors retain their provider's freshness contract.
Native Ownership requires configured resources, reviewed adoption and current owner admission.
An owner reference describes identity; it does not grant permission.

## Preserve source and provider boundaries

Start checkout outside an existing transaction on the actual Billing connection.
Apply the additive Checkout request-hash migration before deployment. Pending
attempts bind the original inputs, contact identity and gateway configuration;
retry identical inputs. Historical active null-hash attempts refuse replay until
their original expiry; never guess URLs or reuse a provider key with changed inputs.
Existing-customer contact synchronization and reconciliation also refuse ambient source transactions.
Local-only contacts can participate in an enclosing transaction.
Keep reserved checkout attempts and provider idempotency keys for retries.
Pass untouched webhook payload bytes and the Stripe signature to the native service.
Subscription notifications trigger complete current-and-historical provider reads.
A browser return and a signed mutable subscription snapshot do not replace that synchronization.
Concurrent observation changes require a fresh reconciliation attempt.

## Configure a complete storage graph

Set `models.account` and `models.subscription` before boot to concrete package subclasses.
Retain native UUIDs, casts, query/persistence hooks, foreign keys and financial ownership declarations.
Subscription items and receipts remain fixed package records.
Configure package `connection` and `tables` to move the whole graph together.
Subclass-only table, connection or foreign-key changes are rejected.
Cashier model adoption and suppression of its routes are explicit choices.

## Deliver consumer results

Checkout returns `id`, `url`, `expiresAt`; access returns `plan`, `state`, `features`, `limits`.
Contacts return a native `BillingAccount`; portal returns a URL.
Map domain exceptions in the host and keep provider diagnostics out of public responses.
Use `BillingGateway` and `SubscriptionReader` for provider substitution.
The usage reference distinguishes gateway test doubles from actual persistence/replay guarantees.
