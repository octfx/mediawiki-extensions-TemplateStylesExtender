# AGENTS.md

## Overview

TemplateStylesExtender is a MediaWiki extension (see `requires` in `extension.json` for the supported MediaWiki floor, which tracks the current LTS; it also requires [TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles)) that widens the set of CSS that TemplateStyles will accept — custom properties, newer colour syntax, grid features, `image-set()`, and similar.

It does this by subclassing the sanitizer and matcher classes from [css-sanitizer](https://www.mediawiki.org/wiki/Css-sanitizer) and swapping the subclasses in through TemplateStyles' hooks:

| Class | Extends | Purpose |
| --- | --- | --- |
| `MatcherFactoryExtender` | `Wikimedia\CSS\Grammar\MatcherFactory` | Builds the grammar matchers for individual CSS values |
| `StylePropertySanitizerExtender` | `Wikimedia\CSS\Sanitizer\StylePropertySanitizer` | Decides which declarations are allowed |
| `FontFaceAtRuleSanitizerExtender` | `Wikimedia\CSS\Sanitizer\FontFaceAtRuleSanitizer` | Widens `@font-face` |
| `StyleRuleSanitizerExtender` | `Wikimedia\CSS\Sanitizer\StyleRuleSanitizer` | Rebuilds the rule sanitizer over this extension's selector grammar |

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

### The selector grammar is not reachable through the matcher factory

TemplateStyles builds `$matcherFactory->cssSelectorList()` into a `StyleRuleSanitizer`
*before* it fires the stylesheet hook, so a selector override on `MatcherFactoryExtender` is
never consulted -- the factory it belongs to is not the one the selectors came from. The
rule sanitizer has to be replaced instead. `StyleRuleSanitizerExtender` does that, and
copies `prependSelectors` off the original rather than rebuilding it: it carries the wrapper
class the rule gets scoped to, and the hook is not told what that is.

It also reaches two protected properties. A renamed one is worth particular care: assigning
to a property that no longer exists creates a dynamic property, which PHP only deprecates,
so hoisting would silently stop. `OverrideIntegrityTest` asserts both still exist.

### `@media` and `@supports` keep their own copy of the rule-sanitizer list

`StylesheetSanitizerHook` swaps entries in the stylesheet's rule-sanitizer list, but
TemplateStyles hands that list to `@media` and `@supports` before the hook runs, so the
nested copies still point at the objects TemplateStyles built. The symptom is a rule that
sanitizes at the top level and is refused one line deeper -- and a refused selector takes
the whole stylesheet with it. `propagateToNestedAtRules()` pushes the replacements down;
anything added to `$newRules` in future needs to go through it too. This is why `@font-face`
inside `@media` did not get this extension's descriptors for several releases.

### The whole-value matcher answers for every property

`addVarSelector()` installs a matcher TemplateStyles consults for any *known* property whose
own grammar refused the value, and it is not told which property it is on. Two rules keep it
from standing in for the sanitizer altogether:

- It applies only where a `var()` is in the value; `testWideMatcherNeedsAVar()` pins that.
  A corpus case meant to prove the matcher refuses something needs a `var()` in it, or it is
  refused by this gate instead and would pass whatever the list held.
- Every alternative in its value list must consume exactly one component value. A
  variable-length one -- `position()` matches one, two or four -- puts the enclosing
  `Quantifier::plus` back to enumerating every way of splitting a value that fails, which
  `testAFailingValueIsNotEnumerated()` catches. One that can match *nothing* makes
  `Quantifier` throw, on every declaration whose own grammar fails.

The list cannot establish that a declaration is valid -- a custom property may hold any token
stream. It establishes that the value reaches no function beyond the ones already allowed
elsewhere, which is why keywords, strings and dimensions go in whole and an arbitrary
function does not.

### Which css-sanitizer version you get

Three places have an opinion and only one of them decides:

| Where | Declares | Effect |
| --- | --- | --- |
| MediaWiki core `composer.json` | an exact pin, currently the same version on every branch from the LTS to master | **decides** what lands in `../../vendor/` |
| TemplateStyles `composer.json` | a compatibility range (`^6.0.0`) | only binding where `composer.local.json` merges it via composer-merge-plugin |
| This extension | nothing | — |

This extension deliberately declares no css-sanitizer dependency, even though it subclasses those classes directly:

- MediaWiki does not install extension dependencies, so the declaration would be inert in most setups;
- where it *is* merged it becomes a second binding constraint that can block a core upgrade;
- CI runs `composer install` in this directory, so a runtime requirement would install a second copy under `vendor/` while Phan analyses `../../vendor/` — two copies, only one of them checked.

`extension.json` cannot express it either: TemplateStyles' own `version` has been `1.0` for its entire history, so `requires.extensions.TemplateStyles` can never encode a css-sanitizer floor.

The support policy is: this extension targets the css-sanitizer shipped by the **current MediaWiki LTS**, and support for older css-sanitizer versions is dropped once the LTS moves past them — which can happen in a *point* release of the LTS, not only at a major upgrade. When you drop such support, raise `requires.MediaWiki` in `extension.json` to the first point release carrying the new css-sanitizer, or wikis on older point releases take a fatal rather than degrading.

The practical consequence is that **the available parent API is whatever the target MediaWiki branch ships**, and nothing will tell you at install time if that changes. Check what is actually installed — that is what Phan and the tests see, and it may differ from the constraint if the checkout is stale:

```sh
# what is installed -- the authoritative answer
grep -A1 '"name": "wikimedia/css-sanitizer"' ../../vendor/composer/installed.json

# what the branch asks for
grep '"wikimedia/css-sanitizer"' ../../composer.json
grep '"wikimedia/css-sanitizer"' ../../extensions/TemplateStyles/composer.json
```

Because there is no declarable constraint, `method_exists()` guards in the source are the only compatibility mechanism available. Each one straddles a specific version boundary, so say which in a comment. Before adding a new guard, check whether any supported MediaWiki branch still needs it — if none do, it is dead code, and Phan will report the older branch of the guard as an undeclared method.

### The corpus test

`tests/phpunit/integration/CssCorpusTest.php` holds every declaration this extension is
meant to affect, asserted against the sanitizer `TemplateStylesHooks::getSanitizer()`
actually builds.

Build the sanitizer that way in tests, never by hand. A hand-assembled
`MatcherFactoryExtender` does not reproduce production: the order in which
`setVarEnabled()` is called relative to the first matcher use changes what is accepted, and
a hand-made factory never exercises TemplateStyles' URL policy at all. Going through the
hook chain is also the only way to catch an override that is never wired in.

Note that `getSanitizer()` memoises per wrapper class, so repeated calls return the *same*
instance and sanitization errors accumulate — clear them before each measurement or every
check after the first false one looks like a failure.

Corpus cases are split two ways: **accepted** (a failure is a regression) and **not yet
implemented** (a failure means someone implemented it and the case should move). When you
add a matcher, add its declarations in the same commit.

A property that combines values needs a combination in the corpus, and a refusal beside it.
The `contain` cases tested one keyword at a time, which its single-keyword matcher accepted
anyway; a combination on its own would have passed a matcher taking any of those keywords
any number of times, which is just as wrong.

A `var()` case covers `addVarSelector()` only if the property's own grammar refuses the
value. `mathFunction()` puts a bare `var()` in every numeric slot but `resolution()`, so
`width: var(--x)` still passes with `addVarSelector()` deleted; `width: var(--x, 100%)`
does not, because that `var()` has no fallback slot. Check a new case against both states
before trusting it.

### Testing anything that depends on configuration

The corpus deliberately uses URLs the default `$wgTemplateStylesAllowedUrls` permits, so it
asserts nothing about configuration. `tests/phpunit/integration/UrlPolicyConfigTest.php`
handles that case and shows the pattern.

Two things make it awkward, and both are worth knowing before writing a similar test.
TemplateStyles memoises its matcher factory and sanitizers in private statics and never
invalidates them, so `overrideConfigValue()` has no effect until those are reset by
reflection — do it in both `setUp()` and `tearDown()`, or a sanitizer built from your
allowlist leaks into later tests. And PHPUnit's `@runInSeparateProcess`, the obvious
alternative, does not work under MediaWiki's test bootstrap.

Whenever a test resets shared state, check it under `--order-by=random` as well as the
default order; contamination is invisible otherwise.

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
