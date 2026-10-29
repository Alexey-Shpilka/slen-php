# .github

## Purpose
The CI of `slen/sdk`. It reads no secret: a pull request from a fork runs exactly what a push to `master` runs.

## Key Files
| File | Description |
|------|-------------|
| `workflows/ci.yml` | `php` (8.4, 8.5: phpunit, `composer quality`, the help slice `--check`) and `php-floor` (`php -l` on 8.3 over `src/`, `config/`, `bin/`, `examples/help`, then `php bin/floor.php`) |
| `dependabot.yml` | weekly: the pinned actions, and the ranges of `composer.json` |

## For AI Agents
- **Every action is pinned to a commit**, with its version in a comment; Dependabot moves both. `permissions: contents: read`; every checkout is `persist-credentials: false`.
- **No secret, no other repository.** The checks that need the API's OpenAPI spec (`ContractTest`, `WebhookEventsContractTest`) skip without `SLEN_OPENAPI_SPEC`; they run with the spec where the contract is kept, against this repository's tags and `master`.
- **No `composer.lock`** (a library): each run resolves the newest versions `composer.json` allows, which is what a consumer installing today gets; the weekly run (Monday 03:17 UTC) catches a dependency that broke the suite while nobody pushed.
- **The suite is 8.4 and runs on 8.4 and 8.5.** The floor `composer.json` declares, 8.3, is held twice: `php -l` per file over what ships and the help examples (an example is code a reader copies), and `bin/floor.php`, the package run on its doubles without PHPUnit. A file that does not parse or a check that fails fails the job; there is no list of excused files.
- **`contract/` and the JSON of `examples/help/` are copies**: a change to them comes from the contract's canon with `contract/SOURCE`, never as an edit here.
- **Releases**: a tag `vX.Y.Z` on `master` with its `CHANGELOG.md` line; nothing is published from CI.
