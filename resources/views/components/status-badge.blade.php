@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $styles = match ($value) {
        'ROUTED' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'QUALIFIED' => 'border-sky-200 bg-sky-50 text-sky-800',
        'RECEIVED', 'VALIDATING' => 'border-blue-200 bg-blue-50 text-blue-800',
        'NEEDS_REVIEW' => 'border-amber-200 bg-amber-50 text-amber-900',
        'DUPLICATE' => 'border-violet-200 bg-violet-50 text-violet-800',
        'INVALID', 'FAILED' => 'border-red-200 bg-red-50 text-red-800',
        default => 'border-slate-200 bg-slate-100 text-slate-700',
    };
    $label = match ($value) {
        'RECEIVED' => 'Reçu',
        'VALIDATING' => 'Validation en cours',
        'INVALID' => 'Invalide',
        'DUPLICATE' => 'Doublon',
        'QUALIFIED' => 'Qualifié',
        'NEEDS_REVIEW' => 'À examiner',
        'ROUTED' => 'Routé',
        'FAILED' => 'Échec',
        default => $value,
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-bold', $styles]) }}>
    {{ $label }}
</span>
