<?php

declare(strict_types=1);

namespace TestHub\Bundle\Action;

class ActionRegistry
{
    /** @var array<string, ActionInterface>|null */
    private ?array $actions = null;

    /**
     * @param iterable<ActionInterface> $taggedActions
     */
    public function __construct(
        private readonly iterable $taggedActions = [],
    ) {
    }

    /**
     * @return array<string, ActionInterface>
     */
    public function all(): array
    {
        if (null === $this->actions) {
            $this->actions = [];

            foreach ($this->taggedActions as $action) {
                $this->actions[$action->getName()] = $action;
            }
        }

        return $this->actions;
    }

    public function get(string $name): ?ActionInterface
    {
        return $this->all()[$name] ?? null;
    }
}
