<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * $wgTemplateStylesExtenderAllowExternalResourcesInCustomProperties (#45) lifts the
 * custom-property rejection a9043c4 added. The unit test covers the sanitizer logic; this
 * covers the wiring -- that PropertySanitizerHook reads the config and feeds it to the
 * sanitizer the real hook chain builds, which the unit test cannot see.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\PropertySanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender
 */
class AllowExternalResourcesConfigTest extends MediaWikiIntegrationTestCase {

	/**
	 * TemplateStyles memoises its factory and sanitizers in private statics it never
	 * invalidates, so an override does nothing until they are dropped. See UrlPolicyConfigTest.
	 */
	private static function resetTemplateStylesCaches(): void {
		$reflection = new ReflectionClass( TemplateStylesHooks::class );
		foreach ( [ 'matcherFactory' => null, 'sanitizers' => [], 'wrappers' => [] ] as $name => $empty ) {
			$reflection->getProperty( $name )->setValue( null, $empty );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		// A self-contained allowlist so the typed-property assertions do not lean on the
		// shipped default: allowed.example passes, everything else is blocked.
		$this->overrideConfigValue( 'TemplateStylesAllowedUrls', [
			'image' => [ '<^https://allowed\.example/>' ],
		] );
		self::resetTemplateStylesCaches();
	}

	protected function tearDown(): void {
		try {
			self::resetTemplateStylesCaches();
		} finally {
			parent::tearDown();
		}
	}

	private function isAccepted( string $declaration ): bool {
		return $this->sanitizes( ".test { $declaration }", explode( ':', $declaration, 2 )[0] );
	}

	private function sanitizes( string $css, string $mustSurvive ): bool {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$output = $sanitizer->sanitize( CSSParser::newFromString( $css )->parseStylesheet() );

		return $sanitizer->getSanitizationErrors() === []
			&& str_contains( (string)$output, trim( $mustSurvive ) );
	}

	private function setFlag( bool $on ): void {
		$this->overrideConfigValue(
			'TemplateStylesExtenderAllowExternalResourcesInCustomProperties', $on
		);
		self::resetTemplateStylesCaches();
	}

	/**
	 * Off by default, so the a9043c4 rejection stands.
	 */
	public function testDefaultRejectsExternalResourcesInCustomProperty(): void {
		$this->setFlag( false );
		$this->assertFalse( $this->isAccepted( '--m: image-set(var(--u) 1x)' ) );
		$this->assertFalse( $this->isAccepted( '--remote: url("https://blocked.example/probe.png")' ) );
	}

	/**
	 * On, the rejection is lifted for custom properties through the whole hook chain.
	 */
	public function testEnabledLiftsCustomPropertyRejectionEndToEnd(): void {
		$this->setFlag( true );

		$this->assertTrue( $this->isAccepted( '--m: image-set(var(--u) 1x)' ),
			'the mask-icon bridge should pass once enabled' );
		$this->assertTrue( $this->isAccepted( '--remote: url("https://blocked.example/probe.png")' ),
			'the rejection is lifted wholesale, so url() in a custom property passes too' );
	}

	/**
	 * The lane the flag actually opens: a blocked URL carried by a custom property and
	 * consumed by a fetching property survives with the flag on, unchecked by the allowlist
	 * -- CSP is the guard. This is the intended behaviour, pinned so it is not mistaken for
	 * a leak.
	 */
	public function testCustomPropertyUrlReachesAFetchingPropertyWhenEnabled(): void {
		$this->setFlag( true );

		$css = '.a { --x: url("https://blocked.example/evil.png"); mask-image: var(--x) }';
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$out = (string)$sanitizer->sanitize( CSSParser::newFromString( $css )->parseStylesheet() );

		$this->assertSame( [], $sanitizer->getSanitizationErrors() );
		$this->assertStringContainsString( 'blocked.example/evil.png', $out,
			'with the flag on the blocked URL survives in the custom property' );
		$this->assertStringContainsString( 'mask-image:var(--x)', str_replace( ' ', '', $out ) );
	}

	/**
	 * A URL written directly in a typed property is unaffected: it stays bound by
	 * $wgTemplateStylesAllowedUrls whatever the flag holds.
	 */
	public function testLiteralTypedPropertyUrlStaysAllowlistBound(): void {
		$this->setFlag( true );

		$this->assertFalse( $this->isAccepted( 'background-image: url("https://blocked.example/probe.png")' ),
			'a blocked host in a literal typed url stays refused' );
		$this->assertTrue( $this->isAccepted( 'background-image: url("https://allowed.example/image.png")' ),
			'an allowed host in a literal typed url is accepted -- the allowlist is active' );
	}
}
