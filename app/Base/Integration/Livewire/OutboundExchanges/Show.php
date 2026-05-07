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
        $exchange = $this->exchange;

        return view('livewire.admin.integration.outbound-exchanges.show', [
            'exchange' => $exchange,
            'canViewPayload' => $this->capabilityAllows('admin.integration_payload.view'),
            'outcomeBadge' => [
                'label' => $this->outcomeLabel($exchange->outcome),
                'variant' => $this->outcomeVariant($exchange->outcome),
                'tooltip' => $this->outcomeTooltip($exchange->outcome),
            ],
            'payloadSections' => [
                [
                    'label' => __('Request Headers'),
                    'payload' => $exchange->request_headers,
                    'display' => $this->formattedPayload($exchange->request_headers),
                    'badge' => null,
                ],
                [
                    'label' => __('Request Body'),
                    'payload' => $exchange->request_body,
                    'display' => $this->formattedPayload($exchange->request_body),
                    'badge' => [
                        'label' => $this->payloadStatusLabel($exchange->request_body_truncated),
                        'tooltip' => $this->payloadStatusTooltip($exchange->request_body_truncated),
                    ],
                ],
                [
                    'label' => __('Response Headers'),
                    'payload' => $exchange->response_headers,
                    'display' => $this->formattedPayload($exchange->response_headers),
                    'badge' => null,
                ],
                [
                    'label' => __('Response Body'),
                    'payload' => $exchange->response_body,
                    'display' => $this->formattedPayload($exchange->response_body),
                    'badge' => [
                        'label' => $this->payloadStatusLabel($exchange->response_body_truncated),
                        'tooltip' => $this->payloadStatusTooltip($exchange->response_body_truncated),
                    ],
                ],
                [
                    'label' => __('Metadata'),
                    'payload' => $exchange->metadata,
                    'display' => $this->formattedPayload($exchange->metadata),
                    'badge' => null,
                ],
            ],
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

    public function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'success' => __('Success'),
            'http_error' => __('HTTP error'),
            'connection_error' => __('Connection error'),
            default => str_replace('_', ' ', ucfirst($outcome)),
        };
    }

    public function outcomeVariant(string $outcome): string
    {
        return match ($outcome) {
            'success' => 'success',
            'http_error', 'connection_error' => 'danger',
            default => 'warning',
        };
    }

    public function outcomeTooltip(string $outcome): string
    {
        return match ($outcome) {
            'success' => __('Completed with a non-error response.'),
            'http_error' => __('The external system returned an HTTP error.'),
            'connection_error' => __('No HTTP response was received.'),
            default => __('Overall result of this outbound exchange.'),
        };
    }

    public function payloadStatusLabel(bool $truncated): string
    {
        return $truncated ? __('Truncated') : __('Retained');
    }

    public function payloadStatusTooltip(bool $truncated): string
    {
        return $truncated
            ? __('Stored as a shortened preview.')
            : __('Retained payloads are removed by retention cleanup.');
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = Auth::user();

        return $user !== null && app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
    }
}
