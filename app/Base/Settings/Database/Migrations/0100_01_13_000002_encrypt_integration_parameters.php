<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->transform(encrypt: true);
    }

    public function down(): void
    {
        $this->transform(encrypt: false);
    }

    private function transform(bool $encrypt): void
    {
        if (! Schema::hasTable('base_settings')) {
            return;
        }

        DB::table('base_settings')
            ->where('key', 'like', 'integrations.%')
            ->where('key', 'not like', '%.description')
            ->where('is_encrypted', ! $encrypt)
            ->orderBy('id')
            ->eachById(function (object $row) use ($encrypt): void {
                $value = $encrypt
                    ? Crypt::encryptString(json_encode($this->decodeValue($row->value), JSON_THROW_ON_ERROR))
                    : json_decode(Crypt::decryptString($this->decodeStoredString($row->value)), true);

                DB::table('base_settings')->where('id', $row->id)->update([
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'is_encrypted' => $encrypt,
                    'updated_at' => now(),
                ]);
            });
    }

    private function decodeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function decodeStoredString(mixed $value): string
    {
        $decoded = $this->decodeValue($value);

        return is_string($decoded) ? $decoded : (string) $value;
    }
};
