<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared data operation ledger: one durable, actor-attributed record for
 * every mass data operation (mirror push/force-push/pull, AX/IBP import,
 * Investment process) plus one summary per affected table. See
 * docs/plans/data-share-mirror-history-and-freshness.md.
 *
 * These tables are Base bookkeeping and are permanently protected from
 * mirroring (see DataShareMirrorCatalog::FIXED_PROTECTED_TABLES).
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('base_database_data_operation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_type', 40)->index(); // mirror_push|mirror_force_push|mirror_pull|ax_import|investment_process
            $table->string('source')->nullable();          // data-share.mirror | sbg.ibp.market-spot
            $table->string('direction', 10)->nullable();    // push|pull
            $table->boolean('is_forced')->default(false);
            $table->string('transfer_mode', 20)->nullable(); // native|portable
            $table->string('local_instance_id')->nullable();
            $table->string('remote_instance_id')->nullable();

            // Attribution. Console/scheduler/agent actors keep their real type;
            // browser runs keep the authenticated user. No connection URL or
            // fingerprint is ever stored as endpoint identity.
            $table->string('actor_type', 40)->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('actor_role', 40)->nullable();
            $table->string('actor_label')->nullable();
            $table->string('trace_id', 40)->nullable()->index();

            // Opaque, best-effort correlation to a scheduler run. Never an FK to
            // base_schedule_runs (that would couple Database to Schedule and its
            // migration order). Exact correlation is deferred.
            $table->string('schedule_run_ref')->nullable();

            $table->string('status', 20)->index(); // running|succeeded|failed|indeterminate
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->unsignedInteger('table_count')->default(0);
            $table->unsignedBigInteger('total_rows_affected')->nullable();
            $table->text('failure_summary')->nullable();

            // Records only that a best-effort audit projection was attempted;
            // the audit contract returns void, so no action id is stored.
            $table->timestamp('audit_projection_attempted_at')->nullable();

            $table->timestamps();

            $table->index(['operation_type', 'status', 'started_at'], 'data_operation_runs_type_status_started_idx');
            $table->index(['local_instance_id', 'remote_instance_id'], 'data_operation_runs_endpoints_idx');
        });

        Schema::create('base_database_data_operation_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')
                ->constrained('base_database_data_operation_runs')
                ->cascadeOnDelete();
            $table->string('table_name')->index();
            $table->json('actions'); // list of applied actions: insert|update|upsert|replace|delete|truncate|mirror

            // Honest effect counters. Distinct quantities stay separate; unknown
            // is never coerced to zero. rows_written carries the combined figure
            // where a bulk upsert cannot split insert from update.
            $table->unsignedBigInteger('rows_source')->nullable();
            $table->unsignedBigInteger('rows_attempted')->nullable();
            $table->unsignedBigInteger('rows_inserted')->nullable();
            $table->unsignedBigInteger('rows_updated')->nullable();
            $table->unsignedBigInteger('rows_written')->nullable();
            $table->unsignedBigInteger('rows_deleted')->nullable();
            $table->unsignedBigInteger('rows_unchanged')->nullable();
            $table->unsignedBigInteger('rows_rejected')->nullable();
            $table->unsignedBigInteger('rows_before')->nullable(); // endpoint totals (observations)
            $table->unsignedBigInteger('rows_after')->nullable();

            // Key range. Composite-safe; range_kind marks whether the boundaries
            // are a true range, a min/max hint, or not applicable (UUID/text/no PK).
            $table->json('key_columns')->nullable();
            $table->string('range_kind', 20)->nullable(); // contiguous|min_max_hint|not_applicable
            $table->string('first_key')->nullable();
            $table->string('last_key')->nullable();

            $table->string('local_schema_fingerprint', 64)->nullable();
            $table->string('remote_schema_fingerprint', 64)->nullable();
            $table->string('terminal_status', 20)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            // One summary per table per run: resume/retry upserts, never duplicates.
            $table->unique(['run_id', 'table_name'], 'data_operation_tables_run_table_unique');
        });

        // Mirror-only current observation projection: the latest successful Local
        // and remote row counts per endpoint pair + table. Read on every catalog
        // render so counts survive refresh and history retention, independent of
        // the remote catalog cache. Imports have no remote to compare, so they
        // write ledger rows but not this projection.
        Schema::create('base_database_data_share_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('local_instance_id');
            $table->string('remote_instance_id');
            $table->string('table_name');
            $table->unsignedBigInteger('local_rows')->nullable();
            $table->unsignedBigInteger('remote_rows')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            // The Local freshness generation this endpoint last acknowledged (a
            // successful push). "Changed since last push" = current generation is
            // newer than this. Null until a generation-tracked push happens.
            $table->unsignedBigInteger('acknowledged_generation')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(
                ['local_instance_id', 'remote_instance_id', 'table_name'],
                'data_share_observations_endpoint_table_unique',
            );
        });

        // Phase 3 (proven on PostgreSQL 17): append-only source-change events.
        // A statement-level trigger INSERTs one row per write statement inside the
        // source transaction, so rolled-back writes leave no event and TRUNCATE is
        // covered. Append-only (distinct rows) is deliberate: an earlier
        // shared-updatable-row design deadlocked when concurrent transactions
        // touched multiple tracked tables in opposite order, and serialized on a
        // hot row; inserting distinct rows removes both. The generation of a table
        // is MAX(id) of its events; a compaction step keeps only the latest per
        // table. SQLite has no trigger and reports Unknown.
        Schema::create('base_database_data_freshness_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('table_name');
            // Writers supply app/transaction time explicitly. A database-session
            // default can use a non-UTC timezone and make observations ambiguous.
            $table->timestamp('occurred_at');
            $table->index(['table_name', 'id'], 'data_freshness_events_table_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_database_data_freshness_events');
        Schema::dropIfExists('base_database_data_share_observations');
        Schema::dropIfExists('base_database_data_operation_tables');
        Schema::dropIfExists('base_database_data_operation_runs');
    }
};
