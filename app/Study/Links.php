<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * Web links (docs/specs/study-memory.md §3): a video, an article, the
 * course's page, kept in the same places as notes and files. Only http and
 * https addresses; the screens open them in a new tab without a referrer.
 * The student's own stream only.
 */
final class Links
{
    public const MAX_TITLE = 200;

    public const MAX_URL = 2000;

    /** @return list<LinkDetails> in order within each place */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'links')->where('workspace_id', $workspaceId)
            ->orderBy('position')->orderBy('id')->get()->map(self::details(...))->all();
    }

    public function find(Principal $by, string $id): LinkDetails
    {
        return self::details($this->row(Guard::learner($by), $id));
    }

    /**
     * Adds a link to the top level (`workspace`), a module or a folder.
     *
     * @param  array{title?: mixed, url?: mixed}  $input  the title is the site's name when left empty
     */
    public function add(Principal $by, string $placeType, string $placeId, array $input): LinkDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $placeType, $placeId, $fields, $id) {
            [$workspaceId, $moduleId, $folderId] = Input::place($scope, $placeType, $placeId);
            Input::workspace($scope, $workspaceId, lock: true);
            LearnerTables::insert($scope, 'links', $fields + [
                'id' => $id, 'workspace_id' => $workspaceId, 'module_id' => $moduleId, 'folder_id' => $folderId,
                'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $folderId),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    /** @param array{title?: mixed, url?: mixed} $input */
    public function update(Principal $by, string $id, array $input): LinkDetails
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'links')->where('id', $id)->update($fields + ['updated_at' => now()]);

        return $this->find($by, $id);
    }

    /** Moves the link to the end of another place in the same workspace. */
    public function move(Principal $by, string $id, string $placeType, string $placeId): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $id, $placeType, $placeId) {
            $row = $this->row($scope, $id);
            [$workspaceId, $moduleId, $folderId] = Input::place($scope, $placeType, $placeId);
            if ($workspaceId !== $row->workspace_id) {
                throw new NotFound;
            }
            if ($moduleId === $row->module_id && $folderId === $row->folder_id) {
                return;
            }
            LearnerTables::query($scope, 'links')->where('id', $id)->update([
                'module_id' => $moduleId, 'folder_id' => $folderId,
                'position' => $this->nextPosition($scope, $workspaceId, $moduleId, $folderId), 'updated_at' => now(),
            ]);
        });
    }

    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'links')->where('id', $id)->delete();
    }

    private function nextPosition(LearnerScope $scope, string $workspaceId, ?string $moduleId, ?string $folderId): int
    {
        $query = LearnerTables::query($scope, 'links')->where('workspace_id', $workspaceId);
        $query = match (true) {
            $folderId !== null => $query->where('folder_id', $folderId),
            $moduleId !== null => $query->whereNull('folder_id')->where('module_id', $moduleId),
            default => $query->whereNull('folder_id')->whereNull('module_id'),
        };

        return (int) $query->max('position') + 1;
    }

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'links')->where('id', $id)->first() ?? throw new NotFound;
    }

    /** @return array{title: string, url: string} */
    private static function validated(array $input): array
    {
        $url = is_string($input['url'] ?? null) ? trim($input['url']) : '';
        $title = Input::text($input, 'title');
        if ($url !== '' && ! preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = "https://{$url}";
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        Input::refuse(array_filter([
            'url' => match (true) {
                $url === '' => 'Paste the address.',
                strlen($url) > self::MAX_URL => 'That address is too long.',
                filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true) || $host === '' => 'Enter a web address, like https://example.com.',
                default => null,
            },
            'title' => $title !== null && mb_strlen($title) > self::MAX_TITLE ? 'Keep the title to '.self::MAX_TITLE.' characters.' : null,
        ]));

        return ['title' => $title ?? preg_replace('/^www\./i', '', $host), 'url' => $url];
    }

    private static function details(object $row): LinkDetails
    {
        return new LinkDetails($row->id, $row->workspace_id, $row->module_id, $row->folder_id, $row->title, $row->url, (int) $row->position);
    }
}
