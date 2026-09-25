<?php

declare(strict_types=1);

namespace TestHub\Bundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TestHub\Bundle\Action\ActionRegistry;

/**
 * Runs a Test Hub action. The panel reads the X-Debug-Token-Link header to link to this run's profile.
 */
class ActionController
{
    public const string ROUTE = '_test_hub_action';

    public function __construct(
        private readonly ActionRegistry $registry,
    ) {
    }

    public function __invoke(Request $request, string $name): JsonResponse
    {
        $action = $this->registry->get($name);

        if (null === $action) {
            return new JsonResponse(['ok' => false, 'message' => \sprintf('Unknown action "%s".', $name)], Response::HTTP_NOT_FOUND);
        }

        $parameters = [];

        foreach (array_keys($action->getParameters()) as $parameter) {
            $parameters[$parameter] = trim((string) $request->request->get($parameter, ''));
        }

        // Legacy code may echo; keep it out of the JSON body but show it in the panel.
        ob_start();

        try {
            $message = $action->run($parameters);
            $ok = true;
        } catch (\Throwable $exception) {
            $message = \sprintf('%s: %s', $exception::class, $exception->getMessage());
            $ok = false;
        } finally {
            $output = (string) ob_get_clean();
        }

        return new JsonResponse(
            ['ok' => $ok, 'message' => $message, 'output' => $output],
            $ok ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
