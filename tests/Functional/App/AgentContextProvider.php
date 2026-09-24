<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Functional\App;

use TestHub\Bundle\HttpClient\State\ContextProviderInterface;

final class AgentContextProvider implements ContextProviderInterface
{
    public function get(): array
    {
        return [new class {
            public function getAgent(): string
            {
                return 'test-agent';
            }
        }];
    }
}
