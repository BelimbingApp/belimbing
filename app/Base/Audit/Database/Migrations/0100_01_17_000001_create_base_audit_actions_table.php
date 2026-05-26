<?php

use App\Base\Audit\Database\Migrations\Concerns\DefinesAuditActorColumns;
use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use DefinesAuditActorColumns;
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('base_audit_actions', function (Blueprint $table): void {
            $table->id();
            $this->addAuditActorColumns($table);
            $table->string('event')->index();
            $table->jsonb('payload')->nullable();
            $table->string('trace_id', 12)->nullable()->index();
            $table->boolean('is_retained')->default(false);
            $table->timestamp('occurred_at')->index();

            $table->index(['event', 'occurred_at']);
            $this->addAuditActorOccurredIndex($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_audit_actions');
    }
};
