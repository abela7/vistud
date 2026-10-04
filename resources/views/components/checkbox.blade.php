{{-- A checkbox with its label; the whole row is the target (DESIGN.md §5.2). A `hint` is one quiet line under the label. --}}
@props(['name', 'label', 'checked' => false, 'value' => '1', 'hint' => null])
@php($id = $attributes->get('id', "field-{$name}"))
<label for="{{ $id }}" @class(['checkbox-row', 'checkbox-row-hinted' => $hint !== null])>
    <span class="checkbox-box">
        <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($checked) @if ($hint !== null) aria-describedby="{{ $id }}-hint" @endif {{ $attributes->except('id')->class('checkbox') }}>
        <x-icon name="check" class="checkbox-mark size-3.5" stroke-width="3" />
    </span>
    <span class="text-sm">{{ $label }}@if ($hint !== null)<span id="{{ $id }}-hint" class="block text-fg-muted">{{ $hint }}</span>@endif</span>
</label>
