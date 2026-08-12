@props(['label', 'value', 'href', 'description' => null])

<a href="{{ $href }}" {{ $attributes->class('group block rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-blue-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2') }}>
    <span class="text-sm font-bold text-slate-600">{{ $label }}</span>
    <span class="mt-2 block text-3xl font-black tracking-tight text-slate-950">{{ number_format($value, 0, ',', ' ') }}</span>
    @if ($description)
        <span class="mt-2 block text-sm text-slate-500">{{ $description }}</span>
    @endif
    <span class="mt-4 inline-flex text-sm font-bold text-blue-700 group-hover:text-blue-900">Consulter <span aria-hidden="true" class="ml-1">→</span></span>
</a>
