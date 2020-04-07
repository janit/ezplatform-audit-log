<?php

/*
 * ezplatform-audit-log
 *
 * This repository contains an eZ Platform 3.0 compatible Event Subscriber
 * that implements a simple audit trail logging functionality Proof of Concept.
 * 
 * eZ Platform is built on the Symfony Framework. To enable this Event Subscriber
 * you should make sure it is configured appropriately in config/services.yaml
 * - https://symfony.com/doc/current/event_dispatcher.html#creating-an-event-subscriber
 *
 * More information in the blog post:
 * - https://ezplatform.com/blog/implementing-an-audit-trail-log-for-ez-platform
 *
 */

namespace App\EventListener;

use eZ\Publish\API\Repository\Events\Content\UpdateContentEvent;
use eZ\Publish\API\Repository\Events\Location\MoveSubtreeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use Psr\Log\LoggerInterface;
use eZ\Publish\API\Repository\PermissionResolver;
use eZ\Publish\API\Repository\UserService;
use eZ\Publish\API\Repository\ContentService;
use EzSystems\EzPlatformVersionComparison\Service\VersionComparisonService;
use eZ\Publish\API\Repository\LocationService;

class AuditListener implements EventSubscriberInterface
{

    private $logger;
    private $permissionResolver;
    private $userService;
    private $contentService;
    private $comparisonService;
    private $locationService;

    public function __construct(
        LoggerInterface $logger,
        PermissionResolver $permissionResolver,
        UserService $userService,
        ContentService $contentService,
        VersionComparisonService $comparisonService,
        LocationService $locationService
    )
    {
        $this->logger = $logger;
        $this->permissionResolver = $permissionResolver;
        $this->userService = $userService;
        $this->contentService = $contentService;
        $this->comparisonService = $comparisonService;
        $this->locationService = $locationService;
    }

    public static function getSubscribedEvents()
    {
        return [
            UpdateContentEvent::class => 'onUpdateContent',
            MoveSubtreeEvent::class => 'moveSubtreeEvent'
        ];
    }

    public function moveSubtreeEvent(MoveSubtreeEvent $event)
    {

        $currentUserId = $this->permissionResolver
            ->getCurrentUserReference()->getUserId();
        $currentUser = $this->userService->loadUser($currentUserId);

        $location = $event->getLocation();
        $locationName = $location->getContent()->getName();

        $newParentLocation = $event->getNewParentLocation();
        $newParentLocationName = $newParentLocation->getContent()->getName();

        $buffer = <<< BUFFER_TEMPLATE
        User with login "$currentUser->login"
        moved location "$locationName" (id: $location->id) 
        under location "$newParentLocationName" (id: $newParentLocation->id)
        BUFFER_TEMPLATE;

        $this->logger->info($buffer);

    }

    public function onUpdateContent(UpdateContentEvent $event)
    {
        $content = $event->getContent();
        $contentName = $content->getName();
        $currentUserId = $this->permissionResolver
                              ->getCurrentUserReference()->getUserId();
        $currentUser = $this->userService->loadUser($currentUserId);

        $buffer = <<< BUFFER_TEMPLATE
        User with login "$currentUser->login" 
        edited object "$contentName" (id: $content->id)
        BUFFER_TEMPLATE;

        $publishedVersionNo = $content->versionInfo->versionNo;

        $versionFrom = $this->contentService->loadVersionInfo(
            $content->versionInfo->contentInfo,
            $publishedVersionNo
        );
        $versionTo = $this->contentService->loadVersionInfo(
            $content->versionInfo->contentInfo,
            $publishedVersionNo-1
        );

        foreach($content->getFields() as $field){
            $comparison = $this->comparisonService
                ->compare($versionFrom, $versionTo)
                ->getFieldValueDiffByIdentifier($field->fieldDefIdentifier)
                ->getComparisonResult();
            if($comparison->isChanged()){
                $buffer .= var_export($comparison,true) . PHP_EOL . PHP_EOL;
            }
        }

        $this->logger->info($buffer);

    }
}