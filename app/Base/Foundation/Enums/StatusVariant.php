<?php

namespace App\Base\Foundation\Enums;

/**
 * Single source of truth for status feedback styling: maps a variant to its
 * semantic surface/border/text token set and its heroicon glyph. Consumed by
 * the inline `x-ui.alert`, the toast item `x-ui.flash`, and the dynamic
 * `x-ui.notification-hub` (which needs the glyph path data in JS).
 *
 * `danger` is an accepted alias of `error`; unknown labels fall back to success.
 */
enum StatusVariant: string
{
    case Success = 'success';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public static function fromLabel(?string $label): self
    {
        return match ($label) {
            'error', 'danger' => self::Error,
            'warning' => self::Warning,
            'info' => self::Info,
            default => self::Success,
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
     * SVG path data for the icon, for JS-rendered notifications that cannot call
     * the `<x-icon>` Blade component. Mirrors the outline heroicon in `icon()`.
     */
    public function iconPath(): string
    {
        return match ($this) {
            self::Success => 'M9 12.75L11.25 15L15 9.75M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z',
            self::Error => 'M12 9V12.75M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12ZM12 15.75H12.0075V15.7575H12V15.75Z',
            self::Warning => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
            self::Info => 'M11.25 11.25L11.2915 11.2293C11.8646 10.9427 12.5099 11.4603 12.3545 12.082L11.6455 14.918C11.4901 15.5397 12.1354 16.0573 12.7085 15.7707L12.75 15.75M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12ZM12 8.25H12.0075V8.2575H12V8.25Z',
        };
    }

    /**
     * Full variant→style map for JS consumers (the notification hub renders
     * dynamic toasts client-side and needs the glyph path inline).
     *
     * @return array<string, array{bg: string, border: string, text: string, path: string}>
     */
    public static function jsMap(): array
    {
        $map = [];

        foreach (self::cases() as $variant) {
            $map[$variant->value] = [...$variant->classes(), 'path' => $variant->iconPath()];
        }

        return $map;
    }
}
