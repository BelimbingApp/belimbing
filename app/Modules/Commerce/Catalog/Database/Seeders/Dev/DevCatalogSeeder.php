<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Core\Company\Database\Seeders\Dev\DevCompanyAddressSeeder;

class DevCatalogSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevCompanyAddressSeeder::class,
    ];

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if ($company === null) {
            return;
        }

        $categories = [];
        foreach ($this->categories() as $category) {
            $categories[$category['code']] = Category::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $category['code'],
                ],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'sort_order' => $category['sort_order'],
                ],
            );
        }

        $templates = [];
        foreach ($this->templates() as $template) {
            $templates[$template['code']] = ProductTemplate::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $template['code'],
                ],
                [
                    'category_id' => ($categories[$template['category_code']] ?? null)?->id,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'is_active' => true,
                ],
            );
        }

        foreach ($this->attributes() as $attribute) {
            CatalogAttribute::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $attribute['code'],
                    'category_id' => $attribute['category_code'] !== null
                        ? ($categories[$attribute['category_code']] ?? null)?->id
                        : null,
                    'product_template_id' => $attribute['template_code'] !== null
                        ? ($templates[$attribute['template_code']] ?? null)?->id
                        : null,
                ],
                [
                    'name' => $attribute['name'],
                    'type' => $attribute['type'],
                    'is_required' => $attribute['is_required'],
                    'options' => $attribute['options'],
                    'sort_order' => $attribute['sort_order'],
                ],
            );
        }
    }

    /**
     * @return list<array{code: string, name: string, description: string, sort_order: int}>
     */
    private function categories(): array
    {
        return [
            [
                'code' => 'auto-lighting',
                'name' => 'Auto Lighting',
                'description' => 'Headlights, tail lights, marker lights, and related lighting assemblies.',
                'sort_order' => 10,
            ],
            [
                'code' => 'engine-parts',
                'name' => 'Engine Parts',
                'description' => 'Used engine accessories and mechanical components.',
                'sort_order' => 20,
            ],
            [
                'code' => 'electronics',
                'name' => 'Electronics',
                'description' => 'Reusable non-auto example for cameras, audio gear, and similar items.',
                'sort_order' => 90,
            ],
        ];
    }

    /**
     * @return list<array{code: string, category_code: string, name: string, description: string}>
     */
    private function templates(): array
    {
        return [
            [
                'code' => 'headlight-assembly',
                'category_code' => 'auto-lighting',
                'name' => 'Headlight Assembly',
                'description' => 'Reusable fields for a vehicle headlight listing.',
            ],
            [
                'code' => 'alternator',
                'category_code' => 'engine-parts',
                'name' => 'Alternator',
                'description' => 'Reusable fields for a used alternator listing.',
            ],
            [
                'code' => 'camera-body',
                'category_code' => 'electronics',
                'name' => 'Camera Body',
                'description' => 'Non-auto example showing the catalog can describe other resale goods.',
            ],
        ];
    }

    /**
     * @return list<array{code: string, category_code: string|null, template_code: string|null, name: string, type: string, is_required: bool, options: array<int, string>|null, sort_order: int}>
     */
    private function attributes(): array
    {
        return [
            [
                'code' => 'condition_grade',
                'category_code' => null,
                'template_code' => null,
                'name' => 'Condition Grade',
                'type' => CatalogAttribute::TYPE_SELECT,
                'is_required' => true,
                'options' => ['A', 'B', 'C', 'For parts'],
                'sort_order' => 10,
            ],
            [
                'code' => 'oem_number',
                'category_code' => 'auto-lighting',
                'template_code' => null,
                'name' => 'OEM Number',
                'type' => CatalogAttribute::TYPE_TEXT,
                'is_required' => false,
                'options' => null,
                'sort_order' => 20,
            ],
            [
                'code' => 'interchange_number',
                'category_code' => 'auto-lighting',
                'template_code' => null,
                'name' => 'Interchange Number',
                'type' => CatalogAttribute::TYPE_TEXT,
                'is_required' => false,
                'options' => null,
                'sort_order' => 30,
            ],
            [
                'code' => 'fitment_year',
                'category_code' => null,
                'template_code' => 'headlight-assembly',
                'name' => 'Fitment Year',
                'type' => CatalogAttribute::TYPE_NUMBER,
                'is_required' => true,
                'options' => null,
                'sort_order' => 40,
            ],
            [
                'code' => 'make',
                'category_code' => null,
                'template_code' => 'headlight-assembly',
                'name' => 'Make',
                'type' => CatalogAttribute::TYPE_TEXT,
                'is_required' => true,
                'options' => null,
                'sort_order' => 50,
            ],
            [
                'code' => 'model',
                'category_code' => null,
                'template_code' => 'headlight-assembly',
                'name' => 'Model',
                'type' => CatalogAttribute::TYPE_TEXT,
                'is_required' => true,
                'options' => null,
                'sort_order' => 60,
            ],
            [
                'code' => 'side',
                'category_code' => null,
                'template_code' => 'headlight-assembly',
                'name' => 'Side',
                'type' => CatalogAttribute::TYPE_SELECT,
                'is_required' => true,
                'options' => ['Driver', 'Passenger', 'Left', 'Right'],
                'sort_order' => 70,
            ],
            [
                'code' => 'amperage',
                'category_code' => null,
                'template_code' => 'alternator',
                'name' => 'Amperage',
                'type' => CatalogAttribute::TYPE_NUMBER,
                'is_required' => false,
                'options' => null,
                'sort_order' => 80,
            ],
            [
                'code' => 'shutter_count',
                'category_code' => null,
                'template_code' => 'camera-body',
                'name' => 'Shutter Count',
                'type' => CatalogAttribute::TYPE_NUMBER,
                'is_required' => false,
                'options' => null,
                'sort_order' => 90,
            ],
        ];
    }
}
