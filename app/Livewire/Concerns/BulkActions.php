<?php

namespace App\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Reusable bulk actions for Livewire list components (DESIGN.md §5).
 * Receives keys from the browser in the format "type:id", validates them,
 * checks ownership through domain services, and collects done/skipped items
 * without failing the entire batch.
 */
trait BulkActions
{
    /** @var list<string> */
    public array $bulkKeys = [];

    /**
     * Executes a bulk action on a set of selected items.
     *
     * @param  string  $action  The action to perform (e.g. 'trash', 'restore', 'delete', 'move', 'status')
     * @param  array<mixed>  $keys  Keys in "type:id" format
     * @param  array<string, mixed>  $payload  Optional parameters
     */
    public function bulk(string $action, array $keys, array $payload = []): void
    {
        if (count($keys) > 200) {
            throw ValidationException::withMessages([
                'keys' => 'At most 200 items can be selected at once.',
            ]);
        }

        $allowedTypes = $this->allowedBulkTypes();
        $validItems = [];

        foreach ($keys as $key) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_-]+:[a-zA-Z0-9_-]+$/', $key)) {
                throw ValidationException::withMessages([
                    'keys' => 'Invalid item key format.',
                ]);
            }

            [$type, $id] = explode(':', $key, 2);
            if (! in_array($type, $allowedTypes, true)) {
                throw ValidationException::withMessages([
                    'keys' => "Item type '{$type}' is not allowed for this list.",
                ]);
            }

            $validItems[] = [$type, $id, $key];
        }

        $this->performBulkAction($action, $validItems, $payload);
    }

    /**
     * Allowed item types for this component (e.g. ['note', 'file', 'folder', 'link']).
     *
     * @return array<string>
     */
    abstract protected function allowedBulkTypes(): array;

    /**
     * Performs the bulk action on the validated items.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $items  [[type, id, key], ...]
     * @param  array<string, mixed>  $payload
     */
    abstract protected function performBulkAction(string $action, array $items, array $payload): void;
}
