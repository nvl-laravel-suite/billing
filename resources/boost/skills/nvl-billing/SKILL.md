---
name: nvl-billing
description: Implement or review tenant-owned Stripe subscription billing with nvl/billing, including Checkout, trials, webhooks, access policies, and reconciliation.
---

# NVL Billing

Use this skill when integrating or changing tenant subscription billing in an application consuming `nvl/billing`.

## Tenant and payment boundaries

- Use a trusted `TenantId` and bind `BillingManagementAccess` for every management action. The default rejects access. Keep customer and subscription IDs out of client-controlled tenant selection.
- Resolve plan and interval through the configured Price catalog. Never let a request choose an arbitrary Stripe Price ID.
- Use `StartCheckoutAction` for hosted subscription Checkout and `BillingPortal` for self-service changes. Unlock features only from `BillingAccess` after signed webhook synchronization or reconciliation.
- Use `UpdateBillingContactAction` for an explicit tenant billing name and email; changes to a user's Auth profile do not update the Stripe customer.
- Keep payment keys and webhook secrets in host environment configuration. Verify webhook signatures; preserve event deduplication and Stripe idempotency keys.

## Trials and access

- Offer a trial once per billing account. Record consumption from confirmed Stripe trial fields; canceled or expired trials remain consumed.
- Keep old Price mappings while subscriptions still reference them. Unknown Prices and unsupported item counts fall back to free access.
- Enforce quotas atomically in the consuming feature. A billing snapshot is a policy input, not a resource counter.

## Deployment

- Select either vendor-loaded or published billing migrations, then enable Billing after configuring Tenancy, Cashier, and the management adapter.
- Schedule `nvl:billing:reconcile` to repair missed or reordered webhooks, and run `nvl:billing:doctor --strict` at deployment.
- Test a complete subscription lifecycle in Stripe test mode, including cardless trials, failed payments, cancellation, webhook retries, and portal return.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `billing.tables.*`, connections through `billing.connection` with Core/Laravel inheritance. Defaults use `nvl_billing_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=billing --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.
