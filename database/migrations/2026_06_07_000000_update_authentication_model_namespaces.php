<?php

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)
                ->where('model_type', 'App\\Models\\User')
                ->update(['model_type' => User::class]);
        }
    }

    public function down(): void
    {
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)
                ->where('model_type', User::class)
                ->update(['model_type' => 'App\\Models\\User']);
        }
    }
};
