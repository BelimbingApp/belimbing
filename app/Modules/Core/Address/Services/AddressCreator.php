<?php
namespace App\Modules\Core\Address\Services;

use App\Modules\Core\Address\Models\Address;
use Illuminate\Support\Carbon;

class AddressCreator
{
    /**
     * Create an address from AI/user raw input with optional parsed attributes.
     *
     * This is intentionally minimal for v1: callers can pass parsed components
     * and any AI metadata; we persist provenance and timestamps consistently.
     *
     * @param  string  $rawInput  Free-form address block (scan/paste/import)
     * @param  array  $attributes  Parsed components like line1/locality/postcode/country_iso/admin1Code
     * @param  string|null  $source  Source of the input (manual, scan, paste, import_api)
     * @param  float|null  $confidence  Parser confidence (0..1)
     */
    public function fromRawInput(
        string $rawInput,
        array $attributes = [],
        ?string $source = null,
        ?float $confidence = null
    ): Address {
        $now = Carbon::now();

        $address = new Address([
            'rawInput' => $rawInput,
            'source' => $source,
            'parseConfidence' => $confidence,
            'parsed_at' => $now,
            'verificationStatus' => 'unverified',
        ]);

        foreach ($attributes as $key => $value) {
            if (! in_array($key, $address->getFillable(), true)) {
                continue;
            }

            $address->setAttribute($key, $value);
        }

        // Best-effort default mapping when caller provides only raw input.
        if (! $address->line1) {
            $lines = preg_split('/\\r\\n|\\r|\\n/', trim($rawInput)) ?: [];
            $address->line1 = $lines[0] ?? null;
            $address->line2 = $lines[1] ?? null;
            $address->line3 = $lines[2] ?? null;
        }

        $address->save();

        return $address;
    }
}
