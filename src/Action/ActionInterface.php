<?php

declare(strict_types=1);

namespace TestHub\Bundle\Action;

/**
 * A button in the Test Hub panel that runs application code, e.g. starting a payout of a test order.
 *
 * Implementations are detected through autoconfiguration. They run inside a regular HTTP request,
 * so the browser's sandbox cookies apply and the run gets its own profile.
 */
interface ActionInterface
{
    /**
     * Unique identifier, used in the action URL. Letters, digits, "_" and "-" only.
     */
    public function getName(): string;

    public function getLabel(): string;

    /**
     * Shown under the label in the panel.
     */
    public function getDescription(): string;

    /**
     * Form fields shown next to the button, as name => label.
     *
     * @return array<string, string>
     */
    public function getParameters(): array;

    /**
     * @param array<string, string> $parameters submitted values, keyed by the names from getParameters()
     *
     * @return string a short result message for the panel
     *
     * @throws \Throwable to report a failure
     */
    public function run(array $parameters): string;
}
