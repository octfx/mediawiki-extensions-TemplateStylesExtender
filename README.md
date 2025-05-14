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
* `content-visibility`

## Installation
Download the zip file from the [latest release](https://github.com/octfx/mediawiki-extensions-TemplateStylesExtender/releases/latest) page.

Extract the folder to `extensions/TemplateStylesExtender`.  
Add the following to `LocalSettings.php`:
```php
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'TemplateStylesExtender' );
```

## Configuration
`$wgTemplateStylesExtenderEnableCssVars`  
Default: `true`  
Enables or disables css variable support.

`$wgTemplateStylesExtenderEnableUnscopingSupport`  
Default: `false`  
Allows users with `editinterface` permissions to unscope css by setting a `wrapclass` attribute.

**Note**: This is potentially expensive, as each templatestyles tag with `wrapclass` set, will attempt to look up the user of the current page revision, and check if this user has the permission to activate css un-scoping. 

Example:
`<templatestyles src="Foo/style.css" wrapclass="mediawiki" />` results in the css being scoped to `.mediawiki` instead of `.mw-parser-output`.

**Note**: Including such a call in a page essentially limits editing to users with the `editinterface` right. You can alternatively include a call to a template that includes the styles. 

`$wgTemplateStylesExtenderUnscopingPermission`  
Default: `editinterface`  
Specify a permission group that is allowed to unscope css.

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

