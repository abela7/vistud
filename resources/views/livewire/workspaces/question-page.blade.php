{{--
    A question on a page of its own, or a new one (App\Livewire\Workspaces\QuestionPage): the question and
    its answer on the left; on the right the options (status, module, topic, whether to ask the teacher,
    when it was asked, deleting it). One Save keeps it all. On a phone the options come after the answer,
    and Save last.
--}}
@php
    use App\Study\Questions;
    use Illuminate\Support\Carbon;

    $look = ['pending' => ['circle-dot', 'blue'], 'stuck' => ['circle-alert', 'red'], 'answered' => ['circle-check', 'green']];
    $new = $questionId === null;
@endphp
<div class="space-y-5">
    <x-workspace.section-header :workspace="$workspace" :title="$new ? 'New question' : 'Question'" :back-href="$backUrl" :back-to="$backTo"
        :eyebrow="$workspace->name.($module ? ' · '.$module->title : '')">
        @unless ($new)
            <x-ai-menu kind="question" :id="$questionId" :label="\Illuminate\Support\Str::limit($question, 60)" />
            <span @class(['question-status', "ws-colour-{$look[$status][1]}"])>
                <x-icon :name="$look[$status][0]" class="size-4" />{{ Questions::STATUSES[$status] }}
            </span>
        @endunless
    </x-workspace.section-header>

    <form wire:submit="save" novalidate class="question-layout" x-data="{ sure: false }" aria-label="{{ $new ? 'New question' : 'Question' }}">
        <div class="question-main space-y-5">
            <div class="question-panel">
                <label for="question-text" class="field-label">What don't you get?</label>
                <textarea id="question-text" class="input question-input mt-2" rows="3" maxlength="{{ Questions::MAX_TEXT }}" wire:model="question" placeholder="Write the question in your own words" @if ($new) autofocus @endif></textarea>
                @error('question') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>

            <div class="question-panel">
                <label for="question-answer" class="field-label">Answer <span class="font-normal text-fg-muted">(optional)</span></label>
                <p class="field-hint mt-1">What you found out, in your own words. Mark it Answered when it makes sense.</p>
                <textarea id="question-answer" class="input question-input mt-2" rows="{{ $status === 'answered' ? 9 : 6 }}" maxlength="{{ Questions::MAX_ANSWER }}" wire:model="answer" @if (! $new && $status === 'answered' && $answer === '') autofocus @endif></textarea>
                @error('answer') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>
        </div>

        <aside class="question-options space-y-4" aria-label="Options">
            <section class="question-panel space-y-5">
                <fieldset>
                    <legend class="field-label mb-2">Status</legend>
                    <div class="status-choices">
                        @foreach (Questions::STATUSES as $key => $word)
                            <label @class(['status-choice', "ws-colour-{$look[$key][1]}"])>
                                <input type="radio" name="question-status" value="{{ $key }}" wire:model.live="status">
                                <x-icon :name="$look[$key][0]" class="size-5" />
                                <span>{{ $word }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('status') <p class="field-error mt-2">{{ $message }}</p> @enderror
                </fieldset>

                <div class="field">
                    <label for="question-module" class="field-label">Module</label>
                    <select id="question-module" class="input mt-2" wire:model.live="moduleId">
                        <option value="">No module</option>
                        @foreach ($modules as $option)
                            <option value="{{ $option->id }}">{{ $option->title }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($new && $topics !== [])
                    <div class="field">
                        <label for="question-topic" class="field-label">Topic <span class="font-normal text-fg-muted">(optional)</span></label>
                        <select id="question-topic" class="input mt-2" wire:model="topicId">
                            <option value="">None</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @elseif (! $new && $topicId !== '' && isset($topicNames[$topicId]))
                    <div>
                        <p class="field-label">Topic</p>
                        <p class="mt-1 text-sm text-fg-muted">{{ $topicNames[$topicId] }}</p>
                    </div>
                @endif

                <x-checkbox name="askTeacher" label="Ask the teacher" wire:model="askTeacher" />

                @if ($asked)
                    <p class="question-asked">
                        <x-icon name="clock" class="size-4 shrink-0" />
                        <span>Asked {{ Carbon::parse($asked->askedAt)->diffForHumans() }}</span>
                    </p>
                @endif
            </section>

            @unless ($new)
                <section class="question-panel question-danger" aria-labelledby="question-delete-label">
                    <h2 id="question-delete-label" class="field-label">Delete this question</h2>
                    <p class="field-hint mt-1">It leaves your lists. What you wrote in the journal stays.</p>
                    <div class="mt-3">
                        <button type="button" class="btn btn-secondary" x-show="! sure" x-on:click="sure = true"><x-icon name="trash-2" class="size-4" />Delete</button>
                        <div class="flex flex-wrap gap-2" x-show="sure" x-cloak>
                            <button type="button" class="btn btn-danger" wire:click="delete">Delete for good</button>
                            <button type="button" class="btn btn-ghost" x-on:click="sure = false">Keep it</button>
                        </div>
                    </div>
                </section>
            @endunless
        </aside>

        <div class="question-actions">
            <a href="{{ $backUrl }}" class="btn btn-secondary">Cancel</a>
            <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
        </div>
    </form>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
