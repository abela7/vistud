{{-- A person's initials in a circle. Decorative: the name is always shown or labelled beside it. --}}
@props(['name'])
<span {{ $attributes->class('avatar') }} aria-hidden="true">{{ collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>
