<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use MediaWikiIntegrationTestCase;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * Every CSS declaration this extension is meant to affect, asserted against the sanitizer
 * TemplateStyles builds in production.
 *
 * Always obtain it from TemplateStylesHooks::getSanitizer(), never by hand. A hand-built
 * factory does not reproduce production -- when setVarEnabled() is called relative to the
 * first matcher use changes what is accepted, and TemplateStyles' URL policy is not
 * exercised at all -- and only the real hook chain catches an override that is never
 * wired in.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\PropertySanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\StylesheetSanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender
 */
class CssCorpusTest extends MediaWikiIntegrationTestCase {

	/**
	 * The corpus below was measured with both custom-property options enabled, which are
	 * the extension.json defaults. If a local configuration disables them, a large number
	 * of cases fail for a reason that has nothing to do with the sanitizer -- so state the
	 * assumption once, here, instead of leaving it implicit in 200 failures.
	 */
	public function testCorpusAssumptionsHold(): void {
		$this->assertTrue(
			TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderCustomPropertiesDeclaration' ),
			'corpus assumes $wgTemplateStylesExtenderCustomPropertiesDeclaration is enabled'
		);
		$this->assertTrue(
			TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderExtendCustomPropertiesValues' ),
			'corpus assumes $wgTemplateStylesExtenderExtendCustomPropertiesValues is enabled'
		);

		// Some cases carry upload.wikimedia.org URLs, which only pass under the default
		// allowlist. UrlPolicyConfigTest asserts allowlist behaviour itself.
		$this->assertContains(
			'<^https://upload\.wikimedia\.org/wikipedia/commons/>',
			$this->getConfVar( 'TemplateStylesAllowedUrls' )['image'] ?? [],
			'corpus assumes the shipped default $wgTemplateStylesAllowedUrls'
		);
	}

	private const COMMONS = 'https://upload.wikimedia.org/wikipedia/commons/a/ab';

	private function isAccepted( string $declaration ): bool {
		return $this->sanitizes( ".test { $declaration }", explode( ':', $declaration, 2 )[0] );
	}

	/**
	 * @param string $css A complete rule or at-rule
	 * @param string $mustSurvive Text that has to appear in the sanitized output
	 */
	private function sanitizes( string $css, string $mustSurvive ): bool {
		// getSanitizer() memoises, so the same instance returns every call and errors
		// accumulate. Without clearing, every check after the first failure looks failed.
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();

		$output = $sanitizer->sanitize( CSSParser::newFromString( $css )->parseStylesheet() );

		// No error is not the same as kept: a declaration can be dropped silently.
		// Require both so a case cannot pass vacuously.
		return $sanitizer->getSanitizationErrors() === []
			&& str_contains( (string)$output, trim( $mustSurvive ) );
	}

	/**
	 * A custom property must not wipe the errors recorded for its neighbours.
	 *
	 * Every other case here is a single declaration in its own rule, so an error-clearing
	 * bug is invisible to them: the invalid declaration is dropped from the output but the
	 * editor is told nothing.
	 */
	public function testInvalidNeighbourStillReportsAnError(): void {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$sanitizer->sanitize(
			CSSParser::newFromString( '.a { color: notacolor; --x: 1px }' )->parseStylesheet()
		);

		$this->assertCount( 1, $sanitizer->getSanitizationErrors(),
			'the invalid color should still be reported after the custom property is sanitized' );
	}

	/**
	 * The `@font-face` at-rule cannot go through isAccepted()'s rule wrapper.
	 *
	 * The family name has to start with "TemplateStyles": TemplateStyles namespaces
	 * font families so a template cannot redefine a site font.
	 *
	 * @dataProvider provideFontFaceDescriptors
	 */
	public function testFontFaceDescriptors( string $descriptor, bool $accepted ): void {
		$css = "@font-face { font-family: 'TemplateStylesCorpus'; $descriptor }";
		$survives = $this->sanitizes( $css, explode( ':', $descriptor, 2 )[0] );

		$this->assertSame( $accepted, $survives, $descriptor );
	}

