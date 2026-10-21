<?php

declare(strict_types=1);

namespace Esoul\TestEvidence;

use function array_values;

use InvalidArgumentException;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * One executed test method (one data set of a data-provider test).
 */
final readonly class TestRecord
{
    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $className,
        public string $methodName,
        public string $file,
        public int|string|null $dataSetName,
        public string $testDox,
        public array $groups,
        public TestOutcome $outcome,
        public float $durationSeconds,
        public int $assertions,
        public ?string $message = null,
        public ?string $details = null,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $groups = $data['groups'] ?? [];
        $dataSetName = $data['dataSetName'] ?? null;

        if (!is_array($groups) || !(is_int($dataSetName) || is_string($dataSetName) || $dataSetName === null)) {
            throw new InvalidArgumentException('Invalid test record.');
        }

        return new self(
            className: self::string($data, 'className'),
            methodName: self::string($data, 'methodName'),
            file: self::string($data, 'file'),
            dataSetName: $dataSetName,
            testDox: self::string($data, 'testDox'),
            groups: array_values(array_filter($groups, is_string(...))),
            outcome: TestOutcome::from(self::string($data, 'outcome')),
            durationSeconds: self::float($data, 'durationSeconds'),
            assertions: self::int($data, 'assertions'),
            message: self::nullableString($data, 'message'),
            details: self::nullableString($data, 'details'),
        );
    }

    /**
     * Stable identity of the test across runs, in PHPUnit's own naming.
     */
    public function id(): string
    {
        return $this->className . '::' . $this->nameWithDataSet();
    }

    public function nameWithDataSet(): string
    {
        return match (true) {
            is_int($this->dataSetName) => sprintf('%s with data set #%d', $this->methodName, $this->dataSetName),
            is_string($this->dataSetName) => sprintf('%s with data set "%s"', $this->methodName, $this->dataSetName),
            default => $this->methodName,
        };
    }

    public function shortClassName(): string
    {
        $position = strrpos($this->className, '\\');

        return $position === false ? $this->className : substr($this->className, $position + 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'className' => $this->className,
            'methodName' => $this->methodName,
            'file' => $this->file,
            'dataSetName' => $this->dataSetName,
            'testDox' => $this->testDox,
            'groups' => $this->groups,
            'outcome' => $this->outcome->value,
            'durationSeconds' => $this->durationSeconds,
            'assertions' => $this->assertions,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Test record field [%s] must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}
