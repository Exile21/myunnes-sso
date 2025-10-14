<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add SSO ID field if it doesn't exist
            if (!Schema::hasColumn('users', 'sso_id')) {
                $table->string('sso_id')->nullable()->after('email');
                $table->index('sso_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'sso_id')) {
                $table->dropIndex(['sso_id']);
                $table->dropColumn('sso_id');
            }
        });
    }
};
