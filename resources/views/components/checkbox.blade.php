{{-- A checkbox with its label; the whole row is the target (DESIGN.md §5.2). --}}
@props(['name', 'label', 'checked' => false, 'value' => '1'])
@php($id = $attributes->get('id', "field-{$name}"))
<label for="{{ $id }}" class="checkbox-row">
    <span class="checkbox-box">
        <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes->except('id')->class('checkbox') }}>
        <x-icon name="check" class="checkbox-mark size-3.5" stroke-width="3" />
    </span>
    <span class="text-sm">{{ $label }}</span>
</label>
