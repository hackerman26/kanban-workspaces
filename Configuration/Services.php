<?php

declare(strict_types=1);

/*
 * This file is part of the "kanban_workspaces" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository as CoreWorkspaceStageRepository;
use WebVision\KanbanWorkspaces\Domain\Repository\WorkspaceStageRepository;

return static function (ContainerConfigurator $containerConfigurator): void {
    // TYPO3 v14 declares the core repository final, so it can neither be extended
    // nor replaced. The class is excluded from the resource scan in Services.yaml
    // and only registered (and autoloaded) where the core class allows it.
    if ((new \ReflectionClass(CoreWorkspaceStageRepository::class))->isFinal()) {
        return;
    }

    $services = $containerConfigurator->services();
    $services->set(WorkspaceStageRepository::class)
        ->autowire()
        ->autoconfigure();
    $services->alias(CoreWorkspaceStageRepository::class, WorkspaceStageRepository::class)
        ->public();
};