	/**
	 * `@media` holds its own copy of the rule-sanitizer list, taken before the hook that
	 * replaces `@font-face` runs, so this extension's descriptors did not reach inside it.
	 */
	public function testFontFaceDescriptorsInsideMedia(): void {
		$css = "@media screen { @font-face { font-family: 'TemplateStylesCorpus'; ascent-override: 100% } }";

		$this->assertTrue( $this->sanitizes( $css, 'ascent-override' ) );
	}

	public static function provideFontFaceDescriptors(): array {
		return [
			'ascent-override percentage' => [ 'ascent-override: 100%', true ],
			'ascent-override normal' => [ 'ascent-override: normal', true ],
			'descent-override percentage' => [ 'descent-override: 100%', true ],
			'descent-override normal' => [ 'descent-override: normal', true ],
			'line-gap-override percentage' => [ 'line-gap-override: 100%', true ],
			'line-gap-override normal' => [ 'line-gap-override: normal', true ],
			'size-adjust' => [ 'size-adjust: 100%', true ],
			'font-display auto' => [ 'font-display: auto', true ],
			'font-display block' => [ 'font-display: block', true ],
			'font-display swap' => [ 'font-display: swap', true ],
			'font-display fallback' => [ 'font-display: fallback', true ],
			'font-display optional' => [ 'font-display: optional', true ],
			'nonsense override value' => [ 'ascent-override: notavalue', false ],
			'nonsense font-display value' => [ 'font-display: notavalue', false ],
		];
	}

	/**
	 * Declarations the extension exists to allow. A failure here is a regression.
	 *
	 * @dataProvider provideAccepted
	 */
	public function testAccepted( string $declaration ): void {
		$this->assertTrue(
			$this->isAccepted( $declaration ),
			"Expected to be accepted but was rejected: $declaration"
		);
	}

	/**
	 * Declarations the extension does not support yet. These are documented rather than
	 * silently missing, so implementing one turns this test red and prompts moving the
	 * case into provideAccepted() -- see the note on relative colours in README.md.
	 *
	 * @dataProvider provideNotYetImplemented
	 */
	public function testNotYetImplemented( string $declaration ): void {
		$this->assertFalse(
			$this->isAccepted( $declaration ),
			"Now accepted -- move this case to provideAccepted(): $declaration"
		);
	}

	/**
	 * Key each declaration by module so a failure names both, without repeating the
	 * declaration as key and value.
	 *
	 * @param string $module
	 * @param string[] $declarations
	 * @return array<string,array{string}>
	 */
	private static function cases( string $module, array $declarations ): array {
		$cases = [];
		foreach ( $declarations as $declaration ) {
			$cases["$module — $declaration"] = [ $declaration ];
		}

		return $cases;
	}

