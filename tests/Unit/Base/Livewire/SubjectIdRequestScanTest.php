<?php

use App\Base\Livewire\SubjectIdRequestScan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('flags a Domain Livewire component that reads a subject id from the request into a query', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $domain = 'ZzSubjectScan'.$suffix;
    $directory = app_path('Domains/'.$domain.'/Example/Livewire');
    File::ensureDirectoryExists($directory);

    try {
        file_put_contents($directory.'/UnsafeShow.php', <<<'PHP'
<?php

namespace App\Domains\ZzFixture\Example\Livewire;

use App\Core\Employee\Models\Employee;
use Livewire\Component;

final class UnsafeShow extends Component
{
    public function mount(): void
    {
        Employee::query()->whereKey(request()->input('employee_id'))->first();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
PHP);

        // Rewrite namespace to match the random domain so the file is realistic.
        $path = $directory.'/UnsafeShow.php';
        file_put_contents($path, str_replace('ZzFixture', $domain, file_get_contents($path)));

        $hits = array_values(array_filter(
            (new SubjectIdRequestScan)->violations(app_path('Domains')),
            static fn (array $row): bool => str_contains($row['path'], $domain),
        ));

        expect($hits)->not->toBeEmpty()
            ->and($hits[0]['evidence'])->toContain('employee_id');
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
    }
});

it('does not flag a component that resolves the subject through the workforce seam', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $domain = 'ZzSubjectSeam'.$suffix;
    $directory = app_path('Domains/'.$domain.'/Example/Livewire');
    File::ensureDirectoryExists($directory);

    try {
        file_put_contents($directory.'/SafeShow.php', <<<PHP
<?php

namespace App\\Domains\\{$domain}\\Example\\Livewire;

use App\\Domains\\People\\Provider\\Contracts\\ResolvesWorkforceSubjects;
use App\\Domains\\People\\Provider\\Data\\WorkforceSubject;
use Livewire\\Component;

final class SafeShow extends Component
{
    public function mount(ResolvesWorkforceSubjects \$resolver): void
    {
        \$id = request()->input('employee_id');
        \$resolver->resolve(new WorkforceSubject(/* fixture only */));
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
PHP);

        $hits = array_values(array_filter(
            (new SubjectIdRequestScan)->violations(app_path('Domains')),
            static fn (array $row): bool => str_contains($row['path'], $domain),
        ));

        expect($hits)->toBe([]);
    } finally {
        File::deleteDirectory(app_path('Domains/'.$domain));
    }
});

it('keeps the production Domain Livewire tree free of unallowlisted subject-id request reads', function (): void {
    expect((new SubjectIdRequestScan)->violations())->toBe([]);
});
