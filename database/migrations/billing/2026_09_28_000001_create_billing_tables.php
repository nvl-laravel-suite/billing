<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Billing\Definitions\Tables\BillingTables;

/** Creates the opt-in tenant billing account and Cashier subscription stores. */
return new class extends Migration
{
    /** Use the central tenant directory connection for all billing records. */
    public function getConnection(): ?string
    {
        $configured = config('billing.connection') ?? config('tenancy.connection');

        return is_string($configured) ? $configured : null;
    }

    /** Create UUID-backed Cashier tables without changing a consumer's users table. */
    public function up(): void
    {
        $schema = $this->schema();

        $schema->create(BillingTables::Accounts, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('stripe_id')->nullable()->unique();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('trial_consumed_at')->nullable();
            $table->uuid('pending_checkout_attempt_id')->nullable();
            $table->string('pending_checkout_price')->nullable();
            $table->string('pending_checkout_session_id')->nullable();
            $table->text('pending_checkout_url')->nullable();
            $table->timestamp('pending_checkout_expires_at')->nullable();
            $table->unsignedSmallInteger('pending_checkout_trial_days')->nullable();
            $table->timestamps();
        });

        $schema->create(BillingTables::Subscriptions, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_account_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['billing_account_id', 'stripe_status'], 'nvl_billing_sub_account_status_idx');
            $table->foreign('billing_account_id')->references('id')->on(BillingTables::Accounts)->cascadeOnDelete();
        });

        $schema->create(BillingTables::SubscriptionItems, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('billing_subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->string('meter_id')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['billing_subscription_id', 'stripe_price'], 'nvl_billing_item_subscription_price_idx');
            $table->foreign('billing_subscription_id')->references('id')->on(BillingTables::Subscriptions)->cascadeOnDelete();
        });

        $schema->create(BillingTables::WebhookEvents, function (Blueprint $table): void {
            $table->string('stripe_event_id')->primary();
            $table->string('type');
            $table->timestamp('processed_at');
        });
    }

    /** Remove all Billing-owned state in reverse dependency order. */
    public function down(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists(BillingTables::WebhookEvents);
        $schema->dropIfExists(BillingTables::SubscriptionItems);
        $schema->dropIfExists(BillingTables::Subscriptions);
        $schema->dropIfExists(BillingTables::Accounts);
    }

    /** Resolve the configured central schema builder. */
    private function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }
};
