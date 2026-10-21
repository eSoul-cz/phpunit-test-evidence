---
name: test-evidence
description: Tag tests with a ticket and produce a Markdown test evidence report with esoul-cz/phpunit-test-evidence. Use when fixing or implementing a ticket whose tests should be tagged, when the user wants a test evidence report, or when a bug report or change request needs proof from automated tests that something works (for example "send us the log from your tests", "prove it retries").
---

# Test evidence reports

`esoul-cz/phpunit-test-evidence` runs the PHPUnit tests of one group and writes a Markdown report: every test with its TestDox sentence, result and duration, a command to re-run each test on its own, and optionally the same tests run against the code before the change.

Project defaults live in `composer.json` under `extra.test-evidence`. Read them first: `ticket-prefix` (the group prefix), `ticket-url`, `rerun`, `copy`, `php-ini` and `output` (where reports go; `{group}` is replaced).

## 1. Tag the tests while working on the ticket

```php
#[Group('ticket-1234')]
#[TestDox('Admin $status on assign-hub-spot-id is retried and the deal gets paired')]
public function test_retryable_admin_response_is_retried(int $status): void
```

- The group is the ticket: `<ticket-prefix><id>`. If the ticket ID is not known, ask for it.
- Tag the tests that prove the requested behaviour, usually feature tests that exercise it end to end, including existing tests that already cover part of it. Use the class attribute when the whole class is about the ticket, method attributes otherwise. One test can carry several ticket groups.
- The TestDox sentence is what the reader of the report sees. State the observable behaviour, not the implementation. `$parameter` placeholders are filled from the data set.
- For a bug, write the regression test first and watch it fail against the current code.

## 2. Ask before generating

When the work is done and the tagged tests pass, ask the user whether a report should be generated. Do not generate one unasked.

## 3. Generate the report

Use the project's Composer script if `composer.json` defines one that runs `test-evidence` (for example `composer test:evidence -- <group> …`); otherwise run `vendor/bin/test-evidence`.

```sh
composer test:evidence -- ticket-1234 --baseline="$(git merge-base HEAD origin/main)"
```

- `--baseline` is the commit before the change, usually where the work branched from its target branch. The report then shows which tests do not pass without the change.
- The report goes to the configured `output` path, or stdout when none is configured. `--output=<file>` overrides it and `--output=-` prints to stdout. The command prints the path it wrote.
- Generate from a committed state: uncommitted changes to tracked files are flagged in the report. If the work is not committed yet, say so and let the user decide.
- Exit code 0: every test passed; 1: a test did not pass (the report is still written); 2: no report (for example no tests in the group, or a `copy` path missing).

## 4. Read the report before handing it over

- Every test passes on the evaluated commit.
- The tests that prove the change do not pass at the baseline, and the reason column shows the reported symptom. A test that fails at the baseline with an unrelated error (for example a helper class added with the change) is not evidence; make the test independent of new helpers or copy them with `--copy`.
- Give the user the report path. When the report goes to a ticket, suggest a short human-written summary above it covering what the tests simulate (faked HTTP, in-memory services) and what they do not prove (environment configuration, deployment).

## Rules

- Never put secrets or personal data into test names, TestDox sentences or data set names: they appear in the report.
- The report is evidence that the code behaves as described under the tested conditions. It does not replace checking the target environment's configuration.
