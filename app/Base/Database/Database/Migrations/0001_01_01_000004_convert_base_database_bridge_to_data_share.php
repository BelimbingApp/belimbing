<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        $hasLegacySchema = Schema::hasTable('base_database_bridge_receive_grants');

        if ($hasLegacySchema) {
            foreach (['base_database_bridge_receipts', 'base_database_bridge_plans', 'base_database_bridge_plan_actions'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->exists()) {
                    throw new RuntimeException("Data Share contains pre-offer {$table} rows. Preserve or deliberately remove that pre-pilot state before migrating.");
                }
            }
        }

        $this->renameSettings('bridge.', 'data_share.', 'bridge/', 'data-share/');

        if (! $hasLegacySchema) {
            return;
        }

        Schema::dropIfExists('base_database_bridge_plan_actions');
        Schema::dropIfExists('base_database_bridge_plans');
        Schema::dropIfExists('base_database_bridge_receipts');
        Schema::dropIfExists('base_database_bridge_receive_grants');
        Schema::dropIfExists('base_database_bridge_events');

        if (! Schema::hasTable('base_database_data_share_transfer_offers')) {
            $this->createOfferTables();
        }
    }

    public function down(): void
    {
        $hasDataShareSchema = Schema::hasTable('base_database_data_share_transfer_offers');

        if ($hasDataShareSchema) {
            foreach (['base_database_data_share_receipts', 'base_database_data_share_plans', 'base_database_data_share_plan_actions'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->exists()) {
                    throw new RuntimeException("Data Share contains transfer-offer {$table} rows and cannot be rolled back without data loss.");
                }
            }
        }

        $this->renameSettings('data_share.', 'bridge.', 'data-share/', 'bridge/');

        if (! $hasDataShareSchema) {
            return;
        }

        Schema::dropIfExists('base_database_data_share_plan_actions');
        Schema::dropIfExists('base_database_data_share_plans');
        Schema::dropIfExists('base_database_data_share_receipts');
        Schema::dropIfExists('base_database_data_share_transfer_offers');

        Schema::create('base_database_bridge_receive_grants', function (Blueprint $table): void {
            $table->id();
            $table->char('grant_id', 26)->unique();
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('issued_by_actor_id')->nullable();
            $table->string('expected_source_instance_id');
            $table->string('expected_source_role', 20);
            $table->string('target_instance_id');
            $table->string('target_role', 20);
            $table->string('scope_name');
            $table->unsignedBigInteger('max_bytes');
            $table->string('status', 20)->index();
            $table->char('consumed_package_sha256', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['expected_source_instance_id', 'scope_name']);
        });

        $this->createReceiptAndPlanTables('base_database_bridge', withGrant: true);

        Schema::create('base_database_bridge_events', function (Blueprint $table): void {
            $table->id();
            $table->string('package_id')->nullable()->index();
            $table->char('plan_hash', 64)->nullable();
            $table->string('action', 40)->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source_instance_id')->nullable();
            $table->string('target_instance_id')->nullable();
            $table->string('scope_name')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('created_at');
        });
    }

    private function createOfferTables(): void
    {
        Schema::create('base_database_data_share_transfer_offers', function (Blueprint $table): void {
            $table->id();
            $table->char('offer_id', 26)->unique();
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('published_by_actor_id')->nullable();
            $table->char('package_id', 26)->unique();
            $table->char('package_sha256', 64);
            $table->string('package_path');
            $table->string('source_instance_id');
            $table->string('source_role', 20);
            $table->string('scope_name');
            $table->unsignedBigInteger('bytes');
            $table->json('metadata');
            $table->string('status', 20)->index();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
            $table->index(['source_instance_id', 'scope_name']);
        });

        $this->createReceiptAndPlanTables('base_database_data_share', withGrant: false);
    }

    private function createReceiptAndPlanTables(string $prefix, bool $withGrant): void
    {
        Schema::create($prefix.'_receipts', function (Blueprint $table) use ($prefix, $withGrant): void {
            $table->id();
            $table->string('package_id')->unique();
            $table->char('package_sha256', 64);
            $table->string('package_path');
            $table->string('source_instance_id');
            $table->string('source_role', 20);
            $table->string('target_instance_id');
            $table->string('scope_name');

            if ($withGrant) {
                $table->foreignId('receive_grant_id')->constrained($prefix.'_receive_grants')->restrictOnDelete();
            } else {
                $table->char('offer_id', 26)->index();
            }

            $table->string('status', 20)->index();
            $table->timestamp('received_at');
            $table->timestamp('expires_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'_plans', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained($prefix.'_receipts')->cascadeOnDelete();
            $table->char('plan_hash', 64)->index();
            $table->char('package_sha256', 64);
            $table->char('destination_fingerprint', 64);
            $table->json('summary');
            $table->string('status', 20)->index();
            $table->timestamp('planned_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'_plan_actions', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('plan_id')->constrained($prefix.'_plans')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('scope_name');
            $table->string('table_name');
            $table->char('primary_key_hash', 64);
            $table->json('primary_key');
            $table->string('action', 20)->index();
            $table->char('incoming_fingerprint', 64);
            $table->char('destination_fingerprint', 64)->nullable();
            $table->unique(['plan_id', 'sequence']);
            $table->index(['plan_id', 'table_name']);
        });
    }

    private function renameSettings(string $fromPrefix, string $toPrefix, string $fromPath, string $toPath): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        foreach (DB::table('base_settings')->where('key', 'like', $fromPrefix.'%')->get() as $setting) {
            $newKey = $toPrefix.substr((string) $setting->key, strlen($fromPrefix));
            $collision = DB::table('base_settings')
                ->where('key', $newKey)
                ->where('scope_type', $setting->scope_type)
                ->where('scope_id', $setting->scope_id)
                ->exists();

            if ($collision) {
                DB::table('base_settings')->where('id', $setting->id)->delete();

                continue;
            }

            $value = json_decode((string) $setting->value, true);

            if (is_string($value) && str_starts_with($value, $fromPath)) {
                $value = $toPath.substr($value, strlen($fromPath));
            }

            DB::table('base_settings')->where('id', $setting->id)->update([
                'key' => $newKey,
                'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now('UTC'),
            ]);
        }
    }
};
