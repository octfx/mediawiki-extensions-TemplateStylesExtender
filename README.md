# TemplateStylesExtender

Extends [Extension:TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles) with new selectors and matchers.

TemplateStylesExtender is developed based on [css-sanitizer](https://www.mediawiki.org/wiki/Css-sanitizer) 5.5.0, which is being used by MediaWiki 1.43.

* CSS Variables:
  * Example: `color: var( --example-var )`
* `scroll-margin-*`, `scroll-padding-*`
* `pointer-events`
* `aspect-ratio`
* `content-visibility`
* Relative Colors

| Module | Changes | Upstream task
| - | - | - |
| [Cascading and Inheritance Level 5](https://www.w3.org/TR/css-cascade-5/) | Added keyword: [`revert-layer`](https://developer.mozilla.org/en-US/docs/Web/CSS/revert-layer) | - |
| [Containment Module Level 3](https://www.w3.org/TR/css-contain-3/) | Added properties: [`contain`](https://developer.mozilla.org/en-US/docs/Web/CSS/contain) | - |
| [Fonts Module Level 4](https://www.w3.org/TR/css-fonts-4/) | Added properties: [`ascent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/ascent-override), [`descent-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/descent-override), [`font-display`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display), [`line-gap-override`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/line-gap-override) | - |
| [Fonts Module Level 5](https://www.w3.org/TR/css-fonts-5/) | Added property: [`size-adjust`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/size-adjust) | - |
| [Masking Module Level 1](https://www.w3.org/TR/css-masking/) | Added property: `-webkit-mask-image` | - |
| [Ruby Annotation Layout Module Level 1](https://www.w3.org/TR/css-ruby-1/) | Addedd properties: [`ruby-align`](https://developer.mozilla.org/en-US/docs/Web/CSS/ruby-align), [`ruby-position`](https://developer.mozilla.org/en-US/docs/Web/CSS/ruby-position) | [T277755](https://phabricator.wikimedia.org/T277755)
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

## Notes on CSS vars
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

## Notes on relative colors
The relative colors module is quite extensive, not every feature is currently implemented.

## Testfile
`tests.css` in the content root is used to validate added matchers.