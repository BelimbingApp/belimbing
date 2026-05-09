<?php
namespace App\Base\Integration\Livewire\OutboundExchanges;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Integration\Models\OutboundExchange;
use App\Base\Integration\Services\OutboundExchangePruner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public string $system = '';

    public string $provider = '';

    public string $operation = '';

    public string $transport = '';

    public string $protocol = '';

    public string $outcome = '';

    public string $ownerType = '';

    public string $ownerId = '';

    public string $since = '';

    public ?string $statusMessage = null;

    public ?string $statusVariant = null;

    public function updated($name): void
    {
        if (in_array($name, ['system', 'provider', 'operation', 'transport', 'protocol', 'outcome', 'ownerType', 'ownerId', 'since'], true)) {
            $this->resetPage();
        }
    }

    public function cleanupPayloads(OutboundExchangePruner $pruner): void
    {
        $this->requireCapability('admin.system.outbound-exchange.delete');

        $count = $pruner->prunePayloads();
        $this->statusMessage = trans_choice('{0} No retained payloads were old enough for cleanup.|{1} Cleaned retained payloads from 1 exchange.|[2,*] Cleaned retained payloads from :count exchanges.', $count, ['count' => $count]);
        $this->statusVariant = $count > 0 ? 'success' : 'info';
    }

    public function deleteExchange(string $id): void
    {
        $this->requireCapability('admin.system.outbound-exchange.delete');

        OutboundExchange::query()->whereKey($id)->delete();
        $this->statusMessage = __('Deleted outbound exchange :id.', ['id' => $id]);
        $this->statusVariant = 'success';
    }

    public function render(): View
    {
        $query = OutboundExchange::query()
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $inner): void {
                    $inner->where('id', 'like', '%'.$this->search.'%')
                        ->orWhere('correlation_id', 'like', '%'.$this->search.'%')
                        ->orWhere('endpoint', 'like', '%'.$this->search.'%')
                        ->orWhere('error_message', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->system !== '', fn (Builder $query) => $query->where('system', $this->system))
            ->when($this->provider !== '', fn (Builder $query) => $query->where('provider', $this->provider))
            ->when($this->operation !== '', fn (Builder $query) => $query->where('operation', $this->operation))
            ->when($this->transport !== '', fn (Builder $query) => $query->where('transport', $this->transport))
            ->when($this->protocol !== '', fn (Builder $query) => $query->where('protocol', $this->protocol))
            ->when($this->outcome !== '', fn (Builder $query) => $query->where('outcome', $this->outcome))
            ->when($this->ownerType !== '', fn (Builder $query) => $query->where('owner_type', $this->ownerType))
            ->when($this->ownerId !== '', fn (Builder $query) => $query->where('owner_id', (int) $this->ownerId))
            ->when($this->since !== '', fn (Builder $query) => $query->where('occurred_at', '>=', now()->subHours((int) $this->since)));

        return view('livewire.admin.integration.outbound-exchanges.index', [
            'exchanges' => $query->latest('occurred_at')->paginate(25),
            'systems' => $this->distinct('system'),
            'providers' => $this->distinct('provider'),
            'operations' => $this->distinct('operation'),
            'transports' => $this->distinct('transport'),
            'protocols' => $this->distinct('protocol'),
            'outcomes' => $this->distinct('outcome'),
            'ownerTypes' => $this->distinct('owner_type'),
            'canDelete' => $this->capabilityAllows('admin.system.outbound-exchange.delete'),
            'statusMessage' => $this->statusMessage,
            'statusVariant' => $this->statusVariant,
        ]);
    }

    /**
     * @return list<string>
     */
    private function distinct(string $column): array
    {
        return OutboundExchange::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    private function requireCapability(string $capability): void
    {
        abort_unless($this->capabilityAllows($capability), 403, "Capability '{$capability}' is required.");
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = Auth::user();

        return $user !== null && app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
    }
}
