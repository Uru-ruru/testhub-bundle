<?php

declare(strict_types=1);

namespace TestHub\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Adds the sandbox host to extra_hosts of a Docker Compose service, so the container resolves it to the host machine.
 * The file is edited as text, keeping its comments and formatting.
 */
#[AsCommand(name: self::NAME, description: self::DESCRIPTION)]
final class DockerHostCommand extends Command
{
    public const string NAME = 'test-hub:docker-host';
    public const string DESCRIPTION = 'Adds the sandbox host to extra_hosts of a Docker Compose service';

    public const string DEFAULT_HOST = 'sandbox.lan';
    public const string DEFAULT_IP = 'host-gateway';

    private const array COMPOSE_FILES = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
        'compose.override.yaml',
        'compose.override.yml',
        'docker-compose.override.yaml',
        'docker-compose.override.yml',
    ];

    private const string KEY_LINE = '/^( *)("[^"]+"|\'[^\']+\'|[\w.-]+):[ \t]*(.*?)[ \t]*$/';

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::OPTIONAL, 'Compose service (container) to add the host to')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Compose file, relative to the project directory or absolute. Default: the compose files in the project directory')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host name to add', self::DEFAULT_HOST)
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Address the host resolves to', self::DEFAULT_IP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $files = $this->composeFiles($input->getOption('file'));
        if (!$files) {
            $io->error(\sprintf('No Docker Compose file found in %s. Pass one with --file.', $this->projectDir));

            return Command::FAILURE;
        }

        /** @var array<string, array{string, string}> $services label => [file, service] */
        $services = [];
        foreach ($files as $file) {
            foreach (array_keys(self::parseServices(self::readLines($file))) as $name) {
                $label = \count($files) > 1 ? \sprintf('%s (%s)', $name, $this->relative($file)) : $name;
                $services[$label] = [$file, $name];
            }
        }

        if (!$services) {
            $io->error(\sprintf('No services found in %s.', implode(', ', array_map($this->relative(...), $files))));

            return Command::FAILURE;
        }

        $target = $this->chooseService($input, $io, $services);
        if (null === $target) {
            return Command::FAILURE;
        }

        [$file, $service] = $target;
        $entry = $input->getOption('host').':'.$input->getOption('ip');
        $lines = self::readLines($file);

        try {
            $added = self::addHost($lines, $service, (string) $input->getOption('host'), $entry);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$added) {
            $io->success(\sprintf('Service "%s" in %s already has %s in extra_hosts.', $service, $this->relative($file), $input->getOption('host')));

            return Command::SUCCESS;
        }

        file_put_contents($file, implode("\n", $lines));
        $io->success([
            \sprintf('Added "%s" to extra_hosts of service "%s" in %s.', $entry, $service, $this->relative($file)),
            \sprintf('Recreate the container to apply it: docker compose up -d %s', $service),
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function composeFiles(?string $option): array
    {
        if (null !== $option) {
            $path = str_starts_with($option, '/') ? $option : $this->projectDir.'/'.$option;

            return is_file($path) ? [$path] : [];
        }

        return array_values(array_filter(
            array_map(fn (string $name) => $this->projectDir.'/'.$name, self::COMPOSE_FILES),
            is_file(...),
        ));
    }

    /**
     * @param array<string, array{string, string}> $services
     *
     * @return array{string, string}|null
     */
    private function chooseService(InputInterface $input, SymfonyStyle $io, array $services): ?array
    {
        $name = $input->getArgument('service');
        if (null !== $name) {
            foreach ($services as $target) {
                if ($target[1] === $name) {
                    return $target;
                }
            }

            $io->error(\sprintf('Service "%s" not found. Services: %s.', $name, implode(', ', array_unique(array_column($services, 1)))));

            return null;
        }

        if (!$input->isInteractive()) {
            $io->error(\sprintf('Pass the service name. Services: %s.', implode(', ', array_unique(array_column($services, 1)))));

            return null;
        }

        $label = (string) $io->choice('Which container should resolve the sandbox host?', array_keys($services));

        return $services[$label];
    }

    /**
     * Adds the entry to extra_hosts of the service.
     *
     * @param list<string> $lines
     *
     * @return bool false when the service already has the host
     */
    public static function addHost(array &$lines, string $service, string $host, string $entry): bool
    {
        $range = self::parseServices($lines)[$service] ?? throw new \RuntimeException(\sprintf('Service "%s" not found.', $service));
        [$start, $end] = $range;

        $serviceIndent = self::indent($lines[$start]);
        $childIndent = null;
        $lastLine = $start;
        $extraHosts = null;

        for ($i = $start + 1; $i < $end; ++$i) {
            if (self::isBlank($lines[$i])) {
                continue;
            }
            $lastLine = $i;
            $childIndent ??= self::indent($lines[$i]);

            if (self::indent($lines[$i]) === $childIndent && preg_match(self::KEY_LINE, $lines[$i], $m) && 'extra_hosts' === trim($m[2], '"\'')) {
                $extraHosts = $i;
            }
        }

        $childIndent ??= $serviceIndent.'    ';
        $hostPattern = '/(^|[\s"\'\-\[,{])'.preg_quote($host, '/').'["\']?\s*[:=]/';

        if (null === $extraHosts) {
            $step = substr($childIndent, \strlen($serviceIndent)) ?: '    ';
            array_splice($lines, $lastLine + 1, 0, [
                $childIndent.'extra_hosts:',
                $childIndent.$step.'- "'.$entry.'"',
            ]);

            return true;
        }

        preg_match(self::KEY_LINE, $lines[$extraHosts], $m);
        $inline = preg_replace('/\s+#.*$/', '', $m[3]);
        if ('' !== $inline) {
            if (preg_match($hostPattern, $inline)) {
                return false;
            }

            throw new \RuntimeException(\sprintf('extra_hosts of service "%s" is written inline (%s). Add "%s" to it yourself.', $service, $inline, $entry));
        }

        $itemIndent = null;
        $isList = true;
        $lastItem = $extraHosts;
        for ($i = $extraHosts + 1; $i < $end; ++$i) {
            if (self::isBlank($lines[$i])) {
                continue;
            }
            if (\strlen(self::indent($lines[$i])) <= \strlen($childIndent)) {
                break;
            }
            if (null === $itemIndent) {
                $itemIndent = self::indent($lines[$i]);
                $isList = str_starts_with(ltrim($lines[$i]), '-');
            }
            if (preg_match($hostPattern, $lines[$i])) {
                return false;
            }
            $lastItem = $i;
        }

        $itemIndent ??= $childIndent.(substr($childIndent, \strlen($serviceIndent)) ?: '    ');
        array_splice($lines, $lastItem + 1, 0, [
            $isList ? $itemIndent.'- "'.$entry.'"' : $itemIndent.$host.': '.substr($entry, \strlen($host) + 1),
        ]);

        return true;
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, array{int, int}> service => [line of its key, line after its block]
     */
    public static function parseServices(array $lines): array
    {
        $count = \count($lines);
        $section = null;
        for ($i = 0; $i < $count; ++$i) {
            if (preg_match('/^services:[ \t]*(#.*)?$/', $lines[$i])) {
                $section = $i;
                break;
            }
        }
        if (null === $section) {
            return [];
        }

        $services = [];
        $indent = null;
        $current = null;
        for ($i = $section + 1; $i < $count; ++$i) {
            if (self::isBlank($lines[$i])) {
                continue;
            }

            $lineIndent = self::indent($lines[$i]);
            if ('' === $lineIndent) {
                break;
            }
            $indent ??= $lineIndent;

            if ($lineIndent === $indent && preg_match(self::KEY_LINE, $lines[$i], $m)) {
                if (null !== $current) {
                    $services[$current][1] = $i;
                }
                $current = trim($m[2], '"\'');
                $services[$current] = [$i, $count];
            }
        }

        if (null !== $current) {
            $services[$current][1] = $i;
        }

        return $services;
    }

    /**
     * @return list<string>
     */
    private static function readLines(string $file): array
    {
        return explode("\n", (string) file_get_contents($file));
    }

    private static function isBlank(string $line): bool
    {
        $trimmed = ltrim($line);

        return '' === $trimmed || str_starts_with($trimmed, '#');
    }

    private static function indent(string $line): string
    {
        return substr($line, 0, \strlen($line) - \strlen(ltrim($line, ' ')));
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->projectDir.'/') ? substr($file, \strlen($this->projectDir) + 1) : $file;
    }
}
