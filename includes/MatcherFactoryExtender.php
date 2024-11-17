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

use MediaWiki\Extension\TemplateStylesExtender\Matcher\VarNameMatcher;
use Wikimedia\CSS\Grammar\Alternative;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Objects\Token;

// phpcs:disable
class MatcherFactoryExtender extends MatcherFactory {
	private static $extendedCssMediaQuery = false;

	/**
	 * CSS-wide value keywords
	 * @see https://www.w3.org/TR/2016/CR-css-values-3-20160929/#common-keywords
	 * @return Matcher
	 */
	public function cssWideKeywords()
	{
		if ( !isset( $this->cache[__METHOD__] ) ) {
			$this->cache[__METHOD__] = new KeywordMatcher( [ 'initial', 'inherit', 'unset', 'revert', 'revert-layer' ] );
		}
		return $this->cache[__METHOD__];
	}

	/**
	 * CSS-color extension enabling RGBA
	 * @see https://developer.mozilla.org/en-US/docs/Web/CSS/hex-color
	 * @return Matcher
	 */
	public function color()
	{
		if ( !isset( $this->cache[__METHOD__] ) ) {
			$color = new Alternative( [
				parent::color(),
				new TokenMatcher( Token::T_HASH, static function ( Token $t ) {
					return preg_match( '/^([0-9a-f]{4})|([0-9a-f]{8})$/i', $t->value() );
				} ),
			]);
			$this->cache[__METHOD__] = $color;
		}
		return $this->cache[__METHOD__];
	}

	/**
	 * Adds `var` support to color functions
	 * @return Matcher|Matcher[]
	 */
	protected function colorFuncs() {
		if ( !isset( $this->cache[__METHOD__] ) ) {
			$var = new FunctionMatcher( 'var', new VarNameMatcher() );

			$i = $this->integer();
			$iVar = new Alternative([ $var, $i ]);

			$n = $this->number();
			$nVar = new Alternative([ $var, $n ]);

			$p = $this->percentage();
			$pVar = new Alternative([ $var, $p ]);

			$this->cache[__METHOD__] = [
				new FunctionMatcher( 'rgb', new Alternative( [
					Quantifier::hash( $iVar, 3, 3 ),
					Quantifier::hash( $pVar, 3, 3 ),
					Quantifier::hash( $var, 1, 3 ),
				] ) ),
				new FunctionMatcher( 'rgba', new Alternative( [
					new Juxtaposition( [ $iVar, $iVar, $iVar, $nVar ], true ),
					new Juxtaposition( [ $pVar, $pVar, $pVar, $nVar ], true ),
					Quantifier::hash( $var, 1, 4 ),
					new Juxtaposition( [ Quantifier::hash( $var, 1, 3 ), $nVar ], true ),
				] ) ),
				new FunctionMatcher( 'hsl', new Alternative([
					new Juxtaposition( [ $nVar, $pVar, $pVar ], true ),
					Quantifier::hash($var, 1, 3),
				]) ),
				new FunctionMatcher( 'hsla', new Alternative([
					new Juxtaposition( [ $nVar, $pVar, $pVar, $nVar ], true ),
					Quantifier::hash($var, 1, 4),
				]) ),
			];
		}
		return $this->cache[__METHOD__];
	}
}
