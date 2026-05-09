<?php
namespace App\Base\Log\Livewire\Logs;

use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Symfony\Component\Finder\SplFileInfo;

class Index extends Component
{
    use TogglesSort;

    public string $sortBy = 'modified_at';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'filename' => true,
        'size' => true,
        'modified_at' => true,
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'filename' => 'asc',
                'size' => 'desc',
                'modified_at' => 'desc',
            ],
            resetPage: false,
        );
    }

    public function render(): View
    {
        $logPath = storage_path('logs');
        $files = collect(File::allFiles($logPath))
            ->filter(fn ($file) => $file->getExtension() === 'log')
            ->values();

        return view('livewire.admin.system.logs.index', [
            'files' => $this->sortLogFiles($files),
        ]);
    }

    /**
     * @param  Collection<int, SplFileInfo>  $files
     * @return Collection<int, SplFileInfo>
     */
    private function sortLogFiles(Collection $files): Collection
    {
        $dir = $this->sortDir === 'desc' ? -1 : 1;

        return $files
            ->sort(function (SplFileInfo $a, SplFileInfo $b) use ($dir): int {
                $primary = match ($this->sortBy) {
                    'filename' => $dir * strcmp($a->getRelativePathname(), $b->getRelativePathname()),
                    'size' => $dir * ($a->getSize() <=> $b->getSize()),
                    'modified_at' => $dir * ($a->getMTime() <=> $b->getMTime()),
                    default => $dir * ($a->getMTime() <=> $b->getMTime()),
                };

                if ($primary !== 0) {
                    return $primary;
                }

                return strcmp($a->getRelativePathname(), $b->getRelativePathname());
            })
            ->values();
    }
}
