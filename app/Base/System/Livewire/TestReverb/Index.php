<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Livewire\TestReverb;

use App\Base\System\Events\ReverbTestMessageOccurred;
use App\Base\System\Http\Controllers\TestReverbDispatchController;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        return view('livewire.admin.system.test-reverb.index', [
            'broadcastDriver' => (string) config('broadcasting.default'),
            'channelName' => 'system.reverb-test.'.(int) auth()->id(),
            'eventName' => ReverbTestMessageOccurred::EVENT_NAME,
            'turnCount' => TestReverbDispatchController::TURN_COUNT,
            'eventCount' => TestReverbDispatchController::EVENT_COUNT,
            'burstIntervalMs' => (int) (TestReverbDispatchController::BURST_INTERVAL_MICROSECONDS / 1000),
            'dispatchUrl' => route('admin.system.test-reverb.dispatch'),
        ]);
    }
}
