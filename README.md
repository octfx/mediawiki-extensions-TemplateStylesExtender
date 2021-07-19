# TemplateStylesExtender
Extends Extension:TemplateStyles by the following new matchers:

* CSS Variables:
  * Example: `color: var( --example-var )`
* `image-rendering`
* `ruby-position`
* `ruby-align`
* `scroll-margin-*`, `scroll-padding-*`
* `pointer-events`

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
