<?php

declare(strict_types=1);

namespace WebVision\KanbanWorkspaces\Service;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the records that have been published from a workspace out of sys_history.
 *
 * The workspaces DataHandler hook writes a "publish" entry (actiontype 7) for every
 * published record. The entry itself is stored for the live workspace (workspace = 0),
 * the source workspace is only part of the JSON payload (history_data.workspaceId).
 *
 * Like the collections of the workspace module, the inline children of a record (e.g. the content
 * elements and files of a news record) are no records of their own: they are listed as children
 * of their top-level parent, which is listed even if it was not changed itself.
 *
 * @phpstan-type PublishEntry array{id: int, table: string, uid: int, record: array<string, mixed>, action: string, tstamp: int, userId: int, comment: string}
 * @phpstan-type CollectionRoot array{table: string, uid: int, record: array<string, mixed>}
 */
class PublishedHistoryService
{
    public const DEFAULT_MAX_AGE_DAYS = 30;
    public const MAX_AGE_DAYS = 365;
    public const DEFAULT_LIMIT = 200;

    /**
     * Entries of one collection published at most this many seconds apart belong to the same
     * publish and are merged into one record.
     */
    public const GROUP_SECONDS = 10;

    protected const MAX_PARENT_LEVELS = 10;

    /**
     * @var array<int, string>
     */
    protected array $userNames = [];

    /**
     * @var array<int, array<string, mixed>|false>
     */
    protected array $readablePages = [];

    /**
     * Inline relations with a foreign field (type inline and file), by child table
     *
     * @var array<string, list<array{table: string, field: string, tableField: string, matchFields: array<string, mixed>}>>|null
     */
    protected ?array $inlineParentRelations = null;

    /**
     * @var array<string, CollectionRoot>
     */
    protected array $collectionRoots = [];

    public function __construct(
        protected readonly ConnectionPool $connectionPool,
        protected readonly UriBuilder $backendUriBuilder,
    ) {
    }

    /**
     * Get the records published from the given workspace since the given timestamp, newest first.
     *
     * The inline children published together with their parent (see GROUP_SECONDS) are returned
     * as "children" of the parent's record. Only records the backend user may read are returned.
     * Records of tables that are hidden in the backend (ctrl.hideTable, e.g. sys_file_reference)
     * are only listed as children. With $pageIds (see getPageTreeIds()) only records on these
     * pages and the pages themselves are returned, otherwise all.
     *
     * @param list<int>|null $pageIds
     *
     * @return list<array{
     *     id: int,
     *     table: string,
     *     uid: int,
     *     title: string,
     *     type: string,
     *     action: string,
     *     pid: int,
     *     pageTitle: string,
     *     publishedAt: string,
     *     publishedBy: string,
     *     comment: string,
     *     historyUrl: string,
     *     children: list<array{id: int, table: string, uid: int, title: string, type: string, action: string, historyUrl: string}>
     * }>
     */
    public function findPublishedRecords(int $workspaceId, int $since, int $limit = self::DEFAULT_LIMIT, ?array $pageIds = null): array
    {
        if ($workspaceId <= 0 || $pageIds === []) {
            return [];
        }
        $pageIdLookup = $pageIds === null ? null : array_fill_keys($pageIds, true);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $result = $queryBuilder
            ->select('uid', 'tablename', 'recuid', 'userid', 'tstamp', 'history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('actiontype', $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_PUBLISH, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('tstamp', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
                // pre-filter only, the payload is decoded and checked below
                $queryBuilder->expr()->like(
                    'history_data',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards('"workspaceId":' . $workspaceId . ',') . '%')
                )
            )
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery();

