<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\Database\Concerns\RegistersSeeders;
use App\Modules\Operation\IT\Database\Seeders\TicketWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersSeeders;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('operation_it_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('reporter_id')->index()->constrained('employees');
            $table->foreignId('assignee_id')->nullable()->index()->constrained('employees');
            $table->string('status')->default('open')->index();
            $table->string('priority')->default('medium'); // low, medium, high, critical
            $table->string('category')->nullable(); // hardware, software, network, access, other
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable(); // physical location / floor / room
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Composite indexes for common queries
            $table->index(['company_id', 'status']);
            $table->index(['status', 'priority']);
        });

        $this->registerSeeder(TicketWorkflowSeeder::class);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->unregisterSeeder(TicketWorkflowSeeder::class);
        Schema::dropIfExists('operation_it_tickets');
    }
};
