---
name: test-evidence
description: Produce a Markdown test evidence report with esoul-cz/phpunit-test-evidence. Use when a bug report or change request needs proof from automated tests that something works (for example "send us the log from your tests", "prove it retries"), or when writing a regression test for a reported bug.
---

# Test evidence reports

`esoul-cz/phpunit-test-evidence` runs the PHPUnit tests of one group and prints a Markdown report: every test with its TestDox sentence, result and duration, a command to re-run each test on its own, and optionally the same tests run against the code before the fix.

## Workflow for a bug report

1. Write the regression test first and watch it fail against the current code.
2. Tag it with the ticket and describe the expected behaviour:

   ```php
   #[Group('freelo-25268176')]
   #[TestDox('Admin $status on assign-hub-spot-id is retried and the deal gets paired')]
   public function test_retryable_admin_response_is_retried(int $status): void
   ```

   - The group is the ticket: `<prefix><id>`, with the prefix the project configures as `ticket-prefix` in `composer.json` under `extra.test-evidence`.
   - The TestDox sentence is what the reader of the report sees. State the observable behaviour, not the implementation. `$parameter` placeholders are filled from the data set.
   - One test can carry several groups if it proves more than one ticket.
3. Fix the code and make the tests pass.
4. Generate the report, comparing against the commit before the fix:

   ```sh
   vendor/bin/test-evidence freelo-25268176 --baseline=<commit before the fix> --output=evidence.md
   ```

5. Read the report before sending it:
   - Every test passes on the evaluated commit.
   - The tests that prove the fix do not pass at the baseline, and the reason column shows the reported symptom. A test that fails at the baseline with an unrelated error (for example a helper class added with the fix) is not evidence; make the test independent of new helpers or copy them with `--copy`.
6. Paste the Markdown into the ticket. Add a short human-written summary above it covering what the tests simulate (faked HTTP, in-memory services) and what they do not prove (environment configuration, deployment).

## Rules

- Run the report on a clean, committed state; the report flags uncommitted changes.
- Never put secrets or personal data into test names, TestDox sentences or data set names: they appear in the report.
- The report is evidence that the code behaves as described under the tested conditions. It does not replace checking the target environment's configuration.
