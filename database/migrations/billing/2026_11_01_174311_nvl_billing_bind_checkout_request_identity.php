<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Support\Config\PackageStorage;

/** Binds future Checkout reservations without guessing historical provider inputs. */
return new class extends Migration
{
    /** Resolve the canonical account connection. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('billing');
    }

    /** Preserve existing attempts as unverified until their original expiry. */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = BillingTables::get(BillingTables::Accounts);
        if (! $schema->hasColumn($table, 'pending_checkout_request_hash')) {
            $schema->table($table, static fn (Blueprint $table) => $table->string('pending_checkout_request_hash', 64)->nullable());
        }
    }

    /** Remove the request identity. Production refuses while accounts exist, because bound attempts cannot be re-verified. */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = BillingTables::get(BillingTables::Accounts);
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'pending_checkout_request_hash')) {
            return;
        }
        if (app()->isProduction() && $schema->getConnection()->table($table)->exists()) {
            throw new RuntimeException('Billing Checkout identity holds accounts; rolling it back in production requires a reviewed retirement migration.');
        }
        $schema->table($table, static fn (Blueprint $table) => $table->dropColumn('pending_checkout_request_hash'));
    }
};
