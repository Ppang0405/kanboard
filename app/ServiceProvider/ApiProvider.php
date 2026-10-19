<?php

namespace Kanboard\ServiceProvider;

use JsonRPC\Response\ResponseBuilder;
use JsonRPC\Server;
use Kanboard\Api\Procedure\ActionProcedure;
use Kanboard\Api\Procedure\AppProcedure;
use Kanboard\Api\Procedure\BoardProcedure;
use Kanboard\Api\Procedure\CategoryProcedure;
use Kanboard\Api\Procedure\ColumnProcedure;
use Kanboard\Api\Procedure\CommentProcedure;
use Kanboard\Api\Procedure\ProjectFileProcedure;
use Kanboard\Api\Procedure\ProjectMetadataProcedure;
use Kanboard\Api\Procedure\TagProcedure;
use Kanboard\Api\Procedure\TaskExternalLinkProcedure;
use Kanboard\Api\Procedure\TaskFileProcedure;
use Kanboard\Api\Procedure\GroupProcedure;
use Kanboard\Api\Procedure\GroupMemberProcedure;
use Kanboard\Api\Procedure\LinkProcedure;
use Kanboard\Api\Procedure\MeProcedure;
use Kanboard\Api\Middleware\AuthenticationMiddleware;
use Kanboard\Api\Procedure\ProjectProcedure;
use Kanboard\Api\Procedure\ProjectPermissionProcedure;
use Kanboard\Api\Procedure\SubtaskProcedure;
use Kanboard\Api\Procedure\SubtaskTimeTrackingProcedure;
use Kanboard\Api\Procedure\SwimlaneProcedure;
use Kanboard\Api\Procedure\TaskMetadataProcedure;
use Kanboard\Api\Procedure\TaskProcedure;
use Kanboard\Api\Procedure\TaskLinkProcedure;
use Kanboard\Api\Procedure\TaskTagProcedure;
use Kanboard\Api\Procedure\UserProcedure;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

/**
 * Class ApiProvider
 *
 * @package Kanboard\ServiceProvider
 * @author  Frederic Guillot
 */
class ApiProvider implements ServiceProviderInterface
{
    /**
     * Registers services on the given container.
     *
     * @param Container $container
     * @return Container
     */
    public function register(Container $container)
    {
        // Optional per-request injection for transports that capture the
        // body, server variables and response builder themselves. Absent
        // keys keep the legacy behavior: null body (php://input),
        // superglobal server variables and the default response builder.
        // Shared (not factory): on a fresh per-request container the
        // injection above is set before the first access, so one Server
        // instance serves the whole request. A null server-vars default
        // keeps the legacy $_SERVER fallback; an explicitly injected
        // empty array must stay empty and never fall back to globals.
        $container['api'] = function ($c) {
            $requestBody = isset($c['apiRequestBody']) ? $c['apiRequestBody'] : null;
            $serverVars = isset($c['apiServerVars']) ? $c['apiServerVars'] : null;
            $responseBuilder = isset($c['apiResponseBuilder']) ? $c['apiResponseBuilder'] : ResponseBuilder::create();
            $server = new Server($requestBody, $serverVars, $responseBuilder);
            $server->setAuthenticationHeader(API_AUTHENTICATION_HEADER);
            $server->getMiddlewareHandler()
                ->withMiddleware(new AuthenticationMiddleware($c))
            ;

            $server->getProcedureHandler()
                ->withObject(new MeProcedure($c))
                ->withObject(new ActionProcedure($c))
                ->withObject(new AppProcedure($c))
                ->withObject(new BoardProcedure($c))
                ->withObject(new ColumnProcedure($c))
                ->withObject(new CategoryProcedure($c))
                ->withObject(new CommentProcedure($c))
                ->withObject(new TaskFileProcedure($c))
                ->withObject(new ProjectFileProcedure($c))
                ->withObject(new LinkProcedure($c))
                ->withObject(new ProjectProcedure($c))
                ->withObject(new ProjectPermissionProcedure($c))
                ->withObject(new ProjectMetadataProcedure($c))
                ->withObject(new SubtaskProcedure($c))
                ->withObject(new SubtaskTimeTrackingProcedure($c))
                ->withObject(new SwimlaneProcedure($c))
                ->withObject(new TaskProcedure($c))
                ->withObject(new TaskLinkProcedure($c))
                ->withObject(new TaskExternalLinkProcedure($c))
                ->withObject(new TaskMetadataProcedure($c))
                ->withObject(new TaskTagProcedure($c))
                ->withObject(new UserProcedure($c))
                ->withObject(new GroupProcedure($c))
                ->withObject(new GroupMemberProcedure($c))
                ->withObject(new TagProcedure($c))
                ->withBeforeMethod('beforeProcedure')
            ;

            return $server;
        };

        return $container;
    }
}
