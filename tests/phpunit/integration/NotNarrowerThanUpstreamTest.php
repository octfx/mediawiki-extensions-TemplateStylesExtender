<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWiki\Extension\TemplateStyles\TemplateStylesMatcherFactory;
use MediaWikiIntegrationTestCase;
use Wikimedia\CSS\Parser\Parser as CSSParser;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

/**
 * This extension may accept more than css-sanitizer does. It must never accept less.
 *
 * Several of its classes replace an upstream method wholesale rather than extending it, so
 * an upstream improvement can be shadowed by an older, narrower copy. A mutation sweep
 * cannot find that: deleting a too-narrow override makes the suite greener, not redder.
 * This compares the two sanitizers directly instead.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender
 */
class NotNarrowerThanUpstreamTest extends MediaWikiIntegrationTestCase {

	private function acceptedByExtension( string $declaration ): bool {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$sanitizer->sanitize( CSSParser::newFromString( ".test { $declaration }" )->parseStylesheet() );

		return $sanitizer->getSanitizationErrors() === [];
	}

	private function acceptedByUpstream( string $declaration ): bool {
		$sanitizer = new StylePropertySanitizer( new TemplateStylesMatcherFactory(
			$this->getConfVar( 'TemplateStylesAllowedUrls' )
		) );

		return $sanitizer->sanitize(
			CSSParser::newFromString( $declaration )->parseDeclaration()
		) !== null;
	}

	/**
	 * @dataProvider provideDeclarations
	 */
	public function testNotNarrowerThanUpstream( string $declaration ): void {
		if ( !$this->acceptedByUpstream( $declaration ) ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->assertTrue(
			$this->acceptedByExtension( $declaration ),
			"css-sanitizer accepts this and the extension does not, so an override is "
				. "shadowing upstream with something narrower: $declaration"
		);
	}

	/**
	 * Shapes across the slots this extension replaces wholesale. Anything upstream rejects
	 * is skipped, so entries can be added freely without checking upstream first.
	 */
	public static function provideDeclarations(): array {
		$declarations = [
			// colour channels, with and without var() fallbacks
			'color: rgb(255 0 0)',
			'color: rgb(255, 0, 0)',
			'color: rgb(var(--r) 0 0)',
			'color: rgb(var(--r, 0) 0 0)',
			'color: rgb(1, 2, 3, none)',
			'color: rgb(1 2 3 / none)',
			'color: rgba(1, 2, 3, 0.5)',
			'color: hsl(var(--h, 120) 50% 50%)',
			'color: hsl(120 var(--s, 50%) 50%)',
			'color: hwb(120 10% 20% / var(--a, 0.5))',
			'color: lab(var(--l, 50%) 40 59.5)',
			'color: lch(50% 70 var(--h, 40))',
			'color: oklab(0.5 0.1 var(--b, 0.1))',
			'color: oklch(0.5 0.1 var(--h, 40))',
			'color: color(display-p3 1 0 0)',
			'color: color(xyz 1 0 0)',
			'color: color(srgb var(--r, 0) 0 0)',
			'color: #aabbccdd',
			'color: var(--c, red)',
			'color: light-dark(red, blue)',
			'color: light-dark(rgb(1 2 3), #aabbcc)',

			// grid, where grid-template-* are replaced wholesale
			'grid-template-columns: repeat(auto-fit, minmax(100px, 1fr))',
			'grid-template-columns: [full-start] minmax(1em, 1fr) [main-start] 1fr',
			'grid-template-columns: fit-content(40%)',
			'grid-template-rows: calc(100% - 10px)',
			'grid-template-columns: repeat(3, 1fr)',

			// masking and images
			'mask-image: linear-gradient(black, transparent)',
			'background-image: linear-gradient(red var(--s, 0%), blue)',

			// css-wide keywords
			'color: inherit',
			'color: revert',

			// math functions in dimension slots
			'width: clamp(1px, 2px, 3px)',
			'width: min(1px, 2px)',
			'width: calc(100% - 10px)',
			'aspect-ratio: 16 / 9',
		];

		return array_combine( $declarations, array_map( static fn ( $d ) => [ $d ], $declarations ) );
	}
}
