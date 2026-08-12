@props(['classification'])

@php
    $value = $classification instanceof \BackedEnum ? $classification->value : (string) $classification;
    $styles = match ($value) {
        'HOT' => 'border-rose-200 bg-rose-50 text-rose-800',
        'WARM' => 'border-orange-200 bg-orange-50 text-orange-900',
        'LOW' => 'border-slate-200 bg-slate-100 text-slate-700',
        default => 'border-slate-200 bg-white text-slate-600',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-bold', $styles]) }}>
    {{ $value !== '' ? $value : 'En attente' }}
</span>
