# TemplateStylesExtender

Extends [Extension:TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles) with new selectors and matchers.

## Features

* Declare CSS custom properties/variables
* Use CSS custom properties/variables in most properties
* Implement additional properties and values as listed below

| Module | Changes | Upstream task
| - | - | - |
| [Basic User Interface Module Level 4](https://www.w3.org/TR/css-ui-4/) | Added property: [`pointer-events`](https://developer.mozilla.org/en-US/docs/Web/CSS/pointer-events) | [T342271](https://phabricator.wikimedia.org/T342271)
| [Cascading and Inheritance Level 5](https://www.w3.org/TR/css-cascade-5/) | Added value: [`revert-layer`](https://developer.mozilla.org/en-US/docs/Web/CSS/revert-layer) | - |
| [Color Module Level 4](https://www.w3.org/TR/css-color-4/) | Added colorspaces to [`color()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/color): `rec2100-pq`, `rec2100-hlg`, `rec2100-linear` | [T265675](https://phabricator.wikimedia.org/T265675), [T351500](https://phabricator.wikimedia.org/T351500)
| [Color Module Level 5](https://www.w3.org/TR/css-color-5/) | Added: [Relative color](https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_colors/Relative_colors) | - |
| [Containment Module Level 3](https://www.w3.org/TR/css-contain-3/) | Added properties: [`contain`](https://developer.mozilla.org/en-US/docs/Web/CSS/contain), [`content-visibility`](https://developer.mozilla.org/en-US/docs/Web/CSS/content-visibility) | - |
| [Filter Effects Module Level 2](https://drafts.fxtf.org/filter-effects-2) | Added property: [`backdrop-filter`](https://developer.mozilla.org/en-US/docs/Web/CSS/backdrop-filter) | - |
| [Fonts Module Level 4](https://www.w3.org/TR/css-fonts-4/) | Added properties: [`font-optical-sizing`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-optical-sizing), [`font-variation-settings`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-variation-settings), [`ascent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/ascent-override), [`descent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/descent-override), [`font-display`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display), [`line-gap-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/line-gap-override) | - |
| [Fonts Module Level 5](https://www.w3.org/TR/css-fonts-5/) | Added property: [`size-adjust`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/size-adjust) | - |
| [Grid Layout Module Level 2](https://www.w3.org/TR/css-grid-2/) | Added value: [`subgrid`](https://developer.mozilla.org/en-US/docs/Glossary/Subgrid) | - |
| [Grid Layout Module Level 3](https://www.w3.org/TR/css-grid-3/) | Added value: `masonry`; added property: `masonry-auto-flow` | - |
| [Images Module Level 4](https://www.w3.org/TR/css-images-4/) | Added function: [`image-set()`](https://developer.mozilla.org/en-US/docs/Web/CSS/image/image-set) | - |
| [Masking Module Level 1](https://www.w3.org/TR/css-masking/) | Added property: `-webkit-mask-image` | - |


## Installation
Download the zip file from the [latest release](https://github.com/octfx/mediawiki-extensions-TemplateStylesExtender/releases/latest) page.

Extract the folder to `extensions/TemplateStylesExtender`.  
Add the following to `LocalSettings.php`:
```php
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'TemplateStylesExtender' );
```

## Configuration

| Configuration | Description | Default |
| - | - | - |
| `$wgTemplateStylesExtenderCustomPropertiesDeclaration` | Allow CSS custom properties (CSS variables) to be declared as properties | `true` |
| `$wgTemplateStylesExtenderExtendCustomPropertiesValues` | Allow the CSS custom properties (CSS variables) to be used as values in all properties.[^4] | `true`
| `$wgTemplateStylesExtenderEnableUnscopingSupport` | Allows users with unscope permissions to unscope CSS by setting a `wrapclass` attribute.[^1][^2] | `false` |
| `$wgTemplateStylesExtenderRequireFontFamilyPrefix` | Require `@font-face` family names to start with `TemplateStyles`, as TemplateStyles itself does.[^3] | `false` |
| `$wgTemplateStylesExtenderUnscopingPermission` | Specify a permission group that is allowed to unscope CSS. | `editinterface` |

[^4]: Colour function channels such as `rgb()` are not affected. `css-sanitizer` accepts `var()` there whatever this is set to, and this extension does not restrict what the sanitizer already allows.

[^3]: `@font-face` is not scoped to `.mw-parser-output`, so a family declared on a TemplateStyles page applies to the whole rendered page, including skin chrome. Leaving this off keeps this extension's long-standing behaviour of allowing any family name. Changing it does not invalidate already-rendered pages, which keep their previous CSS until purged.

[^1]: This is potentially expensive, as each templatestyles tag with `wrapclass` set, will attempt to look up the user of the current page revision, and check if this user has the permission to activate CSS un-scoping. <br/> Example: `<templatestyles src="Foo/style.css" wrapclass="mediawiki" />` results in the CSS being scoped to `.mediawiki` instead of `.mw-parser-output`.

[^2]: Including such a call in a page essentially limits editing to users with the `editinterface` right. You can alternatively include a call to a template that includes the styles.

## Notes
### `:root` CSS variables declaration
Currently using `:root` selectors won't work due to template styles prepending `.mw-parser-output`.

One possible fix is to wrap the entire content into a `div` element and adding the declarations to this, e.g.
```css
div#content-wrap {
	--padding: 10px
}

.content {
	padding: var( --padding )
}
```

Wikitext
```html
<div id="content-wrap">
	<div class=".content">
		The WikiText...
	</div>
</div>
```

### Relative colors
The relative colors module is quite extensive, not every feature is currently implemented.


## Development

* css-sanitizer workboard: https://phabricator.wikimedia.org/tag/css-sanitizer
* css-sanitizer repo: https://github.com/wikimedia/css-sanitizer
* TemplateStyles repo: https://github.com/wikimedia/mediawiki-extensions-TemplateStyles

To see which css-sanitizer your MediaWiki actually installed — which is what
determines the available grammar, and what static analysis checks against:

```sh
grep -A1 '"name": "wikimedia/css-sanitizer"' vendor/composer/installed.json
```

### Tests

```sh
composer phpunit
```

`tests/phpunit/integration/CssCorpusTest.php` asserts every CSS declaration this extension
is meant to affect against the sanitizer TemplateStyles actually builds, split two ways:

* **accepted** — what the extension exists to allow; a failure is a regression
* **not yet implemented** — documented gaps, such as relative colours with `calc()` on a
  channel. Implementing one turns its test red, which is the prompt to move the case into
  the accepted set
* **rejected** — values that must stay refused; a failure here is a widening, not a
  regression

`tests/phpunit/integration/NotNarrowerThanUpstreamTest.php` asserts the other direction:
this extension may accept more than `css-sanitizer` does, never less. Several classes here
replace an upstream method wholesale, so an upstream improvement can be shadowed by an
older, narrower copy — which no test of this extension alone would notice.

The corpus uses URLs that the default `$wgTemplateStylesAllowedUrls` permits, and asserts
that assumption rather than leaving it implicit, so a wiki with a customised allowlist gets
one clear failure instead of several that look like regressions.
`tests/phpunit/integration/UrlPolicyConfigTest.php` covers that separately: it sets an
allowlist explicitly and checks that the properties this extension adds honour it rather
than bypassing it or hardcoding a policy of their own.

When you add a matcher, add its declarations to the corpus in the same commit.

## Support policy

TemplateStylesExtender extends the sanitizers from [css-sanitizer](https://www.mediawiki.org/wiki/Css-sanitizer), the library TemplateStyles is built on. That library is pinned by MediaWiki core rather than by this extension, so the CSS grammar available to you is whichever css-sanitizer your MediaWiki ships.

Development tracks the css-sanitizer shipped by the **current MediaWiki long-term support release**. Support for older css-sanitizer versions is dropped once the LTS moves past them, which can happen in a *point* release of the LTS rather than only at a major upgrade — so a wiki that is behind on point releases of an otherwise supported MediaWiki may still be too old.

The authoritative minimum is `requires.MediaWiki` in [`extension.json`](extension.json). MediaWiki enforces it when loading the extension, so it cannot drift out of sync with what the code actually needs.
