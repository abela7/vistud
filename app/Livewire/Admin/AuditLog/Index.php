<?php

namespace App\Livewire\Admin\AuditLog;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Identity\Accounts;
use App\Identity\PrincipalFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The admin audit log (WP6; ADR 0003 §10.4, T8). Read-only: the component
 * has no action that writes, and the log itself is append-only in the
 * database. Newest first, filtered by one action at a time.
 */
final class Index extends Component
{
    /** What each action reads as, after the actor's name. */
    public const ACTIONS = [
        AuditAction::WORKSPACE_ADMIN_ENTERED => 'entered the admin area',
        AuditAction::ADMIN_ACCESS_DENIED => 'was refused an admin page',
        AuditAction::ACCOUNT_CREATED => 'created an account for',
        AuditAction::ACCOUNT_SUSPENDED => 'suspended',
        AuditAction::ACCOUNT_REACTIVATED => 'reactivated',
        AuditAction::ACCOUNT_DELETION_REQUESTED => 'requested the deletion of',
        AuditAction::ROLE_GRANTED => 'gave admin to',
        AuditAction::ROLE_REVOKED => 'removed admin from',
        AuditAction::INVITATION_CREATED => 'created an invitation',
        AuditAction::INVITATION_REVOKED => 'cancelled an invitation',
        AuditAction::INVITATION_ACCEPTED => 'accepted an invitation',
        AuditAction::TWO_FACTOR_CONFIRMED => 'turned on two-factor authentication',
        AuditAction::TWO_FACTOR_DISABLED => 'turned off two-factor authentication',
        AuditAction::TWO_FACTOR_RESET => 'reset two-factor authentication for',
        AuditAction::RECOVERY_CODES_GENERATED => 'made new recovery codes',
        AuditAction::RECOVERY_CODE_USED => 'logged in with a recovery code',
    ];

    /** The actions whose sentence ends with the account acted on. */
    public const NAMES_TARGET = [
        AuditAction::ACCOUNT_CREATED,
        AuditAction::ACCOUNT_SUSPENDED,
        AuditAction::ACCOUNT_REACTIVATED,
        AuditAction::ACCOUNT_DELETION_REQUESTED,
        AuditAction::ROLE_GRANTED,
        AuditAction::ROLE_REVOKED,
        AuditAction::TWO_FACTOR_RESET,
    ];

    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 20;

    /** One action to show, or '' for all. */
    public string $action = '';

    public int $pages = 1;

    private AuditLog $log;

    private Accounts $accounts;

    private PrincipalFactory $principals;

    public function boot(AuditLog $log, Accounts $accounts, PrincipalFactory $principals): void
    {
        $this->log = $log;
        $this->accounts = $accounts;
        $this->principals = $principals;
    }

    public function updatedAction(): void
    {
        $this->pages = 1;
    }

    public function showMore(): void
    {
        $this->pages = min($this->pages + 1, self::MAX_PAGES);
    }

    public function render(): View
    {
        $by = $this->principals->fromRequest(request());
        $filters = array_key_exists($this->action, self::ACTIONS) ? ['action' => $this->action] : [];

        $entries = [];
        $cursor = null;
        $pages = max(1, min($this->pages, self::MAX_PAGES));
        for ($page = 0; $page < $pages; $page++) {
            $result = $this->log->list($by, $filters, $cursor, self::PAGE_SIZE);
            array_push($entries, ...$result['data']);
            $cursor = $result['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }

        $userIds = [];
        foreach ($entries as $entry) {
            $userIds[] = $entry['actor_user_id'];
            if ($entry['target_type'] === 'user') {
                $userIds[] = $entry['target_id'];
            }
        }

        return view('livewire.admin.audit-log.index', [
            'entries' => $entries,
            'names' => $this->accounts->names($by, $userIds),
            'hasMore' => $cursor !== null && $pages < self::MAX_PAGES,
        ]);
    }
}
