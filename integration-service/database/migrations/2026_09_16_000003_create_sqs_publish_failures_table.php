<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sqs_publish_failures', function (Blueprint $table) {
            $table->id();
            $table->string('queue');
            $table->json('payload');
            $table->unsignedTinyInteger('attempts');
            $table->text('error');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sqs_publish_failures');
    }
};