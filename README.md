# TemplateStylesExtender
Extends Extension:TemplateStyles by the following new matchers:

* CSS Variables:
  * Example: `color: var( --example-var )`
* `image-rendering`
* `ruby-position`
* `ruby-align`
* `scroll-margin-*`, `scroll-padding-*`
* `pointer-events`
* `aspect-ratio`

## Installation
Download the zip file from the [latest release](https://github.com/octfx/mediawiki-extensions-TemplateStylesExtender/releases/latest) page.

Extract the folder to `extensions/TemplateStylesExtender`.  
Add the following to `LocalSettings.php`:
```
wfLoadExtension( 'TemplateStyles' )
wfLoadExtension('TemplateStylesExtender')
```

## Configuration
`$wgTemplateStylesExtenderEnablePrefersColorScheme`  
Default: `true`  
Enables or disables `@media (prefers-color-scheme)` queries.

`$wgTemplateStylesExtenderEnableCssVars`  
Default: `true`  
Enables or disables css variable support.

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

