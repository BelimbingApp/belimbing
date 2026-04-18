<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replace('base_authz_principal_roles', 'principal_type', 'human_user', 'user');
        $this->replace('base_authz_principal_capabilities', 'principal_type', 'human_user', 'user');
        $this->replace('base_authz_decision_logs', 'actor_type', 'human_user', 'user');
        $this->replace('base_audit_actions', 'actor_type', 'human_user', 'user');
        $this->replace('base_audit_mutations', 'actor_type', 'human_user', 'user');
    }

    public function down(): void
    {
        $this->replace('base_authz_principal_roles', 'principal_type', 'user', 'human_user');
        $this->replace('base_authz_principal_capabilities', 'principal_type', 'user', 'human_user');
        $this->replace('base_authz_decision_logs', 'actor_type', 'user', 'human_user');
        $this->replace('base_audit_actions', 'actor_type', 'user', 'human_user');
        $this->replace('base_audit_mutations', 'actor_type', 'user', 'human_user');
    }

    private function replace(string $table, string $column, string $from, string $to): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->where($column, $from)->update([$column => $to]);
    }
};
