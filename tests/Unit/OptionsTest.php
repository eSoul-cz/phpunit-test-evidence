<?php

declare(strict_types=1);

namespace Tests\Unit;

use Esoul\TestEvidence\Cli\Options;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/test-evidence-options-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_file($this->directory . '/composer.json')) {
            unlink($this->directory . '/composer.json');
        }

        rmdir($this->directory);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'missing group' => [[], 'Expected exactly one test group.'],
            'two groups' => [['a', 'b'], 'Expected exactly one test group.'],
            'unknown option' => [['a', '--colour=red'], 'Unknown option [--colour].'],
            'missing value' => [['a', '--baseline'], 'Option [--baseline] requires a value.'],
            'invalid vendor strategy' => [['a', '--vendor=symlink'], 'Option [--vendor] must be "copy" or "install".'],
            'invalid format' => [['a', '--format=pdf'], 'Option [--format] must be "markdown" or "json".'],
        ];
    }

    public function test_defaults_without_composer_configuration(): void
    {
        $options = Options::parse(['ticket-42'], $this->directory);

        self::assertSame('ticket-42', $options->group);
        self::assertNull($options->baseline);
        self::assertSame('copy', $options->vendorStrategy);
        self::assertSame('vendor/bin/phpunit', $options->phpunit);
        self::assertSame('vendor/bin/phpunit --filter {filter}', $options->rerunTemplate);
        self::assertSame('markdown', $options->format);
        self::assertSame([], $options->copyPaths);
        self::assertNull($options->ticketId());
    }

    public function test_composer_configuration_provides_defaults_that_cli_options_override(): void
    {
        $this->composerConfiguration([
            'rerun' => 'php artisan test --filter {filter}',
            'php-ini' => ['memory_limit=1G'],
            'copy' => '.env',
            'vendor' => 'install',
            'ticket-prefix' => 'freelo-',
            'ticket-url' => 'https://app.freelo.io/task/{id}',
        ]);

        $options = Options::parse(['freelo-123', '--vendor', 'copy', '--php-ini=memory_limit=2G', '--php-ini=display_errors=1', '--baseline=abc123'], $this->directory);

        self::assertSame('php artisan test --filter {filter}', $options->rerunTemplate);
        self::assertSame(['memory_limit=2G', 'display_errors=1'], $options->phpIni);
        self::assertSame(['.env'], $options->copyPaths);
        self::assertSame('copy', $options->vendorStrategy);
        self::assertSame('abc123', $options->baseline);
        self::assertSame('123', $options->ticketId());
        self::assertSame('https://app.freelo.io/task/123', $options->ticketLink());
    }

    public function test_arguments_after_double_dash_go_to_phpunit(): void
    {
        $options = Options::parse(['ticket-42', '--', '--testsuite', 'Feature', '--title=not-ours'], $this->directory);

        self::assertSame(['--testsuite', 'Feature', '--title=not-ours'], $options->phpunitArguments);
        self::assertNull($options->title);
    }

    public function test_group_without_the_ticket_prefix_has_no_ticket(): void
    {
        $this->composerConfiguration(['ticket-prefix' => 'freelo-', 'ticket-url' => 'https://example.test/{id}']);

        $options = Options::parse(['regression'], $this->directory);

        self::assertNull($options->ticketId());
        self::assertNull($options->ticketLink());
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_are_rejected(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Options::parse($arguments, $this->directory);
    }

    public function test_unknown_composer_configuration_key_is_rejected(): void
    {
        $this->composerConfiguration(['baseline' => 'HEAD~1']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown composer.json "extra.test-evidence" key [baseline].');

        Options::parse(['ticket-42'], $this->directory);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function composerConfiguration(array $configuration): void
    {
        file_put_contents(
            $this->directory . '/composer.json',
            json_encode(['extra' => ['test-evidence' => $configuration]], JSON_THROW_ON_ERROR),
        );
    }
}
