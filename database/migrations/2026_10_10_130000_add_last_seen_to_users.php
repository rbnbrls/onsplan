<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When someone last used onsplan, and how (web app or an assistant over MCP), for admin.onsplan.nl.
// Kept on the user row because web sessions get garbage-collected and MCP calls have no session at all.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_via', 8)->nullable(); // 'web' | 'mcp'
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'last_seen_via']);
        });
    }
};
