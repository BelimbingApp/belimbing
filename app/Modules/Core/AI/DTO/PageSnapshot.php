<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Rich page snapshot for Lara's deep page inspection.
 *
 * Extends PageContext with form values, table schema, modal state, and
 * focused element. Requested on-demand via the active_page_snapshot tool.
 * All field values are pre-masked by FieldVisibilityResolver before
 * reaching this DTO — the client never handles raw sensitive data.
 */
final readonly class PageSnapshot
{
    /**
     * @param  PageContext  $pageContext  Base page metadata
     * @param  list<FormSnapshot>  $forms  Visible forms with masked field values
     * @param  list<TableSnapshot>  $tables  Visible data tables
     * @param  list<ModalSnapshot>  $modals  Visible modal dialogs
     * @param  string|null  $focusedElement  Currently focused element identifier
     */
    public function __construct(
        public PageContext $pageContext,
        public array $forms = [],
        public array $tables = [],
        public array $modals = [],
        public ?string $focusedElement = null,
    ) {}

    /**
     * Hydrate from a serialized array (e.g. from cache).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pageContext: PageContext::fromArray($data['page']),
            forms: array_map(FormSnapshot::fromArray(...), $data['forms'] ?? []),
            tables: array_map(TableSnapshot::fromArray(...), $data['tables'] ?? []),
            modals: array_map(ModalSnapshot::fromArray(...), $data['modals'] ?? []),
            focusedElement: $data['focused_element'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'page' => $this->pageContext->toArray(),
            'forms' => $this->forms !== [] ? array_map(fn (FormSnapshot $f): array => $f->toArray(), $this->forms) : null,
            'tables' => $this->tables !== [] ? array_map(fn (TableSnapshot $t): array => $t->toArray(), $this->tables) : null,
            'modals' => $this->modals !== [] ? array_map(fn (ModalSnapshot $m): array => $m->toArray(), $this->modals) : null,
            'focused_element' => $this->focusedElement,
        ], fn (mixed $v): bool => $v !== null);
    }
}
