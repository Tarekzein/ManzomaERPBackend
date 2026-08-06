<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Workspace identifier used in the app URLs (/app/{slug}/…).
            $table->string('slug')->nullable()->unique()->after('id');
        });

        $taken = [];

        DB::table('companies')->orderBy('id')->chunkById(200, function ($companies) use (&$taken) {
            foreach ($companies as $company) {
                $base = Str::slug($company->name) ?: 'workspace';
                $slug = $base;
                $suffix = 2;

                while (isset($taken[$slug]) || DB::table('companies')->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$suffix++;
                }

                $taken[$slug] = true;
                DB::table('companies')->where('id', $company->id)->update(['slug' => $slug]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
