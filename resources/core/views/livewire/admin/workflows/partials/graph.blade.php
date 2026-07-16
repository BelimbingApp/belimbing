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
            guarded: @js(__('Guarded')),
            sla: @js(__('SLA')),
            none: @js(__('None')),
        },
    })"
    @keydown.escape.window="clearSelection()"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('How work moves') }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-muted">
                {{ __('Follow the arrows to see what can happen next. Choose any status or transition to inspect its connections and rules.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1 text-xs">
            <span
                x-show="issueCount() === 0"
                class="inline-flex items-center gap-1.5 text-status-success"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-status-success"></span>
                {{ __('No broken connections') }}
            </span>
            <span
                x-show="issueCount() > 0"
                x-cloak
                class="inline-flex items-center gap-1.5 text-status-danger"
            >
                <span class="h-1.5 w-1.5 rounded-full bg-status-danger"></span>
                <span x-text="`${issueCount()} ${issueCount() === 1 ? @js(__('issue')) : @js(__('issues'))}`"></span>
            </span>
            <span class="text-muted tabular-nums">
                {{ __(':statuses statuses · :transitions transitions', [
                    'statuses' => count($workflowGraph['nodes']),
                    'transitions' => count($workflowGraph['edges']),
                ]) }}
            </span>
        </div>
    </div>

    @if($workflowGraph['nodes'] === [])
        <div class="mt-4 flex min-h-64 flex-col items-center justify-center rounded-xl border border-border-default bg-surface-subtle/40 px-6 text-center">
            <x-icon name="heroicon-o-rectangle-group" class="h-6 w-6 text-muted" />
            <p class="mt-3 text-sm font-medium text-ink">{{ __('No statuses configured.') }}</p>
            <p class="mt-1 max-w-md text-sm text-muted">{{ __('Add statuses and transitions to see how work moves from start to finish.') }}</p>
        </div>
    @else
        <div class="mt-4 overflow-hidden rounded-xl border border-border-default bg-surface-card">
            <div class="flex flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div
                    x-ref="viewport"
                    class="overflow-x-auto overscroll-contain bg-surface-subtle/30"
                    role="group"
                    aria-label="{{ __('Workflow map') }}"
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
                                <marker id="workflow-arrow" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto" markerUnits="strokeWidth">
                                    <path d="M 0 0 L 9 4.5 L 0 9 z" fill="var(--color-muted)" />
                                </marker>
                                <marker id="workflow-arrow-accent" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto" markerUnits="strokeWidth">
                                    <path d="M 0 0 L 9 4.5 L 0 9 z" fill="var(--color-accent)" />
                                </marker>
                                <marker id="workflow-arrow-inactive" markerWidth="9" markerHeight="9" refX="8" refY="4.5" orient="auto" markerUnits="strokeWidth">
                                    <path d="M 0 0 L 9 4.5 L 0 9 z" fill="var(--color-border-input)" />
                                </marker>
                            </defs>

                            <g x-ref="edgePaths"></g>
                        </svg>

                        <template x-for="edge in layoutEdges" :key="`edge-label-${edge.id}`">
                            <button
                                type="button"
                                class="absolute left-0 top-0 z-10 flex min-h-11 min-w-11 items-center justify-center px-1 transition-opacity duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent motion-reduce:transition-none"
                                :class="edgeIsDimmed(edge) ? 'opacity-15' : 'opacity-100'"
                                :style="edgeLabelStyle(edge)"
                                :aria-label="`${labels.transition}: ${edge.from} → ${edge.to} — ${edge.label}`"
                                :aria-pressed="selected?.type === 'edge' && selected.id === edge.id"
                                @click="selectEdge(edge.id)"
                            >
                                <span
                                    class="flex max-w-44 flex-col items-center rounded-lg border bg-surface-card px-2.5 py-1 text-center shadow-sm"
                                    :class="edgeIsEmphasized(edge)
                                        ? 'border-accent text-accent'
                                        : (edge.active ? 'border-border-default text-ink' : 'border-dashed border-border-input text-muted')"
                                >
                                    <span class="text-xs font-medium leading-4" x-text="edge.label"></span>
                                    <span
                                        x-show="edgeRuleSummary(edge)"
                                        class="text-[10px] leading-3 text-muted"
                                        x-text="edgeRuleSummary(edge)"
                                    ></span>
                                </span>
                            </button>
                        </template>

                        <template x-for="node in layoutNodes" :key="node.code">
                            <button
                                type="button"
                                class="absolute left-0 top-0 z-20 flex flex-col justify-center rounded-xl border bg-surface-card px-4 text-left shadow-sm transition-[border-color,box-shadow,opacity] duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent motion-reduce:transition-none"
                                :style="nodeStyle(node)"
                                :class="{
                                    'border-accent ring-2 ring-accent/20': selected?.type === 'node' && selected.id === node.code,
                                    'border-accent': nodeIsEmphasized(node) && !(selected?.type === 'node' && selected.id === node.code),
                                    'border-border-default': !nodeIsEmphasized(node) && !node.missing,
                                    'border-status-danger-border bg-status-danger-subtle': node.missing,
                                    'border-dashed': !node.active && !node.missing,
                                    'opacity-20': nodeIsDimmed(node),
                                    'opacity-60': !nodeIsDimmed(node) && !node.active && !node.missing,
                                }"
                                :aria-pressed="selected?.type === 'node' && selected.id === node.code"
                                :title="`${node.label} (${node.code})`"
                                @click="selectNode(node.code)"
                            >
                                <span class="flex w-full items-start justify-between gap-2">
                                    <span class="min-w-0 text-sm font-medium leading-5 text-ink" x-text="node.label"></span>
                                    <span
                                        x-show="nodeStateLabel(node)"
                                        class="shrink-0 rounded-full bg-surface-subtle px-1.5 py-0.5 text-[10px] font-semibold text-muted"
                                        :class="node.missing ? 'bg-status-danger-subtle text-status-danger' : ''"
                                        x-text="nodeStateLabel(node)"
                                    ></span>
                                </span>
                                <span class="mt-1 truncate font-mono text-xs text-muted" x-text="node.code"></span>
                                <span class="mt-1 text-[10px] tabular-nums text-muted" x-text="connectionText(node)"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <aside class="order-first border-b border-border-default bg-surface-subtle/55 lg:order-none lg:border-b-0 lg:border-l">
                    <div class="sticky top-4 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Inspector') }}</p>
                            <x-ui.button
                                type="button"
                                variant="ghost"
                                size="sm"
                                x-show="selected"
                                x-cloak
                                @click="clearSelection()"
                            >
                                {{ __('Clear') }}
                            </x-ui.button>
                        </div>

                        <div x-show="!selected" class="mt-3">
                            <h3 class="text-sm font-medium text-ink">{{ __('Choose something in the map') }}</h3>
                            <p class="mt-1 text-sm leading-5 text-muted">
                                {{ __('Statuses show where work can wait. Transitions show the action, permission, guard, and timing that move it forward.') }}
                            </p>

                            <dl class="mt-5 divide-y divide-border-default border-y border-border-default text-sm">
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-muted">{{ __('Missing statuses') }}</dt>
                                    <dd class="font-medium tabular-nums" :class="missingCount() > 0 ? 'text-status-danger' : 'text-ink'" x-text="missingCount()"></dd>
                                </div>
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-muted">{{ __('Isolated statuses') }}</dt>
                                    <dd class="font-medium tabular-nums" :class="isolatedCount() > 0 ? 'text-status-warning' : 'text-ink'" x-text="isolatedCount()"></dd>
                                </div>
                            </dl>
                        </div>

                        <template x-if="selectedNode()">
                            <div class="mt-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="text-base font-medium leading-5 text-ink" x-text="selectedNode().label"></h3>
                                        <p class="mt-1 truncate font-mono text-xs text-muted" x-text="selectedNode().code"></p>
                                    </div>
                                    <span
                                        class="shrink-0 rounded-full bg-surface-card px-2 py-0.5 text-[10px] font-semibold text-muted"
                                        x-text="nodeStateLabel(selectedNode()) || (selectedNode().active ? @js(__('Active')) : @js(__('Inactive')))"
                                    ></span>
                                </div>

                                <div x-show="selectedNode().missing" class="mt-4 rounded-lg border border-status-danger-border bg-status-danger-subtle p-3 text-sm text-status-danger">
                                    {{ __('A transition points here, but this status is not configured.') }}
                                </div>

                                <section class="mt-5">
                                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Arrives through') }}</h4>
                                    <div class="mt-2 space-y-1">
                                        <template x-for="edge in incomingEdges(selectedNode().code)" :key="`incoming-${edge.id}`">
                                            <button
                                                type="button"
                                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left text-sm text-ink hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                                @click="selectEdge(edge.id)"
                                            >
                                                <span class="min-w-0 truncate" x-text="edge.label"></span>
                                                <span class="shrink-0 text-xs text-muted" x-text="nodeLabel(edge.from)"></span>
                                            </button>
                                        </template>
                                        <p x-show="incomingEdges(selectedNode().code).length === 0" class="text-sm text-muted">{{ __('This is an entry point.') }}</p>
                                    </div>
                                </section>

                                <section class="mt-5">
                                    <h4 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Leaves through') }}</h4>
                                    <div class="mt-2 space-y-1">
                                        <template x-for="edge in outgoingEdges(selectedNode().code)" :key="`outgoing-${edge.id}`">
                                            <button
                                                type="button"
                                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left text-sm text-ink hover:bg-surface-card focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                                @click="selectEdge(edge.id)"
                                            >
                                                <span class="min-w-0 truncate" x-text="edge.label"></span>
                                                <span class="shrink-0 text-xs text-muted" x-text="nodeLabel(edge.to)"></span>
                                            </button>
                                        </template>
                                        <p x-show="outgoingEdges(selectedNode().code).length === 0" class="text-sm text-muted">{{ __('This is an end point.') }}</p>
                                    </div>
                                </section>
                            </div>
                        </template>

                        <template x-if="selectedEdge()">
                            <div class="mt-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="text-base font-medium leading-5 text-ink" x-text="selectedEdge().label"></h3>
                                        <p class="mt-1 text-xs text-muted">{{ __('Transition') }}</p>
                                    </div>
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="selectedEdge().active ? 'bg-status-success-subtle text-status-success' : 'bg-surface-card text-muted'"
                                        x-text="selectedEdge().active ? @js(__('Active')) : @js(__('Inactive'))"
                                    ></span>
                                </div>

                                <div class="mt-4 flex items-center gap-2 text-sm">
                                    <button type="button" class="min-w-0 truncate rounded-lg bg-surface-card px-2 py-1.5 text-ink hover:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent" @click="selectNode(selectedEdge().from)" x-text="nodeLabel(selectedEdge().from)"></button>
                                    <span class="shrink-0 text-muted" aria-hidden="true">→</span>
                                    <button type="button" class="min-w-0 truncate rounded-lg bg-surface-card px-2 py-1.5 text-ink hover:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent" @click="selectNode(selectedEdge().to)" x-text="nodeLabel(selectedEdge().to)"></button>
                                </div>

                                <dl class="mt-5 divide-y divide-border-default border-y border-border-default text-sm">
                                    <div class="py-2.5">
                                        <dt class="text-xs text-muted">{{ __('Capability') }}</dt>
                                        <dd class="mt-0.5 break-all font-mono text-xs text-ink" x-text="selectedEdge().capability ?? labels.none"></dd>
                                    </div>
                                    <div class="py-2.5">
                                        <dt class="text-xs text-muted">{{ __('Guard') }}</dt>
                                        <dd class="mt-0.5 break-all text-ink" x-text="selectedEdge().guard ?? labels.none"></dd>
                                    </div>
                                    <div class="py-2.5">
                                        <dt class="text-xs text-muted">{{ __('Action') }}</dt>
                                        <dd class="mt-0.5 break-all text-ink" x-text="selectedEdge().action ?? labels.none"></dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 py-2.5">
                                        <dt class="text-xs text-muted">{{ __('SLA') }}</dt>
                                        <dd class="text-ink tabular-nums" x-text="selectedEdge().sla"></dd>
                                    </div>
                                </dl>
                            </div>
                        </template>
                    </div>
                </aside>
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-border-default bg-surface-card px-4 py-2.5 text-xs text-muted" aria-label="{{ __('Graph legend') }}">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm border border-border-input bg-surface-card"></span>
                    {{ __('Status') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm border border-dashed border-border-input bg-surface-subtle"></span>
                    {{ __('Inactive') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-sm border border-status-danger-border bg-status-danger-subtle"></span>
                    {{ __('Configuration issue') }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-0.5 w-4 bg-accent"></span>
                    {{ __('Selected connection') }}
                </span>
            </div>
        </div>
    @endif
</div>
