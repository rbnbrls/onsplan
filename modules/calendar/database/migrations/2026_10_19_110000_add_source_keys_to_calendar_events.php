<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('color');
            $table->string('source_key', 255)->nullable()->after('source');
            $table->index(['family_id', 'source', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex('calendar_events_family_id_source_source_key_index');
            $table->dropColumn(['source', 'source_key']);
        });
    }
};
