<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Enums;

enum UiReferenceSection: string
{
    case Foundations = 'foundations';
    case Inputs = 'inputs';
    case Feedback = 'feedback';
    case Actions = 'actions';
    case Navigation = 'navigation';
    case Overlays = 'overlays';
    case DataDisplay = 'data-display';
    case CompositePatterns = 'composite-patterns';

    public function label(): string
    {
        return match ($this) {
            self::Foundations => 'Foundations',
            self::Inputs => 'Inputs',
            self::Feedback => 'Feedback',
            self::Actions => 'Actions',
            self::Navigation => 'Navigation',
            self::Overlays => 'Overlays',
            self::DataDisplay => 'Data Display',
            self::CompositePatterns => 'Composite Patterns',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Foundations => 'Tokens, type, spacing, shape, elevation, and icon language.',
            self::Inputs => 'Text entry, selection controls, and comparison guidance.',
            self::Feedback => 'Alerts, flash behavior, validation, loading, and empty states.',
            self::Actions => 'Buttons, icon actions, destructive entry points, and emphasis.',
            self::Navigation => 'Tabs, page headers, filters, and movement through content.',
            self::Overlays => 'Modal and confirmation behavior, backdrops, and dismissal.',
            self::DataDisplay => 'Cards, badges, tables, metadata, and status treatments.',
            self::CompositePatterns => 'Real page assemblies that combine the primitives.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Foundations => 'heroicon-o-adjustments-horizontal',
            self::Inputs => 'heroicon-o-document-text',
            self::Feedback => 'heroicon-o-exclamation-circle',
            self::Actions => 'heroicon-o-plus-circle',
            self::Navigation => 'heroicon-o-rectangle-group',
            self::Overlays => 'heroicon-o-document-duplicate',
            self::DataDisplay => 'heroicon-o-table-cells',
            self::CompositePatterns => 'heroicon-o-rectangle-stack',
        };
    }

    public static function default(): self
    {
        return self::Foundations;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(static fn (self $section): string => $section->value, self::cases());
    }
}
