@props(['name', 'label', 'type' => 'text', 'value' => null, 'help' => null, 'required' => false])
@php $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name)); @endphp
<div>
    <label for="{{ $id }}" class="label">{{ $label }} @if($required)<span class="text-red-600" aria-hidden="true">*</span>@endif</label>
    @if($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="3" {{ $attributes->merge(['class' => 'input']) }} @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $id }}_err" @enderror>{{ old($name, $value) }}</textarea>
    @else
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ old($name, $value) }}" {{ $attributes->merge(['class' => 'input']) }} @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $id }}_err" @enderror>
    @endif
    @if($help)<p class="mt-1 text-xs text-slate-500">{{ $help }}</p>@endif
    @error($name)<p id="{{ $id }}_err" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
</div>
