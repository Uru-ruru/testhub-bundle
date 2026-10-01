# TestHub Bundle

Routes your application's outgoing `HttpClient` traffic to a TestHub sandbox, switchable per browser from the Symfony web profiler. Dev only.

## Installation

```bash
composer require --dev uru/testhub-bundle
php bin/console test-hub:install
```

With the Symfony Flex recipe (see [Flex recipe](#flex-recipe)), `composer require` registers the bundle for `dev` in `config/bundles.php`, creates `config/packages/test_hub.yaml` and `config/routes/test_hub.yaml`, and adds `SANDBOX_URL`, `SANDBOX_API_KEY` and `USE_SANDBOX` to `.env`. Without the recipe, Flex only registers the bundle. If it is missing from `config/bundles.php`, add it for `dev` only:

```php
return [
    // ...
    TestHub\Bundle\TestHubBundle::class => ['dev' => true],
];
```

`test-hub:install` does the rest:

- creates the two config files if they are missing, and leaves existing ones as they are
- lists your services that implement `ContextProviderInterface` or have a public `get(): array` method, and writes your choice to `context_provider` in `config/packages/test_hub.yaml`. Choose **Detect automatically** to leave the line commented out (see [Agent context](#agent-context))
- asks for the sandbox URL and API key and writes them to `.env.local`
- writes `USE_SANDBOX=1` to `.env.local` unless `USE_SANDBOX` is set there already, so the sandbox is on until you switch it off in the profiler. `--use-sandbox=0` writes another value

To run it without questions, for example in a setup script:

```bash
php bin/console test-hub:install -n --context-provider='App\Sandbox\CurrentAgentProvider' --sandbox-url=https://sandbox.example.com --api-key=your-key
```

`--context-provider=auto` comments the line out again. Options you leave out change nothing.

You can also set the sandbox in `.env.local` yourself:

```dotenv
SANDBOX_URL=https://sandbox.example.com
SANDBOX_API_KEY=your-key
# Default state when the profiler switch is untouched (optional, default: false)
USE_SANDBOX=false
```

### Docker

If the sandbox runs on your machine and the application runs in Docker Compose, the container must resolve the sandbox host to the host machine. `test-hub:docker-host` adds it to `extra_hosts` of the service you choose:

```bash
php bin/console test-hub:docker-host            # asks which service
php bin/console test-hub:docker-host php        # result:
```

```yaml
services:
    php:
        # ...
        extra_hosts:
            - "sandbox.lan:host-gateway"
```

It reads `compose.yaml`, `docker-compose.yml` and their `.override` files in the project directory; pass another file with `--file=docker/compose.yaml`. `--host` (default `sandbox.lan`) and `--ip` (default `host-gateway`) change the entry. The file is edited as text, so comments and formatting stay. Existing `extra_hosts` lists and maps are appended to, and a service that already has the host is left as it is. Recreate the container afterwards: `docker compose up -d php`.

The bundle also checks the kernel environment: in any environment not listed in `test_hub.environments` (default `['dev']`) it registers nothing, even if it is enabled in `bundles.php`.

## Usage

1. Open any page and click **Test Hub** in the debug toolbar (or use its **Turn on** link).
2. In the panel, set **Use sandbox** to *On*. The choice is saved in the `testhub_sandbox` cookie for this browser.
3. Reload your page. Provider requests made through `http_client`, including scoped clients, now go to the sandbox:
   - requests with a type (see [Request type](#request-type)) and an agent go to `{SANDBOX_URL}/api/{agent}/{type}/{event}`
   - any other request, such as auth or calls to internal services, goes to the real API unchanged

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
2. The request context's `getDirection()`, then `getOperation()`, when the value is `deposit`, `withdraw`, `withdrawal`, `payments` or `notification`. These types need no tagging.
3. Otherwise the request is not sent to the sandbox.

The **Deposit event** selector applies to `deposit`, and the **Withdrawal event** selector to `withdraw`, `withdrawal` and `payments`. Every other type gets `success`.

The key goes directly under `extra`, which HttpClient ignores, so this code also runs in `prod`, where the bundle is not loaded. Use the string `'sandboxRequestType'` if the class is not autoloaded in `prod`, for example because you installed the bundle with `--dev`.

> The legacy location `extra.curl.sandboxRequestType` still works in `dev`, but in any environment without the bundle it makes cURL throw a `TypeError`. Move it to `extra`.

Console commands and workers have no browser cookie, so they use `use_sandbox`.

## Agent context

The `{agent}` path segment comes from a context provider service. Its `get()` returns the contexts collected so far, one per outgoing request. The bundle uses the **last** one, which belongs to the request being sent, and calls its `getAgent()`. If the list is empty or the agent is null or empty, the request is not sent to the sandbox.

A request that collects no context of its own, for example an auth call made after a deposit, would still see the previous request's context. If the context has a `getUrl()` method, the bundle uses the context only when its host matches the request's host, so such calls go to the real API. Contexts without `getUrl()` are always used.

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
# config/packages/test_hub.yaml
when@dev:
    test_hub:
        context_provider: App\HttpClient\State\ContextCollector
```

`test-hub:install` lists such services too.

The context must be collected before the sandbox decorator runs. The decorator sits on `http_client.transport` with priority `100`, so a collecting decorator with a lower `decoration_priority` (for example `-20`) runs first.

The Test Hub panel shows which provider is in use under **Configuration**. The provider is only built when a request is actually sent to the sandbox, so it can depend on services that use `http_client` themselves.

## Actions

Actions are buttons in the Test Hub panel that run application code, for example processing a test payout that is waiting for the payout cron. The bundle ships one, [Run payout](#run-payout), and you can add your own. Each run is a new request from your browser, so the **Use sandbox** and event controls apply, and the panel links to the run's profile with its HTTP calls.

The bundle routes are imported for `dev` by `config/routes/test_hub.yaml`, which the recipe or `test-hub:install` creates:

```yaml
# config/routes/test_hub.yaml
when@dev:
    test_hub:
        resource: '@TestHubBundle/config/routes.php'
        prefix: /_test_hub
```

### Your own actions

Implement `ActionInterface`. With autoconfiguration on, the action appears in the panel:

```php
namespace App\Sandbox;

use TestHub\Bundle\Action\ActionInterface;

final class CheckStatusAction implements ActionInterface
{
    public function getName(): string { return 'check_status'; }
    public function getLabel(): string { return 'Check status'; }
    public function getDescription(): string { return 'Asks the provider for the status of an order.'; }

    /** Form fields, as name => label. */
    public function getParameters(): array
    {
        return ['order_id' => 'Order ID'];
    }

    public function run(array $parameters): string
    {
        // ...
        return 'Status checked.';
    }
}
```

`run()` gets the submitted fields as trimmed strings. Its return value is shown in the panel; an exception is shown as a failure. Anything the action prints is captured and shown too. The panel remembers the last values in this browser.

`getName()` must be unique and may contain only letters, digits, `_` and `-`. `payout` is taken by the built-in action.

### Run payout

The built-in **Run payout** action processes a gateway's approved withdrawals the same way the payout cron does, so you don't have to wait for the cron after creating a test withdrawal. Only orders that already passed security are picked up.

| Field | Required | Passed to the runner as |
|---|---|---|
| Provider (integration code) | yes | `$provider` |
| Gateway code | no | `$gateway`, `""` when empty |
| Partner ref_id | no | `$refId`, `0` when empty |

The payout itself is done by a `PayoutRunnerInterface`. In the project (where `PaySystems` exists) the bundle uses its built-in `PaySystemsPayoutRunner`, which runs `Gateway::payout()` like the payout cron, so there is nothing to set up.

In any other application, or to change how the payout runs, implement the interface yourself:

```php
namespace App\Development\TestHub;

use TestHub\Bundle\Action\PayoutRunnerInterface;

final class PayoutRunner implements PayoutRunnerInterface
{
    public function payout(string $provider, string $gateway, int $refId): void
    {
        // Start the payout for the gateway, as the payout cron does.
        // Throw an exception to show the run as failed.
    }
}
```

The runner is chosen in this order:

1. your own alias for `PayoutRunnerInterface` in `services.yaml`
2. your only autoconfigured implementation. If there are several, the container fails to build and asks you to alias one.
3. the built-in `PaySystemsPayoutRunner`, when `\PaySystems` exists
4. none: the **Run payout** button is not shown

On success the panel shows `Payout finished for {provider} ({gateway}). See the payout log for processed orders.` An empty provider fails without calling the runner.

Like any action, the run is a request from your browser. With **Use sandbox** on, the provider calls made during the payout go to the sandbox, and a context with the `withdraw` direction gets its answer from the **Withdrawal event** selector. Open the run's profile from the panel to see those calls.

Because your own runner implements a bundle interface, it can load only where the bundle is installed. If the bundle is a `--dev` dependency, register the runner in `dev` only, for example from a directory that `services.yaml` excludes:

```yaml
# config/packages/development.yaml
when@dev:
    services:
        _defaults:
            autowire: true
            autoconfigure: true

        App\Development\:
            resource: '../../src/Development/'
```

## Configuration

```yaml
# config/packages/test_hub.yaml
when@dev:
    test_hub:
        environments: ['dev']
        sandbox_url: '%env(string:default::SANDBOX_URL)%'
        use_sandbox: '%env(bool:default::USE_SANDBOX)%'
        api_key: '%env(string:default::SANDBOX_API_KEY)%'
        sandbox_cafile: '%env(string:default::SANDBOX_CAFILE)%'
    sandbox_verify_peer: true
    # context_provider: App\Sandbox\CurrentAgentProvider  # only needed to override detection
```

Until `sandbox_url` is set, the sandbox stays off, whatever the switch says.

## Flex recipe

`recipe/` holds the Symfony Flex recipe. Composer never runs files from a dependency, so Flex only applies a recipe that it finds in a recipe repository:

- **Public:** open a pull request to [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib) that copies `recipe/` to `uru/testhub-bundle/1.0/`. The package must be on Packagist. The first time an application installs a contrib recipe, Flex asks to allow contrib recipes.
- **Private:** publish the recipe in your own Flex endpoint and add it to the application's `composer.json` under `extra.symfony.endpoint`, before the `flex://defaults` entry.

`test-hub:install` copies its config files from `recipe/config/`, so keep both in sync by editing only `recipe/`.

## Testing

```bash
composer test
```
