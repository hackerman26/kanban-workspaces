<?php

declare(strict_types=1);

/*
 * This file is part of the "kanban_workspaces" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace WebVision\KanbanWorkspaces\Domain\Repository;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Domain\Model\Workspace;
use TYPO3\CMS\Workspaces\Domain\Model\WorkspaceStage;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository as CoreWorkspaceStageRepository;
use WebVision\KanbanWorkspaces\Service\ReadyToPublishStageFilter;

/**
 * Extends the core {@see CoreWorkspaceStageRepository} to apply the
 * {@see ReadyToPublishStageFilter}, which optionally drops the default
 * "Ready to publish" stage (``StagesService::STAGE_PUBLISH_ID``).
 *
 * TYPO3 v13 only: the class replaces the core repository through an alias in
 * `Configuration/Services.php`, so every consumer (kanban board, core Workspaces
 * module, preview toolbar) receives the filtered stage list, and the core
 * `StagesService::getNextStage()` / `getPreviousStage()` then step directly
 * between the edit and the execute stage. TYPO3 v14 declares the core repository
 * final, so there the class is never registered nor loaded and only the kanban
 * board applies the filter. Like {@see \WebVision\KanbanWorkspaces\Service\StagesService},
 * the constructor is intentionally *not* overridden, so the parent dependencies
 * keep being autowired, and the filter is fetched on demand.
 *
 * Heads up: the parent class is marked `@internal`, so its signature may change
 * with TYPO3 updates.
 */
final readonly class WorkspaceStageRepository extends CoreWorkspaceStageRepository
{
    /**
     * @return WorkspaceStage[]
     */
    public function findAllStagesByWorkspace(BackendUserAuthentication $backendUser, Workspace $workspaceRecord): array
    {
        return GeneralUtility::makeInstance(ReadyToPublishStageFilter::class)->filter(
            parent::findAllStagesByWorkspace($backendUser, $workspaceRecord),
            $workspaceRecord
        );
    }
}
