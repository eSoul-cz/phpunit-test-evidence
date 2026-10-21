<?php

declare(strict_types=1);

namespace Esoul\TestEvidence;

use function array_map;
use function array_sum;
use function count;
use function file_get_contents;

use InvalidArgumentException;

use function is_array;
use function is_string;
use function json_decode;

use JsonException;

use function sprintf;

/**
 * All test records of one PHPUnit run, as written by the extension.
 */
final readonly class RunResult
{
    /**
     * @param  list<TestRecord>  $records
     */
    public function __construct(
        public string $phpVersion,
        public string $phpunitVersion,
        public array $records,
    ) {}

    public static function fromJsonFile(string $path): self
    {
        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            throw new InvalidArgumentException(sprintf('Cannot read test evidence results from [%s].', $path));
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('Invalid test evidence results in [%s].', $path), previous: $exception);
        }

        if (!is_array($data) || !is_array($data['records'] ?? null)) {
            throw new InvalidArgumentException(sprintf('Invalid test evidence results in [%s].', $path));
        }

        $records = [];

        foreach ($data['records'] as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException(sprintf('Invalid test evidence results in [%s].', $path));
            }

            $records[] = TestRecord::fromArray($record);
        }

        return new self(
            phpVersion: is_string($data['phpVersion'] ?? null) ? $data['phpVersion'] : '',
            phpunitVersion: is_string($data['phpunitVersion'] ?? null) ? $data['phpunitVersion'] : '',
            records: $records,
        );
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function successfulCount(): int
    {
        return count(array_filter($this->records, static fn (TestRecord $record): bool => $record->outcome->isSuccessful()));
    }

    public function isSuccessful(): bool
    {
        return $this->records !== [] && $this->successfulCount() === $this->count();
    }

    public function assertions(): int
    {
        return array_sum(array_map(static fn (TestRecord $record): int => $record->assertions, $this->records));
    }

    public function find(string $id): ?TestRecord
    {
        foreach ($this->records as $record) {
            if ($record->id() === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phpVersion' => $this->phpVersion,
            'phpunitVersion' => $this->phpunitVersion,
            'records' => array_map(static fn (TestRecord $record): array => $record->toArray(), $this->records),
        ];
    }
}
