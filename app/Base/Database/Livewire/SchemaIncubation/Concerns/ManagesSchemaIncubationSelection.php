<?php

namespace App\Base\Database\Livewire\SchemaIncubation\Concerns;

trait ManagesSchemaIncubationSelection
{
    private function resetIncubatingSelection(): void
    {
        $this->selectedIncubatingTables = [];
        $this->selectIncubatingPage = false;
    }

    private function resetSearchSelection(): void
    {
        $this->selectedSearchTables = [];
        $this->selectSearchPage = false;
    }

    private function resetIncubationPagination(): void
    {
        $this->resetPage('incubatingPage');
        $this->resetPage('searchPage');
    }
}
