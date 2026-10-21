<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Cli;

use function bin2hex;
use function copy;
use function dirname;
use function file_exists;

use FilesystemIterator;

use function is_dir;
use function is_link;
use function mkdir;
use function random_bytes;
use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function rmdir;

use RuntimeException;
use SplFileInfo;

use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;

use Throwable;

use function unlink;

/**
 * A temporary git worktree of the baseline ref with the evaluated test files, extra project files
 * and a vendor directory, in which the same tests can run against the older application code.
 *
 * @internal
 */
final class BaselineWorkspace
{
    private function __construct(
        private readonly Git $git,
        private readonly string $repositoryRoot,
        private readonly string $worktree,
        public readonly string $projectDirectory,
    ) {}

    /**
     * @param  list<string>  $testFiles  absolute paths of the evaluated test files
     * @param  list<string>  $copyPaths  project-relative files or directories to copy as they are now
     */
    public static function create(
        Git $git,
        ProcessRunner $processes,
        string $projectDirectory,
        string $ref,
        string $vendorStrategy,
        array $testFiles,
        array $copyPaths,
    ): self {
        $projectDirectory = self::realPath($projectDirectory);
        $repositoryRoot = self::realPath($git->root($projectDirectory));
        $worktree = sys_get_temp_dir() . '/test-evidence-' . bin2hex(random_bytes(6));

        $git->addDetachedWorktree($repositoryRoot, $worktree, $ref);

        $workspace = new self(
            $git,
            $repositoryRoot,
            $worktree,
            $worktree . self::relativePath($projectDirectory, $repositoryRoot),
        );

        try {
            foreach ($testFiles as $testFile) {
                $workspace->copyPath(self::realPath($testFile), $workspace->projectDirectory . self::relativePath(self::realPath($testFile), $projectDirectory));
            }

            foreach ($copyPaths as $copyPath) {
                $source = $projectDirectory . '/' . $copyPath;

                if (!file_exists($source)) {
                    throw new RuntimeException(sprintf('Cannot copy [%s] into the baseline: it does not exist.', $copyPath));
                }

                $workspace->copyPath($source, $workspace->projectDirectory . '/' . $copyPath);
            }

            if ($vendorStrategy === 'install') {
                $exitCode = $processes->passthru(['composer', 'install', '--no-interaction', '--no-progress'], $workspace->projectDirectory);

                if ($exitCode !== 0) {
                    throw new RuntimeException('composer install failed in the baseline checkout.');
                }
            } else {
                $workspace->cloneDirectory($processes, $projectDirectory . '/vendor', $workspace->projectDirectory . '/vendor');
            }
        } catch (Throwable $exception) {
            $workspace->remove();

            throw $exception;
        }

        return $workspace;
    }

    public function remove(): void
    {
        try {
            $this->git->removeWorktree($this->repositoryRoot, $this->worktree);
        } catch (RuntimeException) {
            self::removeDirectory($this->worktree);
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory [%s].', $directory));
        }
    }

    private static function realPath(string $path): string
    {
        $realPath = realpath($path);

        if ($realPath === false) {
            throw new RuntimeException(sprintf('Path [%s] does not exist.', $path));
        }

        return $realPath;
    }

    /**
     * @return string the path of $path below $base, with a leading slash, or '' for $base itself
     */
    private static function relativePath(string $path, string $base): string
    {
        if ($path === $base) {
            return '';
        }

        if (!str_starts_with($path, $base . '/')) {
            throw new RuntimeException(sprintf('Path [%s] is outside [%s].', $path, $base));
        }

        return substr($path, strlen($base));
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !is_link($item->getPathname()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    private function copyPath(string $source, string $target): void
    {
        if (is_dir($source)) {
            $this->copyDirectoryWithPhp($source, $target);

            return;
        }

        self::ensureDirectory(dirname($target));

        if (!copy($source, $target)) {
            throw new RuntimeException(sprintf('Cannot copy [%s] into the baseline.', $source));
        }
    }

    /**
     * Copies vendor as real files: a symlinked vendor would make Composer resolve the project's
     * own classes from the original checkout instead of the baseline. Copy-on-write clones keep
     * this fast where the file system supports them.
     */
    private function cloneDirectory(ProcessRunner $processes, string $source, string $target): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException(sprintf('Cannot copy [%s] into the baseline: it does not exist. Use --vendor=install.', $source));
        }

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['cp', '-Rc', $source, $target],
            'Linux' => ['cp', '-R', '--reflink=auto', $source, $target],
            default => null,
        };

        if ($command !== null && $processes->passthru($command, dirname($target)) === 0) {
            return;
        }

        $this->copyDirectoryWithPhp($source, $target);
    }

    private function copyDirectoryWithPhp(string $source, string $target): void
    {
        self::ensureDirectory($target);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $destination = $target . substr($item->getPathname(), strlen($source));

            if ($item->isDir() && !$item->isLink()) {
                self::ensureDirectory($destination);
            } elseif (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException(sprintf('Cannot copy [%s] into the baseline.', $item->getPathname()));
            }
        }
    }
}
