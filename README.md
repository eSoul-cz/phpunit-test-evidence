# PHPUnit Test Evidence

Turn a tagged group of PHPUnit tests into a Markdown report that someone outside the code base can read and verify: what each test proves (its TestDox sentence), whether it passed, a command to re-run every test on its own, and optionally the same tests run against the code **before** the fix.

Built for bug reports and change requests: "can you send us proof from your tests that it now retries?"

Framework-independent: it is a plain PHPUnit extension plus a small CLI, with no dependency besides PHPUnit.

## Requirements

- PHP `>= 8.3`
- PHPUnit `12.x` or `13.x`
- `git` for the baseline comparison

## Installation

```bash
composer require --dev esoul-cz/phpunit-test-evidence
```

No `phpunit.xml` change is needed: the CLI registers the extension with PHPUnit's `--extension` option.

## Usage

### 1. Tag the tests

Put the tests that prove a ticket into a group, and describe the expected behaviour with `#[TestDox]`. Placeholders such as `$status` are filled from the data set.

```php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

#[Group('ticket-42')]
#[DataProvider('sums')]
#[TestDox('Adds $a and $b to $expected')]
public function test_adds(int $a, int $b, int $expected): void
{
    self::assertSame($expected, (new Calculator())->add($a, $b));
}
```

### 2. Generate the report

```bash
vendor/bin/test-evidence ticket-42
vendor/bin/test-evidence ticket-42 --baseline=main --output=evidence.md
```

`--baseline=<git ref>` runs the same tests again against the application code at that ref, typically the commit before the fix. It uses a temporary `git worktree` that receives the test files of the evaluated commit, so the comparison shows which tests only pass because of the change.

PHPUnit's console output goes to STDERR, and the report goes to STDOUT or `--output`.

### Example

<details>
<summary>Report for a fixed addition bug</summary>

```markdown
# Test evidence: ticket-42

|  |  |
|---|---|
| Ticket | [42](https://tickets.example/42) |
| Test group | `ticket-42` |
| Commit | `5bb3436` on `fix/addition` |
| Generated | 2026-10-21 12:15:52 +00:00 |
| Runtime | PHP 8.5.11, PHPUnit 13.4.3 |
| Result | **5 of 5 tests passed** (5 assertions) |
| Without the change | `main` at `57ebb7e`: **3 of 5 tests do not pass** |

## Results

| # | Behaviour | Test | Result | Time |
|---|---|---|---|---|
| 1 | Adds 1 and 2 to 3 | `CalculatorTest::test_adds`<br>data set `one plus two` | ✅ passed | 1 ms |
| 2 | Adds 0 and 0 to 0 | `CalculatorTest::test_adds`<br>data set `zero plus zero` | ✅ passed | 0 ms |
| 3 | Adds twice with data set #0 | `CalculatorTest::test_adds_twice`<br>data set `#0` | ✅ passed | 0 ms |
| 4 | Adds twice with data set #1 | `CalculatorTest::test_adds_twice`<br>data set `#1` | ✅ passed | 0 ms |
| 5 | Returns an integer for "quoted" \| piped input | `CalculatorTest::test_returns_integer` | ✅ passed | 0 ms |

## Re-run a single test

    # 1. Adds 1 and 2 to 3
    vendor/bin/phpunit --filter '/^FixtureProject\\Tests\\CalculatorTest::test_adds with data set "one plus two"$/'
    # 2. Adds 0 and 0 to 0
    vendor/bin/phpunit --filter '/^FixtureProject\\Tests\\CalculatorTest::test_adds with data set "zero plus zero"$/'
    …

## Without the change: `main`

The same tests were run against the application code of `main` (`57ebb7e`); the test files were taken from the evaluated commit. A test that does not pass here shows the behaviour that the change introduces or fixes.

