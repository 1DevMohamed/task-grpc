<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_inbox', function (Blueprint $table) {
            $table->id();

            // Event identity — UNIQUE to prevent duplicate processing
            $table->string('event_id', 64)->unique();

            // Event metadata
            $table->string('event_type', 128);
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 128);
            $table->string('correlation_id', 64)->index();
            $table->string('merchant_id', 64)->nullable();

            // Processing state machine: RECEIVED → PROCESSING → PROCESSED | FAILED
            $table->string('status', 20)->default('RECEIVED')->index();

            // Retry tracking
            $table->unsignedSmallInteger('attempts')->default(0);

            // Full event payload for replay capability
            $table->json('payload');

            // Error context for failed events
            $table->text('error_message')->nullable();
            $table->string('error_category', 30)->nullable(); // permanent, transient, unknown

            // Timing
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            // Composite index for stale processing detection
            $table->index(['status', 'processing_started_at']);

            // Index for DLQ replay by event_id lookup
            $table->index(['event_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_inbox');
    }
};
