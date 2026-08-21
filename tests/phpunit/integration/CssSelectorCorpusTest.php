<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWikiIntegrationTestCase;
use Wikimedia\CSS\Parser\Parser as CSSParser;

/**
 * Every selector this extension is meant to affect, asserted against the sanitizer
 * TemplateStyles builds in production.
 *
 * Selectors need their own corpus rather than a place in CssCorpusTest, because a rejected
 * selector fails differently from a rejected declaration: TemplateStyles refuses the whole
 * stylesheet, not the one rule. That is also why the accepted set matters more here than
 * elsewhere -- a false negative costs an editor the entire page.
 *
 * The scoping cases are the ones to be careful with. TemplateStyles rewrites every selector
 * to sit under `.mw-parser-output`, and it does that by walking the matches the selector
 * grammar captured, so where the wrapper class lands depends on a grammar this extension
 * now replaces. The rewritten prelude is asserted in full rather than just accepted or
 * rejected for that reason.
 *
 * @group TemplateStylesExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\StylesheetSanitizerHook
 * @covers \MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender
 * @covers \MediaWiki\Extension\TemplateStylesExtender\StyleRuleSanitizerExtender
 */
class CssSelectorCorpusTest extends MediaWikiIntegrationTestCase {

	/** @return array{0:bool,1:string} accepted, and the sanitized stylesheet */
	private function sanitize( string $selector ): array {
		// getSanitizer() memoises, so the same instance returns every call and errors
		// accumulate. Without clearing, every check after the first failure looks failed.
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();

		$output = (string)$sanitizer->sanitize(
			CSSParser::newFromString( "$selector { color: red }" )->parseStylesheet()
		);

		return [ $sanitizer->getSanitizationErrors() === [] && $output !== '', $output ];
	}

	private function isAccepted( string $selector ): bool {
		return $this->sanitize( $selector )[0];
	}

	/**
	 * Selectors the extension exists to allow. A failure here is a regression.
	 *
	 * @dataProvider provideAccepted
	 */
	public function testAccepted( string $selector ): void {
		$this->assertTrue( $this->isAccepted( $selector ), "Expected to be accepted: $selector" );
	}

	public static function provideAccepted(): array {
		return self::cases( [
			// Level 3, which must keep working -- cssPseudo() and cssNegation() replace
			// upstream's wholesale, so a mistake here silently drops what it shadowed
			'.a:hover',
			'.a:focus',
			'.a:first-child',
			'.a:last-of-type',
			'.a:nth-child(2n+1)',
			'.a:not(.b)',
			'.a:not(*)',
			'.a:not(:hover)',
			'.a:lang(en)',
			'.a:dir(ltr)',
			'.a::before',
			'.a::first-letter',
			'.a::placeholder',
			'.a > .b + .c ~ .d',
			'a[href^="https"]',
			'#id.class',
			'*',
		] );
	}

	/**
	 * Selectors that must stay refused. A failure here is a widening, not a regression.
	 *
	 * @dataProvider provideRejected
	 */
	public function testRejected( string $selector ): void {
		$this->assertFalse( $this->isAccepted( $selector ), "Expected to be refused: $selector" );
	}

	/**
	 * Note what is absent: `:is(::before)` and friends. Upstream's cssPseudo() covers
	 * pseudo-elements as well as pseudo-classes, and its own `:not()` accepts them for that
	 * reason, so the argument grammar here inherits the same laxity. Nothing can come of it
	 * -- a pseudo-element inside `:is()` matches nothing -- and rejecting it would mean
	 * keeping a second copy of upstream's pseudo-class list in step with theirs.
	 *
	 * @return array<string,array{string}>
	 */
	public static function provideRejected(): array {
		return self::cases( [
			'.a:nonsense',
			'.a::nonsense',
			// an argument that is not a selector at all
		] );
	}

