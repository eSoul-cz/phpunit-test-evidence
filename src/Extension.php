<?php

declare(strict_types=1);

namespace Esoul\TestEvidence;

use Esoul\TestEvidence\Collector\ResultCollector;
use Esoul\TestEvidence\Collector\TestErroredSubscriber;
use Esoul\TestEvidence\Collector\TestFailedSubscriber;
use Esoul\TestEvidence\Collector\TestFinishedSubscriber;
use Esoul\TestEvidence\Collector\TestMarkedIncompleteSubscriber;
use Esoul\TestEvidence\Collector\TestPreparedSubscriber;
use Esoul\TestEvidence\Collector\TestRunnerExecutionFinishedSubscriber;
use Esoul\TestEvidence\Collector\TestSkippedSubscriber;

use function getenv;
use function is_string;

use PHPUnit\Runner\Extension\Extension as PHPUnitExtension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Records every executed test (class, method, data set, TestDox, groups, outcome, duration) to a
 * JSON file. Inactive unless an output path is given, either as the `output` extension parameter
 * in phpunit.xml or through the TEST_EVIDENCE_OUTPUT environment variable, so it can stay
 * registered for normal runs.
 */
final class Extension implements PHPUnitExtension
{
    public const string OUTPUT_ENVIRONMENT_VARIABLE = 'TEST_EVIDENCE_OUTPUT';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $output = $parameters->has('output') ? $parameters->get('output') : getenv(self::OUTPUT_ENVIRONMENT_VARIABLE);

        if (!is_string($output) || $output === '') {
            return;
        }

        $collector = new ResultCollector($output);

        $facade->registerSubscribers(
            new TestPreparedSubscriber($collector),
            new TestFailedSubscriber($collector),
            new TestErroredSubscriber($collector),
            new TestSkippedSubscriber($collector),
            new TestMarkedIncompleteSubscriber($collector),
            new TestFinishedSubscriber($collector),
            new TestRunnerExecutionFinishedSubscriber($collector),
        );
    }
}
