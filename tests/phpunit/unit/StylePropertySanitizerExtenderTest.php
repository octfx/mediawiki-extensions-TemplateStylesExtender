<?php

use MediaWiki\Extension\TemplateStyles\TemplateStylesMatcherFactory;
use MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender;
use MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender;
use Wikimedia\CSS\Parser\Parser;

/**
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender
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

}
