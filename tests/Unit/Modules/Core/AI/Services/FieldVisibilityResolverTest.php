<?php

use App\Modules\Core\AI\Attributes\LaraVisible;
use App\Modules\Core\AI\Services\FieldVisibilityResolver;
use Livewire\Component;

describe('FieldVisibilityResolver', function () {
    it('resolves plain public properties', function () {
        $component = new class extends Component
        {
            public string $name = 'Alice';

            public int $age = 30;

            public function render(): string
            {
                return '';
            }
        };

        $fields = FieldVisibilityResolver::resolveFields($component);
        $names = array_map(fn ($f) => $f->name, $fields);

        expect($names)->toContain('name', 'age')
            ->and($fields[0]->masked)->toBeFalse()
            ->and($fields[0]->value)->toBe('Alice');
    });

    it('masks fields with sensitive names by convention', function () {
        $component = new class extends Component
        {
            public string $password = 'hunter2';

            public string $apiKey = 'sk-abc';

            public string $name = 'visible';

            public function render(): string
            {
                return '';
            }
        };

        $fields = FieldVisibilityResolver::resolveFields($component);
        $byName = [];

        foreach ($fields as $f) {
            $byName[$f->name] = $f;
        }

        expect($byName['password']->masked)->toBeTrue()
            ->and($byName['apiKey']->masked)->toBeTrue()
            ->and($byName['name']->masked)->toBeFalse();
    });

    it('hides fields with #[LaraVisible(false)]', function () {
        $component = new class extends Component
        {
            #[LaraVisible(false)]
            public string $internal = 'hidden';

            public string $name = 'visible';

            public function render(): string
            {
                return '';
            }
        };

        $fields = FieldVisibilityResolver::resolveFields($component);
        $names = array_map(fn ($f) => $f->name, $fields);

        expect($names)->toContain('name')
            ->and($names)->not->toContain('internal');
    });

    it('masks fields with #[LaraVisible(masked: true)]', function () {
        $component = new class extends Component
        {
            #[LaraVisible(masked: true)]
            public string $ssn = '123-45-6789';

            public function render(): string
            {
                return '';
            }
        };

        $fields = FieldVisibilityResolver::resolveFields($component);

        expect($fields[0]->masked)->toBeTrue()
            ->and($fields[0]->name)->toBe('ssn');
    });

    it('excludes Livewire internal properties', function () {
        $component = new class extends Component
        {
            public string $name = 'visible';

            public function render(): string
            {
                return '';
            }
        };

        $fields = FieldVisibilityResolver::resolveFields($component);
        $names = array_map(fn ($f) => $f->name, $fields);

        // Livewire Component base class defines 'id' and other internals
        expect($names)->not->toContain('id')
            ->and($names)->not->toContain('paginators');
    });
});
