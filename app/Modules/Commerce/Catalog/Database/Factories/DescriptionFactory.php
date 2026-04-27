<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Database\Factories;

use App\Modules\Commerce\Catalog\Models\Description;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Description>
 */
class DescriptionFactory extends Factory
{
    /**
     * @var class-string<Model>
     */
    protected $model = Description::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'created_by_user_id' => User::factory(),
            'version' => 1,
            'title' => fake()->sentence(6),
            'body' => fake()->paragraphs(2, true),
            'source' => Description::SOURCE_MANUAL,
            'is_accepted' => false,
            'metadata' => null,
        ];
    }
}
