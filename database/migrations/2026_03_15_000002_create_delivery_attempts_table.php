<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('delivery_uuid', 36)->index();
            $table->unsignedInteger('attempt_number');
            $table->string('provider')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed']);
            $table->text('error_message')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
