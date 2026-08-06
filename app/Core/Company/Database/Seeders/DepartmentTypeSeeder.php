<?php
namespace App\Core\Company\Database\Seeders;

use App\Core\Company\Models\DepartmentType;
use Illuminate\Database\Seeder;

class DepartmentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        /** @see app/Core/Company/Config/company.php */
        $types = config('company.department_types', []);

        foreach ($types as $type) {
            DepartmentType::query()->firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
