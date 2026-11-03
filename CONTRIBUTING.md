# Working on Sapling

| Command | What it does |
| --- | --- |
| `composer test` | Runs the tests through the app, a real `php -S` server, and commands in a subprocess |
| `composer format` | Formats the code with [mago](https://mago.carthage.software), as set in `mago.toml` |
| `composer check` | Checks formatting, runs mago's linter and static analyzer, then the tests |
| `composer mutate` | Runs mutation testing, to find code the tests don't check (needs Xdebug or PCOV) |

Core never calls the global helpers, since an app can replace them.

Tests mirror `src/`, as `tests/Http/RouterTest.php` does for `src/Http/Router.php`, with one `expect()` per line.

The analyzer skips the tests, since it can't follow Pest's closures. `baseline-analyzer.toml` and `baseline-linter.toml` list the findings that have been reviewed, and only new ones are reported. After a fix, regenerate them with `vendor/bin/mago analyze --generate-baseline` and `vendor/bin/mago lint --generate-baseline` and diff them before committing.

Each guide in `docs/` has a table of contents, an introduction, sections named after tasks, and a method reference. Update a guide in the same change as the behaviour it describes. Code for a job Sapling leaves to apps is a short example in the guide it belongs to.
