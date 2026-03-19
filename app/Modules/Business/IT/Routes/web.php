<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Business\IT\Livewire\Tickets\Create;
use App\Modules\Business\IT\Livewire\Tickets\Index;
use App\Modules\Business\IT\Livewire\Tickets\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('it/tickets', Index::class)
        ->middleware('authz:it_ticket.ticket.list')
        ->name('it.tickets.index');

    Route::get('it/tickets/create', Create::class)
        ->middleware('authz:it_ticket.ticket.create')
        ->name('it.tickets.create');

    Route::get('it/tickets/{ticket}', Show::class)
        ->middleware('authz:it_ticket.ticket.view')
        ->name('it.tickets.show');
});
