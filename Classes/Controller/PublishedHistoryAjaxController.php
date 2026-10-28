<?php

declare(strict_types=1);

namespace WebVision\KanbanWorkspaces\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use WebVision\KanbanWorkspaces\Service\PublishedHistoryService;

/**
 * Lists the records published from the current workspace for the "Live" column of the board.
 */
#[AsController]
class PublishedHistoryAjaxController
{
    public function __construct(
        private readonly PublishedHistoryService $publishedHistoryService,
        private readonly Context $context,
    ) {
    }

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $days = PublishedHistoryService::DEFAULT_MAX_AGE_DAYS;
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $records = $this->publishedHistoryService->findPublishedRecords(
            (int)$this->getBackendUser()->workspace,
            $now - $days * 86400
        );

        return new JsonResponse([
            'success' => true,
            'days' => $days,
            'records' => $records,
        ]);
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
