<?php
namespace App\Modules\Core\AI\Services\Messaging\Adapters;

use App\Modules\Core\AI\Contracts\Messaging\ChannelAdapter;
use App\Modules\Core\AI\DTO\Messaging\ChannelAccount;
use App\Modules\Core\AI\DTO\Messaging\InboundMessage;
use App\Modules\Core\AI\DTO\Messaging\SendResult;
use Illuminate\Http\Request;

/**
 * Shared stub behavior for messaging channel adapters.
 *
 * Concrete adapters provide their public identity and capability contract,
 * while this base class centralizes the current placeholder behavior for
 * account resolution, outbound sends, and inbound parsing.
 */
abstract class BaseChannelAdapter implements ChannelAdapter
{
    public function channelId(): string
    {
        return $this->channelKey();
    }

    public function label(): string
    {
        return $this->channelLabel();
    }

    public function resolveAccount(int $companyId, ?string $accountId = null): ?ChannelAccount
    {
        return null;
    }

    public function sendText(ChannelAccount $account, string $target, string $text, array $options = []): SendResult
    {
        return $this->notConfiguredResult();
    }

    public function sendMedia(ChannelAccount $account, string $target, string $mediaPath, ?string $caption = null): SendResult
    {
        return $this->notConfiguredResult();
    }

    public function parseInbound(Request $request): ?InboundMessage
    {
        return null;
    }

    /**
     * Internal configuration key used as the channel identifier.
     */
    abstract protected function channelKey(): string;

    /**
     * Human-readable channel label used in tool responses.
     */
    abstract protected function channelLabel(): string;

    private function notConfiguredResult(): SendResult
    {
        return SendResult::fail($this->label().' adapter is not yet configured. Channel integration pending.');
    }
}
