@props([
    'title' => '',
    'subjects' => [],
    'auditableType' => null,
    'auditableId' => null,
    'sourceCapability',
    'buttonLabel' => null,
    'iconOnly' => false,
    'subjectLabel' => null,
    // When false, the source page capability alone authorizes local History.
    // Full Audit Log navigation and trace timelines stay off — those still need
    // admin.audit.log.list so ordinary maintainers never inherit the admin log.
    'requireAuditListCapability' => true,
])

@php
    $authUser = auth()->user();
    $resolvedSourceCapability = (string) $sourceCapability;
    $requiresAuditList = filter_var($requireAuditListCapability, FILTER_VALIDATE_BOOLEAN);
    $canRenderRecordHistory = false;

    if ($authUser !== null && $resolvedSourceCapability !== '') {
        $authorization = app(\App\Base\Authz\Contracts\AuthorizationService::class);
        $actor = \App\Base\Authz\DTO\Actor::forUser($authUser);

        $canRenderRecordHistory = $authorization->can($actor, $resolvedSourceCapability)->allowed
            && (! $requiresAuditList || $authorization->can($actor, 'admin.audit.log.list')->allowed);
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

    $primarySubject = count($subjectHandles) === 1 ? $subjectHandles[0] : null;
    $fullHistorySearch = $primarySubject !== null
        ? $primarySubject['name'].'#'.$primarySubject['id']
        : null;
    // Local-only History never offers the admin mutations page: that URL is the
    // broad audit surface the source-capability-only path must not inherit.
    $fullHistoryUrl = $requiresAuditList
        && $fullHistorySearch !== null
        && \Illuminate\Support\Facades\Route::has('admin.audit.mutations')
        ? route('admin.audit.mutations', ['search' => $fullHistorySearch])
        : '';
    $componentKey = 'record-history-'.md5(json_encode([
        'subjects' => $subjectHandles,
        'auditable_type' => $auditableType,
        'auditable_id' => $auditableId,
        'source_capability' => $resolvedSourceCapability,
        'subject_label' => $subjectLabel,
        'require_audit_list' => $requiresAuditList,
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
        'subjectLabel' => $subjectLabel,
        'sourceCapability' => $resolvedSourceCapability,
        'requireAuditListCapability' => $requiresAuditList,
    ], key($componentKey))
@endif
