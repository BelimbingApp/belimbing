<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\LegalEntityType;
use App\Modules\Core\Company\Models\RelationshipType;

class DevCompanyAddressSeeder extends DevSeeder
{
    /**
     * Seed the database.
     *
     * Creates realistic test companies, addresses, address links, and company relationships.
     */
    protected function seed(): void
    {
        $rootCompanies = $this->seedRootCompanies();
        $subsidiaries = $this->seedSubsidiaries($rootCompanies[0]);
        $allCompanies = array_merge($rootCompanies, $subsidiaries);

        $addresses = $this->seedAddresses();
        $this->linkAddressesToCompanies($allCompanies, $addresses);
        $this->seedCompanyRelationships($rootCompanies, $subsidiaries);
    }

    /**
     * Seed root companies with varied statuses.
     *
     * @return array<int, Company>
     */
    protected function seedRootCompanies(): array
    {
        $definitions = [
            [
                'name' => 'Stellar Industries Sdn Bhd',
                'legal_name' => 'Stellar Industries Sdn. Bhd.',
                'registration_number' => '201901012345',
                'tax_id' => 'C2584-3109876',
                'legal_entity_type_id' => LegalEntityType::findByCode('private_limited')?->id,
                'jurisdiction' => 'MY',
                'email' => 'admin@stellarindustries.com.my',
                'website' => 'https://stellarindustries.com.my',
                'status' => 'active',
                'scope_activities' => ['manufacturing', 'wholesale', 'export'],
            ],
            [
                'name' => 'Nusantara Trading Co',
                'legal_name' => 'Nusantara Trading Co. Pte. Ltd.',
                'registration_number' => '202200056789',
                'tax_id' => 'T2200056789F',
                'legal_entity_type_id' => LegalEntityType::findByCode('private_limited')?->id,
                'jurisdiction' => 'SG',
                'email' => 'info@nusantaratrading.sg',
                'website' => 'https://nusantaratrading.sg',
                'status' => 'pending',
                'scope_activities' => ['import', 'distribution'],
            ],
            [
                'name' => 'Borneo Logistics',
                'legal_name' => 'Borneo Logistics Sdn. Bhd.',
                'registration_number' => '201801098765',
                'tax_id' => 'C2584-9876543',
                'legal_entity_type_id' => LegalEntityType::findByCode('private_limited')?->id,
                'jurisdiction' => 'MY',
                'email' => 'ops@borneologistics.my',
                'status' => 'active',
                'scope_activities' => ['freight', 'warehousing', 'customs_brokerage'],
            ],
            [
                'name' => 'Pinnacle Holdings',
                'legal_name' => 'Pinnacle Holdings Bhd.',
                'registration_number' => '200501034567',
                'tax_id' => 'C2584-3456789',
                'legal_entity_type_id' => LegalEntityType::findByCode('public_listed')?->id,
                'jurisdiction' => 'MY',
                'email' => 'corporate@pinnacleholdings.com.my',
                'website' => 'https://pinnacleholdings.com.my',
                'status' => 'suspended',
                'scope_activities' => ['investment', 'property'],
            ],
        ];

        $companies = [];

        foreach ($definitions as $definition) {
            $companies[] = Company::query()->firstOrCreate(
                ['registration_number' => $definition['registration_number']],
                $definition
            );
        }

        return $companies;
    }

