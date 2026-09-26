{{--
    The admin audit log (App\Livewire\Admin\AuditLog\Index). Each entry reads
    as a sentence: who, what, to whom, when. Names are looked up for display;
    the log itself holds only IDs.
--}}
@php
    use App\Livewire\Admin\AuditLog\Index;
    use Illuminate\Support\Carbon;

    $icons = ['workspace' => 'layout-dashboard', 'admin' => 'triangle-alert', 'account' => 'users', 'role' => 'shield', 'invitation' => 'mail', 'two_factor' => 'key-round'];
    $name = fn (?string $id) => $id === null ? null : ($names[$id] ?? 'A deleted account');
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="w-full max-w-xs space-y-1.5">
            <label for="audit-action" class="field-label">Show</label>
            <select id="audit-action" class="input" wire:model.live="action">
                <option value="">All activity</option>
                @foreach (Index::ACTIONS as $code => $phrase)
                    <option value="{{ $code }}">{{ ucfirst($phrase) }}</option>
                @endforeach
            </select>
        </div>
        <p class="text-sm font-semibold text-fg-muted" aria-live="polite">
            {{ $hasMore ? 'Showing the latest '.count($entries) : count($entries).' '.Str::plural('entry', count($entries)) }}
        </p>
    </div>

    @if ($entries === [])
        <p class="rounded-xl border border-dashed border-border-strong px-5 py-8 text-center text-fg-muted">Nothing recorded yet.</p>
    @else
        <ol class="account-list divide-y divide-divider" role="list">
            @foreach ($entries as $entry)
                @php
                    $at = Carbon::parse($entry['occurred_at'], 'UTC');
                    $actor = $entry['actor_type'] === 'system' ? 'The server console' : $name($entry['actor_user_id']);
                    $target = $entry['target_type'] === 'user' && in_array($entry['action'], Index::NAMES_TARGET, true) ? $name($entry['target_id']) : null;
                    $self = $target !== null && $entry['target_id'] === $entry['actor_user_id'];
                    $phrase = Index::ACTIONS[$entry['action']] ?? $entry['action'];
                @endphp
                <li wire:key="audit-{{ $entry['id'] }}" class="flex items-start gap-3 px-4 py-3 sm:px-5">
                    <span class="avatar mt-0.5 shrink-0" aria-hidden="true"><x-icon :name="$icons[strtok($entry['action'], '.')] ?? 'scroll-text'" class="size-4" /></span>
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <p class="break-words">
                            <span class="font-semibold">{{ $actor }}</span>
                            {{ $phrase }}
                            @if ($target !== null)
                                <span class="font-semibold">{{ $self ? 'their own account' : $target }}</span>
                            @endif
                            @if ($entry['action'] === 'admin.access_denied')
                                <span class="badge badge-warning ml-1 align-middle"><x-icon name="triangle-alert" class="size-3.5" />Refused</span>
                            @endif
                        </p>
                        <p class="text-sm text-fg-muted">
                            <time datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('j M Y, H:i:s') }} UTC">{{ $at->diffForHumans() }}</time>
                            @if ($entry['actor_role'])
                                · as {{ $entry['actor_role'] }}
                            @endif
                            @if ($entry['ip'])
                                · {{ $entry['ip'] }}
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($hasMore)
            <x-button wire:click="showMore" wire:loading.attr="aria-busy" wire:target="showMore" busy-label="Loading…">Show more</x-button>
        @endif
    @endif
</div>
