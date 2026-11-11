<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Support\Config\PackageStorage;

/** Completes native account lifecycle storage without prematurely declaring ownership adoption. */
return new class extends Migration
{
    /** Use the same actual connection as the Billing models and initial migration. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('billing');
    }

    /** Preserve historical tenant identities while allowing newly admitted native owners. */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = BillingTables::get(BillingTables::Accounts);
        foreach ($schema->getColumns($table) as $column) {
            if ($column['name'] === 'tenant_id' && ! $column['nullable']) {
                $schema->table($table, static fn (Blueprint $table) => $table->uuid('tenant_id')->nullable()->change());
                break;
            }
        }
        if (! $schema->hasColumn($table, 'provider_deleted_at')) {
            $schema->table($table, static fn (Blueprint $table) => $table->timestamp('provider_deleted_at')->nullable());
        }
    }

    /**
     * Remove the provider-deletion fact. Production refuses while accounts exist, because the
     * facts cannot be recovered; `tenant_id` stays nullable so native accounts remain valid.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = BillingTables::get(BillingTables::Accounts);
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'provider_deleted_at')) {
            return;
        }
        if (app()->isProduction() && $schema->getConnection()->table($table)->exists()) {
            throw new RuntimeException('Billing account lifecycle storage holds accounts; rolling it back in production requires a reviewed retirement migration.');
        }
        $schema->table($table, static fn (Blueprint $table) => $table->dropColumn('provider_deleted_at'));
    }
};
