{{-- Reviewing flashcards (App\Http\Controllers\FlashcardReviewController). --}}
<x-layouts.app :title="'Review flashcards · '.$workspace->name" :workspace="$workspace" section="flashcards">
    <div class="mx-auto max-w-3xl">
        <livewire:workspaces.flashcard-review :workspace-id="$workspace->id" :topic-id="$topicId" :module-id="$moduleId" :early="$early" />
    </div>
</x-layouts.app>
