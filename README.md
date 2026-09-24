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
   - requests with a type (see [Request type](#request-type)) and an agent go to `{SANDBOX_URL}/api/{agent}/{type}/{event}`
   - any other request goes to `{SANDBOX_URL}/api_wrap`

   The original URL is sent in the `sandbox-url` header, the event in the `sandbox-event` header and `SANDBOX_API_KEY` in the `sandbox-api-key` header. The API key is sent only to the sandbox, never to real APIs, and is left out when it is empty.
4. The panel lists each outgoing request: original URL, sandbox URL, type/event, status, timing, request options, response headers and the sandbox response body.

**Deposit event** and **Withdrawal event** select whether the sandbox answers `success` or `fail`.

### Request type

The type in the sandbox URL is resolved in this order:

1. `extra.sandboxRequestType` on the request, for non-standard types:
   ```php
   use TestHub\Bundle\Service\SandBoxService;

   $httpClient->request('GET', 'https://psp.example/balance', [
       'extra' => [SandBoxService::SANDBOX_TYPE => 'balance'],
   ]);
   ```
2. The request context's `getDirection()`, then `getOperation()`, when the value is `deposit`, `withdraw` or `withdrawal`. Deposits and withdrawals need no tagging.
3. Otherwise the request goes to `/api_wrap`.

The **Deposit event** and **Withdrawal event** selectors apply to `deposit` and to `withdraw`/`withdrawal`. Every other type gets `success`.

The key goes directly under `extra`, which HttpClient ignores, so this code also runs in `prod`, where the bundle is not loaded. Use the string `'sandboxRequestType'` if the class is not autoloaded in `prod`, for example because you installed the bundle with `--dev`.

> The legacy location `extra.curl.sandboxRequestType` still works in `dev`, but in any environment without the bundle it makes cURL throw a `TypeError`. Move it to `extra`.

Console commands and workers have no browser cookie, so they use `use_sandbox`.

## Agent context

The `{agent}` path segment comes from a context provider service. Its `get()` returns the contexts collected so far, one per outgoing request. The bundle uses the **last** one, which belongs to the request being sent, and calls its `getAgent()`. If the list is empty or the agent is null or empty, the request goes to `/api_wrap`.

Implement the interface in your application. With autoconfiguration on (the Symfony default), the bundle finds your implementation and uses it instead of its empty `DefaultContextProvider`. You don't need any configuration:

```php
namespace App\Sandbox;

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

The implementation is chosen in this order:

1. `test_hub.context_provider`, if set
2. your own alias for `ContextProviderInterface` in `services.yaml`
3. your only autoconfigured implementation. If there are several, the container fails to build and asks you to pick one with option 1.
4. `DefaultContextProvider`, which returns no agent

### A provider with its own interface

If the bundle is installed with `--dev`, an application class that must also load in `prod` cannot implement `TestHub\Bundle\...\ContextProviderInterface`. Keep your own interface and point the bundle at the service. Any service with a public `get(): array` method works:

```yaml
# config/packages/dev/test_hub.yaml
test_hub:
    context_provider: App\HttpClient\State\ContextCollector
```

The context must be collected before the sandbox decorator runs. The decorator sits on `http_client.transport` with priority `100`, so a collecting decorator with a lower `decoration_priority` (for example `-20`) runs first.

The Test Hub panel shows which provider is in use under **Configuration**. The provider is only built when a request is actually sent to the sandbox, so it can depend on services that use `http_client` themselves.

## Configuration

```yaml
# config/packages/dev/test_hub.yaml
test_hub:
    environments: ['dev']
    sandbox_url: '%env(string:default::SANDBOX_URL)%'
    use_sandbox: '%env(bool:default::USE_SANDBOX)%'
    api_key: '%env(string:default::SANDBOX_API_KEY)%'
    # context_provider: App\Sandbox\CurrentAgentProvider  # only needed to override detection
```

Until `sandbox_url` is set, the sandbox stays off, whatever the switch says.

## Testing

```bash
composer test
```
