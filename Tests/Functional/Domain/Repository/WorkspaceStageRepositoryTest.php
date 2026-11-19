<?php

declare(strict_types=1);

/*
 * This file is part of the "kanban_workspaces" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace WebVision\KanbanWorkspaces\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Workspaces\Domain\Model\WorkspaceStage;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository as CoreWorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WebVision\KanbanWorkspaces\Domain\Repository\WorkspaceStageRepository;
use WebVision\KanbanWorkspaces\Service\ReadyToPublishStageFilter;

final class WorkspaceStageRepositoryTest extends FunctionalTestCase
{
    private const TWO_STAGE_WORKSPACE_UID = 1;
    private const PUBLISH_STAGE_WORKSPACE_UID = 2;

    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'web-vision/kanban-workspaces',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/readyToPublishStage.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function coreWorkspaceStageRepositoryIsReplacedByExtendedRepository(): void
    {
        $this->skipIfCoreRepositoryIsFinal();

        self::assertInstanceOf(WorkspaceStageRepository::class, $this->get(CoreWorkspaceStageRepository::class));
    }

    #[Test]
    public function coreWorkspaceStageRepositoryIsKeptWhereItIsFinal(): void
    {
        if (!$this->isCoreRepositoryFinal()) {
            self::markTestSkipped('The core WorkspaceStageRepository is replaceable in this TYPO3 version.');
        }

        self::assertSame(CoreWorkspaceStageRepository::class, $this->get(CoreWorkspaceStageRepository::class)::class);
    }

    #[Test]
    public function replacedRepositoryHidesReadyToPublishStageWhenConfigured(): void
    {
        $this->skipIfCoreRepositoryIsFinal();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['kanban_workspaces'] = ['hideReadyToPublishStage' => '1'];

        $backendUser = $this->setUpBackendUser(1);
        $workspace = $this->get(WorkspaceRepository::class)->findByUid(self::TWO_STAGE_WORKSPACE_UID);
        $stages = $this->get(CoreWorkspaceStageRepository::class)->findAllStagesByWorkspace($backendUser, $workspace);

        self::assertSame(
            [StagesService::STAGE_EDIT_ID, StagesService::STAGE_PUBLISH_EXECUTE_ID],
            array_map(static fn (WorkspaceStage $stage): int => $stage->uid, $stages)
        );
    }

    #[Test]
    public function readyToPublishStageIsKeptByDefault(): void
    {
        self::assertSame(
            [StagesService::STAGE_EDIT_ID, StagesService::STAGE_PUBLISH_ID, StagesService::STAGE_PUBLISH_EXECUTE_ID],
            $this->findStageUids(self::TWO_STAGE_WORKSPACE_UID)
        );
    }

    #[Test]
    public function readyToPublishStageIsHiddenWhenConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['kanban_workspaces'] = ['hideReadyToPublishStage' => '1'];

        self::assertSame(
            [StagesService::STAGE_EDIT_ID, StagesService::STAGE_PUBLISH_EXECUTE_ID],
            $this->findStageUids(self::TWO_STAGE_WORKSPACE_UID)
        );
    }

    #[Test]
    public function readyToPublishStageIsKeptForWorkspacesPublishingOnlyFromThatStage(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['kanban_workspaces'] = ['hideReadyToPublishStage' => '1'];

        self::assertSame(
            [StagesService::STAGE_EDIT_ID, StagesService::STAGE_PUBLISH_ID, StagesService::STAGE_PUBLISH_EXECUTE_ID],
            $this->findStageUids(self::PUBLISH_STAGE_WORKSPACE_UID)
        );
    }

    /**
     * The stages as the kanban board sees them: from the core repository, run
     * through the filter (applied on every TYPO3 version).
     *
     * @return list<int>
     */
    private function findStageUids(int $workspaceUid): array
    {
        $backendUser = $this->setUpBackendUser(1);
        $workspace = $this->get(WorkspaceRepository::class)->findByUid($workspaceUid);
        $stages = $this->get(ReadyToPublishStageFilter::class)->filter(
            $this->get(CoreWorkspaceStageRepository::class)->findAllStagesByWorkspace($backendUser, $workspace),
            $workspace
        );

        return array_map(static fn (WorkspaceStage $stage): int => $stage->uid, $stages);
    }

    private function isCoreRepositoryFinal(): bool
    {
        return (new \ReflectionClass(CoreWorkspaceStageRepository::class))->isFinal();
    }

    private function skipIfCoreRepositoryIsFinal(): void
    {
        if ($this->isCoreRepositoryFinal()) {
            self::markTestSkipped('The core WorkspaceStageRepository is final in this TYPO3 version.');
        }
    }
}
