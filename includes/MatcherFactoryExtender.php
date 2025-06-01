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

use Wikimedia\CSS\Grammar\Alternative;
use Wikimedia\CSS\Grammar\CustomPropertyMatcher;
use Wikimedia\CSS\Grammar\DelimMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\NothingMatcher;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Objects\Token;

class MatcherFactoryExtender extends MatcherFactory {

	private bool $varEnabled = false;

	/**
	 * @param bool $varEnabled
	 * @return void
	 */
	public function setVarEnabled( bool $varEnabled ): void {
		$this->varEnabled = $varEnabled;
	}

	/**
	 * CSS-wide value keywords
	 * @see https://www.w3.org/TR/2016/CR-css-values-3-20160929/#common-keywords
	 */
	public function cssWideKeywords(): Matcher {
		return $this->cache[__METHOD__]
			??= new KeywordMatcher( [
				'initial', 'inherit', 'unset', 'revert', 'revert-layer'
			] );
	}

	/**
	 * CSS-color extension enabling RGBA
	 * @see https://developer.mozilla.org/en-US/docs/Web/CSS/hex-color
	 */
	public function color(): Matcher {
		return $this->cache[__METHOD__]
			??= new Alternative( [
				parent::color(),
				new TokenMatcher( Token::T_HASH, static function ( Token $t ) {
					return preg_match( '/^([0-9a-f]{4})|([0-9a-f]{8})$/i', $t->value() );
				} ),
				new FunctionMatcher( 'var', new CustomPropertyMatcher() )
			] );
	}

	/**
	 * Adds `var` support to color functions
	 * @return Matcher|Matcher[]
	 */
	protected function colorFuncs() {
		if ( !isset( $this->cache[__METHOD__] ) ) {
			$var = new FunctionMatcher( 'var', new CustomPropertyMatcher() );
			if ( !$this->varEnabled ) {
				$var = new NothingMatcher();
			}

			// This needs to be duplicated here from parent::color() as this function calls colorFuncs
			$colorNames = new Alternative( [
				new KeywordMatcher( [
					// Basic colors
					'aqua', 'black', 'blue', 'fuchsia', 'gray', 'green',
					'lime', 'maroon', 'navy', 'olive', 'purple', 'red',
					'silver', 'teal', 'white', 'yellow',
					// Extended colors
					'aliceblue', 'antiquewhite', 'aquamarine', 'azure',
					'beige', 'bisque', 'blanchedalmond', 'blueviolet', 'brown',
					'burlywood', 'cadetblue', 'chartreuse', 'chocolate',
					'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'cyan',
					'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray',
					'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta',
					'darkolivegreen', 'darkorange', 'darkorchid', 'darkred',
					'darksalmon', 'darkseagreen', 'darkslateblue',
					'darkslategray', 'darkslategrey', 'darkturquoise',
					'darkviolet', 'deeppink', 'deepskyblue', 'dimgray',
					'dimgrey', 'dodgerblue', 'firebrick', 'floralwhite',
					'forestgreen', 'gainsboro', 'ghostwhite', 'gold',
					'goldenrod', 'greenyellow', 'grey', 'honeydew', 'hotpink',
					'indianred', 'indigo', 'ivory', 'khaki', 'lavender',
					'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue',
					'lightcoral', 'lightcyan', 'lightgoldenrodyellow',
					'lightgray', 'lightgreen', 'lightgrey', 'lightpink',
					'lightsalmon', 'lightseagreen', 'lightskyblue',
					'lightslategray', 'lightslategrey', 'lightsteelblue',
					'lightyellow', 'limegreen', 'linen', 'magenta',
					'mediumaquamarine', 'mediumblue', 'mediumorchid',
					'mediumpurple', 'mediumseagreen', 'mediumslateblue',
					'mediumspringgreen', 'mediumturquoise', 'mediumvioletred',
					'midnightblue', 'mintcream', 'mistyrose', 'moccasin',
					'navajowhite', 'oldlace', 'olivedrab', 'orange',
					'orangered', 'orchid', 'palegoldenrod', 'palegreen',
					'paleturquoise', 'palevioletred', 'papayawhip',
					'peachpuff', 'peru', 'pink', 'plum', 'powderblue',
					'rosybrown', 'royalblue', 'saddlebrown', 'salmon',
					'sandybrown', 'seagreen', 'seashell', 'sienna', 'skyblue',
					'slateblue', 'slategray', 'slategrey', 'snow',
					'springgreen', 'steelblue', 'tan', 'thistle', 'tomato',
					'turquoise', 'violet', 'wheat', 'whitesmoke',
					'yellowgreen',
					// Other keywords. Intentionally omitting the deprecated system colors.
					'transparent', 'currentColor',
				] ),
				$var
			] );

			$i = $this->integer();
			$iVar = new Alternative( [ $var, $i ] );

			$n = $this->number();
			$nVar = new Alternative( [ $var, $n ] );

			$p = $this->percentage();
			$pVar = new Alternative( [ $var, $p ] );

			$channelNames = new KeywordMatcher( [
				'r', 'g', 'b',
				'x', 'y', 'z',
				'h', 's', 'l',
			] );

			$channelCalc = $this->calc( $channelNames, 'channels' );

			$channelValues = new Alternative( [
				$channelNames,
				$var,
				$this->number(),
				$this->percentage(),
				$this->integer(),
				$channelCalc,
			] );

			$relativeKeyWordMatcher = Quantifier::optional(
				new Juxtaposition( [ new KeywordMatcher( [ 'from' ] ), $colorNames ] )
			);
			$alphaMatcher = Quantifier::optional(
				new Juxtaposition( [ new DelimMatcher( '/' ), new Alternative( [ $nVar, $p ] ) ] ) );

			$colorSpace = new KeywordMatcher( [
				'srgb', 'srgb-linear', 'display-p3', 'a98-rgb',
				'prophoto-rgb', 'rec2020', 'xyz', 'xyz-d50', 'xyz-d65'
			] );

			$this->cache[__METHOD__] = [
				new FunctionMatcher( 'rgb', new Juxtaposition( [
					$relativeKeyWordMatcher,
					new Alternative( [
						Quantifier::hash( $iVar, 3, 3 ),
						Quantifier::hash( $pVar, 3, 3 ),
						Quantifier::hash( $var, 1, 3 ),
						Quantifier::count( $channelValues, 1, 3 ),
					] ),
					$alphaMatcher
				] ) ),

				new FunctionMatcher( 'rgba', new Juxtaposition( [
					$relativeKeyWordMatcher,
					new Alternative( [
						new Juxtaposition( [ $iVar, $iVar, $iVar, $nVar ], true ),
						new Juxtaposition( [ $pVar, $pVar, $pVar, $nVar ], true ),
						Quantifier::hash( $var, 1, 4 ),
						new Juxtaposition( [ Quantifier::hash( $var, 1, 3 ), $nVar ], true ),
					] ),
					$alphaMatcher
				] ) ),

				new FunctionMatcher( 'hsl', new Juxtaposition( [
					$relativeKeyWordMatcher,
					new Alternative( [
						new Juxtaposition( [ $nVar, $pVar, $pVar ], true ),
						Quantifier::hash( $var, 1, 3 ),
						Quantifier::count( $channelValues, 1, 3 ),
					] ),
					$alphaMatcher
				] ) ),

				new FunctionMatcher( 'hsla', new Juxtaposition( [
					$relativeKeyWordMatcher,
					new Alternative( [
						new Juxtaposition( [ $nVar, $pVar, $pVar, $nVar ], true ),
						Quantifier::hash( $var, 1, 4 ),
					] ),
					$alphaMatcher
				] ) ),

				new FunctionMatcher( 'color', new Alternative( [
					// Absolute
					new Juxtaposition( [
						$colorSpace,
						Quantifier::count( $channelValues, 3, 3 ),
						$alphaMatcher
					] ),

					// Relative
					new Juxtaposition( [
						new KeywordMatcher( [ 'from' ] ),
						$colorNames,
						$colorSpace,
						Quantifier::count( $channelValues, 3, 3 ),
						$alphaMatcher
					] ),
				] ) ),
			];
		}

		return $this->cache[__METHOD__];
	}

