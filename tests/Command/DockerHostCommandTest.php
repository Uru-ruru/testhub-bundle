<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use TestHub\Bundle\Command\DockerHostCommand;

class DockerHostCommandTest extends TestCase
{
    private const string COMPOSE = <<<'YAML'
        services:
          # the app
          php:
            image: php:8.4-fpm
            environment:
              APP_ENV: dev

          nginx:
            image: nginx
            extra_hosts:
              - "other.lan:10.0.0.1"
            ports:
              - "80:80"

        volumes:
          data: ~

        YAML;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/testhub-docker-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAddsExtraHostsToService(): void
    {
        $this->write('compose.yaml', self::COMPOSE);

        $tester = $this->tester();
        $tester->execute(['service' => 'php'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString(<<<'YAML'
                environment:
                  APP_ENV: dev
                extra_hosts:
                  - "sandbox.lan:host-gateway"

              nginx:
            YAML, $this->read('compose.yaml'));
    }

    public function testAppendsToExistingList(): void
    {
        $this->write('compose.yaml', self::COMPOSE);

        $this->tester()->execute(['service' => 'nginx'], ['interactive' => false]);

        $this->assertStringContainsString(<<<'YAML'
                extra_hosts:
                  - "other.lan:10.0.0.1"
                  - "sandbox.lan:host-gateway"
                ports:
            YAML, $this->read('compose.yaml'));
    }

    public function testAppendsToExistingMap(): void
    {
        $this->write('docker-compose.yml', "services:\n  php:\n    extra_hosts:\n      other.lan: 10.0.0.1\n");

        $this->tester()->execute(['service' => 'php', '--ip' => '172.17.0.1'], ['interactive' => false]);

        $this->assertSame("services:\n  php:\n    extra_hosts:\n      other.lan: 10.0.0.1\n      sandbox.lan: 172.17.0.1\n", $this->read('docker-compose.yml'));
    }

    public function testKeepsExistingHost(): void
    {
        $compose = "services:\n  php:\n    extra_hosts:\n      - sandbox.lan:host-gateway\n";
        $this->write('compose.yaml', $compose);

        $tester = $this->tester();
        $tester->execute(['service' => 'php'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame($compose, $this->read('compose.yaml'));
        $this->assertStringContainsString('already has', $tester->getDisplay());
    }

    public function testFailsOnInlineExtraHosts(): void
    {
        $compose = "services:\n  php:\n    extra_hosts: [\"a:1.1.1.1\"]\n";
        $this->write('compose.yaml', $compose);

        $tester = $this->tester();
        $tester->execute(['service' => 'php'], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame($compose, $this->read('compose.yaml'));
    }

    public function testInteractiveChoiceAcrossFiles(): void
    {
        $this->write('compose.yaml', self::COMPOSE);
        $this->write('compose.override.yaml', "services:\n    worker:\n        image: php\n");

        $tester = $this->tester();
        $tester->setInputs(['worker (compose.override.yaml)']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(
            "services:\n    worker:\n        image: php\n        extra_hosts:\n            - \"sandbox.lan:host-gateway\"\n",
            $this->read('compose.override.yaml'),
        );
    }

    public function testFileOptionAndUnknownService(): void
    {
        $this->write('docker/compose.yaml', self::COMPOSE);

        $tester = $this->tester();
        $tester->execute(['service' => 'db', '--file' => 'docker/compose.yaml'], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('php, nginx', $tester->getDisplay());
    }

    public function testFailsWithoutComposeFile(): void
    {
        $tester = $this->tester();
        $tester->execute(['service' => 'php'], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No Docker Compose file', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new DockerHostCommand($this->projectDir));
    }

    private function write(string $path, string $contents): void
    {
        (new Filesystem())->dumpFile($this->projectDir.'/'.$path, $contents);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->projectDir.'/'.$path);
    }
}
