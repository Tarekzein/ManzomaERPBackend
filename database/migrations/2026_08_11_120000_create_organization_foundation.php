<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->string('billing_email')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('locale', 8)->default('en');
            $table->string('currency', 3)->default('EGP');
            $table->json('settings')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('billing_suspended_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'archived_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->restrictOnDelete();
            $table->timestamp('archived_at')->nullable()->after('is_active');
            $table->unique(['organization_id', 'id'], 'companies_organization_id_id_unique');
            $table->index(['organization_id', 'archived_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_company_id')
                ->nullable()
                ->after('company_id')
                ->constrained('companies')
                ->nullOnDelete();
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 16)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // Composite foreign keys below keep every custom role and company
        // membership inside the same company and organization.
        Schema::table('company_custom_roles', function (Blueprint $table) {
            $table->unique(['company_id', 'id'], 'company_custom_roles_company_id_id_unique');
        });

        Schema::create('company_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedBigInteger('custom_role_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);

            $table->foreign(['organization_id', 'company_id'], 'company_memberships_organization_company_foreign')
                ->references(['organization_id', 'id'])
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'user_id'], 'company_memberships_organization_user_foreign')
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->cascadeOnDelete();
            $table->foreign(['company_id', 'custom_role_id'], 'company_memberships_company_custom_role_foreign')
                ->references(['company_id', 'id'])
                ->on('company_custom_roles')
                ->restrictOnDelete();
        });

        Schema::create('company_membership_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_membership_id');
            $table->string('permission_name');
            $table->string('effect', 10);
            $table->timestamps();

            $table->unique(['company_membership_id', 'permission_name'], 'company_membership_permission_unique');
            $table->index(['effect', 'permission_name'], 'company_membership_permission_effect_index');
            $table->foreign('company_membership_id', 'company_membership_permission_membership_fk')
                ->references('id')
                ->on('company_memberships')
                ->cascadeOnDelete();
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->string('role', 32)->default('member');
            $table->string('status', 16)->default('pending');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'organization_invitations_organization_id_id_unique');
            $table->index(['organization_id', 'email', 'status'], 'organization_invitations_lookup_index');
            $table->index(['status', 'expires_at']);
        });

        Schema::create('organization_invitation_companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('organization_invitation_id');
            $table->unsignedBigInteger('company_id');
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedBigInteger('custom_role_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_invitation_id', 'company_id'], 'organization_invitation_company_unique');
            $table->index(['organization_id', 'company_id'], 'organization_invitation_company_org_index');

            $table->foreign(
                ['organization_id', 'organization_invitation_id'],
                'organization_invitation_companies_invitation_foreign'
            )->references(['organization_id', 'id'])
                ->on('organization_invitations')
                ->cascadeOnDelete();
            $table->foreign(['organization_id', 'company_id'], 'organization_invitation_companies_company_foreign')
                ->references(['organization_id', 'id'])
                ->on('companies')
                ->cascadeOnDelete();
            $table->foreign(['company_id', 'custom_role_id'], 'organization_invitation_companies_custom_role_foreign')
                ->references(['company_id', 'id'])
                ->on('company_custom_roles')
                ->restrictOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('company_id')
                ->constrained('organizations')
                ->nullOnDelete();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::dropIfExists('organization_invitation_companies');
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('company_membership_permission_overrides');
        Schema::dropIfExists('company_memberships');

        Schema::table('company_custom_roles', function (Blueprint $table) {
            $table->dropUnique('company_custom_roles_company_id_id_unique');
        });

        Schema::dropIfExists('organization_memberships');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_company_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'archived_at']);
            $table->dropUnique('companies_organization_id_id_unique');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn('archived_at');
        });

        Schema::dropIfExists('organizations');
    }
};
