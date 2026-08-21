<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWikiIntegrationTestCase;
use ReflectionClass;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * $wgTemplateStylesExtenderExtendCustomPropertiesValues turns off the var() this extension
 * adds, and nothing else. CssCorpusTest measures it enabled, which is the default, so this
 * is the other half.
 *
 * It reaches four places, so a case comes from each: StylesheetSanitizerHook skips
 * addVarSelector(), and setVarEnabled() feeds the origin, mathFunction() and rawNumber().
 * Colour is the smallest of the four and was once all this provider had. Where the line
 * stops matters as much: css-sanitizer accepts var() on its own in a colour channel, in a
 * shorthand's <color> component and inside calc(), and this must narrow none of them.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\StylesheetSanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender
 */
class CustomPropertyValuesConfigTest extends MediaWikiIntegrationTestCase {

	/**
	 * TemplateStyles memoises these in private statics it never invalidates, so the override
	 * below does nothing until they are dropped. UrlPolicyConfigTest explains why in full.
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
		$this->overrideConfigValue( 'TemplateStylesExtenderExtendCustomPropertiesValues', false );
	}

	protected function tearDown(): void {
		try {
			// Leave no sanitizer built with the option off behind for other tests.
			self::resetTemplateStylesCaches();
		} finally {
			// parent::tearDown() must run even if the reset throws, or MediaWiki reports
			// every later test as "mediaWikiSetUp() was called but not mediaWikiTearDown()".
			parent::tearDown();
		}
	}

	private function isAccepted( string $declaration ): bool {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$output = $sanitizer->sanitize( CSSParser::newFromString( ".test { $declaration }" )->parseStylesheet() );

		// As in CssCorpusTest: no error is not the same as kept.
		return $sanitizer->getSanitizationErrors() === []
			&& str_contains( (string)$output, explode( ':', $declaration, 2 )[0] );
	}

	/**
	 * @dataProvider provideDeclarations
	 */
	public function testOptionGatesOnlyWhatThisExtensionAdds( string $declaration, bool $accepted ): void {
		$this->assertSame( $accepted, $this->isAccepted( $declaration ), $declaration );
	}

	public static function provideDeclarations(): array {
		return [
			// var() in a relative colour's origin; both halves go.
			'origin var()' => [ 'color: rgb(from var(--c) r g b)', false ],
			'origin var() carrying a fallback' => [ 'color: rgb(from var(--c, red) r g b)', false ],

			// The rest of the origin is not this option's business.
			'origin colour word' => [ 'color: rgb(from red r g b)', true ],
			'origin colour function' => [ 'color: rgb(from rgb(1 2 3) r g b)', true ],
			'origin light-dark(), which upstream supplies' => [
				'color: rgb(from light-dark(red, blue) r g b)',
				true,
			],

			// css-sanitizer's own var(), which this option must not narrow.
			'colour channel var()' => [ 'color: rgb(var(--r) 0 0)', true ],
			'colour channel var() carrying a fallback' => [ 'color: rgb(var(--r, 0) 0 0)', true ],
			'var() as a whole colour' => [ 'color: var(--c, red)', true ],
			'light-dark() outside an origin' => [ 'color: light-dark(red, blue)', true ],
			// border takes a <color>, so upstream's color() admits the var() here too
			'var() where a shorthand takes a colour' => [ 'border: var(--shorthand)', true ],
			// upstream's calcSum() admits var(); UrlPolicyConfigTest pins where that stops
			'var() inside calc()' => [ 'left: calc(var(--a) / var(--b))', true ],

			// Beyond colour. Without these, unhooking addVarSelector() or either varEnabled
			// branch would leave this test green.
			'var() in a math function' => [ 'transform: translateX(var(--x))', false ],
			'var() through rawNumber()' => [ 'aspect-ratio: 16 / var(--b)', false ],
			'var() as a whole length' => [ 'width: var(--w)', false ],
			'var() as a transition duration' => [ 'transition: background-color var(--d)', false ],
			'var() in a shorthand only addVarSelector reaches' => [
				'box-shadow: var(--x) var(--y) 0 red inset',
				false,
			],

			// the other option's business, and it stays on
			'declaring a custom property' => [ '--x: 1px', true ],
			// no var() at all
			'a plain value' => [ 'width: 10px', true ],
		];
	}
}
