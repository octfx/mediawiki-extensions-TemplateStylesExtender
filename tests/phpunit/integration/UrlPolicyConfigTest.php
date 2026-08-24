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
 * Integration rather than unit: a unit test can only show the extension delegates to
 * whatever factory it is handed, not that the configured allowlist reaches the properties
 * added through the hook chain -- which is what a wiring mistake breaks.
 *
 * CssCorpusTest uses URLs the default allowlist permits and so says nothing about
 * configuration. This sets the allowlist explicitly, so it depends on no wiki's settings.
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
	 * never invalidates them, so a config override does nothing until they are dropped.
	 * PHPUnit process isolation, the obvious alternative, does not work under MediaWiki's
	 * test bootstrap. A rename upstream makes getProperty() throw, which fails loudly
	 * rather than asserting against a stale sanitizer.
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
	 * The density slot of image-set() must not accept var(), or a custom property can
	 * smuggle a second image-set entry past the URL allowlist.
	 *
	 * Two things prevent it. resolution() calls parent::mathFunction() rather than this
	 * extension's override, which is what would put a bare var() in the slot; and
	 * imageSetOptions() refuses a var() anywhere in the matched value, calc() included.
	 * doSanitize() does not help: the payload is a bare string, which is neither a url()
	 * token nor an external-resource function -- and is exactly what image-set()'s first
	 * argument accepts as a URL.
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
		$this->assertNotSame(
			[],
			$sanitizer->getSanitizationErrors(),
			'the editor must be told why, not have the rule vanish silently'
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
			// calc() is allowed here now, so the var() it can carry has to be refused
			// separately. A custom property cannot close the parens it lands in, so this
			// payload would turn the declaration invalid rather than into a second entry --
			// but the slot sits next to a URL, so it does not get to depend on that.
			'var() wrapped in calc()' => [
				"--r: 2x, \"$evil\" 1x; background-image: image-set(\"$ok\" calc(var(--r)))",
			],
			'var() as a factor inside calc()' => [
				"--d: 2; background-image: image-set(\"$ok\" calc(1x * var(--d)))",
			],
			'var() inside min()' => [
				"--r: 2x; background-image: image-set(\"$ok\" min(var(--r), 3x))",
			],
			// The check compares CSSFunction::getName(), which the tokenizer has already
			// lowercased and unescaped. Both of these would slip past a raw-source match.
			'var() in mixed case' => [
				"--r: 2x, \"$evil\" 1x; background-image: image-set(\"$ok\" calc(VAR(--r)))",
			],
			'var() spelled with an escape' => [
				"--r: 2x, \"$evil\" 1x; background-image: image-set(\"$ok\" calc(\\76 ar(--r)))",
			],
		];
	}

	/**
	 * A math function in the density slot is fine; only the var() one can carry is not.
	 *
	 * The two used to be refused together, because the slot took a bare TokenMatcher. That
	 * kept var() out by keeping calc() out with it, which cost `calc(1x * 2)` for no gain --
	 * a math function evaluates to a number and cannot introduce an entry.
	 */
	public function testMathFunctionsAreAllowedInTheDensitySlot(): void {
		$ok = self::ALLOWED . '/i.png';
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$output = (string)$sanitizer->sanitize(
			CSSParser::newFromString( ".test { background-image: image-set(\"$ok\" calc(1dppx * 2)) }" )
				->parseStylesheet()
		);

		$this->assertStringContainsString( 'background-image', $output );
		$this->assertSame( [], $sanitizer->getSanitizationErrors() );
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
