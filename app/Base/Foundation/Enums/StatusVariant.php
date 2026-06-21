<?php

namespace App\Base\Foundation\Enums;

/**
 * Single source of truth for status feedback styling: maps a variant to its
 * semantic surface/border/text token set and its heroicon glyph. Consumed by
 * the inline `x-ui.alert` and the dynamic `x-ui.notification-hub`.
 *
 * `danger` is an accepted alias of `error`.
 */
enum StatusVariant: string
{
    case Success = 'success';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public static function fromLabel(?string $label): self
    {
        $normalized = strtolower(trim((string) $label));

        return match ($normalized) {
            '', 'success' => self::Success,
            'error', 'danger' => self::Error,
            'warning' => self::Warning,
            'info' => self::Info,
            default => throw new \InvalidArgumentException("Unknown status variant [{$label}]."),
        };
    }

    /**
     * Semantic Tailwind classes for this variant.
     *
     * @return array{bg: string, border: string, text: string}
     */
    public function classes(): array
    {
        return match ($this) {
            self::Success => ['bg' => 'bg-status-success-subtle', 'border' => 'border-status-success-border', 'text' => 'text-status-success'],
            self::Error => ['bg' => 'bg-status-danger-subtle', 'border' => 'border-status-danger-border', 'text' => 'text-status-danger'],
            self::Warning => ['bg' => 'bg-status-warning-subtle', 'border' => 'border-status-warning-border', 'text' => 'text-status-warning'],
            self::Info => ['bg' => 'bg-status-info-subtle', 'border' => 'border-status-info-border', 'text' => 'text-status-info'],
        };
    }

    /** Heroicon (outline) name for use with `<x-icon>`. */
    public function icon(): string
    {
        return match ($this) {
            self::Success => 'heroicon-o-check-circle',
            self::Error => 'heroicon-o-exclamation-circle',
            self::Warning => 'heroicon-o-exclamation-triangle',
            self::Info => 'heroicon-o-information-circle',
        };
    }

    /**
     * Full variant to style map for JS consumers.
     *
     * @return array<string, array{bg: string, border: string, text: string}>
     */
    public static function jsMap(): array
    {
        $map = [];

        foreach (self::cases() as $variant) {
            $map[$variant->value] = $variant->classes();
        }

        return $map;
    }
}
