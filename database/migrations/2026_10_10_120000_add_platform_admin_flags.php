<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Platform admin = a flag on the user (set by `php artisan admin:grant`), and the admin.onsplan.nl
// OAuth client = a flag on the client (`board:client --admin`). Both on rows, never in env lists: an env
// list that was never set on production once handed the board client MCP scopes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });
        Schema::connection(config('passport.connection'))->table('oauth_clients', function (Blueprint $table) {
            $table->boolean('platform_admin')->default(false)->after('first_party');
        });
    }

    public function down(): void
    {
        Schema::connection(config('passport.connection'))->table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('platform_admin');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
