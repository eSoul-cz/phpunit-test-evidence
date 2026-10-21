# Test evidence

This project produces test evidence reports with `esoul-cz/phpunit-test-evidence`. When you fix a bug or implement a feature from a ticket:

- Tag the tests that prove the requested behaviour with the ticket's group: `#[Group('<ticket-prefix><ticket id>')]`, with the prefix from `extra.test-evidence.ticket-prefix` in `composer.json`. These are usually the feature tests that exercise the behaviour end to end, including existing tests that already prove part of it. Put the attribute on the class when the whole class is about the ticket, otherwise on each test method.
- Give every tagged test a `#[TestDox('…')]` sentence that states the observable behaviour for a non-developer reader; `$parameter` placeholders are filled from the data set.
- If the ticket ID is not known, ask for it before tagging.
- When the work is done and the tagged tests pass, ask the user whether a test evidence report should be generated. Do not generate one unasked. If the answer is yes, activate the `test-evidence` skill.
