# Contributing to onsplan

Thanks for helping out! Bug fixes, new modules, translations and documentation improvements are all welcome. Please read [License and contribution terms](#license-and-contribution-terms) before opening a pull request.

## Getting set up

Follow the [Quick start](README.md#quick-start-local-development) in the README to get a local instance running. Local development uses SQLite and needs no external services.

## Development workflow

1. Fork the repo and create a branch off `main` (`git checkout -b fix/short-description`).
2. Make your change, with tests where it makes sense.
3. Run the checks below and make sure they pass.
4. Open a pull request describing **what** changed and **why**. Keep PRs focused, one topic per PR is easier to review.

## Code style and checks

The same checks run in CI, so run them locally first:

```bash
# PHP formatting (Laravel Pint)
./vendor/bin/pint

# Frontend formatting and linting
npm run format        # Prettier (writes)
npm run lint          # ESLint (fixes)

# Type-check the frontend
npx tsc --noEmit

# Tests (Pest)
php artisan test
```

Conventions:

- **PHP** follows Laravel Pint's ruleset. **Frontend** is TypeScript + React with Prettier and ESLint.
- Match the style of the surrounding code, naming, structure, and comment density.
- Write a comment only to explain *why* something non-obvious is done, not *what* the code does.
- Prefer fixing the root cause over adding defensive patches.

## Tests

Tests use [Pest](https://pestphp.com) and live in `tests/`. Feature tests boot a family with modules enabled (see `tests/Feature/Spending/SpendingTest.php` for the pattern). Run the full suite with `php artisan test`, or a single file by passing its path.

## Building a module

New features usually belong in a module rather than the core. See **[docs/modules.md](docs/modules.md)** for the module system and a step-by-step guide, `modules/important_todos` is a small example and `modules/spending` is a full one.

## Security

- Never commit secrets. `.env` and credential files are git-ignored; configuration comes from environment variables (see [docs/configuration.md](docs/configuration.md)).
- If you find a security issue, please report it privately to the maintainer rather than opening a public issue.

## License and contribution terms

onsplan is source-available under the [PolyForm Noncommercial License 1.0.0](LICENSE).

By opening a pull request or otherwise submitting a contribution, you agree that:

1. You wrote it, or otherwise have the right to submit it.
2. You grant the maintainer (Bogdan Dragomir) a perpetual, worldwide, irrevocable, royalty-free license to use, modify, distribute and sublicense your contribution under any terms, including commercially. This lets the hosted service at onsplan.nl keep running and the project's license change in the future.
3. Your contribution is also available to everyone else under the project's current license.

If you can't agree to this, please open an issue describing the change instead of a pull request.