        // no SQL limit: the page and permission checks below need the record, so the limit is applied to the result
        // the groups of entries that are listed as one record, by group index
        $groupRoots = [];
        $groupEntries = [];
        // the entries are newest first, this is the time of the group's last entry
        $groupOldest = [];
        // the group index of the latest group of each collection
        $groupIndexes = [];
        while ($row = $result->fetchAssociative()) {
            $payload = json_decode((string)$row['history_data'], true);
            if (!is_array($payload) || (int)($payload['workspaceId'] ?? 0) !== $workspaceId) {
                continue;
            }
            $entry = $this->buildEntry($row, $payload);
            if ($entry === null) {
                continue;
            }
            $root = $this->getCollectionRoot($entry['table'], $entry['record']);
            if (!$this->isListed($root['table'], $root['uid'], $root['record'], $pageIdLookup)) {
                continue;
            }
            $groupKey = $root['table'] . ':' . $root['uid'];
            $index = $groupIndexes[$groupKey] ?? null;
            if ($index !== null && $groupOldest[$index] - $entry['tstamp'] <= self::GROUP_SECONDS) {
                $groupEntries[$index][] = $entry;
                $groupOldest[$index] = $entry['tstamp'];
                continue;
            }
            if (count($groupRoots) >= $limit) {
                break;
            }
            $index = count($groupRoots);
            $groupIndexes[$groupKey] = $index;
            $groupRoots[$index] = $root;
            $groupEntries[$index] = [$entry];
            $groupOldest[$index] = $entry['tstamp'];
        }
        $result->free();
        return array_map($this->buildRecord(...), $groupRoots, $groupEntries);
    }

    /**
     * Get the given page and its subpages down to the given depth (0 = only the page, like the
     * depth filter of the board) that the backend user may see, like the core workspace module
     * selects the records of a page.
     *
     * @return list<int>
     */
    public function getPageTreeIds(int $pageId, int $depth): array
    {
        if ($pageId <= 0) {
            return [];
        }
        $permsClause = QueryHelper::stripLogicalOperatorPrefix($this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW));
        return array_values(array_unique([$pageId, ...$this->getSubpageIds($pageId, $depth, $permsClause)]));
    }

    /**
     * @return list<int>
     */
    protected function getSubpageIds(int $pageId, int $depth, string $permsClause): array
    {
        if ($depth <= 0) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $permsClause
            )
            ->executeQuery();
        $pageIds = [];
        while ($subpageId = $result->fetchOne()) {
            $pageIds[] = (int)$subpageId;
            array_push($pageIds, ...$this->getSubpageIds((int)$subpageId, $depth - 1, $permsClause));
        }
        return $pageIds;
    }

    /**
     * The publish entry of a record the backend user may read, null otherwise.
     *
     * @param array<string, mixed> $historyRow
     * @param array<string, mixed> $payload
     * @return PublishEntry|null
     */
    protected function buildEntry(array $historyRow, array $payload): ?array
    {
        $table = (string)$historyRow['tablename'];
        $uid = (int)$historyRow['recuid'];
        if (!is_array($GLOBALS['TCA'][$table]['ctrl'] ?? null) || !$this->getBackendUser()->check('tables_select', $table)) {
            return null;
        }
        // deleted records are included: publishing a delete placeholder deletes the live record
        $record = BackendUtility::getRecord($table, $uid, '*', '', false);
        if ($record === null) {
            return null;
        }
        return [
            'id' => (int)$historyRow['uid'],
            'table' => $table,
            'uid' => $uid,
            'record' => $record,
            'action' => $this->getAction($historyRow, $payload, $record),
            'tstamp' => (int)$historyRow['tstamp'],
            'userId' => (int)$historyRow['userid'],
            'comment' => (string)($payload['comment'] ?? ''),
        ];
    }

    /**
     * Whether a record is listed with its children: hidden tables are only listed as children,
     * the record has to be on one of the pages (if given) and the user has to be able to read the page.
     *
     * @param array<string, mixed> $record
     * @param array<int, true>|null $pageIdLookup
     */
    protected function isListed(string $table, int $uid, array $record, ?array $pageIdLookup): bool
    {
        if ($GLOBALS['TCA'][$table]['ctrl']['hideTable'] ?? false) {
            return false;
        }
        if (!$this->getBackendUser()->check('tables_select', $table)) {
            return false;
        }
        $pageId = $table === 'pages' ? $uid : (int)$record['pid'];
        if ($pageIdLookup !== null && !isset($pageIdLookup[$this->getDefaultLanguagePageId($table, $record, $pageId)])) {
            return false;
        }
        return $this->getReadablePage($pageId) !== false;
    }

    /**
     * One record of the given collection root with the published entries of the root and its children.
     *
     * @param CollectionRoot $root
     * @param non-empty-list<PublishEntry> $entries newest first
     * @return array{
     *     id: int,
     *     table: string,
     *     uid: int,
     *     title: string,
     *     type: string,
     *     action: string,
     *     pid: int,
     *     pageTitle: string,
     *     publishedAt: string,
     *     publishedBy: string,
     *     comment: string,
     *     historyUrl: string,
     *     children: list<array{id: int, table: string, uid: int, title: string, type: string, action: string, historyUrl: string}>
     * }
     */
    protected function buildRecord(array $root, array $entries): array
    {
        $table = $root['table'];
        $uid = $root['uid'];
        $pageId = $table === 'pages' ? $uid : (int)$root['record']['pid'];
        // a root that was not published itself has only been changed through its children
        $action = null;
        $children = [];
        foreach ($entries as $entry) {
            if ($entry['table'] === $table && $entry['uid'] === $uid) {
                $action = $action === null ? $entry['action'] : $this->mergeAction($action, $entry['action']);
                continue;
            }
            $key = $entry['table'] . ':' . $entry['uid'];
            if (isset($children[$key])) {
                $children[$key]['action'] = $this->mergeAction($children[$key]['action'], $entry['action']);
                continue;
            }
            $children[$key] = [
                'id' => $entry['id'],
                'table' => $entry['table'],
                'uid' => $entry['uid'],
                'title' => BackendUtility::getRecordTitle($entry['table'], $entry['record']),
                'type' => $this->getTableTitle($entry['table']),
                'action' => $entry['action'],
                'historyUrl' => $this->getHistoryUrl($entry['table'], $entry['uid']),
            ];
        }
        $comments = array_filter(array_column($entries, 'comment'));

        return [
            'id' => $entries[0]['id'],
            'table' => $table,
            'uid' => $uid,
            'title' => BackendUtility::getRecordTitle($table, $root['record']),
            'type' => $this->getTableTitle($table),
            'action' => $action ?? 'modified',
            'pid' => $pageId,
            'pageTitle' => (string)(($this->getReadablePage($pageId) ?: [])['title'] ?? ''),
            'publishedAt' => date('c', $entries[0]['tstamp']),
            'publishedBy' => $this->getUserName($entries[0]['userId']),
            'comment' => (string)(reset($comments) ?: ''),
            'historyUrl' => $this->getHistoryUrl($table, $uid),
            'children' => array_values($children),
        ];
    }

    /**
     * Action of a record published more than once in a group, from the action of the newer and the older entry:
     * a record that was deleted at last stays deleted, one that was created in the group is new.
     */
    protected function mergeAction(string $newerAction, string $olderAction): string
    {
        return $newerAction !== 'deleted' && $olderAction === 'new' ? 'new' : $newerAction;
    }

    /**
     * The top-level record of the collection the given record belongs to, the record itself if it is no inline child.
     * Like the workspace module, only inline relations with a foreign field are followed (type inline and file).
     * Deleted parents are included, see buildEntry().
     *
     * @param array<string, mixed> $record
     * @return CollectionRoot
     */
    protected function getCollectionRoot(string $table, array $record): array
    {
        $key = $table . ':' . (int)$record['uid'];
        if (!isset($this->collectionRoots[$key])) {
            $root = ['table' => $table, 'uid' => (int)$record['uid'], 'record' => $record];
            for ($level = 0; $level < self::MAX_PARENT_LEVELS && ($parent = $this->getInlineParent($root['table'], $root['record'])) !== null; $level++) {
                $root = $parent;
            }
            $this->collectionRoots[$key] = $root;
        }
        return $this->collectionRoots[$key];
    }

    /**
     * @param array<string, mixed> $record
     * @return CollectionRoot|null
     */
    protected function getInlineParent(string $table, array $record): ?array
    {
        foreach ($this->getInlineParentRelations()[$table] ?? [] as $relation) {
            $parentUid = (int)($record[$relation['field']] ?? 0);
            if ($parentUid <= 0 || ($relation['tableField'] !== '' && (string)($record[$relation['tableField']] ?? '') !== $relation['table'])) {
                continue;
            }
            foreach ($relation['matchFields'] as $field => $value) {
                if ((string)($record[$field] ?? '') !== (string)$value) {
                    continue 2;
                }
            }
            $parent = BackendUtility::getRecord($relation['table'], $parentUid, '*', '', false);
            if ($parent !== null) {
                return ['table' => $relation['table'], 'uid' => $parentUid, 'record' => $parent];
            }
        }
        return null;
    }

    /**
     * @return array<string, list<array{table: string, field: string, tableField: string, matchFields: array<string, mixed>}>>
     */
    protected function getInlineParentRelations(): array
    {
        if ($this->inlineParentRelations === null) {
            $this->inlineParentRelations = [];
            foreach ($GLOBALS['TCA'] as $parentTable => $tableConfiguration) {
                foreach ($tableConfiguration['columns'] ?? [] as $column) {
                    $config = $column['config'] ?? [];
                    // type file is prepared to an inline relation to sys_file_reference (TcaPreparation)
                    if (!in_array($config['type'] ?? '', ['inline', 'file'], true)
                        || ($config['foreign_table'] ?? '') === ''
                        || ($config['foreign_field'] ?? '') === ''
                        || ($config['MM'] ?? '') !== ''
                    ) {
                        continue;
                    }
                    $this->inlineParentRelations[$config['foreign_table']][] = [
                        'table' => (string)$parentTable,
                        'field' => $config['foreign_field'],
                        'tableField' => (string)($config['foreign_table_field'] ?? ''),
                        'matchFields' => (array)($config['foreign_match_fields'] ?? []),
                    ];
                }
            }
        }
        return $this->inlineParentRelations;
    }

    protected function getTableTitle(string $table): string
    {
        return $this->getLanguageService()->sL((string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table)) ?: $table;
    }

    protected function getHistoryUrl(string $table, int $uid): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_history', ['element' => $table . ':' . $uid]);
    }

    /**
     * The page tree only contains default language pages, a page translation belongs to its default language page.
     *
     * @param array<string, mixed> $record
     */
    protected function getDefaultLanguagePageId(string $table, array $record, int $pageId): int
    {
        if ($table !== 'pages') {
            return $pageId;
        }
        $translationParentField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? '';
        return (int)($record[$translationParentField] ?? 0) ?: $pageId;
    }

    /**
     * Derive what the publish did: new records are published without a swap (no "oldRecord" in the
     * payload). Publishing a delete placeholder deletes the live record right before the publish entry
     * is written, so the preceding history entry of the record is a delete entry.
     *
     * @param array<string, mixed> $historyRow
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $record
     */
    protected function getAction(array $historyRow, array $payload, array $record): string
    {
        if (!array_key_exists('oldRecord', $payload)) {
            return 'new';
        }
        $table = (string)$historyRow['tablename'];
        $deleteField = $GLOBALS['TCA'][$table]['ctrl']['delete'] ?? '';
        if ($deleteField !== '' && ($record[$deleteField] ?? false) && $this->wasDeletedWhenPublished($historyRow)) {
            return 'deleted';
        }
        return 'modified';
    }

    /**
     * @param array<string, mixed> $historyRow
     */
    protected function wasDeletedWhenPublished(array $historyRow): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $previousAction = $queryBuilder
            ->select('actiontype')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter((string)$historyRow['tablename'])),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter((int)$historyRow['recuid'], Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter((int)$historyRow['uid'], Connection::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return (int)$previousAction === RecordHistoryStore::ACTION_DELETE;
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function getReadablePage(int $pageId): array|false
    {
        if (!array_key_exists($pageId, $this->readablePages)) {
            $backendUser = $this->getBackendUser();
            $page = BackendUtility::readPageAccess($pageId, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
            // readPageAccess() skips deleted pages, so pages removed by publishing a delete
            // placeholder (and the records on them) are only listed for admins
            $this->readablePages[$pageId] = $page === false && $backendUser->isAdmin()
                ? (BackendUtility::getRecord('pages', $pageId, '*', '', false) ?? false)
                : $page;
        }
        return $this->readablePages[$pageId];
    }

    protected function getUserName(int $userId): string
    {
        if (!isset($this->userNames[$userId])) {
            $user = BackendUtility::getRecord('be_users', $userId, 'username,realName', '', false);
            $this->userNames[$userId] = (string)(($user['realName'] ?? '') ?: ($user['username'] ?? ''));
        }
        return $this->userNames[$userId];
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
