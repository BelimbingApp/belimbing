<?php
namespace App\Core\Company\Database\Seeders;

use App\Core\Company\Models\LegalEntityType;
use Illuminate\Database\Seeder;

class LegalEntityTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** @see app/Core/Company/Config/company.php */
        $types = config('company.legal_entity_types', []);

        foreach ($types as $type) {
            LegalEntityType::query()->firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
