<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Livewire\TestSse;

use App\Base\System\Http\Controllers\TestSseStreamController;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        return view('livewire.admin.system.test-sse.index', [
            'streamUrl' => route('admin.system.test-sse.stream'),
            'runtimeSeconds' => TestSseStreamController::DEFAULT_RUNTIME_SECONDS,
            'minFeedIntervalSeconds' => TestSseStreamController::DEFAULT_MIN_FEED_INTERVAL_SECONDS,
            'maxFeedIntervalSeconds' => TestSseStreamController::DEFAULT_MAX_FEED_INTERVAL_SECONDS,
        ]);
    }
}
