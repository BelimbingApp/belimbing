<?php
namespace App\Base\Foundation\Livewire\Concerns;

trait ResetsPaginationOnSearch
{
    public function updatedSearch(): void
    {
        $this->resetPage();
    }
}
