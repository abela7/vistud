<?php

namespace App\Livewire\Study;

use App\Identity\PrincipalFactory;
use App\Mcp\ConnectionDetails;
use App\Mcp\Connections as ConnectionService;
use App\Mcp\Provider;
use App\Mcp\Undo;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings › AI › Connected AI apps (docs/specs/mcp-connections-plan.md M6.1): the chat apps the student let in over
 * MCP, grouped by company, each with what it may do, when it was last used, and Disconnect. "Disconnect all" asks for
 * the password first. Cutting an app off stops its tokens at once; it must sign in again.
 */
final class Connections extends Component
{
    public ?string $flash = null;

    /** The connection whose work the student is asking to take back, and how far back. */
    public ?string $undoFor = null;

    public string $undoWindow = 'day';

    private ConnectionService $connections;

    private PrincipalFactory $principals;

    private Undo $undo;

    public function boot(ConnectionService $connections, PrincipalFactory $principals, Undo $undo): void
    {
        $this->undo = $undo;
        $this->connections = $connections;
        $this->principals = $principals;
    }

    public function disconnect(string $id): void
    {
        try {
            $connection = $this->connections->find($this->principal(), $id);
            $this->connections->disconnect($this->principal(), $id);
            $this->flash = $connection->providerName().' is disconnected. It will ask you to sign in again.';
        } catch (NotFound) {
            $this->flash = 'That app is already gone.';
        }
    }

    /** Opens the "undo what it did" panel for one app. */
    public function chooseUndo(string $id): void
    {
        try {
            $this->connections->find($this->principal(), $id);
        } catch (NotFound) {
            $this->flash = 'That app is already gone.';

            return;
        }
        [$this->undoFor, $this->undoWindow] = [$id, 'day'];
    }

    public function cancelUndo(): void
    {
        $this->undoFor = null;
    }

    /** Takes back what the app saved in the chosen time (Mcp\Undo). */
    public function undo(): void
    {
        $id = $this->undoFor;
        $this->undoFor = null;
        if ($id === null || ! isset(Undo::WINDOWS[$this->undoWindow])) {
            return;
        }
        try {
            $this->connections->find($this->principal(), $id);
        } catch (NotFound) {
            $this->flash = 'That app is already gone.';

            return;
        }
        $said = Undo::words($this->undo->run($this->principal(), $id, $this->undoWindow));
        $this->flash = $said === null ? 'There was nothing to take back in that time.' : "Taken back: {$said}.";
    }

    public function disconnectAll(): mixed
    {
        $by = $this->principal();
        if (! $by->hasRecentPasswordConfirmation()) {
            session()->put('url.intended', route('settings', ['part' => 'ai']).'#connections');

            return $this->redirectRoute('password.confirm');
        }
        $count = $this->connections->disconnectAll($by);
        $this->flash = $count === 0 ? 'No app was connected.' : ($count === 1 ? 'One app is disconnected.' : "{$count} apps are disconnected.");

        return null;
    }

    public function render(): View
    {
        // An account that is only an admin has no study data, so nothing to connect.
        if ($this->principal()->learnerId === null) {
            return view('livewire.study.connections', ['groups' => [], 'ended' => [], 'calls' => [], 'url' => url('/mcp'), 'student' => false, 'windows' => [], 'undoPlan' => null]);
        }
        $all = $this->connections->list($this->principal());
        $live = array_values(array_filter($all, fn (ConnectionDetails $c) => $c->live()));
        $groups = [];
        foreach ($live as $connection) {
            $groups[$connection->provider][] = $connection;
        }
        uksort($groups, fn (string $a, string $b) => array_search($a, array_keys(Provider::NAMES), true) <=> array_search($b, array_keys(Provider::NAMES), true));

        return view('livewire.study.connections', [
            'groups' => $groups,
            'ended' => array_values(array_filter($all, fn (ConnectionDetails $c) => ! $c->live())),
            'calls' => $this->connections->disclosures($this->principal()),
            'url' => url('/mcp'),
            'student' => true,
            'windows' => Undo::WINDOWS,
            'undoPlan' => $this->undoFor === null ? null : Undo::words($this->undo->plan($this->principal(), $this->undoFor, $this->undoWindow)),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
