<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Tool Workspace orchestrator — routes between catalog and per-tool workspace.

namespace App\Modules\Core\AI\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Tools extends Component
{
    /** @var string|null null = catalog view, tool name = workspace view */
    public ?string $toolName = null;

    public function render(): View
    {
        return view('livewire.admin.ai.tools');
    }
}