	public static function provideAccepted(): array {
		// URLs here must pass the default allowlist; see testCorpusAssumptionsHold().
		$commons = self::COMMONS;

		return array_merge(
			// Colour channels take var() with a fallback of the same type, as upstream
			// does. The type restriction is the safety property: a fallback that is not
			// a number/percentage/angle cannot satisfy the slot.
			self::cases( 'Color 4/5', [
				// T36: var() per channel in the legacy comma syntax, the shape reported
				// as "variables in properties not recognized"
				'background-color: rgb(var(--r), var(--g), var(--b))',
				'background-color: rgba(var(--r), var(--g), var(--b), 0.5)',
				'color: rgb(var(--r, 0) 0 0)',
				'color: rgb(var(--r, 0) var(--g, 0) var(--b, 0))',
				// a hue may take an angle fallback: <hue> is <number> | <angle>, so this
				// is spec-correct even though upstream refuses it
				'color: hsl(var(--h, 30deg) 50% 50%)',
				// `none` in the legacy alpha slot, which upstream allows
				'color: rgb(1, 2, 3, none)',
				// the fallback is this factory's matcher, so a bare var() nests
				'color: rgb(var(--r, var(--s)) 0 0)',
				'color: hsl(var(--h, 120) 50% 50%)',
				'color: hsl(120 var(--s, 50%) 50%)',
				'color: lab(var(--l, 50%) 40 59.5)',
				'color: oklch(0.5 0.1 var(--h, 40))',
				'color: rgb(from #36c var(--r, 0) g b)',
			] ),
			// The origin takes a var() with a same-type fallback, as the channels do. Unlike
			// them it needs the option this corpus assumes enabled; colorFuncs() says why.
			self::cases( 'Color 4/5', [
				'color: rgb(from var(--c, red) r g b)',
				'color: rgb(from var(--c, red) r g b / 0.5)',
				'background: hsl(from var(--c, #36c) h s l)',
				'color: color(from var(--c, red) srgb r g b)',
				'color: oklch(from var(--c, oklch(0.5 0.1 40)) l c h)',
				'color: rgb(from var(--c, currentcolor) r g b)',
				// a colour function is a colour, so one with var() channels of its own fits
				'color: rgb(from var(--c, rgb(var(--r) 0 0)) r g b)',
			] ),
			// light-dark() is in upstream's color(); the origin was missing it (#63).
			self::cases( 'Color 4/5', [
				'color: rgb(from light-dark(red, blue) r g b)',
				'color: rgb(from light-dark(red, blue) r g b / 0.5)',
				'color: hsl(from light-dark(red, blue) h s l)',
				'color: hwb(from light-dark(#36c, rgb(1 2 3)) h w b)',
				'color: rgb(from light-dark(rgb(var(--r) 0 0), blue) r g b)',
				// upstream's own, kept so the origin is not the only place it is asserted
				'color: light-dark(red, blue)',
				'color: light-dark(rgb(from red r g b), blue)',
			] ),
			// Regress if mathFunction() is deleted. addVarSelector's fallback reaches
			// neither inside a function nor past a token it does not list, which is what
			// `inset`, `opacity` and the `/` supply in the last three.
			self::cases( 'Custom properties in math slots', [
				'transform: translateX(var(--x))',
				'transform: translate(var(--x), var(--y))',
				'transform: rotate(var(--a))',
				'filter: blur(var(--b))',
				'box-shadow: var(--x) var(--y) 0 red inset',
				'transition: opacity var(--d) ease-in-out',
				'border-radius: var(--r) / 1px',
				'background-image: linear-gradient(red var(--s), blue)',
			] ),
			// Reach rawNumber() through upstream ratio(), which calls it late-bound.
			// `aspect-ratio: var(--r)` is deliberately absent: it passes either way via
			// addVarSelector, so it would not notice rawNumber() being removed.
			self::cases( 'Box Sizing 4', [
				'aspect-ratio: 16 / var(--b)',
				'aspect-ratio: var(--a) / 9',
				'aspect-ratio: var(--a) / var(--b)',
				'aspect-ratio: auto var(--a) / var(--b)',
			] ),
			// var() inside a colour function; needs setVarEnabled() before construction.
			self::cases( 'Color 4/5', [
				'background: hsl(from var(--c) h s l)',
				'background: rgb(var(--r) 0 0)',
			] ),
			self::cases( 'Masking 1', [
				'-webkit-mask-image: none',
				'-webkit-mask-image: inherit',
				'-webkit-mask-image: linear-gradient(black, transparent)',
			] ),
			self::cases( 'Filter Effects 2', [
				"backdrop-filter: url(\"$commons/f.svg#filter\")",
				"backdrop-filter: url(\"$commons/f.svg#filter\") blur(4px) saturate(150%)",
			] ),
			self::cases( 'Images 4', [
				// a math function in the density slot; refused before #62, and worth nothing
				// to refuse, since it evaluates to a number and cannot add an entry. The
				// shape is checked, not the unit -- upstream leaves that to the browser, so
				// calc(1px) reaches here too.
				"background-image: image-set(\"$commons/i1.jpg\" calc(1x * 2))",
				"background-image: image-set(url(\"$commons/i1.jpg\") calc(1dppx * 2))",
				"background-image: image-set(\"$commons/i1.jpg\" 1x, \"$commons/i2.jpg\" 2x)",
				"background-image: image-set(url(\"$commons/i1.jpg\") 1x, url(\"$commons/i2.jpg\") 2x)",
				"background-image: image-set( url(\"$commons/i1.avif\") type(\"image/avif\"), " .
					"url(\"$commons/i2.jpg\") type(\"image/jpeg\") )",
			] ),
			self::cases( 'UI 4', [
				'pointer-events: all',
				'pointer-events: auto',
				'pointer-events: bounding-box',
				'pointer-events: fill',
				'pointer-events: inherit',
				'pointer-events: initial',
				'pointer-events: none',
				'pointer-events: painted',
				'pointer-events: revert',
				'pointer-events: revert-layer',
				'pointer-events: stroke',
				'pointer-events: unset',
				'pointer-events: visible',
				'pointer-events: visibleFill',
				'pointer-events: visiblePainted',
				'pointer-events: visibleStroke',
			] ),
			self::cases( 'Box Sizing 4', [
				'aspect-ratio: 0.5',
				'aspect-ratio: 1',
				'aspect-ratio: 1 / 1',
				'aspect-ratio: 16 / 9',
				'aspect-ratio: 3/4 auto',
				'aspect-ratio: auto 3/4',
				'contain-intrinsic-block-size: 10rem',
				'contain-intrinsic-block-size: auto 300px',
				'contain-intrinsic-block-size: none',
				'contain-intrinsic-height: 10rem',
				'contain-intrinsic-height: auto 300px',
				'contain-intrinsic-height: none',
				'contain-intrinsic-inline-size: 10rem',
				'contain-intrinsic-inline-size: auto 300px',
				'contain-intrinsic-inline-size: none',
				'contain-intrinsic-size: 10rem',
				'contain-intrinsic-size: 300px 10rem',
				'contain-intrinsic-size: auto 300px',
				'contain-intrinsic-size: auto 300px auto 4rem',
				'contain-intrinsic-size: auto none',
				'contain-intrinsic-size: none',
				'contain-intrinsic-size: none none',
				'contain-intrinsic-width: 10rem',
				'contain-intrinsic-width: auto 300px',
				'contain-intrinsic-width: none',
				'min-intrinsic-sizing: legacy',
				'min-intrinsic-sizing: zero-if-extrinsic',
				'min-intrinsic-sizing: zero-if-extrinsic zero-if-scroll',
				'min-intrinsic-sizing: zero-if-scroll',
				'min-intrinsic-sizing: zero-if-scroll zero-if-extrinsic',
			] ),
			self::cases( 'Cascade 5', [
				'font-size: revert-layer',
			] ),
			self::cases( 'Color 4/5', [
				'background: #f09a',
				'background: #ff0099aa',
				'background: color(display-p3 1 0.5 0 / 0.5)',
				'background: color(display-p3 1 0.5 0)',
				'background: color(from green srgb r g b / 0.5)',
				'background: hsl(120 75 25)',
				'background: hsl(120, 75%, 25%)',
				'background: hsl(120deg 75% 25% / 60%)',
				'background: hsl(120deg 75% 25%)',
				'background: hsl(120deg, 75%, 25%, 0.8)',
				'background: hsl(from green h s l / 0.5)',
				'background: hsl(none 75% 25%)',
				'background: hsla(120deg 75% 25% / 60%)',
				'background: hwb(194 0% 0% / 0.5)',
				'background: hwb(194 0% 0%)',
				'background: hwb(from green h w b / 0.5)',
				'background: lab(29.2345% 39.3825 20.0664)',
				'background: lab(52.2345% 40.1645 59.9971 / 0.5)',
				'background: lab(52.2345% 40.1645 59.9971)',
				'background: lab(from green l a b / 0.5)',
				'background: lch(29.2345% 44.2 27)',
				'background: lch(52.2345% 72.2 56.2 / 0.5)',
				'background: lch(52.2345% 72.2 56.2)',
				'background: lch(from green l c h / 0.5)',
				'background: oklab(40.1% 0.1143 0.045)',
				'background: oklab(59.69% 0.1007 0.1191 / 0.5)',
				'background: oklab(59.69% 0.1007 0.1191)',
				'background: oklab(from green l a b / 0.5)',
				'background: oklch(40.1% 0.123 21.57)',
				'background: oklch(59.69% 0.156 49.77 / 0.5)',
				'background: oklch(59.69% 0.156 49.77)',
				'background: oklch(from green l c h / 0.5)',
				'background: rgb(0, 255, 255)',
				'background: rgb(0, 255, 255, 50%)',
				'background: rgb(100% 0% 50%)',
				'background: rgb(255 0 153 / 0.66)',
				'background: rgb(255 0 153 / 66%)',
				'background: rgb(255 255 255 / 50%)',
				'background: rgb(255 255 255)',
				'background: rgb(255, 0, 153)',
				'background: rgb(from green r g b / 0.5)',
				'background: rgba(0 255 255)',
			] ),
			self::cases( 'Containment 3', [
				'contain: content',
				'contain: inline-size',
				'contain: layout',
				'contain: none',
				'contain: paint',
				'contain: size',
				'contain: strict',
				'contain: style',
				'content-visibility: auto',
				'content-visibility: hidden',
				'content-visibility: inherit',
				'content-visibility: initial',
				'content-visibility: revert',
				'content-visibility: revert-layer',
				'content-visibility: unset',
				'content-visibility: visible',
			] ),
			self::cases( 'Custom Properties', [
				'--ts-css-var-shorthand: 1px solid var(--ts-css-var-value)',
				'--ts-css-var-value: #000',
				'background-color: var(--ts-css-var-value, #36c)',
				'border: var(--ts-css-var-shorthand)',
				'color: var(--ts-css-var-value)',
				'grid-template-columns: 1fr var(--right-rail-size)',
				'grid-template-columns: minmax(0, 1fr) var(--right-rail-size)',
				'grid-template-columns: repeat(2, minmax(0, 1fr)) var(--right-rail-size)',
				'grid-template-columns: repeat(var(--column-foo), minmax(0, 1fr))',
			] ),
			self::cases( 'Filter Effects 2', [
				'backdrop-filter: blur(2px)',
				'backdrop-filter: brightness(60%)',
				'backdrop-filter: contrast(40%)',
				'backdrop-filter: drop-shadow(4px 4px 10px blue)',
				'backdrop-filter: grayscale(30%)',
				'backdrop-filter: hue-rotate(120deg)',
				'backdrop-filter: inherit',
				'backdrop-filter: initial',
				'backdrop-filter: invert(70%)',
				'backdrop-filter: none',
				'backdrop-filter: opacity(20%)',
				'backdrop-filter: revert',
				'backdrop-filter: revert-layer',
				'backdrop-filter: saturate(80%)',
				'backdrop-filter: sepia(90%)',
				'backdrop-filter: unset',
			] ),
			self::cases( 'Fonts 4', [
				'font-optical-sizing: auto',
				'font-optical-sizing: inherit',
				'font-optical-sizing: initial',
				'font-optical-sizing: none',
				'font-optical-sizing: revert',
				'font-optical-sizing: revert-layer',
				'font-optical-sizing: unset',
				'font-variation-settings: "xhgt" 0.7',
				'font-variation-settings: inherit',
				'font-variation-settings: initial',
				'font-variation-settings: normal',
				'font-variation-settings: revert',
				'font-variation-settings: revert-layer',
				'font-variation-settings: unset',
			] ),
			self::cases( 'Grid 2', [
				'grid-template-columns: [col-start] subgrid',
				'grid-template-columns: [col-start] subgrid [col-end]',
				'grid-template-columns: subgrid',
				'grid-template-columns: subgrid [col-end]',
				'grid-template-rows: [row-start] subgrid',
				'grid-template-rows: [row-start] subgrid [row-end]',
				'grid-template-rows: subgrid',
				'grid-template-rows: subgrid [row-end]',
			] ),
			self::cases( 'Grid 3', [
				'grid-template-columns: masonry',
				'grid-template-rows: masonry',
				'masonry-auto-flow: next',
				'masonry-auto-flow: next definite-first',
				'masonry-auto-flow: pack',
				'masonry-auto-flow: pack definite-first',
			] ),
			self::cases( 'Images 4', [
				'background-image: image-set( linear-gradient(blue, white) 1x, linear-gradient(blue, green) 2x )',
			] ),
			// Upstream provides these; kept because StylesheetSanitizerHook replaces the
			// whole property map, so a wrongly-built extender would drop them.
			self::cases( 'Ruby 1', [
				'ruby-align: center',
				'ruby-align: inherit',
				'ruby-align: initial',
				'ruby-align: revert',
				'ruby-align: revert-layer',
				'ruby-align: space-around',
				'ruby-align: space-between',
				'ruby-align: start',
				'ruby-align: unset',
				'ruby-position: alternate',
				'ruby-position: alternate over',
				'ruby-position: alternate under',
				'ruby-position: inherit',
				'ruby-position: initial',
				'ruby-position: inter-character',
				'ruby-position: over',
				'ruby-position: revert',
				'ruby-position: revert-layer',
				'ruby-position: under',
				'ruby-position: unset',
			] ),
			// Upstream provides these; kept because StylesheetSanitizerHook replaces the
			// whole property map, so a wrongly-built extender would drop them.
			self::cases( 'Scroll Snap 1', [
				'scroll-margin-block-end: 10px',
				'scroll-margin-block-end: 1em',
				'scroll-margin-block-end: inherit',
				'scroll-margin-block-end: initial',
				'scroll-margin-block-end: revert',
				'scroll-margin-block-end: revert-layer',
				'scroll-margin-block-end: unset',
				'scroll-margin-block: 10px',
				'scroll-margin-block: 1em 0.5em',
				'scroll-margin-block: inherit',
				'scroll-margin-block: initial',
				'scroll-margin-block: revert',
				'scroll-margin-block: revert-layer',
				'scroll-margin-block: unset',
				'scroll-margin: 10px',
				'scroll-margin: 1em 0.5em 1em 1em',
				'scroll-margin: inherit',
				'scroll-margin: initial',
				'scroll-margin: revert',
				'scroll-margin: revert-layer',
				'scroll-margin: unset',
				'scroll-padding-block-end: 10%',
				'scroll-padding-block-end: 10px',
				'scroll-padding-block-end: 1em',
				'scroll-padding-block-end: auto',
				'scroll-padding-block-end: inherit',
				'scroll-padding-block-end: initial',
				'scroll-padding-block-end: revert',
				'scroll-padding-block-end: revert-layer',
				'scroll-padding-block-end: unset',
				'scroll-padding-block: 10%',
				'scroll-padding-block: 10px',
				'scroll-padding-block: 1em 0.5em',
				'scroll-padding-block: auto',
				'scroll-padding-block: inherit',
				'scroll-padding-block: initial',
				'scroll-padding-block: revert',
				'scroll-padding-block: revert-layer',
				'scroll-padding-block: unset',
				'scroll-padding: 10%',
				'scroll-padding: 10px',
				'scroll-padding: 1em 0.5em 1em 1em',
				'scroll-padding: auto',
				'scroll-padding: inherit',
				'scroll-padding: initial',
				'scroll-padding: revert',
				'scroll-padding: revert-layer',
				'scroll-padding: unset',
				'scroll-snap-align: center',
				'scroll-snap-align: center start',
				'scroll-snap-align: end',
				'scroll-snap-align: end center',
				'scroll-snap-align: inherit',
				'scroll-snap-align: initial',
				'scroll-snap-align: none',
				'scroll-snap-align: revert',
				'scroll-snap-align: revert-layer',
				'scroll-snap-align: start',
				'scroll-snap-align: start end',
				'scroll-snap-align: unset',
				'scroll-snap-stop: always',
				'scroll-snap-stop: normal',
				'scroll-snap-type: block',
				'scroll-snap-type: both',
				'scroll-snap-type: both mandatory',
				'scroll-snap-type: inline',
				'scroll-snap-type: none',
				'scroll-snap-type: x',
				'scroll-snap-type: x mandatory',
				'scroll-snap-type: y',
				'scroll-snap-type: y mandatory',
			] ),
			self::cases( 'Values 4', [
				'width: clamp(100px, 100%, 200px)',
				'width: max(100px, 100%, 200px)',
				'width: min(100px, 100%, 200px)',
			] ),
			self::cases( 'Misc', [
				'grid-auto-rows: minmax(3rem, auto)',
				'grid-column: span 2',
				'grid-gap: var(--space-xs)',
				'grid-row: span 3',
				'grid-row: span 4',
				'grid-row: span 8 / auto',
				'grid: auto-flow dense/repeat(auto-fit, minmax(9.375rem, 1fr))',
				'left: calc(var(--var1) / var(--var2) * 100%)',
				'left: calc(var(--var1) / var(--var2))',
				'right: calc((var(--var1) - var(--var2)) / var(--var3) * 100%)',
			] )
		);
	}

