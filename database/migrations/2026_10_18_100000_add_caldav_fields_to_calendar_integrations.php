<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_integrations', function (Blueprint $table) {
            $table->string('server_url')->nullable()->after('provider');
            $table->string('endpoint_url')->nullable()->after('server_url');
            $table->string('principal_url')->nullable()->after('endpoint_url');
            $table->string('calendar_home_url')->nullable()->after('principal_url');
            $table->text('username')->nullable()->after('refresh_token');
            $table->text('password')->nullable()->after('username');
            $table->renameColumn('delta_link', 'sync_cursor');
        });

        Schema::table('calendar_event_syncs', function (Blueprint $table) {
            $table->text('external_href')->nullable()->after('external_event_id');
            $table->string('etag')->nullable()->after('external_href');
            $table->text('external_recurrence')->nullable()->after('etag');
            $table->unsignedInteger('external_sequence')->nullable()->after('external_recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_syncs', function (Blueprint $table) {
            $table->dropColumn(['external_href', 'etag', 'external_recurrence', 'external_sequence']);
        });
        Schema::table('calendar_integrations', function (Blueprint $table) {
            $table->renameColumn('sync_cursor', 'delta_link');
            $table->dropColumn(['server_url', 'endpoint_url', 'principal_url', 'calendar_home_url', 'username', 'password']);
        });
    }
};