| # | Behaviour | Result | Reason |
|---|---|---|---|
| 1 | Adds 1 and 2 to 3 | ❌ failed | Failed asserting that -1 is identical to 3. |
| 2 | Adds 0 and 0 to 0 | ✅ passed |  |
| 3 | Adds twice with data set #0 | ❌ failed | Failed asserting that 0 is identical to 2. |
| 4 | Adds twice with data set #1 | ❌ failed | Failed asserting that 0 is identical to 4. |
| 5 | Returns an integer for "quoted" \| piped input | ✅ passed |  |
```

</details>

Failed, errored and skipped tests also get a **Failures** section with the assertion message and diff.

The re-run filters are anchored regular expressions on the fully qualified test name, so each command runs exactly one test. PHPUnit's `name@data set` shorthand would also match other methods with the same prefix.

## Options

| Option | Default | |
|---|---|---|
| `--baseline=<git ref>` | none | Also run the tests against the code at this ref |
| `--vendor=copy\|install` | `copy` | Baseline vendor directory: a copy (copy-on-write where supported) or `composer install` |
| `--copy=<path>` | none | Repeatable. Project file or directory copied into the baseline checkout, e.g. `.env` |
| `--php-ini=<key=value>` | none | Repeatable. PHP setting for the PHPUnit process, e.g. `memory_limit=1G` |
| `--phpunit=<path>` | `vendor/bin/phpunit` | PHPUnit binary |
| `--rerun=<template>` | `vendor/bin/phpunit --filter {filter}` | Re-run command shown in the report |
| `--ticket-prefix=<prefix>` | none | Group prefix stripped to get the ticket ID |
| `--ticket-url=<template>` | none | Ticket link; `{id}` and `{group}` are replaced |
| `--title=<text>` | `Test evidence: <group>` | Report title |
| `--format=markdown\|json` | `markdown` | Output format |
| `--output=<file>` | STDOUT | Report file |
| `-- <arguments>` | | Passed on to PHPUnit, e.g. `-- --testsuite Feature` |

### Project defaults

Set project-wide defaults in `composer.json`. CLI options override them. Example for a Laravel project that tracks tickets in Freelo:

```json
{
    "extra": {
        "test-evidence": {
            "rerun": "php artisan test --filter {filter}",
            "php-ini": ["memory_limit=1G"],
            "copy": [".env"],
            "ticket-prefix": "freelo-",
            "ticket-url": "https://app.freelo.io/task/{id}"
        }
    }
}
```

Supported keys: `vendor`, `copy`, `php-ini`, `phpunit`, `rerun`, `ticket-prefix`, `ticket-url`.

A group that matches the ticket prefix (`freelo-25268176`) links to its ticket; other groups produce a report without a link.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Every test of the group passed on the evaluated commit |
| `1` | At least one test did not pass; the report is still written |
| `2` | No report: invalid arguments, no tests in the group, or PHPUnit aborted |

Baseline failures never affect the exit code.

## Using the extension directly

The extension records results to a JSON file and does nothing unless an output path is set, so it can stay registered for every run:

```xml
<extensions>
    <bootstrap class="Esoul\TestEvidence\Extension">
        <parameter name="output" value="build/test-evidence.json"/>
    </bootstrap>
</extensions>
```

Alternatively set the `TEST_EVIDENCE_OUTPUT` environment variable and pass `--extension 'Esoul\TestEvidence\Extension'` to PHPUnit.

## Workflow for bug reports

1. Write the regression test first and watch it fail.
2. Tag it with `#[Group('<ticket prefix><ticket id>')]` and a `#[TestDox]` sentence that describes the expected behaviour.
3. Fix the bug.
4. Run `vendor/bin/test-evidence <group> --baseline=<commit before the fix>`.
5. Check that the tests that prove the fix fail at the baseline with the reported symptom, then paste the Markdown into the ticket. Add a short note on what the tests simulate and what they cannot prove, such as environment configuration.

### Agent skill

The package ships this workflow as an agent skill in `resources/boost/skills/test-evidence/SKILL.md`. Laravel Boost picks it up automatically (`php artisan boost:install` or `boost:update`). Other setups can copy the file into their agent's skills directory.

## Limitations

- The baseline receives only the test files of the reported tests (plus `--copy` paths). A test that needs a helper class added together with the fix errors at the baseline instead of failing on the bug; the report shows that reason.
- The baseline compares application code only. Dependencies come from the current `vendor` directory, unless you use `--vendor=install`.
- Tested on macOS and Linux.

## Development

```bash
composer install
composer test      # PHPUnit
composer phpstan   # PHPStan, level 10, analysed for PHP 8.3
composer cs        # Pint
```

## License

GPL-2.0-or-later
