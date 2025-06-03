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
	 * Add revert-layer to the list of CSS-wide value keywords
	 * @inheritDoc
	 */
	public function cssWideKeywords(): Matcher {
		return $this->cache[__METHOD__]
			??= new KeywordMatcher( [
				'initial', 'inherit', 'unset', 'revert', 'revert-layer'
			] );
	}

	/**
	 * Add alpha support to hex-color
	 */
	public function colorHex(): TokenMatcher {
		return $this->cache[__METHOD__]
			??= new TokenMatcher( Token::T_HASH, static function ( Token $t ) {
				return preg_match( '/^([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $t->value() );
			} );
	}

	/**
	 * Partially implements CSS Color Module Level 4 and 5
	 *
	 * TODO: Add support for modern syntax
	 * TODO: Add new color functions
	 * TODO: Tighten up relative color syntax
	 *
	 * @return Matcher|Matcher[]
	 */
	protected function colorFuncs() {
		if ( !isset( $this->cache[__METHOD__] ) ) {
			// Allow variables in color functions
			$var = $this->varEnabled
				? new FunctionMatcher( 'var', new CustomPropertyMatcher() )
				: new NothingMatcher();

			// CSS data types
			$n = new Alternative( [ $this->number(), $var ] );
			$p = new Alternative( [ $this->percentage(), $var ] );
			$a = new Alternative( [ $this->angle(), $var ] );
			$alphaValue = new Alternative( [ $n, $p ] );
			$hue = new Alternative( [ $n, $a ] );

			$none = new KeywordMatcher( [ 'none' ] );
			$nPNone = new Alternative( [ $n, $p, $none ] );
			$hueNone = new Alternative( [ $hue, $none ] );

			$colorSpaceParams = new Alternative( [
				// <predefined-rgb-params>
				new Juxtaposition( [
					new KeywordMatcher( [
						'srgb', 'srgb-linear', 'display-p3', 'a98-rgb', 'prophoto-rgb',
						'rec2020', 'rec2100-pq', 'rec2100-hlg', 'rec2100-linear'
					] ),
					Quantifier::count( $nPNone, 3, 3 ),
				] ),
				// <xyz-params> 
				new Juxtaposition( [
					new KeywordMatcher( [ 'xyz', 'xyz-d50', 'xyz-d65' ] ),
					Quantifier::count( $nPNone, 3, 3 ),
				] ),
			] );

			// $colorWord = new Alternative( [ $this->colorWords(), $var ] );
			$optionalAlpha = Quantifier::optional( new Juxtaposition( [ new DelimMatcher( '/' ), new Alternative( [ $alphaValue, $none ] ) ] ) );
			$optionalLegacyAlpha = Quantifier::optional( $alphaValue );

			// Color functions
			$rgb = new Alternative( [
				// <legacy-rgb-syntax>
				new Juxtaposition( [ Quantifier::hash( $p, 3, 3 ), $optionalLegacyAlpha ], true ),
				new Juxtaposition( [ Quantifier::hash( $n, 3, 3 ), $optionalLegacyAlpha ], true ),
				// <modern-rgb-syntax>
				new Juxtaposition( [ Quantifier::count( $nPNone, 3, 3 ), $optionalAlpha ] ),
			] );
			$hsl = new Alternative( [
				// <legacy-hsl-syntax>
				new Juxtaposition( [ $hue, $p, $p, $optionalLegacyAlpha ], true ),
				// <modern-hsl-syntax>
				new Juxtaposition( [ $hueNone, $nPNone, $nPNone, $optionalAlpha ] ),
			] );
			$hwb = new Juxtaposition( [ $hueNone, $nPNone, $nPNone, $optionalAlpha ] );
			$lab = new Juxtaposition( [ $nPNone, $nPNone, $nPNone, $optionalAlpha ] );
			$lch = new Juxtaposition( [ $nPNone, $nPNone, $hueNone, $optionalAlpha ] );
			$color = new Juxtaposition( [ $colorSpaceParams, $optionalAlpha ] );

			$this->cache[__METHOD__] = [
				new FunctionMatcher( 'rgb', $rgb ),
				new FunctionMatcher( 'rgba', $rgb ),
				new FunctionMatcher( 'hsl', $hsl ),
				new FunctionMatcher( 'hsla', $hsl ),
				new FunctionMatcher( 'hwb', $hwb ),
				new FunctionMatcher( 'lab', $lab ),
				new FunctionMatcher( 'lch', $lch ),
				new FunctionMatcher( 'oklab', $lab ),
				new FunctionMatcher( 'oklch', $lch ),
				new FunctionMatcher( 'color', $color )
			];
		}

		return $this->cache[__METHOD__];
	}

	/** @inheritDoc */
	public function resolution(): Matcher {
		return $this->cache[__METHOD__]
			??= new TokenMatcher( Token::T_DIMENSION, static function ( Token $t ) {
				return preg_match( '/^(dpi|dpcm|dppx|x)$/i', $t->unit() );
			} );
	}

	/**
	 * Partially implements CSS Image Module Level 4
	 */
	public function image(): Matcher {
		if ( isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}

		$image = parent::image();

		$this->cache[__METHOD__] = new Alternative( [
			$image,
			new FunctionMatcher( 'image-set', Quantifier::hash( new Juxtaposition( [
				new Alternative( [ $image, $this->urlstring( 'image' ) ] ),
				new Alternative( [ $this->resolution(), new FunctionMatcher( 'type', $this->string() ) ] )
			] ) ) ),
		] );

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
