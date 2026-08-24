<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * TemplateStyles applies `$wgTemplateStylesDisallowedProperties` and
 * `$wgTemplateStylesDisallowedAtRules` before it fires the hooks this extension implements,
 * so both narrowings arrive already applied and must survive being extended.
 *
 * They did not. The property hook replaced the sanitizer object wholesale and the stylesheet
 * hook re-added `@font-face` unconditionally, so a wiki that had disallowed either got it
 * back by installing this extension -- silently, and in the direction that widens.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\PropertySanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\StylesheetSanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender
 */
class DisallowedListsConfigTest extends MediaWikiIntegrationTestCase {

	/**
	 * TemplateStyles memoises its matcher factory and sanitizers in private statics and
	 * never invalidates them, so an override does nothing until they are dropped. See
	 * UrlPolicyConfigTest, which documents the same dance and why process isolation is
	 * not an option here.
	 */
	private static function resetTemplateStylesCaches(): void {
		$reflection = new ReflectionClass( TemplateStylesHooks::class );
		foreach ( [ 'matcherFactory' => null, 'sanitizers' => [], 'wrappers' => [] ] as $name => $empty ) {
			$reflection->getProperty( $name )->setValue( null, $empty );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		self::resetTemplateStylesCaches();
	}

	protected function tearDown(): void {
		try {
			self::resetTemplateStylesCaches();
		} finally {
			parent::tearDown();
		}
	}

	/** @param string $css A complete rule or at-rule */
	private function survives( string $css, string $mustSurvive ): bool {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();

		$output = $sanitizer->sanitize( CSSParser::newFromString( $css )->parseStylesheet() );

		return $sanitizer->getSanitizationErrors() === []
			&& str_contains( (string)$output, $mustSurvive );
	}

	/**
	 * A property css-sanitizer supplies. Nothing about it is this extension's, so if the
	 * disallowed list is being dropped it shows here first.
	 */
	public function testDisallowedUpstreamPropertyStaysDisallowed(): void {
		$this->assertTrue(
			$this->survives( '.a { float: left }', 'float' ),
			'float must be allowed when nothing disallows it, or this test proves nothing'
		);

		$this->overrideConfigValue( 'TemplateStylesDisallowedProperties', [ 'float' ] );
		self::resetTemplateStylesCaches();

		$this->assertFalse(
			$this->survives( '.a { float: left }', 'float' ),
			'$wgTemplateStylesDisallowedProperties must still be honoured'
		);
	}

	/**
	 * A property this extension adds. The list has to reach the widened set too, or an
	 * operator can disallow only what they already had.
	 */
	public function testDisallowedAddedPropertyStaysDisallowed(): void {
		$this->assertTrue(
			$this->survives( '.a { pointer-events: none }', 'pointer-events' ),
			'pointer-events is added by this extension and must be allowed by default'
		);

		$this->overrideConfigValue( 'TemplateStylesDisallowedProperties', [ 'pointer-events' ] );
		self::resetTemplateStylesCaches();

		$this->assertFalse(
			$this->survives( '.a { pointer-events: none }', 'pointer-events' ),
			'a property this extension adds must be disallowable like any other'
		);
	}

	/**
	 * `@font-face` is the one at-rule this extension replaces, and replacing it is what
	 * put it back after TemplateStyles had removed it.
	 */
	public function testDisallowedFontFaceStaysDisallowed(): void {
		$css = "@font-face { font-family: 'TemplateStylesX'; src: local(Foo) }";

		$this->assertTrue(
			$this->survives( $css, 'font-family' ),
			'@font-face must be allowed when nothing disallows it, or this test proves nothing'
		);

		$this->overrideConfigValue( 'TemplateStylesDisallowedAtRules', [ '@font-face' ] );
		self::resetTemplateStylesCaches();

		$this->assertFalse(
			$this->survives( $css, 'font-family' ),
			'$wgTemplateStylesDisallowedAtRules must still be honoured for @font-face'
		);
	}

	/**
	 * The nested copy is a separate list, so disallowing an at-rule has to reach inside
	 * `@media` as well -- the same path that made this extension's descriptors miss it.
	 */
	public function testDisallowedFontFaceStaysDisallowedInsideMedia(): void {
		$this->overrideConfigValue( 'TemplateStylesDisallowedAtRules', [ '@font-face' ] );
		self::resetTemplateStylesCaches();

		$this->assertFalse(
			$this->survives(
				"@media screen { @font-face { font-family: 'TemplateStylesX'; src: local(Foo) } }",
				'font-family'
			),
			'@font-face must stay disallowed inside @media too'
		);
	}
}
