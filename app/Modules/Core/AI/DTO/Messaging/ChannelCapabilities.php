<?php
namespace App\Modules\Core\AI\DTO\Messaging;

/**
 * Value object describing what a messaging channel supports.
 *
 * Each channel adapter returns an instance describing its platform
 * capabilities. The MessageTool uses this to validate actions before
 * dispatching them to the adapter.
 */
final readonly class ChannelCapabilities
{
    /**
     * @param  bool  $supportsReactions  Whether the channel supports message reactions
     * @param  bool  $supportsEditing  Whether sent messages can be edited
     * @param  bool  $supportsDeletion  Whether sent messages can be deleted
     * @param  bool  $supportsPolls  Whether the channel supports polls
     * @param  bool  $supportsThreads  Whether the channel supports threaded replies
     * @param  bool  $supportsMedia  Whether the channel supports media attachments
     * @param  bool  $supportsButtons  Whether the channel supports interactive buttons
     * @param  bool  $supportsSearch  Whether the channel supports message search
     * @param  list<string>  $mediaTypes  Supported media types (e.g., 'image', 'document')
     * @param  int  $maxMessageLength  Maximum message length in characters
     */
    public function __construct(
        public bool $supportsReactions = false,
        public bool $supportsEditing = false,
        public bool $supportsDeletion = false,
        public bool $supportsPolls = false,
        public bool $supportsThreads = false,
        public bool $supportsMedia = true,
        public bool $supportsButtons = false,
        public bool $supportsSearch = false,
        public array $mediaTypes = ['image', 'document'],
        public int $maxMessageLength = 4096,
    ) {}
}
