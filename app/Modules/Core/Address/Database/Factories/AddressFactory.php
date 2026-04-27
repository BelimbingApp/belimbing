<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Address\Database\Factories;

use App\Modules\Core\Address\Models\Address;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Address::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->randomElement(['HQ', 'Warehouse', 'Office', 'Billing', 'Shipping']),
            'phone' => fake()->phoneNumber(),
            'line1' => fake()->streetAddress(),
            'line2' => fake()->optional()->secondaryAddress(),
            'locality' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country_iso' => fake()->countryCode(),
            'verificationStatus' => 'unverified',
            'source' => 'manual',
        ];
    }

    /**
     * Indicate that the address is verified.
     */
    public function verified(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'verificationStatus' => 'verified',
            ],
        );
    }

    /**
     * Indicate that the address is suggested.
     */
    public function suggested(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'verificationStatus' => 'suggested',
            ],
        );
    }

    /**
     * Indicate that the address is unverified.
     */
    public function unverified(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'verificationStatus' => 'unverified',
            ],
        );
    }

    /**
     * Indicate that the address has raw input.
     */
    public function withRawInput(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'rawInput' => fake()->address(),
                'parsed_at' => now(),
                'parserVersion' => '1.0.0',
                'parseConfidence' => fake()->randomFloat(4, 0.5, 1.0),
            ],
        );
    }

    /**
     * Indicate that the address was sourced from a scan.
     */
    public function fromScan(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'source' => 'scan',
                'sourceRef' => fake()->uuid(),
            ],
        );
    }

    /**
     * Indicate that the address was sourced from an import.
     */
    public function fromImport(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'source' => 'import',
                'sourceRef' => fake()->uuid(),
            ],
        );
    }
}
