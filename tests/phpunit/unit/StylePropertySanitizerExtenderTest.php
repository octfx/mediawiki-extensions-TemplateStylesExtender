<?php

use MediaWiki\Extension\TemplateStyles\TemplateStylesMatcherFactory;
use MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender;
use MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use Wikimedia\CSS\Parser\Parser;

/**
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender
 */
class StylePropertySanitizerExtenderTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideDeclarations
	 */
	public function testPreservesTemplateStylesUrlPolicy( string $declarationText, bool $allowed ): void {
		$factory = new TemplateStylesMatcherFactory( [
			'image' => [
				'<^https://allowed\\.example/>',
			],
		] );
		$sanitizer = new StylePropertySanitizerExtender( $factory );
		$declaration = Parser::newFromString( $declarationText )->parseDeclaration();

		$this->assertSame( $allowed, $sanitizer->sanitize( $declaration ) !== null );
	}

	public static function provideDeclarations(): array {
		return [
			'allowed URL' => [
				'background-image: url("https://allowed.example/image.png")',
				true,
			],
			'blocked URL' => [
				'background-image: url("https://blocked.example/probe.png")',
				false,
			],
			'allowed image-set URL' => [
				'background-image: image-set("https://allowed.example/image.png" 1x)',
				true,
			],
			'blocked image-set URL' => [
				'background-image: image-set("https://blocked.example/probe.png" 1x)',
				false,
			],
		];
	}

	/**
	 * @dataProvider provideCustomProperties
	 */
	public function testRejectsExternalResourcesInCustomProperties(
		string $declarationText,
		bool $allowed
	): void {
		$baseFactory = new TemplateStylesMatcherFactory( [
			'image' => [
				'<^https://allowed\\.example/>',
			],
		] );
		$sanitizer = new StylePropertySanitizerExtender(
			new MatcherFactoryExtender( $baseFactory )
		);
		$sanitizer->setVarEnabled( true );
		$declaration = Parser::newFromString( $declarationText )->parseDeclaration();

		$this->assertSame( $allowed, $sanitizer->sanitize( $declaration ) !== null );
	}

	public static function provideCustomProperties(): array {
		return [
			'URL function' => [
				'--remote: url("https://blocked.example/probe.png")',
				false,
			],
			'URL token' => [
				'--remote: url(https://blocked.example/probe.png)',
				false,
			],
			'image-set function' => [
				'--remote: image-set("https://blocked.example/probe.png" 1x)',
				false,
			],
			'prefixed image-set function' => [
				'--remote: -webkit-image-set("https://blocked.example/probe.png" 1x)',
				false,
			],
			'image function' => [
				'--remote: image("https://blocked.example/probe.png")',
				false,
			],
			'src function' => [
				'--remote: src("https://blocked.example/probe.png")',
				false,
			],
			'typed attr function' => [
				'--remote: attr(data-image type(<url>))',
				false,
			],
			'gradient' => [
				'--gradient: linear-gradient(red, blue)',
				true,
			],
			'length' => [
				'--spacing: 1rem',
				true,
			],
			'color' => [
				'--color: #123456',
				true,
			],
			'string' => [
				'--label: "Example text"',
				true,
			],
			'variable reference' => [
				'--spacing-large: calc(var(--spacing) * 2)',
				true,
			],
		];
	}

	/**
	 * @dataProvider provideGridDeclarations
	 */
	public function testExtendedGridProperties( string $declarationText, bool $allowed ): void {
		$factory = new MatcherFactoryExtender( new TemplateStylesMatcherFactory( [] ) );
		$factory->setVarEnabled( true );
		$sanitizer = new StylePropertySanitizerExtender( $factory );
		$sanitizer->setVarEnabled( true );
		$declaration = Parser::newFromString( $declarationText )->parseDeclaration();

		$this->assertSame( $allowed, $sanitizer->sanitize( $declaration ) !== null );
	}

	public static function provideGridDeclarations(): array {
		return [
			// CSS Grid Module Level 2 -- subgrid
			'subgrid columns' => [ 'grid-template-columns: subgrid', true ],
			'subgrid rows with line names' => [ 'grid-template-rows: [row-start] subgrid [row-end]', true ],
			// CSS Grid Module Level 3 -- masonry
			'masonry rows' => [ 'grid-template-rows: masonry', true ],
			'masonry-auto-flow' => [ 'masonry-auto-flow: next definite-first', true ],
			// variables in track lists
			'var in track list' => [ 'grid-template-columns: 1fr var(--right-rail-size)', true ],
			'var as repeat count' => [ 'grid-template-columns: repeat(var(--cols), minmax(0, 1fr))', true ],
			// still rejected
			'nonsense keyword' => [ 'grid-template-columns: definitely-not-a-thing', false ],
		];
	}

	/**
	 * The scroll properties are added through addKnownProperties(), so the grammar each one
	 * gets is this extension's own and nothing upstream constrains it. These pin the shape:
	 * how many values a shorthand takes, and which types its slots hold.
	 *
	 * @dataProvider provideScrollDeclarations
	 */
	public function testScrollProperties( string $declarationText, bool $allowed ): void {
		$factory = new MatcherFactoryExtender( new TemplateStylesMatcherFactory( [] ) );
		$sanitizer = new StylePropertySanitizerExtender( $factory );
		$extender = new TemplateStylesExtender();
		$extender->addCssOverscrollBehavior1( $sanitizer );
		$extender->addCssScrollbars1( $sanitizer, $factory );
		$declaration = Parser::newFromString( $declarationText )->parseDeclaration();

		$this->assertSame( $allowed, $sanitizer->sanitize( $declaration ) !== null );
	}

	public static function provideScrollDeclarations(): array {
		return [
			// CSS Overscroll Behavior Module Level 1 -- [ contain | none | auto ]{1,2}
			'overscroll-behavior one value' => [ 'overscroll-behavior: contain', true ],
			'overscroll-behavior two values' => [ 'overscroll-behavior: none auto', true ],
			'overscroll-behavior three values' => [ 'overscroll-behavior: none auto contain', false ],
			'overscroll-behavior longhand' => [ 'overscroll-behavior-x: none', true ],
			'overscroll-behavior longhand takes one value' => [ 'overscroll-behavior-y: none auto', false ],
			'overscroll-behavior is not a length' => [ 'overscroll-behavior: 10px', false ],
			// `chain` is in the editor's draft but not the published one, and ships in no
			// engine but Blink, where it is still experimental
			'overscroll-behavior chain is not in the published spec' => [ 'overscroll-behavior: chain', false ],
			// CSS Scrollbars Styling Module Level 1 -- auto | <color>{2}
			'scrollbar-color auto' => [ 'scrollbar-color: auto', true ],
			'scrollbar-color pair' => [ 'scrollbar-color: red blue', true ],
			'scrollbar-color needs both colours' => [ 'scrollbar-color: red', false ],
			'scrollbar-color takes no third' => [ 'scrollbar-color: red blue green', false ],
			'scrollbar-color is not a keyword' => [ 'scrollbar-color: thin', false ],
			// dropped from the spec in favour of letting `auto` follow color-scheme
			'scrollbar-color light and dark are gone' => [ 'scrollbar-color: light dark', false ],
			'scrollbar-width thin' => [ 'scrollbar-width: thin', true ],
			'scrollbar-width is not a length' => [ 'scrollbar-width: 10px', false ],
		];
	}

}
