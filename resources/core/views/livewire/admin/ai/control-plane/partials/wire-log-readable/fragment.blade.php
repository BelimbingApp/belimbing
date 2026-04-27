<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Support\Str;

/** @var array<string, mixed> $fragment */
/** @var string $runId */
$kind = $fragment['kind'] ?? 'raw';

if ($kind === 'empty_run') {
    $label = __('× :count keep-alives', ['count' => $fragment['count']]);
    $title = __('entries #:first–#:last', [
        'first' => $fragment['first_entry_number'],
        'last' => $fragment['last_entry_number'],
    ]);
    echo '<span class="inline-flex items-center rounded-full bg-surface-subtle px-2 py-0.5 text-[11px] text-muted" title="'.htmlspecialchars($title, ENT_QUOTES).'">';
    echo htmlspecialchars($label, ENT_QUOTES);
    echo '</span>';

    return;
}

$entryNumber = $fragment['entry_number'] ?? 0;
$variant = match ($kind) {
    'content' => 'default',
    'reasoning' => 'info',
    'tool_call' => 'accent',
    'tool_args' => 'info',
    'finish_reason' => $fragment['severity'] ?? 'default',
    'decode_error' => 'warning',
    'done' => 'default',
    'empty' => 'default',
    default => 'default',
};

$prefix = match ($kind) {
    'content' => __('content'),
    'reasoning' => __('reasoning'),
    'tool_call' => __('tool'),
    'tool_args' => __('args'),
    'finish_reason' => __('finish'),
    'decode_error' => __('decode'),
    'done' => __('done'),
    'empty' => __('keep-alive'),
    default => __('raw'),
};

$preview = $fragment['text'] ?? '';
$displayText = $preview === '' ? '' : ': '.Str::limit((string) $preview, 60, '…');
$gapMs = $fragment['gap_ms'] ?? null;
$showGap = $fragment['has_gap_warning'] ?? false;
$rawLine = $fragment['raw_line'] ?? '';
?>
@if ($showGap && $gapMs !== null)
    <span class="inline-flex items-center rounded-full bg-status-warning-subtle px-2 py-0.5 text-[11px] text-status-warning" title="{{ __('Inter-fragment gap') }}">
        {{ __('stalled :sec sec', ['sec' => number_format($gapMs / 1000, 1)]) }}
    </span>
@endif

<div
    id="wire-log-entry-{{ $entryNumber }}"
    x-data="{
        open: false,
        init() {
            const desired = '#wire-log-entry-{{ $entryNumber }}';
            if (window.location.hash === desired) {
                this.open = true;
            }
            window.addEventListener('hashchange', () => {
                if (window.location.hash === desired) {
                    this.open = true;
                }
            });
            window.addEventListener('wire-log-open-entry', (e) => {
                if (e?.detail?.entryNumber === {{ (int) $entryNumber }}) {
                    this.open = true;
                }
            });
        },
    }"
    x-init="init()"
    class="inline-flex flex-col"
>
    <button
        type="button"
        @click="open = !open"
        class="group inline-flex items-center gap-1 rounded-full border border-border-default/60 bg-surface-card px-2 py-0.5 text-[11px] text-left hover:border-accent/40"
        :class="open ? 'border-accent/60 bg-surface-subtle' : ''"
        title="{{ __('Show payload for #:n', ['n' => $entryNumber]) }} {{ $fragment['at'] ?? '' }}"
    >
        <span class="font-mono text-muted/70 group-hover:text-accent">#{{ $entryNumber }}</span>
        <x-ui.badge :variant="$variant">{{ $prefix }}</x-ui.badge>
        @if ($displayText !== '')
            <span class="truncate text-ink">{{ $displayText }}</span>
        @endif
    </button>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="mt-1 max-w-[40rem] basis-full rounded-md border border-border-default/60 bg-surface-subtle/60 p-2 font-mono text-[11px] text-muted"
    >
        @if ($rawLine !== '')
            <pre class="max-h-48 overflow-auto whitespace-pre-wrap break-words text-ink">{{ $rawLine }}</pre>
        @else
            <span class="italic">{{ __('No raw payload captured for this fragment.') }}</span>
        @endif
        <a
            href="{{ route('admin.ai.runs.wire-log-entry', ['runId' => $runId, 'entryNumber' => $entryNumber]) }}"
            target="_blank"
            rel="noreferrer"
            class="mt-1 inline-flex items-center gap-1 text-accent hover:underline"
        >
            <x-icon name="heroicon-o-arrow-top-right-on-square" class="size-3" />
            {{ __('Open raw entry') }}
        </a>
    </div>
</div>
