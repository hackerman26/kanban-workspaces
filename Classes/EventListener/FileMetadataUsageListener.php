<?php

declare(strict_types=1);

namespace WebVision\KanbanWorkspaces\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Workspaces\Event\AfterDataGeneratedForWorkspaceEvent;

/**
 * File metadata (sys_file_metadata) is stored on the root level and belongs to
 * no page, so the board cannot tell on which page a metadata change matters.
 * This listener adds the uids of the pages that use the file (through a file
 * reference that is live or part of the item's workspace) as "usedOnPages",
 * so the board can show the change on those pages.
 */
final class FileMetadataUsageListener
{
    private const TABLE = 'sys_file_metadata';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    #[AsEventListener(identifier: 'kanban-workspaces/file-metadata-usage')]
    public function __invoke(AfterDataGeneratedForWorkspaceEvent $event): void
    {
        $data = $event->getData();
        $metadataUids = [];
        foreach ($data as $item) {
            if (($item['table'] ?? '') === self::TABLE && (int)($item['uid'] ?? 0) > 0) {
                $metadataUids[] = (int)$item['uid'];
            }
        }
        if ($metadataUids === []) {
            return;
        }

        $fileUidByMetadataUid = $this->findFileUids($metadataUids);
        $pageUidsByFileUid = [];
        foreach ($data as &$item) {
            if (($item['table'] ?? '') !== self::TABLE) {
                continue;
            }
            $fileUid = $fileUidByMetadataUid[(int)($item['uid'] ?? 0)] ?? 0;
            $workspaceId = (int)($item['t3ver_wsid'] ?? 0) ?: (int)($GLOBALS['BE_USER']->workspace ?? 0);
            if ($fileUid <= 0) {
                $item['usedOnPages'] = [];
                continue;
            }
            $pageUidsByFileUid[$fileUid][$workspaceId] ??= $this->findPagesUsingFile($fileUid, $workspaceId);
            $item['usedOnPages'] = $pageUidsByFileUid[$fileUid][$workspaceId];
        }
        unset($item);
        $event->setData($data);
    }

    /**
     * @param int[] $metadataUids
     * @return array<int, int> file uid by metadata uid
     */
    private function findFileUids(array $metadataUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'file')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($metadataUids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();
        $fileUids = [];
        foreach ($rows as $row) {
            $fileUids[(int)$row['uid']] = (int)$row['file'];
        }
        return $fileUids;
    }

    /**
     * @return int[] uids of the pages holding a reference to the file, live or in the workspace
     */
    private function findPagesUsingFile(int $fileUid, int $workspaceId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('pid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter([0, $workspaceId], Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gt('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->groupBy('pid')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(intval(...), $rows);
    }
}
