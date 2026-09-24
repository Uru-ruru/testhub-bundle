# TestHub Bundle

Routes your application's outgoing `HttpClient` traffic to a TestHub sandbox, switchable per browser from the Symfony web profiler. Dev only.

## Installation

```bash
composer require --dev uru/testhub-bundle
```

Register the bundle for `dev` only in `config/bundles.php`:

```php
return [
    // ...
    TestHub\Bundle\TestHubBundle::class => ['dev' => true],
];
```

Set the sandbox in `.env.local`:

```dotenv
SANDBOX_URL=https://sandbox.example.com
SANDBOX_API_KEY=your-key
# Default state when the profiler switch is untouched (optional, default: false)
USE_SANDBOX=false
```

The bundle also checks the kernel environment: in any environment not listed in `test_hub.environments` (default `['dev']`) it registers nothing, even if it is enabled in `bundles.php`.

## Usage

1. Open any page and click **Test Hub** in the debug toolbar (or use its **Turn on** link).
2. In the panel, set **Use sandbox** to *On*. The choice is saved in the `testhub_sandbox` cookie for this browser.
3. Reload your page. Every request made through `http_client`, including scoped clients, now goes to the sandbox:
   - requests tagged with a type go to `{SANDBOX_URL}/api/{agent}/{type}/{event}`
   - any other request goes to `{SANDBOX_URL}/api_wrap`

   The original URL is sent in the `url` header, the event in the `event` header and `SANDBOX_API_KEY` in the `api-key` header. The API key is sent only to the sandbox, never to real APIs, and is left out when it is empty.
4. The panel lists each outgoing request: original URL, sandbox URL, type/event, status, timing, request options, response headers and the sandbox response body.

**Deposit event** and **Withdrawal event** select whether the sandbox answers `success` or `fail`.

Tag a request with a type:

```php
use TestHub\Bundle\Service\SandBoxService;

$httpClient->request('POST', 'https://psp.example/deposit', [
    'json' => $payload,
    'extra' => [SandBoxService::SANDBOX_TYPE => 'deposit'], // or 'withdrawal'
]);
```

The key goes directly under `extra`, which HttpClient ignores, so this code also runs in `prod`, where the bundle is not loaded. Use the string `'sandboxRequestType'` if the class is not autoloaded in `prod`, for example because you installed the bundle with `--dev`.

> The legacy location `extra.curl.sandboxRequestType` still works in `dev`, but in any environment without the bundle it makes cURL throw a `TypeError`. Move it to `extra`.

Console commands and workers have no browser cookie, so they use `use_sandbox`.

## Agent context

The `{agent}` path segment comes from a `ContextProviderInterface` service. Its `get()` returns a list whose first item has a `getAgent(): string` method. If no agent is available, the request goes to `/api_wrap`.

```php
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;

final class CurrentAgentProvider implements ContextProviderInterface
{
    public function __construct(private AgentRepository $agents) {}

    public function get(): array
    {
        return [$this->agents->current()];
    }
}
```

## Configuration

```yaml
# config/packages/dev/test_hub.yaml
test_hub:
    environments: ['dev']
    sandbox_url: '%env(string:default::SANDBOX_URL)%'
    use_sandbox: '%env(bool:default::USE_SANDBOX)%'
    api_key: '%env(string:default::SANDBOX_API_KEY)%'
    context_provider: App\Sandbox\CurrentAgentProvider
```

Until `sandbox_url` is set, the sandbox stays off, whatever the switch says.

## Testing

```bash
composer test
```
