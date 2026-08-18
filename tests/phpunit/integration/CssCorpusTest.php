<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use MediaWikiIntegrationTestCase;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * Every CSS declaration this extension is meant to affect, asserted against the sanitizer
 * TemplateStyles actually builds in production.
 *
 * The sanitizer is obtained from TemplateStylesHooks::getSanitizer() rather than assembled
 * by hand, because assembling it by hand does not reproduce production: the order in which
 * setVarEnabled() is called relative to the first matcher use changes what is accepted, and
 * a hand-built factory never exercises TemplateStyles' URL policy at all. Going through the
 * real hook chain is also the only way to catch an override that is never wired in -- the
 * failure mode that left subgrid and masonry support inert for a whole release series.
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
	}

	private function isAccepted( string $declaration ): bool {
		// getSanitizer() memoises per wrapper class, so the same instance comes back on
		// every call and sanitization errors accumulate across checks. Clear them first.
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();

		$stylesheet = CSSParser::newFromString( ".test { $declaration }" )->parseStylesheet();
		$sanitizer->sanitize( $stylesheet );

		return $sanitizer->getSanitizationErrors() === [];
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
	 * Declarations that must stay rejected because allowing them would let a template
	 * reference an off-wiki resource. A failure here is a security regression.
	 *
	 * @dataProvider provideRejectedByDesign
	 */
	public function testRejectedByDesign( string $declaration ): void {
		$this->assertFalse(
			$this->isAccepted( $declaration ),
			"Expected to be rejected but was accepted: $declaration"
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
		return array_merge(
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

	/** All of these are relative URLs, which TemplateStyles' URL policy blocks. */
	public static function provideRejectedByDesign(): array {
		return array_merge(
			self::cases( 'Filter Effects 2', [
				'backdrop-filter: url("common-filters.svg#filter")',
				'backdrop-filter: url("filters.svg#filter") blur(4px) saturate(150%)',
			] ),
			self::cases( 'Images 4', [
				'background-image: image-set( url("image1.avif") type("image/avif"), ' .
					'url("image2.jpg") type("image/jpeg") )',
				'background-image: image-set("image1.jpg" 1x, "image2.jpg" 2x)',
				'background-image: image-set(url("image1.jpg") 1x, url("image2.jpg") 2x)',
			] )
		);
	}

	/** Relative colours with calc() on a channel are not implemented. */
	public static function provideNotYetImplemented(): array {
		return array_merge(
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
