<div
    x-data="blbWorkflowGraph({
        nodes: @js($workflowGraph['nodes']),
        edges: @js($workflowGraph['edges']),
        labels: {
            start: @js(__('Start')),
            end: @js(__('End')),
            isolated: @js(__('Isolated')),
            inactive: @js(__('Inactive')),
            missing: @js(__('Missing')),
            transition: @js(__('Transition')),
            selectedStatus: @js(__('Selected status')),
            selectedTransition: @js(__('Selected transition')),
            guarded: @js(__('Guarded')),
            sla: @js(__('SLA')),
            selectionHint: @js(__('Select a status or transition to highlight its connected path.')),
        },
    })"
    @keydown.escape.window="clearSelection()"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('State machine') }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                {{ __('Select a status or transition to highlight its connected path. Scroll horizontally when the workflow extends beyond the viewport.') }}
            </p>
        </div>

        <p class="text-sm text-muted tabular-nums">
            {{ trans_choice(':count status|:count statuses', count($workflowGraph['nodes']), ['count' => count($workflowGraph['nodes'])]) }}
            <span aria-hidden="true">·</span>
            {{ trans_choice(':count transition|:count transitions', count($workflowGraph['edges']), ['count' => count($workflowGraph['edges'])]) }}
        </p>
    </div>

    @if($workflowGraph['nodes'] === [])
        <div class="mt-4 flex min-h-64 flex-col items-center justify-center rounded-xl border border-border-default bg-surface-subtle/40 px-6 text-center">
            <x-icon name="heroicon-o-rectangle-group" class="h-6 w-6 text-muted" />
            <p class="mt-3 text-sm font-medium text-ink">{{ __('No statuses configured.') }}</p>
            <p class="mt-1 max-w-md text-sm text-muted">{{ __('Add statuses and transitions to see this workflow as a state machine.') }}</p>
        </div>
    @else
        <div class="mt-4 overflow-hidden rounded-xl border border-border-default bg-surface-subtle/40">
            <div
                x-ref="viewport"
                class="overflow-auto overscroll-contain"
                role="group"
                aria-label="{{ __('Workflow state machine') }}"
            >
                <div
                    class="relative"
                    :style="`width: ${canvasWidth}px; height: ${canvasHeight}px;`"
                >
                    <svg
                        class="absolute inset-0 h-full w-full overflow-visible"
                        :viewBox="`0 0 ${canvasWidth} ${canvasHeight}`"
                        aria-hidden="true"
                    >
                        <defs>
                            <marker id="workflow-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth">
                                <path d="M 0 0 L 8 4 L 0 8 z" fill="var(--color-muted)" />
                            </marker>
                            <marker id="workflow-arrow-accent" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth">
                                <path d="M 0 0 L 8 4 L 0 8 z" fill="var(--color-accent)" />
                            </marker>
                            <marker id="workflow-arrow-inactive" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth">
                                <path d="M 0 0 L 8 4 L 0 8 z" fill="var(--color-border-input)" />
                            </marker>
                        </defs>

                        <template x-for="edge in layoutEdges" :key="`edge-path-${edge.id}`">
                            <g>
                                <path
                                    :d="edge.path"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    :stroke-dasharray="edge.active ? null : '6 6'"
                                    :marker-end="edgeIsEmphasized(edge)
                                        ? 'url(#workflow-arrow-accent)'
                                        : (edge.active ? 'url(#workflow-arrow)' : 'url(#workflow-arrow-inactive)')"
                                    :class="edgeIsEmphasized(edge)
                                        ? 'text-accent'
                                        : (edge.active ? 'text-muted' : 'text-border-input')"
                                ></path>
                                <path
                                    :d="edge.path"
                                    fill="none"
                                    stroke="transparent"
                                    stroke-width="18"
                                    class="cursor-pointer"
                                    @click="selectEdge(edge.id)"
                                ></path>
                            </g>
                        </template>
                    </svg>

                    <template x-for="edge in layoutEdges" :key="`edge-label-${edge.id}`">
                        <button
                            type="button"
                            class="absolute left-0 top-0 z-10 flex min-h-11 min-w-11 items-center justify-center px-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :style="edgeLabelStyle(edge)"
                            :aria-label="`${labels.transition}: ${edge.from} → ${edge.to} — ${edge.label}`"
                            :aria-pressed="selected?.type === 'edge' && selected.id === edge.id"
                            @click="selectEdge(edge.id)"
                        >
                            <span
                                class="max-w-40 truncate rounded-md border bg-surface-card px-2 py-0.5 text-xs font-medium shadow-sm transition-colors"
                                :class="edgeIsEmphasized(edge)
                                    ? 'border-accent text-accent'
                                    : (edge.active ? 'border-border-default text-ink' : 'border-border-input text-muted')"
                                x-text="edge.label"
                            ></span>
                        </button>
                    </template>

                    <template x-for="node in layoutNodes" :key="node.code">
                        <button
                            type="button"
                            class="absolute left-0 top-0 z-20 flex flex-col justify-center rounded-xl border bg-surface-card px-4 text-left shadow-sm transition-[border-color,box-shadow,opacity] focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                            :style="nodeStyle(node)"
                            :class="{
                                'border-accent ring-2 ring-accent/20': selected?.type === 'node' && selected.id === node.code,
                                'border-accent': nodeIsEmphasized(node) && !(selected?.type === 'node' && selected.id === node.code),
                                'border-border-default': !nodeIsEmphasized(node) && !node.missing,
                                'border-status-danger-border bg-status-danger-subtle': node.missing,
                                'border-dashed opacity-60': !node.active && !node.missing,
                            }"
                            :aria-pressed="selected?.type === 'node' && selected.id === node.code"
                            :title="`${node.label} (${node.code})`"
                            @click="selectNode(node.code)"
                        >
                            <span class="flex w-full items-center justify-between gap-2">
                                <span class="min-w-0 truncate text-sm font-medium text-ink" x-text="node.label"></span>
                                <span
                                    x-show="nodeStateLabel(node)"
                                    class="shrink-0 rounded-full bg-surface-subtle px-1.5 py-0.5 text-[10px] font-semibold text-muted"
                                    x-text="nodeStateLabel(node)"
                                ></span>
                            </span>
                            <span class="mt-1 truncate font-mono text-xs text-muted" x-text="node.code"></span>
                        </button>
                    </template>
                </div>
            </div>

            <div class="flex min-h-12 flex-wrap items-center justify-between gap-3 border-t border-border-default bg-surface-card px-4 py-2">
                <p class="text-sm text-muted" aria-live="polite" x-text="selectionText()"></p>

                <x-ui.button
                    type="button"
                    variant="ghost"
                    size="sm"
                    x-show="selected"
                    x-cloak
                    @click="clearSelection()"
                >
                    {{ __('Clear selection') }}
                </x-ui.button>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted" aria-label="{{ __('Graph legend') }}">
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full border border-border-input bg-surface-card"></span>
                {{ __('Active status') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full border border-dashed border-border-input bg-surface-subtle"></span>
                {{ __('Inactive status or transition') }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="h-0.5 w-4 bg-accent"></span>
                {{ __('Selected path') }}
            </span>
        </div>
    @endif
</div>
