<?php

namespace App\Base\Database\Livewire\SchemaIncubation\Concerns;

trait ManagesSchemaIncubationSelection
{
    private function resetIncubatingSelection(): void
    {
        $this->selectedIncubatingTables = [];
    }

    private function resetSearchSelection(): void
    {
        $this->selectedSearchTables = [];
    }

    private function resetIncubationPagination(): void
    {
        $this->resetPage('incubatingPage');
        $this->resetPage('searchPage');
    }
}
