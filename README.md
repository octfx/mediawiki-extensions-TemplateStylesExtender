# TemplateStylesExtender

Widens the CSS that [Extension:TemplateStyles](https://www.mediawiki.org/wiki/Extension:TemplateStyles)
accepts on a wiki.

**Writing CSS on a wiki that has this installed?**
See [docs/css-support.md](docs/css-support.md) for what you can now write.

## Requirements

- MediaWiki 1.43.9 or later
- Extension:TemplateStyles

## Installation

Download the zip file from the [latest release](https://github.com/octfx/mediawiki-extensions-TemplateStylesExtender/releases/latest) page.

Extract the folder to `extensions/TemplateStylesExtender`, then add to `LocalSettings.php`:
```php
wfLoadExtension( 'TemplateStyles' );
wfLoadExtension( 'TemplateStylesExtender' );
```

The order matters. Both extensions register a handler for the `<templatestyles>` tag and the
later registration replaces the earlier one, so loading this one first leaves `wrapclass` with
no effect and no error.

## Configuration

Scoping and URL policy are unchanged by default: every selector is still scoped to
`.mw-parser-output`, and `url()` is still checked against `$wgTemplateStylesAllowedUrls`. Two
settings below opt out of one each.

| Configuration | Description | Default |
| - | - | - |
| `$wgTemplateStylesExtenderCustomPropertiesDeclaration` | Allow CSS custom properties (`--*`) to be declared. | `true` |
| `$wgTemplateStylesExtenderExtendCustomPropertiesValues` | Allow `var()` in properties whose own grammar has no slot for it. | `true` |
| `$wgTemplateStylesExtenderRequireFontFamilyPrefix` | Require `@font-face` family names to start with `TemplateStyles`. TemplateStyles enforces this itself; installing this extension lifts it until you set this to `true`. `@font-face` is not scoped to `.mw-parser-output`, so a family declared on a TemplateStyles page applies to the whole rendered page, including skin chrome. | `false` |
| `$wgTemplateStylesExtenderAllowExternalResourcesInCustomProperties` | Allow `url()`, `image-set()` and the other external-resource functions inside custom property (`--*`) values. Those URLs are then no longer checked against `$wgTemplateStylesAllowedUrls`; enable it only where an enforced Content-Security-Policy restricts these fetches to trusted hosts. | `false` |
| `$wgTemplateStylesExtenderEnableUnscopingSupport` | Allow CSS to be [unscoped](#unscoping-with-wrapclass) by setting a `wrapclass` attribute. | `false` |
| `$wgTemplateStylesExtenderUnscopingPermission` | The right a user must hold to [unscope](#unscoping-with-wrapclass) CSS. Must name a right that already exists on the wiki; this extension defines none. | `editinterface` |

`$wgTemplateStylesDisallowedProperties` and `$wgTemplateStylesDisallowedAtRules` still apply,
including to the properties this extension adds, so either list can narrow the grammar back
per property or per at-rule.

Nothing here narrows it wholesale; removing the extension is that lever. It needs no database
change, and neither does turning a setting off: pages already rendered keep their CSS until
they are re-parsed, and a stylesheet page that used the widened CSS still renders with the
refused rules dropped. It cannot be saved again until they are removed.

## Unscoping with `wrapclass`

With `$wgTemplateStylesExtenderEnableUnscopingSupport` on, a `wrapclass` attribute scopes the
stylesheet to a class of your choosing instead of `.mw-parser-output`:

```html
<templatestyles src="Foo/style.css" wrapclass="mediawiki" />
```

Three things to weigh before enabling it:

* **The right reaches the whole page.** Any class is accepted, so `wrapclass="mediawiki"`
  scopes a rule to the body class and it can restyle anything on the page. Grant the right as
  you would the right to edit site CSS.
* **It effectively limits editing to holders of that right.** Anyone without it who edits the
  page replaces the unscoped CSS with an error message visible to every reader, on top of the
  normally scoped CSS. Putting the tag in a template rather than an article moves the check to
  the template's last editor.
* **It is potentially expensive.** Each `wrapclass` tag costs a lookup of the last editor of
  the page the tag sits on, plus a rights check.

## Contributing

Issues and pull requests go to
[the repository](https://github.com/octfx/mediawiki-extensions-TemplateStylesExtender).

## License

GPL-2.0-or-later. See [COPYING](COPYING).
