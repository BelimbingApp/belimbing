@props([
    'title' => '',
    'subjects' => [],
    'auditableType' => null,
    'auditableId' => null,
    'sourceCapability',
    'buttonLabel' => null,
    'iconOnly' => false,
])

@php
    $authUser = auth()->user();
    $resolvedSourceCapability = (string) $sourceCapability;
    $canRenderRecordHistory = false;

    if ($authUser !== null && $resolvedSourceCapability !== '') {
        $authorization = app(\App\Base\Authz\Contracts\AuthorizationService::class);
        $actor = \App\Base\Authz\DTO\Actor::forUser($authUser);

        $canRenderRecordHistory = $authorization->can($actor, 'admin.audit.log.list')->allowed
            && $authorization->can($actor, $resolvedSourceCapability)->allowed;
    }

    $subjectHandles = collect($subjects)
        ->filter(fn (mixed $subject): bool => is_array($subject) && isset($subject['name'], $subject['id']) && $subject['name'] !== '' && $subject['id'] !== null && $subject['id'] !== '')
        ->map(fn (array $subject): array => [
            'name' => (string) $subject['name'],
            'id' => is_numeric($subject['id']) ? (int) $subject['id'] : (string) $subject['id'],
            ...(($subject['identifier'] ?? null) !== null && $subject['identifier'] !== '' ? ['identifier' => (string) $subject['identifier']] : []),
        ])
        ->values()
        ->all();

    $primarySubject = $subjectHandles[0] ?? null;
    $fullHistorySearch = $primarySubject !== null
        ? $primarySubject['name'].'#'.$primarySubject['id']
        : null;
    $fullHistoryUrl = $fullHistorySearch !== null && \Illuminate\Support\Facades\Route::has('admin.audit.mutations')
        ? route('admin.audit.mutations', ['search' => $fullHistorySearch])
        : '';
    $componentKey = 'record-history-'.md5(json_encode([
        'subjects' => $subjectHandles,
        'auditable_type' => $auditableType,
        'auditable_id' => $auditableId,
        'source_capability' => $resolvedSourceCapability,
    ], JSON_THROW_ON_ERROR));
@endphp

@if ($canRenderRecordHistory)
    @livewire(\App\Base\Audit\Livewire\AuditLog\SourceHistory::class, [
        'title' => $title !== '' ? $title : __('History'),
        'subjects' => $subjectHandles,
        'auditableType' => $auditableType,
        'auditableId' => $auditableId,
        'allUrl' => $fullHistoryUrl,
        'buttonLabel' => $buttonLabel ?? __('History'),
        'iconOnly' => $iconOnly,
        'sourceCapability' => $resolvedSourceCapability,
    ], key($componentKey))
@endif
