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
			// Selectors 4 state pseudo-classes (#67)
			'.card:focus-within',
			'.card:focus-visible',
			'a:any-link',
			// the issue's motivating case: draw the focus ring on the container
			'.card:has(a:focus-visible)',

			// :has(), including the relative forms
			'.card:has(.title)',
			'.card:has(> .title)',
			'.card:has(+ .sibling)',
			'.card:has(~ .later)',
			'.card:has(.a, .b)',
			'.card:has(:focus-visible)',

			// :is() and :where(), used to keep specificity flat
			':is(.a, .b) .c',
			'.a:where(.b, .c)',
			':where(.a) .b',
			'.a:is(.b .c)',

			// :not() over a selector list, which upstream refuses
			'.a:not(.b, .c)',
			'.a:not(.b .c)',
			'.a:not(:has(.b))',
			'.a:not(:focus-visible)',

			// form and input state
			'input:read-only',
			'input:read-write',
			'input:placeholder-shown',
			'input:required',
			'input:optional',
			'input:valid',
			'input:invalid',
			'input:in-range',
			'input:out-of-range',
			'option:default',
			'.form-row:has(input:invalid)',

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
			'.a:has()',
			'.a:not()',
			// an argument that is not a selector at all
			'.a:is("string")',
			'.a:has(12px)',
			'.a:is(url("https://upload.wikimedia.org/wikipedia/commons/a/ab/x.png"))',
		] );
	}

	/**
	 * Documented gaps. The argument of a functional pseudo-class is deliberately bounded:
	 * it cannot be built from the grammar that contains it without recursing, and a bounded
	 * one also cannot be driven to backtrack, which matters for a grammar that runs on every
	 * save. See MatcherFactoryExtender::cssPseudo().
	 *
	 * @dataProvider provideNotYetImplemented
	 */
	public function testNotYetImplemented( string $selector ): void {
		$this->assertFalse(
			$this->isAccepted( $selector ),
			"Now accepted -- move this case to provideAccepted(): $selector"
		);
	}

	public static function provideNotYetImplemented(): array {
		return self::cases( [
			// :is() and :where() take a forgiving selector list, which may be empty, so a
			// browser accepts these. Refusing them is this grammar being behind, not a line
			// worth holding.
			'.a:is()',
			'.a:where()',

			'.a:has(:has(.b))',
			'.a:is(.b:has(.c))',
			'.a:is(.b:is(.c))',
			'.a:where(.b:not(.c, .d))',
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
			'@media, :focus-within' => [ '@media screen { .card:focus-within { color: red } }', true ],
			'@media, :has()' => [ '@media screen { .card:has(.x) { color: red } }', true ],
			'@media, :not() list' => [ '@media screen { .a:not(.b, .c) { color: red } }', true ],
			'@media, nested @media' => [
				'@media screen { @media print { .card:focus-within { color: red } } }', true,
			],
			'@supports, :focus-within' => [
				'@supports (display: grid) { .card:focus-within { color: red } }', true,
			],
			// the same replacement reaches @font-face, which this extension also widens
			'@media, @font-face descriptor this extension adds' => [
				"@media screen { @font-face { font-family: 'TemplateStylesX'; ascent-override: 100% } }",
				true,
			],
			// a nonsense selector must still be refused there, not waved through
			'@media, nonsense selector' => [ '@media screen { .a:nonsense { color: red } }', false ],
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
			'level 4 pseudo-class is scoped like any other' => [
				'.card:focus-within',
				'.mw-parser-output .card:focus-within',
			],
			'the argument of :is() is not separately scoped' => [
				'.a:is(.b, .c)',
				'.mw-parser-output .a:is(.b, .c)',
			],
			'a leading :is() is still scoped' => [
				':is(.a, .b) .c',
				'.mw-parser-output :is(.a, .b) .c',
			],
			'the argument of :has() is not separately scoped' => [
				'.card:has(> .title)',
				'.mw-parser-output .card:has(> .title)',
			],
			'the argument of :not() is not separately scoped' => [
				'.a:not(.b, .c)',
				'.mw-parser-output .a:not(.b, .c)',
			],
			'every selector in a list is scoped' => [
				'.a:is(.b), .d:focus-within',
				'.mw-parser-output .a:is(.b), .mw-parser-output .d:focus-within',
			],
			// Hoisting moves the wrapper after an html/body prefix, so that a theme class
			// on <html> can still gate a rule. StyleRuleSanitizerExtender has to rebuild
			// that matcher; without it these are accepted but scoped under .mw-parser-output,
			// where they can never match.
			'html prefix is hoisted, level 3' => [
				'html.night .card',
				'html.night .mw-parser-output .card',
			],
			'html prefix is hoisted, level 4' => [
				'html.night .card:focus-within',
				'html.night .mw-parser-output .card:focus-within',
			],
			'body prefix is hoisted with :has()' => [
				'body.rtl .card:has(a:focus-visible)',
				'body.rtl .mw-parser-output .card:has(a:focus-visible)',
			],
			// Known dead, and pinned so it stays visible: the hoist test reads the 'element'
			// capture, which a functional pseudo-class does not set, so this is scoped under
			// the wrapper where it can never match. Teaching the predicate to look inside
			// :is() is more logic than the case is worth.
			'a leading :is() does not hoist' => [
				':is(html, body) .card',
				'.mw-parser-output :is(html, body) .card',
			],
			'a non-hoistable prefix is not hoisted' => [
				'div.night .card:focus-within',
				'.mw-parser-output div.night .card:focus-within',
			],
		];
	}

	/**
	 * Nothing may escape the wrapper class, whatever it is asked to select.
	 *
	 * This is the guarantee TemplateStyles exists for: a template may style the content it
	 * is transcluded into and nothing else. The selectors below all reach for something
	 * outside that -- `html`, `body`, `:root`, a bare universal -- from inside a functional
	 * pseudo-class, which is the part of the grammar this extension added. Whether each one
	 * is accepted is not the point; what matters is that if it is, every selector in the
	 * rewritten prelude still carries the wrapper.
	 *
	 * @dataProvider provideScopeEscapeAttempts
	 */
	public function testNothingEscapesTheWrapper( string $selector ): void {
		[ $accepted, $output ] = $this->sanitize( $selector );
		if ( !$accepted ) {
			// Refusing is a fine answer; the assertion is about what happens if it is not.
			$this->addToAssertionCount( 1 );
			return;
		}

		$prelude = trim( explode( '{', $output, 2 )[0] );
		foreach ( self::splitSelectorList( $prelude ) as $entry ) {
			$this->assertStringContainsString(
				'.mw-parser-output',
				$entry,
				"unscoped selector produced from: $selector"
			);
		}
	}

	public static function provideScopeEscapeAttempts(): array {
		return self::cases( [
			'.a:is(html, .b)',
			'.a:is(.b, html *)',
			'.a:has(html)',
			':is(html) .a',
			':where(html, body) .a',
			'.a:not(html)',
			'html:has(.a)',
			'body:focus-within',
			'.a:where(:root)',
			':root:has(.a)',
			':has(> html)',
			':is(*)',
			'*:has(.a)',
		] );
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
