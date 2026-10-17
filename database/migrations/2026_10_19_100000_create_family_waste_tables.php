<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('family_waste_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('postal_code', 8)->nullable();
            $table->string('house_number', 20)->nullable();
            $table->string('house_number_suffix', 20)->nullable();
            $table->string('street_name')->nullable();
            $table->string('country', 2)->default('NL');
            $table->string('collector', 80)->nullable();
            $table->string('collector_detection_status', 40)->default('unconfigured');
            $table->json('selected_waste_types')->nullable();
            $table->foreignId('task_list_id')->nullable();
            $table->unsignedSmallInteger('task_offset_days')->default(1);
            $table->string('task_time', 5)->default('18:00');
            $table->unsignedSmallInteger('sync_horizon_months')->default(3);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('status', 40)->default('unconfigured');
            $table->timestamps();
            $table->unique('family_id');
        });

        Schema::create('family_waste_pickups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('waste_type', 80);
            $table->string('waste_label');
            $table->date('pickup_date');
            $table->string('source_event_key', 255);
            $table->json('source_payload')->nullable();
            $table->foreignId('calendar_event_id')->nullable();
            $table->foreignId('task_id')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->unique(['family_id', 'source_event_key']);
            $table->index(['family_id', 'pickup_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_waste_pickups');
        Schema::dropIfExists('family_waste_settings');
    }
};