	/**
	 * A var() fallback is restricted to its slot's own type, so it cannot reach a value the
	 * slot would otherwise refuse. The origin is the wider slot: it takes colour functions.
	 *
	 * @dataProvider provideRejectedFallbacks
	 */
	public function testFallbacksAreTypeRestricted( string $declaration ): void {
		$this->assertFalse( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideRejectedFallbacks(): array {
		// A URL the default allowlist permits, so these are refused for their type, not
		// their host.
		$commons = self::COMMONS;

		return [
			// numeric slots
			'url fallback' => [ "color: rgb(var(--r, url(\"$commons/x.png\")) 0 0)" ],
			'colour-word fallback in a numeric slot' => [ 'color: rgb(var(--r, red) 0 0)' ],
			'string fallback' => [ 'color: rgb(var(--r, "0") 0 0)' ],

			// the origin: a colour slot, so a different refused set, same rule
			'url fallback in an origin' => [
				"color: rgb(from var(--c, url(\"$commons/x.png\")) r g b)",
			],
			'image-set fallback in an origin' => [
				"color: rgb(from var(--c, image-set(\"$commons/i.png\" 1x)) r g b)",
			],
			'string fallback in an origin' => [ 'color: rgb(from var(--c, "red") r g b)' ],
			'length fallback in an origin' => [ 'color: rgb(from var(--c, 10px) r g b)' ],
			'number fallback in an origin' => [ 'color: rgb(from var(--c, 0) r g b)' ],
			'unknown keyword fallback in an origin' => [ 'color: rgb(from var(--c, notacolor) r g b)' ],
			// one <color>, not a list: a fallback cannot supply the channels as well
			'two-value fallback in an origin' => [ 'color: rgb(from var(--c, red 0) r g b)' ],
		];
	}

	/**
	 * The origin accepts colour functions, so what light-dark() refuses there is worth
	 * pinning rather than assuming.
	 *
	 * @dataProvider provideRejectedLightDarkOrigins
	 */
	public function testLightDarkOriginTakesTwoColours( string $declaration ): void {
		$this->assertFalse( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideRejectedLightDarkOrigins(): array {
		// See provideRejectedFallbacks().
		$commons = self::COMMONS;

		return [
			'url instead of a colour' => [
				"color: rgb(from light-dark(url(\"$commons/x.png\"), blue) r g b)",
			],
			'length instead of a colour' => [ 'color: rgb(from light-dark(red, 10px) r g b)' ],
			'no arguments' => [ 'color: rgb(from light-dark() r g b)' ],
			'one argument' => [ 'color: rgb(from light-dark(red) r g b)' ],
			'three arguments' => [ 'color: rgb(from light-dark(red, blue, green) r g b)' ],
			'no comma' => [ 'color: rgb(from light-dark(red blue) r g b)' ],
		];
	}

	/**
	 * Documented gaps. Several are shapes upstream lacks too, kept so an upstream
	 * improvement turns a test red rather than passing unnoticed.
	 */
	public static function provideNotYetImplemented(): array {
		// URLs here must pass the default allowlist, or a case is refused for its host and
		// says nothing about the gap it is meant to document.
		$commons = self::COMMONS;

		return array_merge(
			// A fallback inside a fallback: the inner var() is admitted, but not with a
			// fallback of its own, since only one level goes through rawOrCustomProp().
			self::cases( 'Color 4/5', [
				'color: rgb(var(--r, var(--s, 0)) 0 0)',
			] ),
			// The origin's fallback is a <color>, and a bare var() is not one. A channel
			// nests one because anything mathFunction() or rawNumber() admit is admitted in
			// its fallback; no colour matcher is built through either. Upstream's color()
			// is the same shape, so this is not a narrowing.
			self::cases( 'Color 4/5', [
				'color: rgb(from var(--c, var(--d)) r g b)',
				// a relative colour is not an origin -- see colorFuncs()
				'color: rgb(from rgb(from red r g b) r g b)',
				'color: rgb(from var(--c, rgb(from red r g b)) r g b)',
				'color: rgb(from light-dark(rgb(from red r g b), blue) r g b)',
			] ),
			// light-dark() admits no var() in its arguments and cannot be a var() fallback,
			// in an origin as at the top level. Both mirror upstream.
			self::cases( 'Color 4/5', [
				'color: light-dark(var(--l), var(--d))',
				'color: rgb(from light-dark(var(--l), var(--d)) r g b)',
				'color: rgb(from var(--c, light-dark(red, blue)) r g b)',
			] ),
			// image-set() per CSS Images 4: the density is optional, and a resolution and a
			// type() may both appear. Predates #62 and unrelated to it.
			self::cases( 'Images 4', [
				"background-image: image-set(\"$commons/i1.jpg\")",
				"background-image: image-set(\"$commons/i1.jpg\" 1x type(\"image/avif\"))",
			] ),
			// rawNumber()'s var() wrapper takes no fallback. Unlike the colour channels,
			// this is not a narrowing -- upstream's ratio() admits no var() at all.
			self::cases( 'Box Sizing 4', [
				'aspect-ratio: 16 / var(--b, 9)',
				// upstream ratio() is built from rawNumber(), which excludes math functions
				'aspect-ratio: calc(16) / var(--b)',
			] ),
			self::cases( 'Color 4/5', [
				'background: color(from #0000FF xyz calc(x + 0.75) y calc(z - 0.35))',
				'background: hsl(from #0000FF h s calc(l + 20))',
				'background: hsl(from rgb(200 0 0) calc(h + 30) s calc(l + 30))',
				'background: hwb(from #0000FF h calc(w + 30) b)',
				'background: hwb(from lch(40% 70 240deg) h w calc(b - 30))',
				'background: lab(from #0000FF calc(l + 10) a b)',
				'background: lab(from hsl(180 100% 50%) calc(l - 10) a b)',
				'background: lch(from #0000FF calc(l + 10) c h)',
				'background: lch(from hsl(180 100% 50%) calc(l - 10) c h)',
				'background: lch(from var(--aColorValue) l c h / calc(alpha - 0.1))',
				'background: oklab(from #0000FF calc(l + 0.1) a b / calc(alpha * 0.9))',
				'background: oklab(from hsl(180 100% 50%) calc(l - 0.1) a b)',
				'background: oklch(from #0000FF calc(l + 0.1) c h)',
				'background: oklch(from hsl(180 100% 50%) calc(l - 0.1) c h)',
				'background: oklch(from var(--aColor) l c h / calc(alpha - 0.1))',
				'background: rgb(from #0000FF calc(r + 40) calc(g + 40) b)',
				'background: rgb(from hwb(120deg 10% 20%) r g calc(b + 200))',
			] )
		);
	}
}
