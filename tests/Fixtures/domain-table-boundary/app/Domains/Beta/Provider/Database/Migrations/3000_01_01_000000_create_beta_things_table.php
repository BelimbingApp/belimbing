<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beta_things', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function queryOwnTable(): void
    {
        DB::table('beta_things')->count();
    }
};
