<?php

declare(strict_types=1);

/*
 * This file is part of the "kanban_workspaces" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace WebVision\KanbanWorkspaces\Service;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Domain\Model\Workspace;
use TYPO3\CMS\Workspaces\Domain\Model\WorkspaceStage;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use WebVision\KanbanWorkspaces\Configuration\EmConfiguration;

/**
 * Drops the default "Ready to publish" stage (``StagesService::STAGE_PUBLISH_ID``)
 * from a workspace's stage list when the ``hideReadyToPublishStage`` extension
 * configuration option is enabled, so the workflow steps directly from "Editing"
 * to "Publish".
 *
 * Workspaces with "Publish only content in publish stage"
 * (``sys_workspace.publish_access & PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE``) keep
 * the stage: the core workflow requires it there (`GridDataService` looks it up
 * and would fail without it).
 *
 * The kanban board applies the filter itself. On TYPO3 v13 the
 * {@see \WebVision\KanbanWorkspaces\Domain\Repository\WorkspaceStageRepository}
 * additionally applies it for every consumer of the core repository.
 */
#[Autoconfigure(public: true)]
final readonly class ReadyToPublishStageFilter
{
    /**
     * @param WorkspaceStage[] $stages
     * @return WorkspaceStage[]
     */
    public function filter(array $stages, Workspace $workspaceRecord): array
    {
        if (!GeneralUtility::makeInstance(EmConfiguration::class)->getHideReadyToPublishStage()
            || $this->requiresReadyToPublishStage($workspaceRecord)
        ) {
            return $stages;
        }

        return array_values(array_filter(
            $stages,
            static fn (WorkspaceStage $stage): bool => $stage->uid !== StagesService::STAGE_PUBLISH_ID
        ));
    }

    /**
     * The Workspace model carries no publish_access property, so the raw record is read.
     */
    private function requiresReadyToPublishStage(Workspace $workspaceRecord): bool
    {
        $record = BackendUtility::getRecord('sys_workspace', $workspaceRecord->uid, 'publish_access');

        return ((int)($record['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE) !== 0;
    }
}
