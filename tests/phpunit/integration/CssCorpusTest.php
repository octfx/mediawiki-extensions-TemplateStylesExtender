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
	 * A scroll-driven animation is only useful if its `@keyframes` survive with it, and
	 * those go through a sanitizer of their own that this extension does not replace.
	 */
	public function testScrollDrivenAnimationAndItsKeyframesBothSurvive(): void {
		$css = '@keyframes reveal { from { opacity: 0 } to { opacity: 1 } } '
			. '.card { animation: reveal linear both; animation-timeline: view(); '
			. 'animation-range: entry 0% cover 40% }';

		$this->assertTrue( $this->sanitizes( $css, 'animation-timeline' ) );
		$this->assertTrue( $this->sanitizes( $css, '@keyframes' ) );
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
	 * Values the shipped sanitizer has to keep refusing.
	 *
	 * Every one is a value some draft or older spec offers, so the risk is a contributor
	 * reading a newer document and "restoring" it. None carries a var(), which is what keeps
	 * addVarSelector()'s whole-value matcher out of the way -- with one in, the matcher
	 * answers for any known property and these would pass whatever the grammar held.
	 *
	 * @dataProvider provideRejected
	 */
	public function testRejected( string $declaration ): void {
		$this->assertFalse(
			$this->isAccepted( $declaration ),
			"Expected to be rejected but was accepted: $declaration"
		);
	}

	public static function provideRejected(): array {
		return array_merge(
			// `chain` is in the editor's draft only, and ships in no engine but Blink,
			// where it is still experimental.
			self::cases( 'Overscroll Behavior 1', [
				'overscroll-behavior-x: contain none',
				'overscroll-behavior: auto contain none',
				'overscroll-behavior: chain',
			] ),
			// `light` and `dark` were dropped in favour of letting `auto` follow color-scheme.
			self::cases( 'Scrollbars 1', [
				'scrollbar-color: light dark',
				'scrollbar-color: red',
				'scrollbar-width: 8px',
			] ),
			// `in <dashed-ident>` names an @color-profile, which is in the published
			// draft but ships in no engine and has no at-rule sanitizer here to name --
			// its src is an external ICC fetch with no URL policy over it.
			self::cases( 'Color 5', [
				'color: color-mix(in --swopc, red, blue)',
			] ),
			// The named half of the module is deliberately absent, so a timeline can be
			// referred to only by one of the anonymous functions. `scroll` as a named range
			// is editor's-draft-only and ships nowhere, like `chain` above.
			self::cases( 'Scroll-driven Animations 1', [
				'animation-range: scroll',
				'animation-timeline: --my-timeline',
				'animation-timeline: view(nearest)',
				'scroll-timeline-name: --my-timeline',
				'scroll-timeline: --my-timeline block',
				'timeline-scope: all',
				'view-timeline-name: --my-timeline',
			] )
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
			// color-mix() (#46). Two things about it are newer than the function, which
			// has been interoperable since 2023, and neither is where a reader would look
			// for it: the interpolation method became optional, defaulting to oklab (CSSWG
			// 2025-08-19, shipped Safari 26.2 / Firefox 147 / Chrome 145), and the colour
			// list went from exactly two to one or more (CSSWG 2025-04-01, Firefox 150).
			self::cases( 'Color 5', [
				// #46's own shape, and the same without a var(): the whole-value matcher
				// refuses an arbitrary function, so only this grammar can accept either.
				'background-color: color-mix(in oklch, var(--button-ground) 88%, #000)',
				'background-color: color-mix(in srgb, #206484 88%, #000)',
				'color: color-mix(in srgb, red, blue)',
				// the method omitted, and its comma with it
				'color: color-mix(red, blue)',
				// a hue method follows a polar space, and `hue` closes it
				'color: color-mix(in hsl shorter hue, red, blue)',
				'color: color-mix(in oklch longer hue, red, blue)',
				'color: color-mix(in lch increasing hue, red, blue)',
				'color: color-mix(in hwb decreasing hue, red, blue)',
				// the interpolation list is not color()'s
				'color: color-mix(in lab, red, blue)',
				'color: color-mix(in oklab, red, blue)',
				'color: color-mix(in display-p3-linear, red, blue)',
				'color: color-mix(in xyz-d65, red, blue)',
				// `&&`, so the percentage may lead
				'color: color-mix(in srgb, 25% red, blue)',
				'color: color-mix(in srgb, red 40%, blue 60%)',
				'color: color-mix(in srgb, red calc(50% / 2), blue)',
				// an argument is any colour colorFuncs() makes, one level deep
				'color: color-mix(in srgb, rgb(from #36c r g b), blue)',
				'color: color-mix(in srgb, light-dark(red, blue), white)',
				'color: color-mix(in hsl, currentcolor, blue)',
				'color: color-mix(in srgb, transparent, blue)',
				'color: color-mix(in oklab, var(--brand) 20%, transparent)',
				// `#` is one or more, not two
				'color: color-mix(in srgb, red)',
				'color: color-mix(in srgb, red, green, blue)',
				// <percentage [0,100]> is not enforced, and a browser refuses the whole
				// declaration rather than clamping it: css-values-4 clamps the result of
				// a math function, not a literal. Pinned as the gap it is.
				'color: color-mix(in srgb, red 150%, blue)',
				'color: color-mix(in srgb, red -10%, blue)',
				// the slots colorFuncs() reaches through safeColor() and color()
				'border-color: color-mix(in srgb, red, blue)',
				'background: linear-gradient(color-mix(in srgb, red, blue), white)',
				'color: light-dark(color-mix(in srgb, red, blue), white)',
				'color: var(--c, color-mix(in srgb, red, blue))',
			] ),
			// Regress if mathFunction() is deleted. The whole-value matcher does not reach
			// inside a function, so the first four and the last isolate it whatever that
			// matcher admits -- the gradient through the very same image() instance. Cases
			// five to seven no longer do: `inset`, `opacity` and `/` all joined that list
			// in #71, so they would pass through it with mathFunction() gone.
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
			// The whole-value matcher addVarSelector() installs, reached only once the
			// property's own grammar has refused the value. It is what carries a var() into
			// a keyword slot, and a fallback into a numeric one -- mathFunction()'s var()
			// has no fallback slot.
			self::cases( 'Custom properties, whole value', [
				'border: 1px var(--border-style) black',
				// the fallback is a value list, as the spec has it, and may be empty
				'border: var(--border, 1px solid red)',
				'color: var(--x, )',
				'border: var(--width) var(--style) var(--color)',
				'border-image-source: var(--image)',
				'box-shadow: var(--shadow-sm), var(--shadow-lg)',
				'display: var(--display)',
				'font-family: var(--font-stack)',
				'padding: var(--gutter, 1rem)',
				'text-align: var(--align)',
				'transition: var(--property) var(--duration) var(--easing)',
				'transition-timing-function: var(--easing)',
				'width: var(--w, 100%)',
				'z-index: var(--z, 10)',
			] ),
			// #71: which keywords a property takes is the property's business, and this
			// matcher is not told which property it is on -- so a var() may sit beside any
			// keyword, and carry one as its fallback. The last three are the types the
			// list gained to go with it.
			self::cases( 'Custom properties, whole value', [
				'background-image: var(--image, none)',
				'cursor: var(--cursor, pointer)',
				'display: var(--display, block)',
				'flex-flow: var(--direction) wrap',
				'list-style: var(--type) inside',
				'pointer-events: var(--pe, none)',
				'text-decoration: underline var(--style) red',
				'content: "x" var(--suffix)',
				'font-family: var(--stack), "Some Font", sans-serif',
				'grid-template-columns: var(--tracks, 1fr)',
				'transition-duration: var(--duration, 0.3s)',
			] ),
			// `/` is on the list too, so a var() reaches the slots a `/` separates.
			self::cases( 'Box Sizing 4', [
				'aspect-ratio: 16 / var(--b, 9)',
				'aspect-ratio: calc(16) / var(--b)',
			] ),
			self::cases( 'Custom properties, whole value', [
				'font: var(--weight) 1rem/1.5 sans-serif',
				'grid-area: var(--a) / 2 / 3 / 4',
			] ),
			// None of this extension's own properties takes a var() in a slot of its own
			// either, so one in them arrives at that same matcher.
			self::cases( 'Custom properties, whole value', [
				'-webkit-mask-image: var(--mask)',
				'backdrop-filter: var(--filter)',
				'contain: var(--containment)',
				'content-visibility: var(--visibility)',
				'font-optical-sizing: var(--optical-sizing)',
				'font-variation-settings: var(--variations)',
				'masonry-auto-flow: var(--flow)',
				'pointer-events: var(--pointer-events)',
			] ),
			// Reach rawNumber() through upstream ratio(), which calls it late-bound.
			// Since #71 these no longer isolate it: `/` and `auto` are both on the
			// whole-value matcher's list, so all four pass through that instead with
			// rawNumber()'s var() branch gone. Nothing isolates it any more -- a value
			// ratio() accepts is one that matcher accepts too.
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
				'contain: inline-size layout',
				'contain: layout',
				'contain: layout paint style',
				'contain: none',
				'contain: paint',
				'contain: paint layout',
				'contain: size',
				'contain: size layout',
				'contain: size layout paint style',
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
			self::cases( 'Overscroll Behavior 1', [
				'overscroll-behavior-block: auto',
				'overscroll-behavior-block: contain',
				'overscroll-behavior-inline: contain',
				'overscroll-behavior-inline: none',
				'overscroll-behavior-x: contain',
				'overscroll-behavior-x: none',
				'overscroll-behavior-y: auto',
				'overscroll-behavior-y: contain',
				'overscroll-behavior: auto',
				'overscroll-behavior: contain',
				'overscroll-behavior: contain auto',
				'overscroll-behavior: inherit',
				'overscroll-behavior: initial',
				'overscroll-behavior: none',
				'overscroll-behavior: none contain',
				'overscroll-behavior: revert',
				'overscroll-behavior: revert-layer',
				'overscroll-behavior: unset',
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
			self::cases( 'Scroll-driven Animations 1', [
				'animation-range-end: exit',
				'animation-range-start: entry 25%',
				'animation-range: contain',
				'animation-range: cover 20%',
				'animation-range: entry 10% exit 90%',
				'animation-range: entry-crossing exit-crossing',
				'animation-range: inherit',
				'animation-range: initial',
				'animation-range: normal',
				'animation-range: revert',
				'animation-range: revert-layer',
				'animation-range: unset',
				'animation-timeline: auto',
				'animation-timeline: none',
				'animation-timeline: scroll()',
				'animation-timeline: scroll(nearest block)',
				'animation-timeline: scroll(root)',
				'animation-timeline: view()',
				'animation-timeline: view(auto 10px)',
				'animation-timeline: view(block 20%)',
				'animation-timeline: view(inline)',
			] ),
			self::cases( 'Scrollbars 1', [
				'scrollbar-color: #fff #0645ad',
				'scrollbar-color: auto',
				'scrollbar-color: inherit',
				'scrollbar-color: initial',
				'scrollbar-color: rebeccapurple green',
				'scrollbar-color: revert',
				'scrollbar-color: revert-layer',
				'scrollbar-color: rgb(0 0 0 / 0.5) transparent',
				'scrollbar-color: unset',
				'scrollbar-width: auto',
				'scrollbar-width: inherit',
				'scrollbar-width: initial',
				'scrollbar-width: none',
				'scrollbar-width: revert',
				'scrollbar-width: revert-layer',
				'scrollbar-width: thin',
				'scrollbar-width: unset',
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
	 * The accepted cases pin only that the combinations are taken. They pass just as well
	 * against a matcher taking any of the eight keywords, any number of times, in any
	 * order, which is wrong. These pin the other side.
	 *
	 * @dataProvider provideRejectedContainCombinations
	 */
	public function testContainKeywordsDoNotCombineFreely( string $declaration ): void {
		$this->assertFalse( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideRejectedContainCombinations(): array {
		return [
			// none, strict and content stand alone
			'none beside a feature' => [ 'contain: none layout' ],
			'strict beside a feature' => [ 'contain: strict layout' ],
			'content beside a feature' => [ 'contain: content paint' ],
			// size and inline-size are alternatives to each other, not siblings
			'both sizes' => [ 'contain: size inline-size' ],
			// and each feature appears at most once
			'a repeated feature' => [ 'contain: layout layout' ],
		];
	}

	/**
	 * What color-mix() refuses is where its shape is actually pinned. Every row is a form
	 * some plausible transcription of the production would take, and none carries a var().
	 *
	 * @dataProvider provideRejectedColorMix
	 */
	public function testColorMixShape( string $declaration ): void {
		$this->assertFalse( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideRejectedColorMix(): array {
		return [
			// The comma sits outside the `?` in the production, so a literal reading
			// demands it. Juxtaposition's comma mode elides it with the empty match.
			'a leading comma' => [ 'color: color-mix(, red, blue)' ],
			'the method without its comma' => [ 'color: color-mix(in srgb red, blue)' ],
			'the method last' => [ 'color: color-mix(red, blue, in oklab)' ],

			// <hue-interpolation-method> is two keywords, and follows a polar space only
			'a hue method without `hue`' => [ 'color: color-mix(in hsl shorter, red, blue)' ],
			'`hue` without a hue method' => [ 'color: color-mix(in hsl hue, red, blue)' ],
			'a hue method after a rectangular space' => [
				'color: color-mix(in lab longer hue, red, blue)',
			],
			'a hue method after srgb' => [ 'color: color-mix(in srgb longer hue, red, blue)' ],
			'a hue method after xyz' => [ 'color: color-mix(in xyz shorter hue, red, blue)' ],

			// the interpolation slot is not color()'s keyword list
			'a color() space that is not an interpolation space' => [
				'color: color-mix(in rec2100-pq, red, blue)',
			],
			'an unknown space' => [ 'color: color-mix(in foo, red, blue)' ],

			// `&&` takes both in either order, not either alone
			'a percentage with no colour' => [ 'color: color-mix(in srgb, 50%, 50%)' ],
			'something that is not a colour' => [ 'color: color-mix(in srgb, red, 10px)' ],

			// `#` is comma-separated, and one argument holds one colour
			'two colours in one argument' => [ 'color: color-mix(in srgb, red blue, white)' ],
			'the whole list unseparated' => [ 'color: color-mix(in srgb, red 50% blue 50%)' ],
			'no arguments at all' => [ 'color: color-mix()' ],
			'a method and nothing to mix' => [ 'color: color-mix(in srgb)' ],

			// the argument is a colour, so neither of these reaches a fetch
			'a url where a colour goes' => [
				'color: color-mix(in srgb, url("https://upload.wikimedia.org/wikipedia/commons/a/ab/x.png"), blue)',
			],
			'an attr() where a colour goes' => [ 'color: color-mix(in srgb, attr(data-x), blue)' ],

			// Each `?` in the production is one or none. Quantifier::star in either place
			// leaves every other row here green, so these are the two that pin them.
			'two percentages on one argument' => [
				'color: color-mix(in srgb, red 40% 60%, blue)',
			],
			'two interpolation methods' => [ 'color: color-mix(in srgb in oklab, red, blue)' ],
		];
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
	 * The list of value types the whole-value matcher is built from. The vehicle is one of
	 * this extension's own keyword-only properties, so nothing else can satisfy the slot
	 * and dropping a type turns a row red -- except where a second member covers the same
	 * value. `number()` covers the integer row and `ident()` covers the colour one, so
	 * those two isolate nothing. `ident()` is also the only thing covering the position,
	 * css-wide-keyword and line-style rows, which since #71 is what they isolate.
	 *
	 * @dataProvider provideWideMatcherValueTypes
	 */
	public function testWideMatcherValueTypes( string $declaration, bool $accepted ): void {
		$this->assertSame( $accepted, $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideWideMatcherValueTypes(): array {
		// A URL the default allowlist permits, so the refused rows are refused for their
		// type rather than their host.
		$commons = self::COMMONS;

		return [
			'colour' => [ 'pointer-events: var(--x, red)', true ],
			'hex colour' => [ 'pointer-events: var(--x, #36c)', true ],
			'image' => [ "pointer-events: var(--x, url(\"$commons/i.png\"))", true ],
			'image-set' => [ "pointer-events: var(--x, image-set(\"$commons/i.png\" 1x))", true ],
			'gradient' => [ 'pointer-events: var(--x, linear-gradient(red, blue))', true ],
			'length' => [ 'pointer-events: var(--x, 1px)', true ],
			'integer' => [ 'pointer-events: var(--x, 2)', true ],
			'number' => [ 'pointer-events: var(--x, 2.5)', true ],
			'percentage' => [ 'pointer-events: var(--x, 50%)', true ],
			'angle' => [ 'pointer-events: var(--x, 30deg)', true ],
			'frequency' => [ 'pointer-events: var(--x, 3khz)', true ],
			'resolution' => [ 'pointer-events: var(--x, 2x)', true ],
			'position' => [ 'pointer-events: var(--x, left top)', true ],
			// a function form: the keyword one, `ease-in-out`, is an ident, so it would
			// stay green with cssSingleEasingFunction() dropped from the list
			'easing function' => [ 'pointer-events: var(--x, cubic-bezier(0, 0, 1, 1))', true ],
			'css-wide keyword' => [ 'pointer-events: var(--x, revert-layer)', true ],
			'line style' => [ 'pointer-events: var(--x, solid)', true ],
			'line style, wavy' => [ 'pointer-events: var(--x, wavy)', true ],

			'time' => [ 'pointer-events: var(--x, 1s)', true ],
			'flex' => [ 'pointer-events: var(--x, 1fr)', true ],
			'string' => [ 'pointer-events: var(--x, "s")', true ],
			// `var( --x, <the property's own default> )`, the shape #71 was filed about.
			// Which keywords a property takes is its own business, and this matcher is not
			// told which property it is on, so it takes any of them.
			'a keyword of the property itself' => [ 'pointer-events: var(--x, visible)', true ],
			'none' => [ 'pointer-events: var(--x, none)', true ],
			'auto' => [ 'pointer-events: var(--x, auto)', true ],
			'a line style outside the five' => [ 'pointer-events: var(--x, groove)', true ],
			'a line width' => [ 'pointer-events: var(--x, thin)', true ],

			// A function is not inert, so the list still holds no arbitrary one.
			'attr()' => [ 'pointer-events: var(--x, attr(data-x))', false ],
			'a url the policy refuses' => [
				'pointer-events: var(--x, url("https://evil.example.org/x.png"))',
				false,
			],
		];
	}

	/**
	 * What the whole-value matcher must still refuse. Keywords and strings are inert and
	 * are admitted, but a function is not: the list holds no arbitrary one, and its image()
	 * is this factory's, so $wgTemplateStylesAllowedUrls still applies to whatever reaches
	 * a slot through it. Nor does it admit a block.
	 *
	 * Every row carries a var(), or the matcher is never consulted and the row would pass
	 * whatever the list held.
	 *
	 * @dataProvider provideWideMatcherRefusals
	 */
	public function testWideMatcherStillRefuses( string $declaration ): void {
		$this->assertFalse( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideWideMatcherRefusals(): array {
		// evil.example.org matches no entry of the default allowlist. src() is not what
		// refuses those rows: UrlMatcher takes `src` as it takes `url`, so the same URL on
		// a permitted host is accepted.
		return [
			'a url the policy refuses, beside a var()' => [
				'background: var(--x) url("https://evil.example.org/x.png")',
			],
			'an image-set the policy refuses' => [
				'background: var(--x) image-set("https://evil.example.org/x.png" 1x)',
			],
			'an src() the policy refuses' => [
				'background-image: var(--x) src("https://evil.example.org/x.png")',
			],
			'attr()' => [ 'pointer-events: var(--x) attr(data-x)' ],
			'expression()' => [ 'width: var(--w) expression(alert(1))' ],
			'an unknown function' => [ 'pointer-events: var(--x) notafunction(1px)' ],
			'a block' => [ 'pointer-events: var(--x) [auto]' ],
			'var() with no custom property' => [ 'color: var()' ],
		];
	}

	/**
	 * The matcher goes on the property sanitizer TemplateStyles shares with `@media`,
	 * `@supports` and `@keyframes`. `@font-face` builds its own, and `@page` clones the
	 * shared one before this hook runs, so neither of those gets it.
	 *
	 * @dataProvider provideWideMatcherInAtRules
	 */
	public function testWideMatcherInsideAtRules(
		string $css,
		string $mustSurvive,
		bool $accepted
	): void {
		$this->assertSame( $accepted, $this->sanitizes( $css, $mustSurvive ), $css );
	}

	public static function provideWideMatcherInAtRules(): array {
		return [
			'@media' => [ '@media screen { .a { display: var(--d) } }', 'display', true ],
			'@supports' => [
				'@supports (display: grid) { .a { display: var(--d) } }', 'display', true,
			],
			'@keyframes' => [
				'@keyframes TemplateStylesCorpus { from { display: var(--d) } }', 'display', true,
			],
			'@font-face' => [
				"@font-face { font-family: 'TemplateStylesCorpus'; font-display: var(--d) }",
				'font-display',
				false,
			],
			// The clone costs `@page` every property this extension adds as well, not only
			// this matcher.
			'@page' => [ '@page { color: var(--c, 10px) }', 'color', false ],
		];
	}

	/**
	 * The matcher is not told which property it is on, so nothing here is checked against
	 * one: not the value's type, not how many values there are, not a fallback's type, and
	 * not which allowlist the property's own slot would have used. Tightening any of that
	 * turns these red.
	 *
	 * @dataProvider provideAcceptedRegardlessOfProperty
	 */
	public function testWideMatcherIgnoresTheProperty( string $declaration ): void {
		$this->assertTrue( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideAcceptedRegardlessOfProperty(): array {
		$commons = self::COMMONS;

		return [
			'a colour as a width' => [ 'width: var(--w) red' ],
			'an angle as pointer-events' => [ 'pointer-events: var(--x) 30deg' ],
			'a resolution as a display' => [ 'display: var(--d) 2x' ],
			'a comma alone' => [ 'color: var(--c) ,' ],
			'more values than the property takes' => [
				'border-width: var(--w) 1px 2px 3px 4px 5px',
			],
			// provideRejectedFallbacks() pins the opposite one level down: inside rgb(), a
			// fallback is held to the slot's own type.
			'a length as a colour fallback' => [ 'color: var(--c, 10px)' ],
			// url( 'image' ), where the property's own slot is url( 'svg' ) -- upstream's
			// filter included. A wiki that narrows only the svg allowlist loses it here.
			'an image where the property wants an svg' => [
				"backdrop-filter: var(--f) url(\"$commons/x.png\")",
			],
			// A bare string is a URL only inside image-set(), and this matcher never
			// reaches into a function's arguments -- image-set's own grammar matches them,
			// where the slot is the policy-checked urlstring(). What keeps a var() from
			// becoming a fetch is StylePropertySanitizerExtender::doSanitize() refusing
			// url/src/image-set/attr/image tokens in a `--*` declaration, which is
			// untouched. So these sanitize clean and the browser drops them.
			'a string shaped like a url' => [
				'background-image: var(--x) "https://evil.example.org/x.png"',
			],
			'a string shaped like a relative url' => [
				'list-style-image: var(--i) "/w/images/x.png"',
			],
		];
	}

	/**
	 * What it does check is that a var() is there at all. Every row below is a row of
	 * provideAcceptedRegardlessOfProperty() with the var() taken out, so the property's
	 * own grammar judges it and an editor is told the value is wrong.
	 *
	 * @dataProvider provideRefusedWithoutAVar
	 */
	public function testWideMatcherNeedsAVar( string $declaration ): void {
		$this->assertFalse( $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideRefusedWithoutAVar(): array {
		$commons = self::COMMONS;

		return [
			'a colour as a width' => [ 'width: red' ],
			'an angle as pointer-events' => [ 'pointer-events: 30deg' ],
			'a resolution as a display' => [ 'display: 2x' ],
			'a comma alone' => [ 'color: ,' ],
			'more values than the property takes' => [ 'border-width: 1px 2px 3px 4px 5px' ],
			'an image where the property wants an svg' => [
				"backdrop-filter: url(\"$commons/x.png\")",
			],
		];
	}

	/**
	 * Every alternative in the value list consumes exactly one component value, so a value
	 * that fails has one decomposition. A variable-length one -- position(), which matches
	 * one, two or four -- puts the Quantifier::plus back to enumerating every way of
	 * splitting the value, and this takes seconds rather than milliseconds. The bound is
	 * three orders of magnitude above what it costs today, so it is the shape that turns
	 * this red, not the machine it runs on.
	 */
	public function testAFailingValueIsNotEnumerated(): void {
		$declaration = 'pointer-events: ' . str_repeat( 'left top ', 12 ) . '[x]';

		$started = hrtime( true );
		$accepted = $this->isAccepted( $declaration );
		$elapsedMs = ( hrtime( true ) - $started ) / 1e6;

		$this->assertFalse( $accepted, $declaration );
		$this->assertLessThan( 2000, $elapsedMs, 'the value list has a variable-length alternative' );
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
			// A color-mix() argument is every colour colorFuncs() makes except a
			// color-mix(), which is the matcher being built -- and one is not a relative
			// colour's origin either, since the origin is built from the absolute
			// functions and predates it. The same bound as the two cases above.
			self::cases( 'Color 5', [
				'color: color-mix(in srgb, color-mix(in srgb, red, blue), white)',
				'color: rgb(from color-mix(in srgb, red, blue) r g b)',
				'color: rgb(from var(--c, color-mix(in srgb, red, blue)) r g b)',
				'color: color-mix(in srgb, var(--c, color-mix(in srgb, red, blue)), white)',
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
