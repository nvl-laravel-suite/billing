<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Support\Config\PackageStorage;

/** Records the provider time of the last applied customer update so late retries cannot regress the contact. */
return new class extends Migration
{
    private const string Column = 'provider_customer_updated_at';

    /** Resolve the canonical account connection. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('billing');
    }

    /** Add the nullable ordering fact; existing accounts accept their next customer update. */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = BillingTables::get(BillingTables::Accounts);
        if ($schema->hasTable($table) && ! $schema->hasColumn($table, self::Column)) {
            $schema->table($table, static fn (Blueprint $table) => $table->timestamp(self::Column)->nullable());
        }
    }

    /** Remove only the ordering fact; contacts and provider identities are retained. */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = BillingTables::get(BillingTables::Accounts);
        if ($schema->hasTable($table) && $schema->hasColumn($table, self::Column)) {
            $schema->table($table, static fn (Blueprint $table) => $table->dropColumn(self::Column));
        }
    }
};
