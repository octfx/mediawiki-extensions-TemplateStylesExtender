<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * The properties this extension adds must honour the wiki's $wgTemplateStylesAllowedUrls,
 * not bypass it and not hardcode a policy of their own.
 *
 * This has to be an integration test. A unit test can only show that the extension
 * delegates to whatever matcher factory it is handed; it cannot show that the wiki's
 * configured allowlist actually reaches the properties added through the hook chain, which
 * is the property that a wiring mistake would break.
 *
 * The corpus in CssCorpusTest deliberately uses URLs that the default allowlist permits, so
 * it stays silent about configuration. This is where configuration is exercised, with the
 * allowlist set explicitly so the assertions do not depend on the wiki's own settings.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\PropertySanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\StylesheetSanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender
 */
class UrlPolicyConfigTest extends MediaWikiIntegrationTestCase {

	private const ALLOWED = 'https://allowed.example';
	private const BLOCKED = 'https://blocked.example';

	/**
	 * TemplateStyles memoises its matcher factory and sanitizers in private statics and
	 * never invalidates them, so a config override has no effect until they are dropped.
	 *
	 * Reaching into another extension's internals is not ideal, but the alternative --
	 * PHPUnit process isolation -- does not work under MediaWiki's test bootstrap. If
	 * TemplateStyles renames these properties, getProperty() throws and this test fails
	 * loudly rather than silently asserting against a stale sanitizer.
	 */
	private static function resetTemplateStylesCaches(): void {
		$reflection = new ReflectionClass( TemplateStylesHooks::class );
		foreach ( [ 'matcherFactory' => null, 'sanitizers' => [], 'wrappers' => [] ] as $name => $empty ) {
			// No setAccessible() call: it has been a no-op since PHP 8.1, which this
			// extension already requires, and PHP 8.5 deprecates it -- and MediaWiki
			// turns deprecations into test failures.
			$reflection->getProperty( $name )->setValue( null, $empty );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		self::resetTemplateStylesCaches();
		$this->overrideConfigValue( 'TemplateStylesAllowedUrls', [
			'audio' => [],
			'image' => [ '<^' . preg_quote( self::ALLOWED, '<' ) . '/>' ],
			'svg' => [ '<^' . preg_quote( self::ALLOWED, '<' ) . '/>' ],
			'font' => [],
			'namespace' => [ '<.>' ],
			'css' => [],
		] );
	}

	protected function tearDown(): void {
		try {
			// Leave no sanitizer built from this test's allowlist behind for other tests.
			self::resetTemplateStylesCaches();
		} finally {
			// parent::tearDown() must run even if the reset throws. MediaWiki skips its
			// own teardown when tearDown() raises, and then reports every subsequent test
			// as "mediaWikiSetUp() was called but not mediaWikiTearDown()" -- turning one
			// real failure into a dozen misleading ones.
			parent::tearDown();
		}
	}

	private function isAccepted( string $declaration ): bool {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$sanitizer->sanitize( CSSParser::newFromString( ".test { $declaration }" )->parseStylesheet() );

		return $sanitizer->getSanitizationErrors() === [];
	}

	/**
	 * The density slot of image-set() must not accept var(), or a template can smuggle a
	 * second image-set entry past the URL allowlist.
	 *
	 * MatcherFactoryExtender::resolution() is what prevents this. It looks redundant --
	 * deleting it fails no other test -- but upstream's resolution() is built as
	 * mathFunction( rawResolution() ), and mathFunction() is late-bound to this extension's
	 * var()-aware override. Inheriting it therefore admits var() into the density slot.
	 *
	 * doSanitize() does not help here: it rejects url() tokens and external-resource
	 * functions inside a custom property, but the payload below is a bare string, which is
	 * neither -- and a bare string IS a URL in image-set()'s first argument.
	 *
	 * @dataProvider provideResolutionSlotPayloads
	 */
	public function testResolutionSlotRejectsSubstitution( string $declarations ): void {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$output = (string)$sanitizer->sanitize(
			CSSParser::newFromString( ".test { $declarations }" )->parseStylesheet()
		);

		$this->assertStringNotContainsString(
			'background-image',
			$output,
			'the referencing declaration must be dropped, not just flagged'
		);
	}

	public static function provideResolutionSlotPayloads(): array {
		$evil = self::BLOCKED . '/tracker.png';
		// Must be a host this test's own allowlist permits, or the declaration is dropped
		// because of the URL rather than because of the density slot, and the test passes
		// for the wrong reason.
		$ok = self::ALLOWED . '/i.png';

		return [
			'var() carrying a second image-set entry' => [
				"--r: 2x, \"$evil\" 1x; background-image: image-set(\"$ok\" var(--r))",
			],
			'var() in the density slot at all' => [
				"--r: 1x; background-image: image-set(\"$ok\" var(--r))",
			],
			'math function in the density slot' => [
				"background-image: image-set(\"$ok\" calc(1dppx * 2))",
			],
		];
	}

	/**
	 * @dataProvider provideUrls
	 */
	public function testConfiguredAllowlistApplies( string $declaration, bool $allowed ): void {
		$this->assertSame( $allowed, $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideUrls(): array {
		$allowed = self::ALLOWED;
		$blocked = self::BLOCKED;
		// The Wikimedia host is what the shipped default permits. Under this test's
		// allowlist it must be refused -- that is what proves configuration took effect
		// rather than the assertions passing against the default policy.
		$default = 'https://upload.wikimedia.org/wikipedia/commons/a/ab';

		return [
			// image-set(), added by this extension
			'image-set, allowed host' => [ "background-image: image-set(\"$allowed/i.png\" 1x)", true ],
			'image-set, blocked host' => [ "background-image: image-set(\"$blocked/i.png\" 1x)", false ],
			'image-set, url() form, allowed host' => [
				"background-image: image-set(url(\"$allowed/i.png\") 1x)",
				true,
			],
			'image-set, url() form, blocked host' => [
				"background-image: image-set(url(\"$blocked/i.png\") 1x)",
				false,
			],
			'image-set, default-policy host is not special-cased' => [
				"background-image: image-set(\"$default/x.png\" 1x)", false,
			],

			// backdrop-filter, added by this extension, takes an SVG filter reference
			'backdrop-filter, allowed host' => [ "backdrop-filter: url(\"$allowed/f.svg#f\")", true ],
			'backdrop-filter, blocked host' => [ "backdrop-filter: url(\"$blocked/f.svg#f\")", false ],
			'backdrop-filter, blocked host beside a permitted function' => [
				"backdrop-filter: url(\"$blocked/f.svg#f\") blur(4px)", false,
			],
			'backdrop-filter, relative URL is not resolved into the allowlist' => [
				'backdrop-filter: url("f.svg#f")', false,
			],

			// custom properties, where this extension does its own external-resource scan
			'custom property, blocked host' => [ "--x: url(\"$blocked/i.png\")", false ],
			'custom property, even an allowed host is refused' => [ "--x: url(\"$allowed/i.png\")", false ],
		];
	}
}
