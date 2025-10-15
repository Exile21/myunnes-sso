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
            if (!Schema::hasColumn('users', 'remember_token')) {
                $column = $table->string('remember_token', 100)->nullable();

                if (Schema::hasColumn('users', 'password')) {
                    $column->after('password');
                }
            }
        });

        Schema::table('sys_user', function (Blueprint $table) {
            if (!Schema::hasColumn('sys_user', 'remember_token')) {
                $column = $table->string('remember_token', 100)->nullable();

                if (Schema::hasColumn('sys_user', 'password_user')) {
                    $column->after('password_user');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'remember_token')) {
                $table->dropColumn('remember_token');
            }
        });

        Schema::table('sys_user', function (Blueprint $table) {
            if (Schema::hasColumn('sys_user', 'remember_token')) {
                $table->dropColumn('remember_token');
            }
        });
    }
};