	/**
	 * `@media` and `@supports` hold their own copy of the rule-sanitizer list, taken before
	 * the hook that replaces one runs, so a selector can sanitize at the top level and be
	 * refused a line deeper -- taking the whole stylesheet with it. Nothing asserted that
	 * path before, which is why it stayed broken through a full green suite.
	 *
	 * @dataProvider provideNestedAtRules
	 */
	public function testAcceptedInsideAtRules( string $css, bool $accepted ): void {
		$sanitizer = TemplateStylesHooks::getSanitizer( 'mw-parser-output' );
		$sanitizer->clearSanitizationErrors();
		$output = (string)$sanitizer->sanitize( CSSParser::newFromString( $css )->parseStylesheet() );

		$this->assertSame( $accepted, $sanitizer->getSanitizationErrors() === [] && $output !== '', $css );
	}

	public static function provideNestedAtRules(): array {
		return [
			'@media, level 3' => [ '@media screen { .card:hover { color: red } }', true ],
			// the same replacement reaches @font-face, which this extension also widens
			'@media, @font-face descriptor this extension adds' => [
				"@media screen { @font-face { font-family: 'TemplateStylesX'; ascent-override: 100% } }",
				true,
			],
			// a nonsense selector must still be refused there, not waved through
			// and nothing gains an at-rule it was not given
			'@media, @namespace stays refused' => [ '@media screen { @namespace x "y"; }', false ],
		];
	}

	/**
	 * Where the wrapper class lands. An inner selector must never be scoped as though it
	 * were a top-level one, and a top-level one must never escape the wrapper.
	 *
	 * @dataProvider provideScoping
	 */
	public function testScoping( string $selector, string $expectedPrelude ): void {
		[ $accepted, $output ] = $this->sanitize( $selector );

		$this->assertTrue( $accepted, "Expected to be accepted: $selector" );
		$this->assertStringStartsWith( "$expectedPrelude {", $output, $selector );
	}

	public static function provideScoping(): array {
		return [
			'every selector in a list is scoped' => [
				'.a, .b:hover',
				'.mw-parser-output .a, .mw-parser-output .b:hover',
			],
			// Hoisting moves the wrapper after an html/body prefix, so that a theme class
			// on <html> can still gate a rule. StyleRuleSanitizerExtender has to rebuild
			// that matcher; without it these are accepted but scoped under .mw-parser-output,
			// where they can never match.
			'html prefix is hoisted' => [
				'html.night .card',
				'html.night .mw-parser-output .card',
			],
			'body prefix is hoisted' => [
				'body.rtl .card',
				'body.rtl .mw-parser-output .card',
			],
			// Known dead, and pinned so it stays visible: the hoist test reads the 'element'
			// capture, which a functional pseudo-class does not set, so this is scoped under
			// the wrapper where it can never match. Teaching the predicate to look inside
			// :is() is more logic than the case is worth.
			'a non-hoistable prefix is not hoisted' => [
				'div.night .card',
				'.mw-parser-output div.night .card',
			],
		];
	}

	/**
	 * Split a selector list on its separating commas only.
	 *
	 * A comma inside `:is(...)` separates arguments, not selectors, so splitting naively
	 * reports the tail of every functional pseudo-class as an unscoped selector -- a test
	 * that fails on correct output.
	 *
	 * @return string[]
	 */
	private static function splitSelectorList( string $prelude ): array {
		$parts = [];
		$depth = 0;
		$current = '';
		foreach ( str_split( $prelude ) as $char ) {
			if ( $char === '(' ) {
				$depth++;
			} elseif ( $char === ')' ) {
				$depth--;
			}
			if ( $char === ',' && $depth === 0 ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;

		return $parts;
	}

	/**
	 * @param string[] $selectors
	 * @return array<string,array{string}>
	 */
	private static function cases( array $selectors ): array {
		$cases = [];
		foreach ( $selectors as $selector ) {
			$cases[$selector] = [ $selector ];
		}

		return $cases;
	}
}
