<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Controllers\Settings;

use App\Modules\Core\User\Actions\Logout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeleteAccountController
{
    /**
     * Delete the currently authenticated user's account.
     */
    public function destroy(Request $request, Logout $logout): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = Auth::user();

        $logout();

        $user->delete();

        return redirect('/');
    }
}
