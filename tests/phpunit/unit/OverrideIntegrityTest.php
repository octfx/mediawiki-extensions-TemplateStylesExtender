<?php

use MediaWiki\Extension\TemplateStylesExtender\FontFaceAtRuleSanitizerExtender;
use MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender;
use MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender;

/**
 * These classes work by overriding methods css-sanitizer calls internally. An override
 * whose name no longer matches anything on the parent is never reached, and nothing fails:
 * that is how cssGrid1(), renamed to cssGrid3(), left subgrid and masonry support inert for
 * a whole release line.
 *
 * The register below is checked in both directions, so neither a rename upstream nor a new
 * override here can pass unnoticed.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\FontFaceAtRuleSanitizerExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender
 */
class OverrideIntegrityTest extends MediaWikiUnitTestCase {

	/**
	 * Every method here is meant to override its parent. Adding one is deliberate: an
	 * override replaces upstream's version, so it can end up narrower than the code it
	 * shadows, which no test of this extension alone would notice.
	 * NotNarrowerThanUpstreamTest is where that is checked.
	 */
	private const INTENDED_OVERRIDES = [
		MatcherFactoryExtender::class => [
			'cssWideKeywords',
			'colorFuncs',
			'image',
			'mathFunction',
			'rawNumber',
			'resolution',
			'url',
			'urlstring',
		],
		StylePropertySanitizerExtender::class => [
			'cssGrid1',
			'cssMasking1',
			'doSanitize',
		],
		FontFaceAtRuleSanitizerExtender::class => [],
	];

	/**
	 * Compares the overrides the class actually has against the register, which catches
	 * both directions at once:
	 *
	 *  - a registered name the parent no longer declares stops being an override, so it
	 *    disappears from the actual set -- the cssGrid1 -> cssGrid3 failure;
	 *  - a new override nobody registered appears in the actual set, which is the prompt
	 *    to decide whether it is wanted and whether it is narrower than what it replaces.
	 *
	 * @dataProvider provideClasses
	 */
	public function testOverridesMatchTheRegister( string $class ): void {
		$reflection = new ReflectionClass( $class );
		$parent = $reflection->getParentClass();

		$actual = [];
		foreach ( $reflection->getMethods() as $method ) {
			if ( $method->getDeclaringClass()->getName() !== $class || $method->isConstructor() ) {
				continue;
			}
			// Private parent methods are not inherited, so sharing a name is not an override.
			if ( !$parent->hasMethod( $method->getName() )
				|| $parent->getMethod( $method->getName() )->isPrivate()
			) {
				continue;
			}
			$actual[] = $method->getName();
		}

		$this->assertEqualsCanonicalizing(
			self::INTENDED_OVERRIDES[$class],
			$actual,
			"The overrides $class actually has do not match INTENDED_OVERRIDES. A name "
				. "missing from the actual set overrides nothing and is never called; a name "
				. "missing from the register is an override nobody declared."
		);
	}

	public static function provideClasses(): array {
		return array_map(
			static fn ( $class ) => [ $class ],
			array_combine( array_keys( self::INTENDED_OVERRIDES ), array_keys( self::INTENDED_OVERRIDES ) )
		);
	}
}
