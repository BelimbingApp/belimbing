<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProvider;

final readonly class SupabaseMirrorProvider implements DataShareMirrorProvider
{
    public function __construct(private PostgresMirrorConnectionUrl $urls) {}

    public function key(): string
    {
        return 'supabase';
    }

    public function label(): string
    {
        return __('Supabase');
    }

    public function description(): string
    {
        return __('Hosted PostgreSQL using a Supabase direct or session-pooler database URL.');
    }

    public function connectionLabel(): string
    {
        return __('Supabase');
    }

    public function configuration(string $url): array
    {
        return $this->urls->parse($url);
    }
}
