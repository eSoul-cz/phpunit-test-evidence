<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Cli;

use RuntimeException;

/**
 * @internal
 */
final readonly class Git
{
    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function root(string $directory): string
    {
        return $this->processes->capture(['git', 'rev-parse', '--show-toplevel'], $directory);
    }

    public function shortCommit(string $directory, string $ref = 'HEAD'): string
    {
        return $this->processes->capture(['git', 'rev-parse', '--short', $ref . '^{commit}'], $directory);
    }

    public function branch(string $directory): ?string
    {
        try {
            $branch = $this->processes->capture(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], $directory);
        } catch (RuntimeException) {
            return null;
        }

        return $branch === '' ? null : $branch;
    }

    public function hasUncommittedChanges(string $directory): bool
    {
        return $this->processes->capture(['git', 'status', '--porcelain', '--untracked-files=no'], $directory) !== '';
    }

    public function addDetachedWorktree(string $repository, string $path, string $ref): void
    {
        $this->processes->capture(['git', 'worktree', 'add', '--quiet', '--detach', $path, $ref . '^{commit}'], $repository);
    }

    public function removeWorktree(string $repository, string $path): void
    {
        $this->processes->capture(['git', 'worktree', 'remove', '--force', $path], $repository);
    }
}
