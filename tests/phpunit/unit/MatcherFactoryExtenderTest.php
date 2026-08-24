<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Unit;

use MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender;
use MediaWikiUnitTestCase;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * Grammar guards that production wiring hides.
 *
 * A hand-built factory is the wrong tool for the corpus -- see CssCorpusTest -- because it
 * does not reproduce how TemplateStyles assembles things. It is the right tool here: these
 * assertions are about one matcher in isolation, and the point is to catch a guard whose
 * effect is masked further up, where a second check happens to cover the same ground.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 */
class MatcherFactoryExtenderTest extends MediaWikiUnitTestCase {

	private function resolutionMatches( string $value ): bool {
		$factory = new MatcherFactoryExtender( MatcherFactory::singleton() );
		// The configuration that makes this dangerous: var() enabled everywhere it can be.
		$factory->setVarEnabled( true );

		return (bool)$factory->resolution()->matchAgainst(
			CSSParser::newFromString( $value )->parseComponentValueList()
		);
	}

	/**
	 * resolution() must not admit a bare var(), whoever calls it.
	 *
	 * It is public, and image-set()'s density slot is the consumer that sits next to a URL;
	 * a var() there substitutes textually into a second entry, past the URL allowlist.
	 * Asserted here rather than through image-set(), because imageSetOptions() refuses a
	 * var() too -- so production wiring hides whether this guard is doing anything.
	 */
	public function testResolutionRefusesABareVar(): void {
		$this->assertFalse( $this->resolutionMatches( 'var(--r)' ) );
		$this->assertFalse( $this->resolutionMatches( 'var(--r, 2x)' ) );
	}

	/** Math functions are the point of allowing anything beyond a raw dimension (#62). */
	public function testResolutionAcceptsMathFunctions(): void {
		$this->assertTrue( $this->resolutionMatches( '2x' ) );
		$this->assertTrue( $this->resolutionMatches( '2dppx' ) );
		$this->assertTrue( $this->resolutionMatches( 'calc(1x * 2)' ) );
		$this->assertTrue( $this->resolutionMatches( 'min(1x, 2x)' ) );
	}

	/** Not a resolution at all. */
	public function testResolutionRefusesOtherUnits(): void {
		$this->assertFalse( $this->resolutionMatches( '2kg' ) );
		$this->assertFalse( $this->resolutionMatches( '2px' ) );
	}
}
