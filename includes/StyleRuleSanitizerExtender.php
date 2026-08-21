<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @file
 */

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender;

use Wikimedia\CSS\Grammar\CheckedMatcher;
use Wikimedia\CSS\Grammar\GrammarMatch;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Sanitizer\StyleRuleSanitizer;

/**
 * A StyleRuleSanitizer whose selector grammar comes from MatcherFactoryExtender.
 *
 * TemplateStyles fixes the selector matcher before the stylesheet hook runs, so widening
 * MatcherFactoryExtender alone changes nothing and the rule sanitizer has to be replaced.
 *
 * prependSelectors is copied, never rebuilt: it carries the wrapper class the rule gets
 * scoped to, and the hook is not told which one that is.
 */
class StyleRuleSanitizerExtender extends StyleRuleSanitizer {

	public static function fromOriginal(
		StyleRuleSanitizer $original,
		MatcherFactoryExtender $factory
	): self {
		// Protected on the parent, which a subclass may read off a parent instance -- so no
		// reflection. Everything but the selector grammar comes from $original, which is
		// what makes this a like-for-like replacement.
		$sanitizer = new self( $factory->cssSelectorList(), $original->propertySanitizer, [
			'prependSelectors' => $original->prependSelectors,
		] );

		// Only if TemplateStyles decided on hoisting; that is its call, not ours.
		if ( $original->hoistableMatcher !== null ) {
			$sanitizer->hoistableMatcher = self::buildHoistableMatcher( $factory );
		}

		return $sanitizer;
	}

	/**
	 * Rebuild the hoisting matcher, which turns `html.night .card` into
	 * `html.night .mw-parser-output .card` rather than scoping it where it cannot match.
	 *
	 * Rebuilt rather than reused because the parent constructor parses the part after
	 * `html`/`body` with MatcherFactory::singleton(), hardcoded and Level 3 -- leaving
	 * `html.night .card:focus-within` accepted but unhoisted, and so silently dead.
	 *
	 * The html/body test is copied from TemplateStyles, which assembles it inline and never
	 * hands it over. It reads the 'element' capture, so a leading `:is()` does not hoist.
	 * CssSelectorCorpusTest::testScoping pins both.
	 */
	private static function buildHoistableMatcher( MatcherFactoryExtender $factory ): Matcher {
		$hoistableComponent = new CheckedMatcher(
			$factory->cssSimpleSelectorSeq(),
			static function ( ComponentValueList $values, GrammarMatch $match, array $options ) {
				foreach ( $match->getCapturedMatches() as $m ) {
					if ( $m->getName() !== 'element' ) {
						continue;
					}
					$str = (string)$m;

					return $str === 'html' || $str === 'body';
				}

				return false;
			}
		);

		$prefix = new Juxtaposition( [
			$hoistableComponent,
			Quantifier::star( new Juxtaposition( [
				$factory->significantWhitespace(),
				$hoistableComponent,
			] ) ),
		] );

		$matcher = new Juxtaposition( [
			$prefix->capture( 'prefix' ),
			$factory->significantWhitespace()->capture( 'ws' ),
			$factory->cssSelector()->capture( 'postfix' ),
		] );
		$matcher->setDefaultOptions( [ 'skip-whitespace' => false ] );

		return $matcher;
	}
}
