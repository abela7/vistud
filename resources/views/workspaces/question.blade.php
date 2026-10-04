{{--
    A question on a page of its own, or a new one (App\Http\Controllers\QuestionPageController):
    the question and its answer, and the options beside them (App\Livewire\Workspaces\QuestionPage).
--}}
@php
    use Illuminate\Support\Str;

    $shown = $question?->text !== null ? Str::limit($question->text, 60) : 'New question';
@endphp
<x-layouts.app :title="$shown.' · Questions · '.$workspace->name" :workspace="$workspace" :section="($question?->moduleId ?? ($module ?? null)) !== null ? 'modules' : 'progress'">
    <livewire:workspaces.question-page
        :workspace-id="$workspace->id"
        :question-id="$question?->id"
        :in-module="$module ?? null"
        :about-topic="$topic ?? null"
        :from="$from"
        :with-status="$status ?? null" />
    <livewire:workspaces.ai-assist :workspace-id="$workspace->id" key="ai-assist" />
</x-layouts.app>
