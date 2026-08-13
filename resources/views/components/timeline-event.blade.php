@props(['event'])

@php
    $type = $event->type->value;
    $label = match ($type) {
        'lead.received' => 'Lead reçu',
        'processing.started' => 'Traitement démarré',
        'lead.normalized' => 'Données normalisées',
        'validation.succeeded' => 'Validation réussie',
        'validation.failed' => 'Validation échouée',
        'duplicate.checked' => 'Recherche de doublons terminée',
        'duplicate.detected' => 'Doublon détecté',
        'score.calculated' => 'Score calculé',
        'lead.classified' => 'Lead classifié',
        'lead.routing_decided' => 'Assignation déterminée',
        'lead.qualified' => 'Lead qualifié',
        'transmission.succeeded' => 'Transmission simulée réussie',
        'transmission.failed' => 'Transmission simulée échouée',
        'lead.routed' => 'Lead routé',
        'review.requested' => 'Examen demandé',
        'review.corrected' => 'Correction humaine enregistrée',
        'review.reprocessing_requested' => 'Retraitement après revue demandé',
        'processing.manual_retry_requested' => 'Reprise manuelle demandée',
        'processing.retry_scheduled' => 'Nouvelle tentative planifiée',
        'processing.attempt_failed' => 'Tentative échouée',
        'processing.failed' => 'Traitement échoué',
        default => 'Événement de traitement',
    };
    $contextLabels = [
        'score' => 'Score',
        'classification' => 'Classification',
        'assigned_to' => 'Assignation',
        'service' => 'Service',
        'rule_codes' => 'Règles',
        'reason_codes' => 'Motifs',
        'candidate_ids' => 'Identifiants candidats',
        'retry_delay' => 'Délai de reprise',
        'error_code' => 'Code d’erreur',
        'changed_fields' => 'Champs corrigés',
        'cycle_id' => 'Cycle de traitement',
    ];
    $visibleContext = array_intersect_key($event->context ?? [], $contextLabels);
@endphp

<li class="relative pl-8">
    <span class="absolute left-0 top-1.5 h-3 w-3 rounded-full bg-blue-700 ring-4 ring-blue-100" aria-hidden="true"></span>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
        <h3 class="font-bold text-slate-900">{{ $label }}</h3>
        <time datetime="{{ $event->created_at->toIso8601String() }}" class="text-sm text-slate-600">
            {{ $event->created_at->format('d/m/Y à H:i:s') }}
        </time>
    </div>

    @if ($event->from_status || $event->to_status)
        <p class="mt-1 text-sm text-slate-600">
            @if ($event->from_status)
                {{ $event->from_status->value }}
                <span aria-hidden="true">→</span>
            @endif
            {{ $event->to_status?->value }}
        </p>
    @endif

    @if ($visibleContext !== [])
        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
            @foreach ($visibleContext as $key => $value)
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <dt class="font-semibold text-slate-700">{{ $contextLabels[$key] }}</dt>
                    <dd class="mt-0.5 break-words text-slate-900">
                        {{ is_array($value) ? implode(', ', $value) : $value }}
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</li>
