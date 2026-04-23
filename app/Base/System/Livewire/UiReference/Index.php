<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Livewire\UiReference;

use App\Base\System\Enums\UiReferenceSection;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public string $section = 'foundations';

    public bool $demoModalOpen = false;

    public bool $demoConfirmOpen = false;

    public string $textValue = 'Acme Logistics';

    public string $searchValue = 'ship';

    public string $textareaValue = 'Compact copy keeps intent clear without flattening hierarchy.';

    public string $selectValue = 'approved';

    public string $comboboxValue = 'review';

    public string $editableComboboxValue = 'Custom label';

    public string $dateValue = '2026-04-23T10:30';

    public bool $checkboxValue = true;

    public string $radioValue = 'combobox';

    public function demoActionMessage(): void
    {
        $this->dispatch('ui-reference-action-message', message: __('Saved.'));
    }

    public function mount(?string $section = null): void
    {
        $this->section = UiReferenceSection::tryFrom($section ?? UiReferenceSection::default()->value)?->value
            ?? UiReferenceSection::default()->value;
    }

    public function sectionUrl(UiReferenceSection $section): string
    {
        if ($section === UiReferenceSection::default()) {
            return route('admin.system.ui-reference.index');
        }

        return route('admin.system.ui-reference.show', ['section' => $section->value]);
    }

    public function render(): View
    {
        return view('livewire.admin.system.ui-reference.index', [
            'sections' => UiReferenceSection::cases(),
            'currentSection' => UiReferenceSection::from($this->section),
            'colorTokens' => $this->colorTokens(),
            'spacingTokens' => $this->spacingTokens(),
            'typeSamples' => $this->typeSamples(),
            'iconSamples' => $this->iconSamples(),
            'statusOptions' => $this->statusOptions(),
            'comboboxOptions' => $this->comboboxOptions(),
            'tableRows' => $this->tableRows(),
            'demoPaginator' => $this->demoPaginator(),
        ]);
    }

    /**
     * @return list<array{name: string, class: string, note: string}>
     */
    private function colorTokens(): array
    {
        return [
            ['name' => 'surface-page', 'class' => 'bg-surface-page border-border-default', 'note' => 'Main page background'],
            ['name' => 'surface-card', 'class' => 'bg-surface-card border-border-default', 'note' => 'Cards, inputs, overlays'],
            ['name' => 'surface-subtle', 'class' => 'bg-surface-subtle border-border-default', 'note' => 'Headers, hover, grouping'],
            ['name' => 'surface-sidebar', 'class' => 'bg-surface-sidebar border-border-default', 'note' => 'Persistent navigation'],
            ['name' => 'surface-bar', 'class' => 'bg-surface-bar border-border-default', 'note' => 'Top and status bars'],
            ['name' => 'accent', 'class' => 'bg-accent border-transparent', 'note' => 'Primary actions'],
            ['name' => 'status-success', 'class' => 'bg-status-success-subtle border-status-success-border', 'note' => 'Positive feedback'],
            ['name' => 'status-warning', 'class' => 'bg-status-warning-subtle border-status-warning-border', 'note' => 'Needs attention'],
            ['name' => 'status-danger', 'class' => 'bg-status-danger-subtle border-status-danger-border', 'note' => 'Errors and destructive state'],
            ['name' => 'status-info', 'class' => 'bg-status-info-subtle border-status-info-border', 'note' => 'Neutral informational feedback'],
        ];
    }

    /**
     * @return list<array{token: string, class: string, note: string}>
     */
    private function spacingTokens(): array
    {
        return [
            ['token' => 'p-card-inner', 'class' => 'p-card-inner', 'note' => 'Card internals'],
            ['token' => 'px-input-x / py-input-y', 'class' => 'px-input-x py-input-y', 'note' => 'Control padding'],
            ['token' => 'px-table-cell-x / py-table-cell-y', 'class' => 'px-table-cell-x py-table-cell-y', 'note' => 'Table rhythm'],
            ['token' => 'space-y-section-gap', 'class' => 'space-y-section-gap', 'note' => 'Page section spacing'],
        ];
    }

    /**
     * @return list<array{label: string, class: string, sample: string}>
     */
    private function typeSamples(): array
    {
        return [
            ['label' => 'Page Title', 'class' => 'text-xl font-medium tracking-tight text-ink', 'sample' => 'UI Reference'],
            ['label' => 'Section Heading', 'class' => 'text-lg font-medium tracking-tight text-ink', 'sample' => 'Foundations'],
            ['label' => 'Body Copy', 'class' => 'text-sm text-ink', 'sample' => 'Compact language should still read calmly over long sessions.'],
            ['label' => 'Muted Support', 'class' => 'text-sm text-muted', 'sample' => 'Use for support copy, metadata, and low-emphasis labels.'],
            ['label' => 'Label Style', 'class' => 'text-[11px] uppercase tracking-wider font-semibold text-muted', 'sample' => 'Status'],
            ['label' => 'Tabular Data', 'class' => 'text-sm tabular-nums text-ink', 'sample' => '2026-04-23 10:30'],
        ];
    }

    /**
     * @return list<array{name: string, usage: string}>
     */
    private function iconSamples(): array
    {
        return [
            ['name' => 'heroicon-o-adjustments-horizontal', 'usage' => 'Outline icon for primary UI language'],
            ['name' => 'heroicon-o-exclamation-circle', 'usage' => 'Outline alert or feedback symbol'],
            ['name' => 'heroicon-m-chevron-down', 'usage' => 'Mini icon for dense inline controls'],
            ['name' => 'heroicon-m-check', 'usage' => 'Mini confirmation in dense layouts'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'review', 'label' => 'In Review'],
            ['value' => 'approved', 'label' => 'Approved'],
            ['value' => 'archived', 'label' => 'Archived'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function comboboxOptions(): array
    {
        return [
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'review', 'label' => 'In Review'],
            ['value' => 'approved', 'label' => 'Approved'],
            ['value' => 'archived', 'label' => 'Archived'],
            ['value' => 'blocked', 'label' => 'Blocked by Dependency'],
            ['value' => 'waiting', 'label' => 'Waiting on External Input'],
            ['value' => 'ready', 'label' => 'Ready for Release'],
        ];
    }

    /**
     * @return list<array{name: string, owner: string, status: string, updated_at: string}>
     */
    private function tableRows(): array
    {
        return [
            ['name' => 'Supplier Onboarding', 'owner' => 'Operations', 'status' => 'Active', 'updated_at' => '2026-04-23 09:14:00'],
            ['name' => 'AI Provider Review', 'owner' => 'Platform', 'status' => 'Draft', 'updated_at' => '2026-04-22 16:42:00'],
            ['name' => 'Localization Audit', 'owner' => 'System', 'status' => 'Queued', 'updated_at' => '2026-04-21 11:08:00'],
        ];
    }

    private function demoPaginator(): LengthAwarePaginator
    {
        $items = Collection::times(42, static fn (int $index): array => [
            'id' => $index,
            'label' => "Record {$index}",
        ]);

        return new LengthAwarePaginator(
            items: $items->forPage(2, 10)->values(),
            total: $items->count(),
            perPage: 10,
            currentPage: 2,
            options: [
                'path' => $this->sectionUrl(UiReferenceSection::Navigation),
                'pageName' => 'page',
            ],
        );
    }
}
