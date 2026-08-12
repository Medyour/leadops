@props(['name', 'label', 'required' => false, 'help' => null])

<div>
    <label for="{{ $name }}" class="block text-sm font-bold text-slate-800">
        {{ $label }}
        @if ($required)
            <span class="text-red-700" aria-hidden="true">*</span>
            <span class="sr-only">(obligatoire)</span>
        @endif
    </label>

    @if ($help)
        <p id="{{ $name }}-help" class="mt-1 text-sm text-slate-600">{{ $help }}</p>
    @endif

    <div class="mt-2">{{ $slot }}</div>

    @error($name)
        <p id="{{ $name }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>
    @enderror
</div>
