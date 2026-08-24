# Changelog

## 3.0.0

> ⚠️ **Requires MediaWiki 1.43.9 or newer.** Support for css-sanitizer 5.5.0 is dropped, and
> 1.43.9 is the first 1.43 point release carrying css-sanitizer 6.2.1. On anything older the
> extension refuses to load.

> ⚠️ **`$wgTemplateStylesDisallowedProperties` and `@font-face` in
> `$wgTemplateStylesDisallowedAtRules` take effect again.** Installing this extension had been
> discarding both. If your wiki sets either, review them before upgrading: template CSS using
> what those lists block stops saving, a disallowed property is dropped from the rules already
> rendered, and a disallowed `@font-face` is dropped whole. Pages that fail to re-parse land in
> the `templatestyles-stylesheet-error-category` tracking category.

### Breaking changes

- Requires MediaWiki 1.43.9 or newer, up from 1.43.0.
- `$wgTemplateStylesDisallowedProperties` and `@font-face` in
  `$wgTemplateStylesDisallowedAtRules` apply again, as described above. Disallowing
  `mask-image` also disallows `-webkit-mask-image`.
- `$wgTemplateStylesAllowedUrls` applies to the properties this extension adds, so a URL
  outside the allowlist stops saving (by @eugenedt).
- `url()`, `image()`, `image-set()`, `-webkit-image-set()`, `src()` and `attr()` are refused
  inside custom property values, as are bare URL tokens (by @eugenedt).
  `$wgTemplateStylesExtenderAllowExternalResourcesInCustomProperties` allows them again.
- A value carrying no `var()` is judged by the property's own grammar again. Values that
  saved before because a `var()` elsewhere in the stylesheet stood in for them are now
  reported as errors.

### Additions

- Selectors Level 4 pseudo-classes: [`:has()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:has),
  [`:is()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:is),
  [`:where()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:where),
  [`:focus-visible`](https://developer.mozilla.org/en-US/docs/Web/CSS/:focus-visible),
  `:focus-within`, `:any-link` and the form-state ones. `:not()` takes a list of complex
  selectors. Inside `:is()`, `:where()` and `:has()` the added pseudo-classes are available as
  bare keywords, not as functions.
- This extension's selectors and `@font-face` descriptors work inside `@media` and `@supports`.
- [`color-mix()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/color-mix), with
  an optional interpolation method and one or more colours.
- Scroll-driven animations:
  [`animation-timeline`](https://developer.mozilla.org/en-US/docs/Web/CSS/animation-timeline)
  with `scroll()` and `view()`, plus
  [`animation-range`](https://developer.mozilla.org/en-US/docs/Web/CSS/animation-range) and its
  longhands. Named timelines stay refused.
- [`overscroll-behavior`](https://developer.mozilla.org/en-US/docs/Web/CSS/overscroll-behavior)
  and its longhands,
  [`scrollbar-width`](https://developer.mozilla.org/en-US/docs/Web/CSS/scrollbar-width) and
  [`scrollbar-color`](https://developer.mozilla.org/en-US/docs/Web/CSS/scrollbar-color).
- [`light-dark()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/light-dark) with
  a `var()` in either argument, on `border-color`, and as a
  [relative colour](https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_colors/Relative_colors)
  origin.
- The CSS Images 4 shape of
  [`image-set()`](https://developer.mozilla.org/en-US/docs/Web/CSS/image/image-set): a
  resolution, a `type()`, both in either order, or neither.
- `$wgTemplateStylesExtenderRequireFontFamilyPrefix` (default `false`): require `@font-face`
  family names to start with `TemplateStyles`, as plain TemplateStyles does.
- `$wgTemplateStylesExtenderAllowExternalResourcesInCustomProperties` (default `false`): allow
  external-resource functions in custom property values. Enable it only on a wiki whose
  enforced Content-Security-Policy restricts those fetches to trusted hosts.

### Fixes

- `subgrid`, `masonry`, `masonry-auto-flow` and `var()` in grid track lists work. The 2.2.0
  code that added them was never reached.
- `var()` works inside colour functions: `hsl(from var(--c) h s l)`, `rgb(var(--r) 0 0)`.
- A `var()` fallback may be a keyword or a list of values: `pointer-events: var(--pe, none)`,
  `border: var(--border, 1px solid red)`, `color: var(--x, )`.
- A `var()` fallback is accepted in a colour channel, `rgb(var(--r, 0) 0 0)`, and in a
  relative colour's origin, `rgb(from var(--c, red) r g b)`.
- [`contain`](https://developer.mozilla.org/en-US/docs/Web/CSS/contain) accepts combinations of
  its keywords: `contain: size layout`.
- `image-set()` accepts a math function in the density slot: `calc(1x * 2)`.
- An invalid declaration next to a custom property is reported instead of vanishing, so
  `.a { color: notacolor; --x: 1px }` no longer saves.
- With `$wgTemplateStylesExtenderEnableUnscopingSupport` on, a page whose last editor lacks the
  unscoping right shows the permission message instead of a fatal error.

### Other changes

- The CSS reference moved from `README.md` to `docs/css-support.md`, which ships in the release
  archive.

## 2.2.0

### Additions

- CSS Grid Module Levels [2](https://www.w3.org/TR/css-grid-2/) and
  [3](https://www.w3.org/TR/css-grid-3/) (by @Gravy59).
- Partial `min()` and `max()` (by @hsl0).

### Fixes

- Support css-sanitizer 6.0.0 (by @xtexx).

## 2.1.0

### Additions

- Full [Color Module Level 4](https://www.w3.org/TR/css-color-4/).
- [`image-set()`](https://developer.mozilla.org/en-US/docs/Web/CSS/image/image-set) (by @tesinormed).
- Improved [relative colour](https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_colors/Relative_colors) syntax.

### Fixes

- Use namespaced `GlobalVarConfig` (by @SomeMWDev).

## 2.0.0

> ⚠️ Not correctly tagged for Composer. Install 2.1.0 instead.

### Breaking changes

- Requires MediaWiki 1.43 or newer.
- `$wgTemplateStylesExtenderEnableCssVars` is replaced by
  `$wgTemplateStylesExtenderCustomPropertiesDeclaration` and
  `$wgTemplateStylesExtenderExtendCustomPropertiesValues`.

### Additions

- [`contain`](https://developer.mozilla.org/en-US/docs/Web/CSS/contain).
- [`scroll-snap-align`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-snap-align),
  [`scroll-snap-stop`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-snap-stop) and
  [`scroll-snap-type`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-snap-type).
- An error message when a user is not allowed to unscope.

### Removals

- Methods css-sanitizer 5.5.0 already provides.
