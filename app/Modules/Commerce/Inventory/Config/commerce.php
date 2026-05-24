<?php

use App\Modules\Commerce\Inventory\Readiness\ItemBasicsReadinessContributor;

return [
    'readiness_contributors' => [
        ItemBasicsReadinessContributor::class,
    ],

    'workbench_panels' => [
        [
            'id' => 'commerce.inventory.item-basics',
            'label' => 'Item checklist',
            'description' => 'Core listing basics every sellable item should have before marketplace work.',
            'subject' => 'commerce.inventory.item',
            'readiness_contributor' => ItemBasicsReadinessContributor::class,
        ],
    ],
];
