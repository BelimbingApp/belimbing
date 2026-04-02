<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Lightweight page metadata for Lara's page awareness.
 *
 * Built server-side by the owning Livewire component (via ProvidesLaraPageContext)
 * or derived from the current route as a fallback. Injected into the system prompt
 * as a compact XML tag (~30 tokens).
 */
final readonly class PageContext
{
    /**
     * @param  string  $route  Named route (e.g. 'admin.employees.show')
     * @param  string  $url  Full URL of the page
     * @param  string|null  $title  Page title or record name
     * @param  string|null  $module  Module name (e.g. 'Employee')
     * @param  string|null  $resourceType  Resource type (e.g. 'employee')
     * @param  int|string|null  $resourceId  Resource identifier
     * @param  list<string>  $tabs  Available tab labels
     * @param  string|null  $activeTab  Currently active tab
     * @param  list<string>  $visibleActions  Available action labels
     * @param  list<string>  $breadcrumbs  Breadcrumb trail
     * @param  list<string>  $filters  Active filter labels
     * @param  string|null  $searchQuery  Active search query text
     */
    public function __construct(
        public string $route,
        public string $url,
        public ?string $title = null,
        public ?string $module = null,
        public ?string $resourceType = null,
        public int|string|null $resourceId = null,
        public array $tabs = [],
        public ?string $activeTab = null,
        public array $visibleActions = [],
        public array $breadcrumbs = [],
        public array $filters = [],
        public ?string $searchQuery = null,
    ) {}

    /**
     * Hydrate from a serialized array (e.g. from cache).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            route: $data['route'],
            url: $data['url'],
            title: $data['title'] ?? null,
            module: $data['module'] ?? null,
            resourceType: $data['resource_type'] ?? null,
            resourceId: $data['resource_id'] ?? null,
            tabs: $data['tabs'] ?? [],
            activeTab: $data['active_tab'] ?? null,
            visibleActions: $data['visible_actions'] ?? [],
            breadcrumbs: $data['breadcrumbs'] ?? [],
            filters: $data['filters'] ?? [],
            searchQuery: $data['search_query'] ?? null,
        );
    }

    /**
     * Render as compact XML for system prompt injection (~30 tokens).
     */
    public function toPromptXml(): string
    {
        $attrs = ['route="'.$this->xmlEscape($this->route).'"'];

        if ($this->title !== null) {
            $attrs[] = 'title="'.$this->xmlEscape($this->title).'"';
        }

        if ($this->module !== null) {
            $attrs[] = 'module="'.$this->xmlEscape($this->module).'"';
        }

        if ($this->resourceType !== null) {
            $attrs[] = 'resource_type="'.$this->xmlEscape($this->resourceType).'"';
        }

        if ($this->resourceId !== null) {
            $attrs[] = 'resource_id="'.$this->xmlEscape((string) $this->resourceId).'"';
        }

        if ($this->activeTab !== null) {
            $attrs[] = 'active_tab="'.$this->xmlEscape($this->activeTab).'"';
        }

        if ($this->visibleActions !== []) {
            $attrs[] = 'actions="'.$this->xmlEscape(implode(', ', $this->visibleActions)).'"';
        }

        if ($this->filters !== []) {
            $attrs[] = 'filters="'.$this->xmlEscape(implode(', ', $this->filters)).'"';
        }

        if ($this->searchQuery !== null) {
            $attrs[] = 'search="'.$this->xmlEscape($this->searchQuery).'"';
        }

        return '<current_page '.implode(' ', $attrs).' />';
    }

    /**
     * Serialize for tool response or diagnostics.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'route' => $this->route,
            'url' => $this->url,
            'title' => $this->title,
            'module' => $this->module,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'tabs' => $this->tabs !== [] ? $this->tabs : null,
            'active_tab' => $this->activeTab,
            'visible_actions' => $this->visibleActions !== [] ? $this->visibleActions : null,
            'breadcrumbs' => $this->breadcrumbs !== [] ? $this->breadcrumbs : null,
            'filters' => $this->filters !== [] ? $this->filters : null,
            'search_query' => $this->searchQuery,
        ], fn (mixed $v): bool => $v !== null);
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
