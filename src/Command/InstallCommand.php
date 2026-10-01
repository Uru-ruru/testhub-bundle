<?php

declare(strict_types=1);

namespace TestHub\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TestHub\Bundle\HttpClient\State\ContextProviderInterface;

/**
 * Sets the bundle up in the application: copies the recipe's config files when they are missing,
 * writes the chosen context provider and sandbox_verify_peer to config/packages/test_hub.yaml and the sandbox to .env.local.
 * USE_SANDBOX=1 is written to .env.local unless it is set there already.
 */
#[AsCommand(name: self::NAME, description: self::DESCRIPTION)]
final class InstallCommand extends Command
{
    public const string NAME = 'test-hub:install';
    public const string DESCRIPTION = 'Creates the Test Hub config files and chooses the context provider';

    public const string AUTO = 'auto';

    private const string PACKAGE_CONFIG = 'config/packages/test_hub.yaml';
    private const string ROUTES_CONFIG = 'config/routes/test_hub.yaml';
    private const string ENV_FILE = '.env.local';

    /**
     * @param string       $currentProvider    the provider the container uses now
     * @param string|null  $configuredProvider test_hub.context_provider, when set
     * @param list<string> $candidates         application services that can be the provider
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $currentProvider,
        private readonly ?string $configuredProvider = null,
        private readonly array $candidates = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('context-provider', null, InputOption::VALUE_REQUIRED, \sprintf('Service ID or class of the context provider, or "%s" to detect it', self::AUTO))
            ->addOption('sandbox-url', null, InputOption::VALUE_REQUIRED, 'Sandbox URL, written to .env.local as SANDBOX_URL')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Sandbox API key, written to .env.local as SANDBOX_API_KEY')
            ->addOption('use-sandbox', null, InputOption::VALUE_REQUIRED, 'Default sandbox state, written to .env.local as USE_SANDBOX. Default: 1 unless it is set there already')
            ->addOption('sandbox-verify-peer', null, InputOption::VALUE_REQUIRED, 'Whether to verify the sandbox TLS certificate (1 or 0), written to test_hub.yaml as sandbox_verify_peer. Default: 0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test Hub installation');

        $this->copyFromRecipe(self::PACKAGE_CONFIG, $io);
        $this->copyFromRecipe(self::ROUTES_CONFIG, $io);

        $provider = $this->chooseProvider($input, $io);
        if (null !== $provider) {
            $this->writeConfig('context_provider', self::AUTO === $provider
                ? '# context_provider: ~'
                : \sprintf("context_provider: '%s'", str_replace("'", "''", $provider)), $io);
        }

        $this->writeEnv([
            'SANDBOX_URL' => $this->askValue($input, $io, 'sandbox-url', 'Sandbox URL', 'SANDBOX_URL'),
            'SANDBOX_API_KEY' => $this->askValue($input, $io, 'api-key', 'Sandbox API key', 'SANDBOX_API_KEY'),
            'USE_SANDBOX' => $input->getOption('use-sandbox') ?? ($this->hasEnv('USE_SANDBOX') ? null : '1'),
        ], $io);

        $verifyPeer = $this->chooseVerifyPeer($input, $io);
        if (null !== $verifyPeer) {
            $this->writeConfig('sandbox_verify_peer', 'sandbox_verify_peer: '.($verifyPeer ? 'true' : 'false'), $io);
        }

        $io->success('Test Hub is installed. Open any page and click "Test Hub" in the debug toolbar.');

        return Command::SUCCESS;
    }

    private function copyFromRecipe(string $path, SymfonyStyle $io): void
    {
        $target = $this->projectDir.'/'.$path;
        if (is_file($target)) {
            $io->writeln(\sprintf(' <info>Exists</info>  %s', $path));

            return;
        }

        $source = \dirname(__DIR__, 2).'/recipe/'.$path;
        if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0777, true) && !is_dir(\dirname($target))) {
            throw new \RuntimeException(\sprintf('Cannot create the "%s" directory.', \dirname($target)));
        }
        if (!copy($source, $target)) {
            throw new \RuntimeException(\sprintf('Cannot write "%s".', $target));
        }

        $io->writeln(\sprintf(' <info>Created</info> %s', $path));
    }

    /**
     * @return string|null the provider to write, AUTO to detect it, or null to leave the config as it is
     */
    private function chooseProvider(InputInterface $input, SymfonyStyle $io): ?string
    {
        $option = $input->getOption('context-provider');
        if (null !== $option) {
            return $option;
        }

        if (!$input->isInteractive()) {
            return null;
        }

        $io->section('Context provider');
        $io->text([
            'The context provider gives the agent for the sandbox URL: {SANDBOX_URL}/api/{agent}/{type}/{event}.',
            \sprintf('Now in use: <info>%s</info>', $this->currentProvider),
        ]);

        if (!$this->candidates && null === $this->configuredProvider) {
            $io->note(\sprintf('No application service has a public get(): array method. Implement %s, then run this command again to choose it. Until then requests go to /api_wrap.', ContextProviderInterface::class));

            return null;
        }

        $auto = 'Detect automatically';
        $choices = array_values(array_unique([$auto, ...$this->candidates, ...(array) $this->configuredProvider]));
        $answer = (string) $io->choice('Which service gives the agent context?', $choices, $this->configuredProvider ?? $auto);

        return $auto === $answer ? self::AUTO : $answer;
    }

