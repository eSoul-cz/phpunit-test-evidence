<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Report;

use function array_map;

use Esoul\TestEvidence\TestRecord;

use function json_encode;

final class JsonRenderer
{
    public function render(Report $report): string
    {
        $records = array_map(static fn (TestRecord $record): array => [
            ...$record->toArray(),
            'id' => $record->id(),
            'rerun' => RerunCommand::forRecord($record, $report->rerunTemplate),
        ], $report->result->records);

        return json_encode([
            'group' => $report->group,
            'title' => $report->title,
            'ticket' => ['id' => $report->ticketId, 'url' => $report->ticketUrl],
            'commit' => $report->commit,
            'branch' => $report->branch,
            'hasUncommittedChanges' => $report->hasUncommittedChanges,
            'generatedAt' => $report->generatedAt->format(DATE_ATOM),
            'phpVersion' => $report->result->phpVersion,
            'phpunitVersion' => $report->result->phpunitVersion,
            'passed' => $report->result->successfulCount(),
            'total' => $report->result->count(),
            'records' => $records,
            'baseline' => $report->baseline === null ? null : [
                'ref' => $report->baseline->ref,
                'commit' => $report->baseline->commit,
                ...$report->baseline->result->toArray(),
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