    /**
     * Seed subsidiary companies under a parent.
     *
     * @param  Company  $parent  The parent company
     * @return array<int, Company>
     */
    protected function seedSubsidiaries(Company $parent): array
    {
        $definitions = [
            [
                'name' => 'Stellar Packaging',
                'legal_name' => 'Stellar Packaging Sdn. Bhd.',
                'registration_number' => '202101078901',
                'tax_id' => 'C2584-7890123',
                'legal_entity_type_id' => LegalEntityType::findByCode('private_limited')?->id,
                'jurisdiction' => 'MY',
                'email' => 'packaging@stellarindustries.com.my',
                'status' => 'active',
                'scope_activities' => ['packaging', 'printing'],
                'parent_id' => $parent->id,
            ],
            [
                'name' => 'Stellar Digital Solutions',
                'legal_name' => 'Stellar Digital Solutions Sdn. Bhd.',
                'registration_number' => '202201045678',
                'tax_id' => 'C2584-4567890',
                'legal_entity_type_id' => LegalEntityType::findByCode('private_limited')?->id,
                'jurisdiction' => 'MY',
                'email' => 'digital@stellarindustries.com.my',
                'website' => 'https://stellardigital.com.my',
                'status' => 'active',
                'scope_activities' => ['software', 'consulting'],
                'parent_id' => $parent->id,
            ],
            [
                'name' => 'Stellar Ventures Indonesia',
                'legal_name' => 'PT Stellar Ventures Indonesia',
                'registration_number' => 'AHU-0012345.AH.01.01',
                'tax_id' => '01.234.567.8-012.000',
                'legal_entity_type_id' => LegalEntityType::findByCode('private_limited')?->id,
                'jurisdiction' => 'ID',
                'email' => 'jakarta@stellarventures.co.id',
                'status' => 'pending',
                'scope_activities' => ['trading', 'distribution'],
                'parent_id' => $parent->id,
            ],
        ];

        $companies = [];

        foreach ($definitions as $definition) {
            $companies[] = Company::query()->firstOrCreate(
                ['registration_number' => $definition['registration_number']],
                $definition
            );
        }

        return $companies;
    }

    /**
     * Seed addresses with varied levels of detail.
     *
     * @return array<int, Address>
     */
    protected function seedAddresses(): array
    {
        $definitions = [
            [
                'label' => 'Stellar HQ',
                'phone' => '+60 3-7890 1234',
                'line1' => 'Level 15, Menara Stellar',
                'line2' => 'Jalan Sultan Ismail',
                'locality' => 'Kuala Lumpur',
                'postcode' => '50250',
                'country_iso' => 'MY',
                'admin1Code' => 'MY.14',
                'verificationStatus' => 'verified',
            ],
            [
                'label' => 'Stellar Factory',
                'phone' => '+60 3-5678 9012',
                'line1' => 'Lot 12, Kawasan Perindustrian Nilai',
                'line2' => 'Jalan Industri 3',
                'locality' => 'Nilai',
                'postcode' => '71800',
                'country_iso' => 'MY',
                'admin1Code' => 'MY.05',
                'verificationStatus' => 'verified',
            ],
            [
                'label' => 'SG Office',
                'phone' => '+65 6789 0123',
                'line1' => '8 Marina Boulevard',
                'line2' => '#05-02 Marina Bay Financial Centre',
                'locality' => 'Singapore',
                'postcode' => '018981',
                'country_iso' => 'SG',
                'verificationStatus' => 'verified',
            ],
            [
                'label' => 'Borneo Warehouse',
                'phone' => '+60 82-456 789',
                'line1' => 'Lot 3456, Demak Laut Industrial Park',
                'locality' => 'Kuching',
                'postcode' => '93050',
                'country_iso' => 'MY',
                'admin1Code' => 'MY.13',
                'verificationStatus' => 'verified',
            ],
            [
                'label' => 'Billing Address',
                'line1' => 'PO Box 1234',
                'locality' => 'Petaling Jaya',
                'postcode' => '46000',
                'country_iso' => 'MY',
                'admin1Code' => 'MY.12',
                'verificationStatus' => 'unverified',
            ],
            [
                'label' => 'Jakarta Office',
                'phone' => '+62 21-5678 9012',
                'line1' => 'Gedung Wisma 46, Lantai 22',
                'line2' => 'Jl. Jend. Sudirman Kav. 1',
                'locality' => 'Jakarta Pusat',
                'postcode' => '10220',
                'country_iso' => 'ID',
                'verificationStatus' => 'suggested',
            ],
            [
                'label' => 'Penang Branch',
                'line1' => '23A, Lebuh Pantai',
                'locality' => 'George Town',
                'postcode' => '10300',
                'country_iso' => 'MY',
                'admin1Code' => 'MY.07',
                'verificationStatus' => 'unverified',
            ],
            [
                'label' => 'Johor Shipping Depot',
                'phone' => '+60 7-345 6789',
                'line1' => 'PLO 88, Jalan Nibong',
                'line2' => 'Kawasan Perindustrian Tanjung Langsat',
                'locality' => 'Pasir Gudang',
                'postcode' => '81700',
                'country_iso' => 'MY',
                'admin1Code' => 'MY.01',
                'verificationStatus' => 'verified',
            ],
        ];

        $addresses = [];

        foreach ($definitions as $definition) {
            $addresses[] = Address::query()->firstOrCreate(
                [
                    'label' => $definition['label'],
                    'country_iso' => $definition['country_iso'],
                ],
                $definition
            );
        }

        return $addresses;
    }

