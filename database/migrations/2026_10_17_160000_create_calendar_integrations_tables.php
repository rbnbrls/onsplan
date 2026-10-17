<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('microsoft_graph');
            $table->string('calendar_id');
            $table->string('calendar_name');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('microsoft_user_id')->nullable();
            $table->string('microsoft_email')->nullable();
            $table->string('status', 40)->default('active');
            $table->string('subscription_id')->nullable();
            $table->timestamp('subscription_expires_at')->nullable();
            $table->text('delta_link')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['family_id', 'provider']);
        });

        Schema::create('calendar_event_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_integration_id')->constrained()->cascadeOnDelete();
            // The calendar module owns calendar_events and may be installed after the core migrations.
            // Keep this nullable reference free of a migration-order dependency; the model relation
            // and the integration cascade still provide the required cleanup semantics.
            $table->foreignId('calendar_event_id')->nullable();
            $table->string('external_event_id');
            $table->string('external_ical_uid')->nullable();
            $table->string('origin', 20)->default('outlook');
            $table->string('sync_hash', 64)->nullable();
            $table->timestamp('local_changed_at')->nullable();
            $table->timestamp('external_changed_at')->nullable();
            $table->boolean('external_deleted')->default(false);
            $table->timestamps();
            $table->unique(['calendar_integration_id', 'external_event_id']);
            $table->index(['family_id', 'calendar_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_syncs');
        Schema::dropIfExists('calendar_integrations');
    }
};
