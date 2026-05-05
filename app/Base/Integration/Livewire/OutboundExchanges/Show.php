<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Livewire\OutboundExchanges;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Integration\Models\OutboundExchange;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public OutboundExchange $exchange;

    public function mount(OutboundExchange $exchange): void
    {
        $this->exchange = $exchange;
    }

    public function render(): View
    {
        return view('livewire.admin.integration.outbound-exchanges.show', [
            'exchange' => $this->exchange,
            'canViewPayload' => $this->capabilityAllows('admin.integration_payload.view'),
        ]);
    }

    public function formattedPayload(mixed $payload): string
    {
        if ($payload === null) {
            return '';
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = Auth::user();

        return $user !== null && app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
    }
}