	/**
	 * Wraps the parent `calc` to allow using variables in the $typeMatcher
	 *
	 * @param Matcher $typeMatcher
	 * @param string $type
	 * @return Matcher
	 */
	public function calc( Matcher $typeMatcher, $type ) {
		if ( !$this->varEnabled ) {
			return parent::calc( $typeMatcher, $type );
		}

		return parent::calc( new Alternative( [
			$typeMatcher,
			new FunctionMatcher( 'var', new CustomPropertyMatcher() ),
		] ), $type );
	}

	/**
	 * Allow variables for numbers if enabled
	 * @return Alternative|Matcher|Matcher[]|TokenMatcher
	 */
   public function rawNumber() {
	   if ( !$this->varEnabled ) {
		   return parent::rawNumber();
	   }

	   return $this->cache[__METHOD__]
		   ??= new Alternative( [
			   new TokenMatcher( Token::T_NUMBER ),
			   new FunctionMatcher( 'var', new CustomPropertyMatcher() ),
		   ] );
   }

   /**
	* Backport Ratio values from master branch
	* This is not present in css-sanitizer 5.5.0
	*
	* @see https://github.com/wikimedia/css-sanitizer/commit/ffe10a21512f00405b4d0d124eb2c4866749e300
	*/
	public function ratio(): Matcher {
		// Use the parent method if it exists
		if ( method_exists( parent::class, 'ratio' ) ) {
			return parent::ratio();
		}

		return $this->cache[__METHOD__]
			// <ratio> = <number [0,∞]> [ / <number [0,∞]> ]?
			??= new Alternative( [
				$this->rawNumber(),
				new Juxtaposition( [
					$this->rawNumber(),
					$this->optionalWhitespace(),
					new DelimMatcher( [ '/' ] ),
					$this->optionalWhitespace(),
					$this->rawNumber(),
				] ),
			] );
	}
}
