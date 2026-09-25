<?php

namespace TestHub\Bundle\Service;

use Symfony\Component\HttpFoundation\Request;

class SandBoxService
{
    public const string SANDBOX_TYPE = 'sandboxRequestType';

    public const string API_KEY_HEADER = 'sandbox-api-key';
    public const string URL_HEADER = 'sandbox-url';
    public const string EVENT_HEADER = 'sandbox-event';

    /**
     * Cookie set by the profiler "Use sandbox" switch: "1" forces the sandbox on, "0" forces it off.
     */
    public const string COOKIE_NAME = 'testhub_sandbox';

    private const string API_PREFIX = '/api';
    private const string API_PREFIX_WRAP = '/api_wrap';

    public function __construct(
        private readonly string $apiKey = '',
        private readonly bool $useSandbox = false,
        private readonly string $sandboxUrl = '',
    ) {
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * The configured default, before any per-browser override.
     */
    public function getStatus(): bool
    {
        return $this->useSandbox;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->sandboxUrl;
    }

    public function getOverride(?Request $request): ?bool
    {
        return match ($request?->cookies->get(self::COOKIE_NAME)) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    /**
     * Whether requests made while handling $request go to the sandbox.
     * Without a request (console, workers) the configured default applies.
     */
    public function isEnabledFor(?Request $request): bool
    {
        return $this->isConfigured() && ($this->getOverride($request) ?? $this->useSandbox);
    }

    public function getUrl(): string
    {
        return $this->sandboxUrl.self::API_PREFIX;
    }

    public function getUrlWrap(): string
    {
        return $this->sandboxUrl.self::API_PREFIX_WRAP;
    }
}
