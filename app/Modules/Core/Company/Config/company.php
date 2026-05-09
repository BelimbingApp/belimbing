<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Relationship Types
    |--------------------------------------------------------------------------
    |
    | Define the default relationship types for company relationships.
    | Each deployment can customize these types to match their business model.
    |
    | Structure:
    | - code: Unique identifier (lowercase, no spaces)
    | - name: Display name
    | - description: Human-readable description
    | - is_external: Whether this relationship type allows external portal access
    | - is_active: Whether this type is active by default
    | - metadata: Additional configuration (permissions, etc.)
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Department Types
    |--------------------------------------------------------------------------
    |
    | Define the default department types available to companies.
    | Each deployment can customize these types to match their organizational structure.
    |
    | Structure:
    | - code: Unique identifier (lowercase, no spaces)
    | - name: Display name
    | - category: Classification (administrative, operational, revenue, support)
    | - description: Human-readable description
    | - is_active: Whether this type is active by default
    | - metadata: Additional configuration
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Legal Entity Types
    |--------------------------------------------------------------------------
    |
    | Define the default legal entity types for companies.
    | These are jurisdiction-agnostic categories; jurisdiction-specific names
    | (e.g., Sdn Bhd, Pte Ltd) can be stored in metadata.
    |
    | Structure:
    | - code: Unique identifier (lowercase, no spaces)
    | - name: Display name
    | - description: Human-readable description
    | - is_active: Whether this type is active by default
    | - metadata: Additional configuration
    |
    */

    'legal_entity_types' => [
        [
            'code' => 'sole_proprietorship',
            'name' => 'Sole Proprietorship',
            'description' => 'Business owned and operated by a single individual',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'partnership',
            'name' => 'Partnership',
            'description' => 'Business owned by two or more partners sharing profits and liabilities',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'llp',
            'name' => 'Limited Liability Partnership',
            'description' => 'Partnership where partners have limited personal liability',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'private_limited',
            'name' => 'Private Limited Company',
            'description' => 'Privately held company with limited liability; shares not publicly traded',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'public_listed',
            'name' => 'Public Listed Company',
            'description' => 'Company whose shares are traded on a public stock exchange',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'public_unlisted',
            'name' => 'Public Unlisted Company',
            'description' => 'Public company whose shares are not listed on a stock exchange',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'corporation',
            'name' => 'Corporation',
            'description' => 'Incorporated entity with separate legal personality from its owners',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'cooperative',
            'name' => 'Cooperative',
            'description' => 'Member-owned organisation operated for mutual benefit',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'non_profit',
            'name' => 'Non-Profit Organisation',
            'description' => 'Organisation operated for social, charitable, or educational purposes',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'government',
            'name' => 'Government Entity',
            'description' => 'Government body or state-owned enterprise',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'branch',
            'name' => 'Branch Office',
            'description' => 'Local office of a foreign-registered company',
            'is_active' => true,
            'metadata' => [],
        ],
    ],

    'department_types' => [
        [
            'code' => 'exec',
            'name' => 'Executive Office',
            'category' => 'administrative',
            'description' => 'Executive leadership and strategic management',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'hr',
            'name' => 'Human Resources',
            'category' => 'administrative',
            'description' => 'HR management, recruitment, employee relations',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'finance',
            'name' => 'Finance & Accounting',
            'category' => 'administrative',
            'description' => 'Financial management, accounting, auditing',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'legal',
            'name' => 'Legal & Compliance',
            'category' => 'administrative',
            'description' => 'Legal affairs, compliance, risk management',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'sales',
            'name' => 'Sales',
            'category' => 'revenue',
            'description' => 'Sales operations and customer acquisition',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'marketing',
            'name' => 'Marketing',
            'category' => 'revenue',
            'description' => 'Marketing, branding, and customer engagement',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'operations',
            'name' => 'Operations',
            'category' => 'operational',
            'description' => 'Day-to-day operations and process management',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'production',
            'name' => 'Production / Manufacturing',
            'category' => 'operational',
            'description' => 'Manufacturing, production, and quality control',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'it',
            'name' => 'Information Technology',
            'category' => 'support',
            'description' => 'IT infrastructure, systems, and support',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'customer_support',
            'name' => 'Customer Support',
            'category' => 'support',
            'description' => 'Customer service and technical support',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'facilities',
            'name' => 'Facilities Management',
            'category' => 'support',
            'description' => 'Facilities, maintenance, and workplace management',
            'is_active' => true,
            'metadata' => [],
        ],
        [
            'code' => 'rnd',
            'name' => 'Research & Development',
            'category' => 'operational',
            'description' => 'Research, development, and innovation',
            'is_active' => true,
            'metadata' => [],
        ],
    ],

    'relationship_types' => [
        [
            'code' => 'internal',
            'name' => 'Internal',
            'description' => 'Internal company relationship within the same group/organization',
            'is_external' => false,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => ['view_all', 'edit_all'],
            ],
        ],
        [
            'code' => 'customer',
            'name' => 'Customer',
            'description' => 'Customer relationship - company purchases from us',
            'is_external' => true,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => ['view_orders', 'view_invoices', 'view_statements'],
            ],
        ],
        [
            'code' => 'supplier',
            'name' => 'Supplier',
            'description' => 'Supplier relationship - we purchase from this company',
            'is_external' => true,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => ['view_purchase_orders', 'submit_invoices'],
            ],
        ],
        [
            'code' => 'partner',
            'name' => 'Partner',
            'description' => 'Business partner relationship - collaborative business relationship',
            'is_external' => true,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => ['view_shared_projects', 'view_shared_documents'],
            ],
        ],
        [
            'code' => 'agency',
            'name' => 'Agency',
            'description' => 'Agency relationship - company acts on our behalf (e.g., customs broker, freight forwarder)',
            'is_external' => true,
            'is_active' => true,
            'metadata' => [
                'allows_data_sharing' => true,
                'default_permissions' => ['view_shipments', 'submit_documents'],
            ],
        ],
    ],

];
