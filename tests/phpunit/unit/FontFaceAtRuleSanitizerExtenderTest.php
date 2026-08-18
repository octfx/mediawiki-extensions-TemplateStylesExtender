<?php

use MediaWiki\Extension\TemplateStyles\TemplateStylesMatcherFactory;
use MediaWiki\Extension\TemplateStylesExtender\FontFaceAtRuleSanitizerExtender;
use Wikimedia\CSS\Parser\Parser;

/**
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\FontFaceAtRuleSanitizerExtender
 */
class FontFaceAtRuleSanitizerExtenderTest extends MediaWikiUnitTestCase {

	private function sanitize( string $css, bool $requireFamilyPrefix ): ?string {
		$sanitizer = new FontFaceAtRuleSanitizerExtender(
			new TemplateStylesMatcherFactory( [ 'font' => [ '<^https://allowed\.example/>' ] ] ),
			$requireFamilyPrefix
		);
		$rule = Parser::newFromString( $css )->parseRule();
		$out = $sanitizer->sanitize( $rule );

		return $out === null ? null : (string)$out;
	}

	/**
	 * The descriptors this extension adds are the point of the class, and must work
	 * whichever way the family-name flag is set.
	 *
	 * @dataProvider provideDescriptors
	 */
	public function testAddedDescriptors( string $descriptor ): void {
		foreach ( [ false, true ] as $requireFamilyPrefix ) {
			$out = $this->sanitize(
				"@font-face { font-family: 'TemplateStylesX'; $descriptor }",
				$requireFamilyPrefix
			);
			$this->assertNotNull( $out );
			$this->assertStringContainsString(
				explode( ':', $descriptor, 2 )[0],
				$out,
				"descriptor dropped with requireFamilyPrefix=" . var_export( $requireFamilyPrefix, true )
			);
		}
	}

	public static function provideDescriptors(): array {
		return [
			'ascent-override' => [ 'ascent-override: 100%' ],
			'descent-override' => [ 'descent-override: normal' ],
			'line-gap-override' => [ 'line-gap-override: 100%' ],
			'size-adjust' => [ 'size-adjust: 100%' ],
			'font-display' => [ 'font-display: swap' ],
		];
	}

	/**
	 * With the flag off -- the default, and this extension's long-standing behaviour --
	 * any family name is accepted.
	 *
	 * @dataProvider provideFamilyNames
	 */
	public function testFamilyNamesWithoutPrefixRequirement( string $family ): void {
		$out = $this->sanitize( "@font-face { font-family: $family; src: local('X') }", false );

		$this->assertNotNull( $out );
		$this->assertStringContainsString( 'font-family', $out, "family dropped: $family" );
	}

	/**
	 * With the flag on, only "TemplateStyles"-prefixed names survive, matching
	 * TemplateStyles' own rule. @font-face is not scoped to .mw-parser-output, so an
	 * unprefixed family would otherwise apply to the whole page.
	 *
	 * @dataProvider provideFamilyNames
	 */
	public function testFamilyNamesWithPrefixRequirement( string $family, bool $prefixed ): void {
		$out = $this->sanitize( "@font-face { font-family: $family; src: local('X') }", true );

		$this->assertSame(
			$prefixed,
			$out !== null && str_contains( $out, 'font-family' ),
			"family $family with the prefix requirement enabled"
		);
	}

	public static function provideFamilyNames(): array {
		return [
			'prefixed identifier' => [ 'TemplateStylesX', true ],
			'prefixed string' => [ "'TemplateStylesX'", true ],
			'unprefixed identifier' => [ 'Arial', false ],
			'unprefixed string' => [ "'Arial'", false ],
			'prefix must be at the start' => [ "'MyTemplateStyles'", false ],
		];
	}
}
