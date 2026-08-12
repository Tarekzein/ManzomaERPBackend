<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceCompanyForeignKey(
            'company_subscriptions',
            'company_subscriptions_company_history_fk',
            'restrict',
        );
        $this->replaceCompanyForeignKey(
            'subscription_payments',
            'subscription_payments_company_history_fk',
            'restrict',
        );
    }

    public function down(): void
    {
        $this->replaceCompanyForeignKey(
            'subscription_payments',
            'subscription_payments_company_id_foreign',
            'cascade',
        );
        $this->replaceCompanyForeignKey(
            'company_subscriptions',
            'company_subscriptions_company_id_foreign',
            'cascade',
        );
    }

    private function replaceCompanyForeignKey(string $tableName, string $newName, string $onDelete): void
    {
        $foreignKey = collect(Schema::getForeignKeys($tableName))->first(
            fn (array $key): bool => $key['columns'] === ['company_id']
                && $key['foreign_table'] === 'companies'
                && $key['foreign_columns'] === ['id']
        );

        if ($foreignKey && strtolower((string) $foreignKey['on_delete']) === $onDelete) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::table($tableName, function (Blueprint $table) use (
            $driver,
            $foreignKey,
            $newName,
            $onDelete,
        ): void {
            // SQLite exposes foreign keys without names and requires column-based
            // removal. MySQL/PostgreSQL names are discovered from the live schema
            // so this also works when a deployment used a non-default prefix/name.
            if ($foreignKey) {
                if ($driver === 'sqlite' || blank($foreignKey['name'] ?? null)) {
                    $table->dropForeign(['company_id']);
                } else {
                    $table->dropForeign($foreignKey['name']);
                }
            }

            $table->foreign('company_id', $newName)
                ->references('id')
                ->on('companies')
                ->onDelete($onDelete);
        });
    }
};