    /**
     * Link addresses to companies via the addressables morph pivot table.
     *
     * @param  array<int, Company>  $companies  All companies
     * @param  array<int, Address>  $addresses  All addresses
     */
    protected function linkAddressesToCompanies(array $companies, array $addresses): void
    {
        $links = [
            [0, 0, ['headquarters'], true, 0, '2019-06-01', null],
            [0, 1, ['branch'], false, 1, '2020-01-15', null],
            [0, 4, ['billing'], false, 2, '2019-06-01', null],
            [0, 7, ['shipping'], false, 3, '2023-03-01', null],
            [1, 2, ['headquarters'], true, 0, '2022-04-01', null],
            [1, 4, ['billing'], false, 1, '2022-04-01', null],
            [2, 3, ['headquarters'], true, 0, '2018-09-01', null],
            [2, 7, ['shipping'], false, 1, '2021-06-15', null],
            [3, 0, ['headquarters'], true, 0, '2005-03-01', '2024-12-31'],
            [3, 6, ['branch'], false, 1, '2010-07-01', '2024-12-31'],
            [4, 1, ['headquarters'], true, 0, '2021-08-01', null],
            [5, 0, ['headquarters'], true, 0, '2022-05-01', null],
            [5, 6, ['branch'], false, 1, '2023-01-15', null],
            [6, 5, ['headquarters'], true, 0, '2022-11-01', null],
        ];

        foreach ($links as [$companyIdx, $addressIdx, $kind, $isPrimary, $priority, $validFrom, $validTo]) {
            if (! isset($companies[$companyIdx], $addresses[$addressIdx])) {
                continue;
            }

            $company = $companies[$companyIdx];
            $address = $addresses[$addressIdx];

            $exists = $company->addresses()
                ->where('address_id', $address->id)
                ->exists();

            if (! $exists) {
                $company->addresses()->attach($address->id, [
                    'kind' => $kind,
                    'is_primary' => $isPrimary,
                    'priority' => $priority,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ]);
            }
        }
    }

    /**
     * Seed company relationships using existing relationship types.
     *
     * @param  array<int, Company>  $rootCompanies  Root companies
     * @param  array<int, Company>  $subsidiaries  Subsidiary companies
     */
    protected function seedCompanyRelationships(array $rootCompanies, array $subsidiaries): void
    {
        $customerType = RelationshipType::findByCode('customer');
        $supplierType = RelationshipType::findByCode('supplier');
        $partnerType = RelationshipType::findByCode('partner');

        if (! $customerType || ! $supplierType || ! $partnerType) {
            return;
        }

        $definitions = [
            [
                'company_id' => $rootCompanies[0]->id,
                'related_company_id' => $rootCompanies[1]->id,
                'relationship_type_id' => $customerType->id,
                'effective_from' => '2022-06-01',
                'effective_to' => null,
                'metadata' => ['credit_limit' => 500000, 'currency' => 'SGD'],
            ],
            [
                'company_id' => $rootCompanies[0]->id,
                'related_company_id' => $rootCompanies[2]->id,
                'relationship_type_id' => $supplierType->id,
                'effective_from' => '2020-03-15',
                'effective_to' => null,
                'metadata' => ['service_type' => 'freight_forwarding', 'contract_ref' => 'SVC-2020-0088'],
            ],
            [
                'company_id' => $rootCompanies[1]->id,
                'related_company_id' => $rootCompanies[3]->id,
                'relationship_type_id' => $partnerType->id,
                'effective_from' => '2023-01-01',
                'effective_to' => '2025-12-31',
                'metadata' => ['partnership_area' => 'property_development'],
            ],
        ];

        foreach ($definitions as $definition) {
            CompanyRelationship::query()->firstOrCreate(
                [
                    'company_id' => $definition['company_id'],
                    'related_company_id' => $definition['related_company_id'],
                    'relationship_type_id' => $definition['relationship_type_id'],
                ],
                $definition
            );
        }
    }
}
