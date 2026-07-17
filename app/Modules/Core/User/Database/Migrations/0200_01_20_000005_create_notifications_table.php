<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the standard Laravel database-notifications table. Users are
     * the only notifiable today, but the polymorphic shape is Laravel's
     * contract, so we keep it as-is.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            // Laravel's DatabaseChannel inserts client-generated UUID string
            // ids (NotificationSender assigns Str::orderedUuid before send),
            // so the pk must be a uuid — an auto-increment id() breaks every
            // insert with a datatype mismatch.
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
