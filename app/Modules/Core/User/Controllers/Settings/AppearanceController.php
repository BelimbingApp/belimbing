<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Controllers\Settings;

use Illuminate\View\View;

class AppearanceController
{
    /**
     * Show the appearance-settings page.
     */
    public function show(): View
    {
        return view('settings.appearance');
    }
}
