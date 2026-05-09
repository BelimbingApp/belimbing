<?php
namespace App\Modules\Core\AI\DTO\Messaging;

/**
 * Value object representing an authenticated messaging channel account.
 *
 * Holds the credentials and metadata needed by a channel adapter to
 * send and receive messages on behalf of a company.
 */
final readonly class ChannelAccount
{
    /**
     * @param  string  $id  Account identifier (DB primary key or external ID)
     * @param  string  $channelId  Channel identifier (e.g., 'whatsapp', 'telegram')
     * @param  int  $companyId  Owning company ID
     * @param  string  $accountType  Account type ('business' or 'personal')
     * @param  array<string, mixed>  $credentials  Encrypted/decrypted credentials
     * @param  int|null  $ownerEmployeeId  Owner employee ID (for personal relays)
     */
    public function __construct(
        public string $id,
        public string $channelId,
        public int $companyId,
        public string $accountType = 'business',
        public array $credentials = [],
        public ?int $ownerEmployeeId = null,
    ) {}
}
