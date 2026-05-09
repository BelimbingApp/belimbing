<?php
namespace App\Modules\Core\Employee\Database\Seeders;

use App\Modules\Core\Employee\Models\EmployeeType;
use Illuminate\Database\Seeder;

class EmployeeTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds system types; licensees add custom types via UI.
     */
    public function run(): void
    {
        $systemTypes = [
            ['code' => 'full_time', 'label' => 'Full Time', 'is_system' => true],
            ['code' => 'part_time', 'label' => 'Part Time', 'is_system' => true],
            ['code' => 'contractor', 'label' => 'Contractor', 'is_system' => true],
            ['code' => 'intern', 'label' => 'Intern', 'is_system' => true],
            ['code' => 'agent', 'label' => 'Agent', 'is_system' => true],
        ];

        foreach ($systemTypes as $type) {
            EmployeeType::query()->updateOrCreate(
                ['code' => $type['code']],
                ['label' => $type['label'], 'is_system' => $type['is_system']]
            );
        }
    }
}
