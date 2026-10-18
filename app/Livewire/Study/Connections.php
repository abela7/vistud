<?php

namespace App\Livewire\Study;

use App\Identity\PrincipalFactory;
use App\Mcp\ConnectionDetails;
use App\Mcp\Connections as ConnectionService;
use App\Mcp\Provider;
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

    private ConnectionService $connections;

    private PrincipalFactory $principals;

    public function boot(ConnectionService $connections, PrincipalFactory $principals): void
    {
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
            return view('livewire.study.connections', ['groups' => [], 'ended' => [], 'url' => url('/mcp'), 'student' => false]);
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
            'url' => url('/mcp'),
            'student' => true,
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
