<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->char('notification_uuid', 36)->index();
            $table->char('user_uuid', 36)->index();
            $table->enum('channel', ['email', 'whatsapp', 'push'])->index();
            $table->string('provider')->nullable();
            $table->string('recipient')->nullable();
            $table->text('subject')->nullable();
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['pending', 'processing', 'sent', 'failed'])->default('pending')->index();
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
