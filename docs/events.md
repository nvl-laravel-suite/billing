# NVL billing events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

Account/subscription/tenant and Stripe session identifiers only. No contact name/email, checkout URL, payment method details or raw provider payload. tenantId is persisted account ownership; it is not listener authorization.

Disabled Billing emits nothing. Unchanged contact/customer/subscription/item replays emit nothing. A checkout event requires the first persisted matching pending-attempt session. Webhook and reconciliation share writers; repeated cancellation preserves ends_at and emits no duplicate transition.

Account operation values are created, contact_updated, customer_updated, customer_deleted, trial_consumed, checkout_cleared. Subscription currentStatus and previousStatus are persisted Stripe status strings, not a new enum. Stripe calls stay outside local multi-row transactions.

BillingEvents receives calls from StartCheckoutAction, UpdateBillingContactAction, BillingCustomerSyncer, BillingAccountStateUpdater and BillingSubscriptionSyncer. BillingReconciler and signed BillingWebhookController reach these same reusable writers.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [BillingAccountChanged](#billingaccountchanged) | 1 | Account lifecycle/contact/customer transition persisted. |
| [BillingCheckoutStarted](#billingcheckoutstarted) | 1 | Matching checkout session identity first persisted. |
| [BillingSubscriptionChanged](#billingsubscriptionchanged) | 1 | Subscription/items state changed. |

### BillingAccountChanged

`Nvl\Billing\Events\BillingAccountChanged` · [source](../src/Events/BillingAccountChanged.php) · event schema `1`.

Account lifecycle/contact/customer transition persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$accountId` | `string` | public | `required` | — |
| `$tenantId` | `string` | public | `required` | — |
| `$operation` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$accountId` | `string` | — |
| `$tenantId` | `string` | — |
| `$operation` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/BillingEvents.php](../src/Services/BillingEvents.php) | `$account->getConnection()` |

Publisher callers (the publisher above supplies the exact model connection):

| Caller | Boundary |
| --- | --- |
| [Actions/StartCheckoutAction.php](../src/Actions/StartCheckoutAction.php) | `BillingEvents::accountChanged` |
| [Actions/UpdateBillingContactAction.php](../src/Actions/UpdateBillingContactAction.php) | `BillingEvents::accountChanged` |
| [Actions/UpdateBillingContactAction.php](../src/Actions/UpdateBillingContactAction.php) | `BillingEvents::accountChanged` |
| [Services/BillingAccountStateUpdater.php](../src/Services/BillingAccountStateUpdater.php) | `BillingEvents::accountChanged` |
| [Services/BillingAccountStateUpdater.php](../src/Services/BillingAccountStateUpdater.php) | `BillingEvents::accountChanged` |
| [Services/BillingCustomerSyncer.php](../src/Services/BillingCustomerSyncer.php) | `BillingEvents::accountChanged` |
| [Services/BillingCustomerSyncer.php](../src/Services/BillingCustomerSyncer.php) | `BillingEvents::accountChanged` |

### BillingCheckoutStarted

`Nvl\Billing\Events\BillingCheckoutStarted` · [source](../src/Events/BillingCheckoutStarted.php) · event schema `1`.

Matching checkout session identity first persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$accountId` | `string` | public | `required` | — |
| `$tenantId` | `string` | public | `required` | — |
| `$attemptId` | `string` | public | `required` | — |
| `$sessionId` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$accountId` | `string` | — |
| `$tenantId` | `string` | — |
| `$attemptId` | `string` | — |
| `$sessionId` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/BillingEvents.php](../src/Services/BillingEvents.php) | `$account->getConnection()` |

Publisher callers (the publisher above supplies the exact model connection):

| Caller | Boundary |
| --- | --- |
| [Actions/StartCheckoutAction.php](../src/Actions/StartCheckoutAction.php) | `BillingEvents::checkoutStarted` |

### BillingSubscriptionChanged

`Nvl\Billing\Events\BillingSubscriptionChanged` · [source](../src/Events/BillingSubscriptionChanged.php) · event schema `1`.

Subscription/items state changed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$subscriptionId` | `string` | public | `required` | — |
| `$accountId` | `string` | public | `required` | — |
| `$tenantId` | `string` | public | `required` | — |
| `$previousStatus` | `?string` | public | `required` | — |
| `$currentStatus` | `string` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$subscriptionId` | `string` | — |
| `$accountId` | `string` | — |
| `$tenantId` | `string` | — |
| `$previousStatus` | `?string` | — |
| `$currentStatus` | `string` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Services/BillingEvents.php](../src/Services/BillingEvents.php) | `$subscription->getConnection()` |

Publisher callers (the publisher above supplies the exact model connection):

| Caller | Boundary |
| --- | --- |
| [Services/BillingSubscriptionSyncer.php](../src/Services/BillingSubscriptionSyncer.php) | `BillingEvents::subscriptionChanged` |
| [Services/BillingSubscriptionSyncer.php](../src/Services/BillingSubscriptionSyncer.php) | `BillingEvents::subscriptionChanged` |

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name; an old alias import does not rename that canonical object. Legacy exact listeners are bridged at delivery time through Laravel’s native dispatcher. Keep compatibility listener tests on their exact legacy name, and migrate suffix-specific wildcards to canonical names.

