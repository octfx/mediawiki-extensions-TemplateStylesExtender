# AGENTS.md

## Overview

TemplateStylesExtender is a MediaWiki extension (requires MW 1.43.9+ and [TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles)) that widens the set of CSS that TemplateStyles will accept — custom properties, newer colour syntax, grid features, `image-set()`, and similar.

It does this by subclassing the sanitizer and matcher classes from [css-sanitizer](https://www.mediawiki.org/wiki/Css-sanitizer) and swapping the subclasses in through TemplateStyles' hooks:

| Class | Extends | Purpose |
| --- | --- | --- |
| `MatcherFactoryExtender` | `Wikimedia\CSS\Grammar\MatcherFactory` | Builds the grammar matchers for individual CSS values |
| `StylePropertySanitizerExtender` | `Wikimedia\CSS\Sanitizer\StylePropertySanitizer` | Decides which declarations are allowed |
| `FontFaceAtRuleSanitizerExtender` | `Wikimedia\CSS\Sanitizer\FontFaceAtRuleSanitizer` | Widens `@font-face` |

The swap happens in `includes/Hooks/`: `PropertySanitizerHook` and `StylesheetSanitizerHook` implement TemplateStyles' hook interfaces, and `MainHooks` re-registers the `<templatestyles>` tag to support the `wrapclass` unscoping feature.

There is **no JavaScript and no ResourceLoader stylesheet** in this extension. It is PHP only.

## Verification

Run only what's relevant to the files you changed.

| Files changed | Command |
| --- | --- |
| `*.php` | `composer preflight` (lint, style, Phan, and PHPUnit) |
| `i18n/` | `npm run lint:i18n` |

Auto-fix: `composer fix` (minus-x + phpcbf).

Individual steps, if you need them: `composer test` (parallel-lint + phpcs + minus-x), `composer phan`, `composer phpunit`.

**Always run the relevant checks before committing.** Read the full output — PHPCS warnings must be fixed, not just errors. The command exits 0 even with warnings, so do not treat the exit code alone as a pass.

**Phan is not yet clean.** There are known pre-existing findings that predate CI running Phan at all. Do not add new ones, and do not silence existing ones with a blanket `suppress_issue_types` in `.phan/config.php` — that would blind the job to real regressions. Fix at the call site, or suppress a single line with a comment explaining why.

### Dev environment

This project's standard dev environment is the MediaWiki Docker setup defined in the parent `mediawiki/` directory. The user may be using a different environment. Ask the user for their dev environment URL and how to run commands if not already known.

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/TemplateStylesExtender && composer preflight"
```

### Phan

Phan needs a full MediaWiki installation at `../../` for type resolution, **and it needs TemplateStyles checked out at `../../extensions/TemplateStyles`** — this extension subclasses TemplateStyles' hook interfaces, so without it Phan cannot resolve the parent types. `.phan/config.php` adds that directory.

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/TemplateStylesExtender && composer phan"
```

## Working with css-sanitizer

This is the part of the codebase most likely to break silently. Read this before touching `includes/`.

### Every override must match a method the parent actually declares

The sanitizer classes work by overriding hook methods that the parent's constructor calls. If you override a method the parent does not declare, **nothing fails** — the constructor simply never reaches your code, and the feature you added silently does nothing.

This has already happened once: an override of `cssGrid1()` was renamed to `cssGrid3()`, and subgrid/masonry support was inert for an entire release series without any test or lint noticing.

Before adding or renaming an override, confirm the parent declares it:

```sh
grep -n "function <name>" ../../vendor/wikimedia/css-sanitizer/src/Sanitizer/StylePropertySanitizer.php
```

and confirm something actually calls it (use `-F`; an unescaped `$` is an anchor in a normal grep pattern and will silently match nothing):

```sh
grep -Fn '$this-><name>' ../../vendor/wikimedia/css-sanitizer/src/Sanitizer/StylePropertySanitizer.php
```

The same applies to `MatcherFactory`. Methods documented `@inheritDoc` are overrides and must exist on the parent; anything else is a new method.

### Which css-sanitizer version you get

Three places have an opinion and only one of them decides:

| Where | Declares | Effect |
| --- | --- | --- |
| MediaWiki core `composer.json` | an exact pin (`6.2.1` on every branch from REL1_43 to master) | **decides** what lands in `../../vendor/` |
| TemplateStyles `composer.json` | a compatibility range (`^6.0.0`) | only binding where `composer.local.json` merges it via composer-merge-plugin |
| This extension | nothing | — |

This extension deliberately declares no css-sanitizer dependency, even though it subclasses those classes directly:

- MediaWiki does not install extension dependencies, so the declaration would be inert in most setups;
- where it *is* merged it becomes a second binding constraint that can block a core upgrade;
- CI runs `composer install` in this directory, so a runtime requirement would install a second copy under `vendor/` while Phan analyses `../../vendor/` — two copies, only one of them checked.

`extension.json` cannot express it either: TemplateStyles' own `version` has been `1.0` for its entire history, so `requires.extensions.TemplateStyles` can never encode a css-sanitizer floor.

The practical consequence is that **the available parent API is whatever the target MediaWiki branch ships**, and nothing will tell you at install time if that changes. Check what is actually installed — that is what Phan and the tests see, and it may differ from the constraint if the checkout is stale:

```sh
# what is installed -- the authoritative answer
grep -A1 '"name": "wikimedia/css-sanitizer"' ../../vendor/composer/installed.json

# what the branch asks for
grep '"wikimedia/css-sanitizer"' ../../composer.json
grep '"wikimedia/css-sanitizer"' ../../extensions/TemplateStyles/composer.json
```

Because there is no declarable constraint, `method_exists()` guards in the source are the only compatibility mechanism available. Each one straddles a specific version boundary, so say which in a comment. Before adding a new guard, check whether any supported MediaWiki branch still needs it — if none do, it is dead code, and Phan will report the older branch of the guard as an undeclared method.

### tests.css

`tests.css` at the repository root is a hand-maintained corpus of every construct this extension is supposed to allow. It is **not** run by CI — the README asks a human to paste it into a TemplateStyles page and look at the result.

Treat it as documentation of intent, and keep it updated when adding matchers. Be aware that a declaration listed there is not evidence the feature works.

## Coding conventions

### PHP

- All files start with `declare( strict_types=1 );`
- Use native PHP types (properties, parameters, return values); use PHPDoc only for collection types like `string[]`
- Always use MediaWiki-namespaced imports (`use MediaWiki\Title\Title;`), never legacy shims (`use Title;`)
- Memoise matcher construction by assigning to `$this->cache[__METHOD__]` and then returning it on a separate line — not `return $this->cache[__METHOD__] ??= ...`, which the MediaWiki codesniffer rejects

### extension.json

`extension.json` is the source of truth for how the extension is wired — hooks, config variables, and dependencies are declared there.

- Hook handlers are registered under `HookHandlers` and bound under `Hooks`
- Config variables are prefixed `wgTemplateStylesExtender` and declared under `config`
- The extension declares its dependency on TemplateStyles under `requires.extensions`
- Do not add a `platform.php` requirement — MediaWiki core already enforces the PHP floor for the branch it is installed into

### Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/) (e.g. `fix:`, `feat:`, `refactor:`)
- Use `ci:` or `chore:` for non-user-facing changes (tooling, config, dependencies)
- Keep each commit to one concern and green on its own, so the history stays bisectable

### i18n

- Any user-facing string needs a message key in `i18n/en.json`
- Every key in `en.json` must also have a documentation entry in `i18n/qqq.json` — `npm run lint:i18n` fails otherwise
- Both files need an `@metadata` block

## CI

CI runs via the reusable workflows in [StarCitizenTools/mediawiki-ci-workflows](https://github.com/StarCitizenTools/mediawiki-ci-workflows), called from `.github/workflows/ci.yml`:

| Job | What it runs |
| --- | --- |
| `lint` | `composer test` and `npm run lint:i18n` |
| `analyze-php` | `composer phan` |
| `test-php` | PHPUnit across MediaWiki 1.43 → master |

Jobs are gated on a change-detection step, so editing only `i18n/` will not run the PHP jobs. If you add a new file type that should gate a job, update the `files_yaml` filter in `ci.yml`.

Both PHP jobs pass `extra-extensions: TemplateStyles`, which is what puts TemplateStyles on disk for type resolution and for loading the extension at test time.
