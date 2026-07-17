<?php

namespace App\Base\AI\Services\ProviderMapping;

/**
 * Moonshot Kimi model families with distinct reasoning request contracts.
 *
 * Kimi generations moved the reasoning switch twice: K2.5/K2.6 use a
 * `thinking` object (K2.6 adds `keep` for preserved reasoning), while K3
 * replaced it with a top-level `reasoning_effort` and rejects `thinking`
 * outright. Mapping and capability code share this detection so the two
 * never disagree about which contract a model speaks.
 */
enum KimiModelFamily
{
    /** kimi-k3* — reasoning always on; `reasoning_effort` (currently only "max"). */
    case K3;

    /** kimi-k2.5* — `thinking: {type: enabled|disabled}`; no `keep` support. */
    case K2Thinking;

    /** kimi-k2.6* — `thinking: {type, keep}`; `keep: "all"` preserves reasoning across turns. */
    case K2ThinkingKeep;

    /** kimi-k2.7* and legacy kimi-k2-thinking — reasoning mandatory, no request controls. */
    case K2AlwaysThinking;

    public static function fromModel(string $model): ?self
    {
        $id = strtolower(str_contains($model, '/') ? basename($model) : $model);

        if (! str_starts_with($id, 'kimi-')) {
            return null;
        }

        return match (true) {
            str_starts_with($id, 'kimi-k3') => self::K3,
            str_starts_with($id, 'kimi-k2.5') => self::K2Thinking,
            str_starts_with($id, 'kimi-k2.6') => self::K2ThinkingKeep,
            str_starts_with($id, 'kimi-k2.7'),
            str_starts_with($id, 'kimi-k2-thinking') => self::K2AlwaysThinking,
            default => null,
        };
    }
}
