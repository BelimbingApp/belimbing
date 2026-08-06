<?php
namespace App\Core\Company\Database\Seeders;

use App\Core\Company\Models\RelationshipType;
use Illuminate\Database\Seeder;

class RelationshipTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** @see app/Core/Company/Config/company.php */
        $types = config('company.relationship_types', []);

        foreach ($types as $type) {
            RelationshipType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
