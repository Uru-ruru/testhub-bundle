<?php

declare(strict_types=1);

namespace TestHub\Bundle\Twig;

use Symfony\Component\HttpKernel\Profiler\Profiler;
use TestHub\Bundle\Collector\SandBoxDataCollector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lists recent profiles with outgoing HTTP requests. Calls made from AJAX or redirected
 * requests are stored in their own profiles, not in the page the toolbar belongs to.
 */
final class SandBoxProfilerExtension extends AbstractExtension
{
    /**
     * @param \Closure(): ?Profiler $profiler
     */
    public function __construct(
        private readonly \Closure $profiler,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('test_hub_recent_profiles', $this->getRecentProfiles(...)),
        ];
    }

    /**
     * Only called when the panel is rendered, so ordinary requests never pay for it.
     *
     * @return list<array{token: string, method: string, url: string, time: int, requests: int, sandboxed: int, errors: int}>
     */
    public function getRecentProfiles(?string $excludeToken = null, int $scan = 30): array
    {
        $profiler = ($this->profiler)();

        if (!$profiler instanceof Profiler) {
            return [];
        }

        $recent = [];

        foreach ($profiler->find(null, null, $scan, null, null, null) as $row) {
            if ($row['token'] === $excludeToken || !$profile = $profiler->loadProfile($row['token'])) {
                continue;
            }

            if (!$profile->hasCollector(SandBoxDataCollector::NAME)) {
                continue;
            }

            /** @var SandBoxDataCollector $collector */
            $collector = $profile->getCollector(SandBoxDataCollector::NAME);

            if (!$collector->getRequestCount()) {
                continue;
            }

            $recent[] = [
                'token' => $profile->getToken(),
                'method' => $profile->getMethod(),
                'url' => $profile->getUrl(),
                'time' => $profile->getTime(),
                'requests' => $collector->getRequestCount(),
                'sandboxed' => $collector->getSandboxedCount(),
                'errors' => $collector->getErrorCount(),
            ];
        }

        return $recent;
    }
}
