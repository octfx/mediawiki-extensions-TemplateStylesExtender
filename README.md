# TemplateStylesExtender

Extends [Extension:TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles) with new selectors and matchers.

TemplateStylesExtender is developed based on [css-sanitizer](https://www.mediawiki.org/wiki/Css-sanitizer) 5.5.0, which is being used by MediaWiki 1.43.

## Features

* Declare CSS custom properties/variables
* Use CSS custom properties/variables in most properties
* Implement additional properties and values as listed below

| Module | Changes | Upstream task
| - | - | - |
| [Basic User Interface Module Level 4](https://www.w3.org/TR/css-ui-4/) | Added property: [`pointer-events`](https://developer.mozilla.org/en-US/docs/Web/CSS/pointer-events) | [T342271](https://phabricator.wikimedia.org/T342271)
| [Box Sizing Module Level 4](https://www.w3.org/TR/css-sizing-4/) | Backported for `css-sanitizer` 5.5.0 | [T375344](https://phabricator.wikimedia.org/T375344)
| [Cascading and Inheritance Level 5](https://www.w3.org/TR/css-cascade-5/) | Added value: [`revert-layer`](https://developer.mozilla.org/en-US/docs/Web/CSS/revert-layer) | - |
| [Color Module Level 4](https://www.w3.org/TR/css-color-4/) | Fully implemented | [T265675](https://phabricator.wikimedia.org/T265675) |
| [Color Module Level 5](https://www.w3.org/TR/css-color-5/) | Added: [Relative color](https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_colors/Relative_colors) | - |
| [Containment Module Level 3](https://www.w3.org/TR/css-contain-3/) | Added properties: [`contain`](https://developer.mozilla.org/en-US/docs/Web/CSS/contain), [`content-visibility`](https://developer.mozilla.org/en-US/docs/Web/CSS/content-visibility) | - |
| [Filter Effects Module Level 2](https://drafts.fxtf.org/filter-effects-2) | Added property: [`backdrop-filter`](https://developer.mozilla.org/en-US/docs/Web/CSS/backdrop-filter) | - |
| [Fonts Module Level 4](https://www.w3.org/TR/css-fonts-4/) | Added properties: [`font-optical-sizing`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-optical-sizing), [`font-variation-settings`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-variation-settings), [`ascent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/ascent-override), [`descent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/descent-override), [`font-display`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display), [`line-gap-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/line-gap-override) | - |
| [Fonts Module Level 5](https://www.w3.org/TR/css-fonts-5/) | Added property: [`size-adjust`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/size-adjust) | - |
| [Masking Module Level 1](https://www.w3.org/TR/css-masking/) | Added property: `-webkit-mask-image` | - |
| [Ruby Annotation Layout Module Level 1](https://www.w3.org/TR/css-ruby-1/) | Added properties: [`ruby-align`](https://developer.mozilla.org/en-US/docs/Web/CSS/ruby-align), [`ruby-position`](https://developer.mozilla.org/en-US/docs/Web/CSS/ruby-position) | [T277755](https://phabricator.wikimedia.org/T277755)
| [Scroll Snap Module Level 1](https://www.w3.org/TR/css-scroll-snap-1/) | Added properties: [`scroll-margin`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-margin), [`scroll-padding`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-padding), [`scroll-snap-align`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-snap-align), [`scroll-snap-stop`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-snap-stop), [`scroll-snap-type`](https://developer.mozilla.org/en-US/docs/Web/CSS/scroll-snap-type) | [T271598](https://phabricator.wikimedia.org/T271598)
| [Values and Units Module Level 4](https://www.w3.org/TR/css-values-4/) | Added function: [`clamp()`](https://developer.mozilla.org/en-US/docs/Web/CSS/clamp) | [T394619](https://phabricator.wikimedia.org/T394619) |


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
| `$wgTemplateStylesExtenderExtendCustomPropertiesValues` | Allow the CSS custom properties (CSS variables) to be used as values in all properties | `true`
| `$wgTemplateStylesExtenderEnableUnscopingSupport` | Allows users with unscope permissions to unscope CSS by setting a `wrapclass` attribute.[^1][^2] | `false` |
| `$wgTemplateStylesExtenderUnscopingPermission` | Specify a permission group that is allowed to unscope CSS. | `editinterface` |

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

### Test file
Add `tests.css` in the content root to a TemplateStyle page to validate added matchers.