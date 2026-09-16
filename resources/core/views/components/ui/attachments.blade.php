@props([
    'items' => [],
    'emptyMessage' => null,
])

@php
    $resolvedItems = collect($items);
@endphp

@if($resolvedItems->isEmpty())
    <p {{ $attributes->class(['text-sm text-muted']) }}>{{ $emptyMessage ?? __('No attachments.') }}</p>
@else
    <ul {{ $attributes->class(['divide-y divide-border-default rounded-xl border border-border-default bg-surface-card']) }}>
        @foreach($resolvedItems as $item)
            @php
                $reference = $item instanceof \App\Base\Media\Models\MediaAttachment ? $item : null;
                $asset = $reference?->mediaAsset;
                $publicId = $reference?->public_id ?? data_get($item, 'public_id');
                $filename = $asset?->original_filename ?? data_get($item, 'filename') ?? __('Attachment');
                $size = $asset?->file_size ?? data_get($item, 'size');
                $url = $publicId ? route('media.attachments.download', ['attachment' => $publicId]) : data_get($item, 'url');
            @endphp
            <li wire:key="attachment-{{ $publicId ?? $loop->index }}" class="flex min-w-0 items-center justify-between gap-3 px-table-cell-x py-table-cell-y">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">{{ $filename }}</p>
                    @if($size !== null)
                        <p class="text-xs tabular-nums text-muted">{{ \Illuminate\Support\Number::fileSize((int) $size) }}</p>
                    @endif
                </div>
                @if($url)
                    <x-ui.link kind="download" :href="$url" :navigate="false">{{ __('Download') }}</x-ui.link>
                @endif
            </li>
        @endforeach
    </ul>
@endif
