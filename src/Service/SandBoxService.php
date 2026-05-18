<?php

namespace TestHub\Bundle\Service;

class SandBoxService
{
    public const string SANDBOX_TYPE = 'sandboxRequestType';

    private const string API_PREFIX = '/api';
    private const string API_PREFIX_WRAP = '/api_wrap';

    public function __construct(
        private readonly string $apiKey = '',
        private readonly bool $useSandbox = false,
        private readonly string $sandboxUrl = '',
    ) {}

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getStatus(): bool
    {
        return $this->useSandbox;
    }

    public function getUrl(): string
    {
        return $this->sandboxUrl . self::API_PREFIX;
    }

    public function getUrlWrap(): string
    {
        return $this->sandboxUrl . self::API_PREFIX_WRAP;
    }
}
