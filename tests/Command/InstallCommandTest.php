<?php

declare(strict_types=1);

namespace TestHub\Bundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use TestHub\Bundle\Command\InstallCommand;
use TestHub\Bundle\HttpClient\State\DefaultContextProvider;

class InstallCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/testhub-install-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testCopiesRecipeConfigFiles(): void
    {
        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        $this->assertFileEquals(\dirname(__DIR__, 2).'/recipe/config/packages/test_hub.yaml', $this->projectDir.'/config/packages/test_hub.yaml');
        $this->assertFileEquals(\dirname(__DIR__, 2).'/recipe/config/routes/test_hub.yaml', $this->projectDir.'/config/routes/test_hub.yaml');
        $this->assertSame("USE_SANDBOX=1\n", file_get_contents($this->projectDir.'/.env.local'));
    }

    public function testKeepsExistingUseSandbox(): void
    {
        $this->write('.env.local', "USE_SANDBOX=false\n");

        $this->tester()->execute([], ['interactive' => false]);

        $this->assertSame("USE_SANDBOX=false\n", file_get_contents($this->projectDir.'/.env.local'));
    }

    public function testWritesUseSandboxFromOption(): void
    {
        $this->write('.env.local', "USE_SANDBOX=1\n");

        $this->tester()->execute(['--use-sandbox' => '0'], ['interactive' => false]);

        $this->assertSame("USE_SANDBOX=0\n", file_get_contents($this->projectDir.'/.env.local'));
    }

    public function testKeepsExistingConfigFiles(): void
    {
        $this->write('config/routes/test_hub.yaml', "# mine\n");

        $this->tester()->execute([], ['interactive' => false]);

        $this->assertSame("# mine\n", file_get_contents($this->projectDir.'/config/routes/test_hub.yaml'));
    }

    public function testWritesProviderFromOption(): void
    {
        $this->tester()->execute(['--context-provider' => 'App\Sandbox\Provider'], ['interactive' => false]);

        $config = (string) file_get_contents($this->projectDir.'/config/packages/test_hub.yaml');
        $this->assertStringContainsString("        context_provider: 'App\\Sandbox\\Provider'\n", $config);
        $this->assertStringNotContainsString('# context_provider', $config);
    }

    public function testRecipeDisablesVerifyPeer(): void
    {
        $this->tester()->execute([], ['interactive' => false]);

        $this->assertStringContainsString("        sandbox_verify_peer: false\n", (string) file_get_contents($this->projectDir.'/config/packages/test_hub.yaml'));
    }

    public function testWritesVerifyPeerFromOption(): void
    {
        $this->write('config/packages/test_hub.yaml', "test_hub:\n    # sandbox_verify_peer: false\n");

        $this->tester()->execute(['--sandbox-verify-peer' => '1'], ['interactive' => false]);

        $this->assertSame("test_hub:\n    sandbox_verify_peer: true\n", file_get_contents($this->projectDir.'/config/packages/test_hub.yaml'));
    }

    public function testRejectsInvalidVerifyPeer(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->tester()->execute(['--sandbox-verify-peer' => 'maybe'], ['interactive' => false]);
    }

    public function testAutoCommentsProviderOut(): void
    {
        $this->write('config/packages/test_hub.yaml', "test_hub:\n    context_provider: app.provider\n");

        $this->tester()->execute(['--context-provider' => InstallCommand::AUTO], ['interactive' => false]);

        $this->assertSame("test_hub:\n    # context_provider: ~\n", file_get_contents($this->projectDir.'/config/packages/test_hub.yaml'));
    }

    public function testWarnsWithoutProviderLine(): void
    {
        $this->write('config/packages/test_hub.yaml', "test_hub: ~\n");

        $tester = $this->tester();
        $tester->execute(['--context-provider' => 'app.provider'], ['interactive' => false]);

        $this->assertSame("test_hub: ~\n", file_get_contents($this->projectDir.'/config/packages/test_hub.yaml'));
        $this->assertStringContainsString("context_provider: 'app.provider'", $tester->getDisplay());
    }

    public function testInteractiveChoiceAndEnv(): void
    {
        $this->write('.env.local', "APP_SECRET=abc\nSANDBOX_URL=https://old.example\n");

        $tester = $this->tester(['app.first', 'app.second']);
        $tester->setInputs(['app.second', 'https://sandbox.example', 'key with space', 'yes']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $config = (string) file_get_contents($this->projectDir.'/config/packages/test_hub.yaml');
        $this->assertStringContainsString("context_provider: 'app.second'", $config);
        $this->assertStringContainsString("        sandbox_verify_peer: true\n", $config);
        $this->assertSame(
            "APP_SECRET=abc\nSANDBOX_URL=https://sandbox.example\nSANDBOX_API_KEY='key with space'\nUSE_SANDBOX=1\n",
            file_get_contents($this->projectDir.'/.env.local'),
        );
    }

    public function testInteractiveWithoutCandidatesLeavesProvider(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', '', '']);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('sandbox_verify_peer: false', (string) file_get_contents($this->projectDir.'/config/packages/test_hub.yaml'));
        $this->assertStringContainsString('# context_provider: ~', (string) file_get_contents($this->projectDir.'/config/packages/test_hub.yaml'));
        $this->assertStringContainsString('No application service', $tester->getDisplay());
    }

    public function testRecipeManifestIsValid(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/recipe/manifest.json'), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(['dev'], $manifest['bundles']['TestHub\Bundle\TestHubBundle']);
        $this->assertArrayHasKey('SANDBOX_URL', $manifest['env']);
    }

    /**
     * @param list<string> $candidates
     */
    private function tester(array $candidates = []): CommandTester
    {
        return new CommandTester(new InstallCommand($this->projectDir, DefaultContextProvider::class, null, $candidates));
    }

    private function write(string $path, string $contents): void
    {
        (new Filesystem())->dumpFile($this->projectDir.'/'.$path, $contents);
    }
}