    /**
     * @return bool|null whether to verify the sandbox certificate, or null to leave the config as it is
     */
    private function chooseVerifyPeer(InputInterface $input, SymfonyStyle $io): ?bool
    {
        $option = $input->getOption('sandbox-verify-peer');
        if (null !== $option) {
            return filter_var($option, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE)
                ?? throw new InvalidOptionException(\sprintf('The "--sandbox-verify-peer" option must be 1 or 0, "%s" given.', $option));
        }

        if (!$input->isInteractive()) {
            return null;
        }

        $current = preg_match('/^[ \t]*sandbox_verify_peer:[ \t]*(\S+)/m', (string) file_get_contents($this->projectDir.'/'.self::PACKAGE_CONFIG), $matches)
            ? filter_var($matches[1], \FILTER_VALIDATE_BOOL)
            : false;

        return $io->confirm('Verify the sandbox TLS certificate? Answer no for a self-signed certificate', $current);
    }

    /**
     * Replaces the first line setting $key, commented out or not, in the package config with $line.
     */
    private function writeConfig(string $key, string $line, SymfonyStyle $io): void
    {
        $path = $this->projectDir.'/'.self::PACKAGE_CONFIG;
        $contents = (string) file_get_contents($path);
        $pattern = '/^([ \t]*)#?[ \t]*'.preg_quote($key, '/').':.*$/m';
        $updated = preg_replace_callback($pattern, static fn (array $m) => $m[1].$line, $contents, 1, $count);

        if (!$count) {
            $io->warning(\sprintf('%s has no %s line. Add it under test_hub:%s    %s', self::PACKAGE_CONFIG, $key, \PHP_EOL, $line));

            return;
        }

        if ($updated !== $contents) {
            file_put_contents($path, $updated);
        }

        $io->writeln(\sprintf(' <info>Updated</info> %s: %s', self::PACKAGE_CONFIG, $line));
    }

    private function askValue(InputInterface $input, SymfonyStyle $io, string $option, string $question, string $env): ?string
    {
        $value = $input->getOption($option);
        if (null !== $value || !$input->isInteractive()) {
            return $value;
        }

        $current = $_SERVER[$env] ?? $_ENV[$env] ?? null;
        $answer = $io->ask($question, \is_string($current) && '' !== $current ? $current : null);

        return null === $answer || $answer === $current ? null : (string) $answer;
    }

    private function hasEnv(string $name): bool
    {
        $path = $this->projectDir.'/'.self::ENV_FILE;

        return is_file($path) && preg_match('/^'.preg_quote($name, '/').'=/m', (string) file_get_contents($path));
    }

    /**
     * Sets the given variables in .env.local, keeping every other line. Null values are left as they are.
     *
     * @param array<string, string|null> $variables
     */
    private function writeEnv(array $variables, SymfonyStyle $io): void
    {
        $variables = array_filter($variables, static fn (?string $value) => null !== $value);
        if (!$variables) {
            return;
        }

        $path = $this->projectDir.'/'.self::ENV_FILE;
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($variables as $name => $value) {
            $line = $name.'='.self::quoteEnv($value);
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace_callback($pattern, static fn () => $line, $contents, 1);
            } else {
                $contents .= ('' === $contents || str_ends_with($contents, "\n") ? '' : "\n").$line."\n";
            }
        }

        file_put_contents($path, $contents);
        $io->writeln(\sprintf(' <info>Updated</info> %s: %s', self::ENV_FILE, implode(', ', array_keys($variables))));
    }

    private static function quoteEnv(string $value): string
    {
        if (preg_match('/^[\w.:\/@%+=,-]*$/', $value)) {
            return $value;
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
