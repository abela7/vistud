{{-- Mockup: a workspace section's heading. $title, optional $action. --}}
<div class="flex flex-wrap items-start justify-between gap-4">
    <div class="flex items-center gap-3">
        <span class="ws-chip ws-colour-green size-11 max-sm:hidden"><x-icon name="microscope" class="size-5" /></span>
        <div>
            <p class="text-sm text-fg-muted">Biology · BIO101</p>
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $title }}</h1>
        </div>
    </div>
    @isset($action)
        <x-button variant="primary" icon="plus">{{ $action }}</x-button>
    @endisset
</div>
