# CSS supported by TemplateStylesExtender

What [TemplateStylesExtender](../README.md) lets you write beyond what TemplateStyles accepts
on its own. Anything not listed here behaves as TemplateStyles already did, and the
[module index](#module-index) is the complete list of what it adds.

Everything here applies identically inside `@media`, `@supports` and `@keyframes`,
`@font-face` descriptors included. `@page` gets none of it.

Saved, and the page is still wrong? These are the cases nothing reports:

| Symptom | |
| - | - |
| Variables set on `:root` reach nothing | [`:root` never matches](#root-never-matches) |
| A `--*` saved with a typo and the rule does nothing | [Nothing checks what a custom property holds](#nothing-checks-what-a-custom-property-holds) |
| Every `var()` is refused | [Where the wiki has custom properties off](#where-the-wiki-has-custom-properties-off) |
| `:is(html, body) .card` saves and matches nothing | [A leading `:is()` is not hoisted](#a-leading-is-is-not-hoisted) |
| `animation-timeline` stopped working when you added `animation` | [An `animation` shorthand after it resets it](#an-animation-shorthand-after-animation-timeline-resets-it) |

## Custom properties and `var()`

A `var()` may sit beside any keyword, and carry any value list as its fallback, including an
empty one:

```css
border: var(--border, 1px solid red);   /* accepted: a fallback of several values */
flex-flow: var(--direction) wrap;       /* accepted: a keyword beside a var() */
color: var(--x, );                      /* accepted: if --x is unset, color falls back to `unset` */
```

A `url()` in a fallback must still point at a host the wiki allows. No `var()` in a
resolution, anywhere: see [Images and filters](#images-and-filters).

`url()`, `image-set()`, `image()`, `src()` and `attr()` are refused in a `--*` value unless the
wiki has turned them on, and it is off by default. The check is on the function, not the host:

```css
--icon: url(https://…);            /* rejected, allowed host or not */
background-image: var(--icon);
background-image: url(https://…);  /* the same URL, checked normally */
```

### `:root` never matches

TemplateStyles prepends `.mw-parser-output` to every selector, so `:root` becomes
`.mw-parser-output :root`, which matches nothing. Declare the variables on a wrapper instead:

```css
.content-wrap { --padding: 10px }
.content { padding: var(--padding) }
```

### Nothing checks what a custom property holds

`--w: 10 px` saves as readily as `--w: 10px`, and so does `--junk: this is (not) css`. The
mistake is reported nowhere.

The same goes the other way: once a `var()` is anywhere in a value, the rest of that value is
not checked against the property either. `width: var(--w) red` saves, and so does
`border-width: var(--w) 1px 2px 3px 4px 5px`. A typo beside a `var()` is reported nowhere.

Both show up at render time as a declaration that does not apply at all: the element falls
back to the inherited or initial value, not to an earlier declaration.

The latitude is useful in one direction. A value a property's own grammar refuses can live in
a custom property and be substituted in.

### Where the wiki has custom properties off

Custom properties are a wiki setting, on by default. Where they are off, what survives is what
TemplateStyles took already:

```css
width: var(--w);                  /* gone */
transform: translateX(var(--x));  /* gone */
color: rgb(from var(--c) r g b);  /* gone */
color: rgb(var(--r) 0 0);         /* kept */
color: var(--c, red);             /* kept */
```

## Selectors

This extension adds the pseudo-classes listed in the [module index](#module-index) and widens
`:not()` to a list of complex selectors:

```css
.a:not(.b, .c) { }      /* accepted: a list */
.a:not(.b .c) { }       /* accepted: with a combinator */
.a:not(:has(.b)) { }    /* accepted: and another functional pseudo-class */
```

Inside `:is()`, `:where()` and `:has()` the argument grammar is deliberately bounded one
level deep, so the pseudo-classes this extension adds are available there as bare keywords
but not as functions:

```css
.card:has(a:focus-visible) { }  /* accepted */
.card:has(:has(.b)) { }         /* rejected */
.a:is(.b:has(.c)) { }           /* rejected */
.a:where(.b:not(.c, .d)) { }    /* rejected: :not() takes one simple selector here */
```

All four need something between the parentheses.

### A leading `:is()` is not hoisted

TemplateStyles hoists a leading `html` or `body` out of the scope, and recognises it by
element name. A pseudo-class added here is accepted in that position and then scoped, where
it matches nothing:

```css
:is(html, body) .card { }      /* accepted: scoped, matches nothing */
.card:where(html.night *) { }  /* accepted: scoped, and matches */
```

## Colours

### Relative colours

The origin (the value after `from`) accepts a colour word or hex, an absolute colour
function, `light-dark()`, or a `var()` carrying a colour fallback:

```css
color: rgb(from red r g b);                    /* accepted */
color: rgb(from light-dark(red, blue) r g b);  /* accepted */
color: rgb(from var(--c, red) r g b);          /* accepted */
```

A relative colour cannot itself be an origin, directly or inside `light-dark()`, and `calc()`
on a channel is not supported:

```css
color: rgb(from rgb(from red r g b) r g b);           /* rejected */
color: rgb(from color-mix(in srgb, red, blue) r g b); /* rejected */
color: rgb(from light-dark(var(--c), blue) r g b);    /* rejected */
color: lch(from red calc(l + 10) c h);                /* rejected */
color: lch(from red l c h / calc(alpha - 0.1));       /* rejected */
```

`rgba()` and `hsla()` have no relative form; use `rgb()` and `hsl()`.

### `color-mix()`

The interpolation method is optional and defaults to `oklab`, and the colour list takes one
or more:

```css
color: color-mix(in srgb, 25% red, blue);  /* accepted: the percentage may lead */
color: color-mix(red, blue);               /* accepted: method optional, oklab implied */
```

An argument may be any colour this extension accepts, including a relative colour and
`light-dark()`, but not another `color-mix()`:

```css
color: color-mix(in srgb, rgb(from var(--c) r g b), white);       /* accepted */
color: color-mix(in srgb, color-mix(in srgb, red, blue), white);  /* rejected: no nesting */
```

Its interpolation spaces are not the same list `color()` takes: `color-mix()` adds the polar
spaces (`hsl`, `hwb`, `lch`, `oklch`) along with `lab` and `oklab`, and does not accept the
three `rec2100-*` spaces.

## Grid

`subgrid` and `masonry` reach `grid-template-columns` and `grid-template-rows` only:

```css
grid-template-columns: [start] subgrid [end];  /* accepted: one line-name block per side */
grid-template-columns: subgrid [a] [b];        /* rejected: not a line-name list */
grid-template-rows: masonry;                   /* accepted */
grid-template: subgrid / 1fr;                  /* rejected: the shorthands do not take it */
masonry-auto-flow: pack definite-first;        /* accepted: [pack | next] then definite-first */
```

Track sizing takes a bare `var()`, but not one with a fallback:

```css
grid-template-columns: repeat(var(--count), var(--track));               /* accepted */
grid-template-columns: repeat(auto-fit, minmax(var(--min), 1fr));        /* accepted */
grid-template-columns: repeat(auto-fit, minmax(var(--min, 200px), 1fr)); /* rejected */
grid-template-columns: var(--tracks, 1fr);                               /* accepted: one var() is the whole value */
```

Put the default in the custom property, not in the fallback.

## Images and filters

An `image-set()` entry takes a resolution, a `type()`, both in either order, or neither. No
`var()` reaches any of it, not even inside a `calc()`:

```css
background-image: image-set(url(…));                       /* accepted */
background-image: image-set(url(…) 1x);                    /* accepted */
background-image: image-set(url(…) calc(1x * 2));          /* accepted */
background-image: image-set(url(…) 1x type("image/avif")); /* accepted */
background-image: image-set(url(…) calc(1x * var(--s)));   /* rejected: no var() in a resolution */
background-image: image-set(url(…) type(var(--t)));        /* rejected: nor in a type() */
background-image: image-set(url(…) 1x 2x);                 /* rejected: each may appear once */
background-image: image-set(var(--x));                     /* rejected: a var() is not a URL */
background-image: image-set("…a.png" "…b.png");            /* rejected: one URL per entry */
```

`-webkit-image-set()` is not accepted.

`backdrop-filter` takes the same value as `filter`. A `url()` in it must point at an SVG
filter on a host the wiki allows **for SVG**; the hosts allowed for images do not apply, so a
`url()` that works in `background-image` can still be refused here.

## Fonts and `@font-face`

`ascent-override`, `descent-override`, `font-display`, `line-gap-override` and `size-adjust`
are `@font-face` **descriptors**. In a style rule they are refused, not ignored:

```css
.card { font-display: swap; }  /* rejected: a descriptor, not a property */
```

`font-display` takes no `var()`, unlike a property in a style rule.

`font-optical-sizing` and `font-variation-settings` are ordinary properties. The axis tag
must be one of `wght`, `wdth`, `slnt`, `ital`, `opsz` or a quoted string; an unquoted custom
axis is refused.

TemplateStyles requires every family name to start with `TemplateStyles`; this extension
lifts that unless the wiki turns it back on. A family name is global to the rendered page,
skin chrome included, so prefix it with your template's name to avoid colliding with another.

## Scrolling and scrollbars

`animation-timeline` takes the anonymous timelines only, `scroll()` and `view()`. Named ones
(`scroll-timeline`, `view-timeline`, `timeline-scope`) are rejected.

`view()` takes an axis and an inset, not `scroll()`'s scroller keywords, so `view(nearest)`
is refused.

Refused on purpose, not a bug: `animation-range: scroll`, `overscroll-behavior: chain`,
`scrollbar-color: light | dark`.

`scrollbar-color` takes `auto` or exactly two colours (thumb then track), so a single colour
is refused. `scrollbar-width` takes `auto`, `thin` or `none`, not a length. The
`overscroll-behavior` shorthand takes one or two values; each longhand takes exactly one.

### An `animation` shorthand after `animation-timeline` resets it

`animation-timeline` and the `animation-range` properties are reset-only parts of the
`animation` shorthand, so a shorthand written after any of them silently puts it back to its
initial value. Write the shorthand first:

```css
.card {
	animation: reveal linear both;  /* `both` also covers browsers without scroll-driven animations */
	animation-timeline: view();
	animation-range: entry 0% cover 40%;
}
```

## Module index

| Module | Changes |
| - | - |
| [Basic User Interface Module Level 4](https://www.w3.org/TR/css-ui-4/) | Added property: [`pointer-events`](https://developer.mozilla.org/en-US/docs/Web/CSS/pointer-events). Upstream: [T342271](https://phabricator.wikimedia.org/T342271) |
| [Cascading and Inheritance Level 5](https://www.w3.org/TR/css-cascade-5/) | Added value: [`revert-layer`](https://developer.mozilla.org/en-US/docs/Web/CSS/revert-layer). There is no `@layer` to declare a layer with, so it acts as `revert` |
| [Color Module Level 4](https://www.w3.org/TR/css-color-4/) | Added colorspaces to [`color()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/color): `rec2100-pq`, `rec2100-hlg`, `rec2100-linear`; added a `var()` with an `<angle>` fallback in a hue channel. Upstream: [T265675](https://phabricator.wikimedia.org/T265675), [T351500](https://phabricator.wikimedia.org/T351500) |
| [Color Module Level 5](https://www.w3.org/TR/css-color-5/) | Added: [Relative color](https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_colors/Relative_colors) including [`light-dark()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/light-dark) as an origin, [`color-mix()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/color-mix) |
| [Containment Module Level 3](https://www.w3.org/TR/css-contain-3/) | Added properties: [`contain`](https://developer.mozilla.org/en-US/docs/Web/CSS/contain), [`content-visibility`](https://developer.mozilla.org/en-US/docs/Web/CSS/content-visibility) |
| [Custom Properties Level 1](https://www.w3.org/TR/css-variables-1/) | Added: `--*` declarations, and [`var()`](https://developer.mozilla.org/en-US/docs/Web/CSS/var) in properties whose own grammar has no slot for it |
| [Filter Effects Module Level 2](https://drafts.fxtf.org/filter-effects-2) | Added property: [`backdrop-filter`](https://developer.mozilla.org/en-US/docs/Web/CSS/backdrop-filter) |
| [Fonts Module Level 4](https://www.w3.org/TR/css-fonts-4/) | Added properties: [`font-optical-sizing`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-optical-sizing), [`font-variation-settings`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-variation-settings). Added `@font-face` descriptors: [`ascent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/ascent-override), [`descent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/descent-override), [`font-display`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display), [`line-gap-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/line-gap-override) |
| [Fonts Module Level 5](https://www.w3.org/TR/css-fonts-5/) | Added `@font-face` descriptor: [`size-adjust`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/size-adjust) |
| [Grid Layout Module Level 1](https://www.w3.org/TR/css-grid-1/) | Added value: a bare `var()` in track sizing |
| [Grid Layout Module Level 2](https://www.w3.org/TR/css-grid-2/) | Added value: [`subgrid`](https://developer.mozilla.org/en-US/docs/Glossary/Subgrid) |
| [Grid Layout Module Level 3](https://www.w3.org/TR/css-grid-3/) | Added value: `masonry`; added property: `masonry-auto-flow` |
| [Images Module Level 4](https://www.w3.org/TR/css-images-4/) | Added function: [`image-set()`](https://developer.mozilla.org/en-US/docs/Web/CSS/image/image-set) |
| [Masking Module Level 1](https://www.w3.org/TR/css-masking/) | Added property: `-webkit-mask-image` |
| [Overscroll Behavior Module Level 1](https://www.w3.org/TR/css-overscroll-1/) | Added properties: [`overscroll-behavior`](https://developer.mozilla.org/en-US/docs/Web/CSS/overscroll-behavior) and its `-x`, `-y`, `-inline` and `-block` longhands |
| [Scroll-driven Animations Module Level 1](https://www.w3.org/TR/scroll-animations-1/) | Added properties: [`animation-timeline`](https://developer.mozilla.org/en-US/docs/Web/CSS/animation-timeline), [`animation-range`](https://developer.mozilla.org/en-US/docs/Web/CSS/animation-range), `animation-range-start`, `animation-range-end` |
| [Scrollbars Styling Module Level 1](https://www.w3.org/TR/css-scrollbars-1/) | Added properties: [`scrollbar-color`](https://developer.mozilla.org/en-US/docs/Web/CSS/scrollbar-color), [`scrollbar-width`](https://developer.mozilla.org/en-US/docs/Web/CSS/scrollbar-width) |
| [Selectors Level 4](https://www.w3.org/TR/selectors-4/) | Added pseudo-classes: [`:has()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:has), [`:is()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:is), [`:where()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:where), [`:focus-within`](https://developer.mozilla.org/en-US/docs/Web/CSS/:focus-within), [`:focus-visible`](https://developer.mozilla.org/en-US/docs/Web/CSS/:focus-visible), `:any-link`, and the form-state ones (`:read-only`, `:read-write`, `:placeholder-shown`, `:default`, `:required`, `:optional`, `:valid`, `:invalid`, `:in-range`, `:out-of-range`); widened [`:not()`](https://developer.mozilla.org/en-US/docs/Web/CSS/:not) to a list of complex selectors |
