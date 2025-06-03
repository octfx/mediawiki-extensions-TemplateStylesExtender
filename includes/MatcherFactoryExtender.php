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
	 * @return Matcher|Matcher[]
	 */
	protected function colorFuncs() {
		if ( isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}

		// Common var matcher
		$var = $this->varEnabled
			? new FunctionMatcher( 'var', new CustomPropertyMatcher() )
			: new NothingMatcher();

		$n = new Alternative( [ $this->number(), $var ] );
		$p = new Alternative( [ $this->percentage(), $var ] );
		$a = new Alternative( [ $this->angle(), $var ] );
		$nP = new Alternative( [ $n, $p ] );
		$hueWithVar = new Alternative( [ $n, $a ] );

		$none = new KeywordMatcher( [ 'none' ] );
		$nPNone = new Alternative( [ $n, $p, $none ] );
		$hueNone = new Alternative( [ $hueWithVar, $none ] );

		// Colorspace keywords
		$predefinedRgb = new KeywordMatcher( [
			'srgb', 'srgb-linear', 'display-p3', 'a98-rgb', 'prophoto-rgb',
			'rec2020', 'rec2100-pq', 'rec2100-hlg', 'rec2100-linear'
		] );
		$xyzSpace = new KeywordMatcher( [ 'xyz', 'xyz-d50', 'xyz-d65' ] );

		// Alpha matchers
		$optionalAlpha = Quantifier::optional( new Juxtaposition(
			[ new DelimMatcher( '/' ), new Alternative( [ $nP, $none ] ) ]
		) );
		$optionalLegacyAlpha = Quantifier::optional( $nP );

		// Absolute color syntaxes
		$rgbSyntax = $this->buildRgbSyntax( $n, $p, $nPNone, $optionalAlpha, $optionalLegacyAlpha );
		$hslSyntax = $this->buildHslSyntax( $hueWithVar, $p, $hueNone, $nPNone, $optionalAlpha, $optionalLegacyAlpha );
		$hwbSyntax = $this->buildStandardColorSyntax( [ $hueNone, $nPNone, $nPNone ], $optionalAlpha );
		$labSyntax = $this->buildStandardColorSyntax( [ $nPNone, $nPNone, $nPNone ], $optionalAlpha );
		$lchSyntax = $this->buildStandardColorSyntax( [ $nPNone, $nPNone, $hueNone ], $optionalAlpha );

		$colorSpaceParamsForColorFunc = new Alternative( [
			new Juxtaposition( [
				new Alternative( [ $predefinedRgb, $xyzSpace ] ),
				Quantifier::count( $nPNone, 3, 3 ),
			] )
		] );
		$colorFuncSyntax = $this->buildStandardColorSyntax( [ $colorSpaceParamsForColorFunc ], $optionalAlpha );

		$absoluteColorFuncs = [
			new FunctionMatcher( 'rgb', $rgbSyntax ),
			new FunctionMatcher( 'rgba', $rgbSyntax ),
			new FunctionMatcher( 'hsl', $hslSyntax ),
			new FunctionMatcher( 'hsla', $hslSyntax ),
			new FunctionMatcher( 'hwb', $hwbSyntax ),
			new FunctionMatcher( 'lab', $labSyntax ),
			new FunctionMatcher( 'lch', $lchSyntax ),
			new FunctionMatcher( 'oklab', $labSyntax ),
			new FunctionMatcher( 'oklch', $lchSyntax ),
			new FunctionMatcher( 'color', $colorFuncSyntax )
		];

		// Relative color syntax components
		$originColor = new Alternative( [
			$this->colorWords(),
			$this->colorHex(),
			$var,
			...$absoluteColorFuncs
		] );

		$optionalAlphaCalc = new Alternative( [
			$optionalAlpha,
			Quantifier::optional( new Juxtaposition( [
				new DelimMatcher( '/' ),
				$this->calc( new KeywordMatcher( [ 'alpha' ] ), 'number' )
			] ) )
		] );

		// Component definitions for relative colors
		$relativeRgbComponents = [
			$this->createRelativeColorChannel( 'r', $nPNone ),
			$this->createRelativeColorChannel( 'g', $nPNone ),
			$this->createRelativeColorChannel( 'b', $nPNone ),
		];
		$relativeHslComponents = [
			$this->createRelativeColorChannel( 'h', $hueNone ),
			$this->createRelativeColorChannel( 's', $nPNone ),
			$this->createRelativeColorChannel( 'l', $nPNone ),
		];
		$relativeHwbComponents = [
			$this->createRelativeColorChannel( 'h', $hueNone ),
			$this->createRelativeColorChannel( 'w', $nPNone ),
			$this->createRelativeColorChannel( 'b', $nPNone ),
		];
		// For lab and oklab
		$relativeLabComponents = [
			$this->createRelativeColorChannel( 'l', $nPNone ),
			$this->createRelativeColorChannel( 'a', $nPNone ),
			$this->createRelativeColorChannel( 'b', $nPNone ),
		];
		// For lch and oklch
		$relativeLchComponents = [
			$this->createRelativeColorChannel( 'l', $nPNone ),
			$this->createRelativeColorChannel( 'c', $nPNone ),
			$this->createRelativeColorChannel( 'h', $hueNone ),
		];
		$relativeColorFuncColorComponents = [
			new Alternative( [
				new Juxtaposition( [
					$predefinedRgb,
					$this->createRelativeColorChannel( 'r', $nPNone ),
					$this->createRelativeColorChannel( 'g', $nPNone ),
					$this->createRelativeColorChannel( 'b', $nPNone ),
				] ),
				new Juxtaposition( [
					$xyzSpace,
					$this->createRelativeColorChannel( 'x', $nPNone ),
					$this->createRelativeColorChannel( 'y', $nPNone ),
					$this->createRelativeColorChannel( 'z', $nPNone ),
				] )
			] )
		];

		$relativeColorFuncs = [
			new FunctionMatcher( 'rgb',
				$this->buildRelativeColorSyntax( $originColor, $relativeRgbComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'hsl',
				$this->buildRelativeColorSyntax( $originColor, $relativeHslComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'hwb',
				$this->buildRelativeColorSyntax( $originColor, $relativeHwbComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'lab',
				$this->buildRelativeColorSyntax( $originColor, $relativeLabComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'lch',
				$this->buildRelativeColorSyntax( $originColor, $relativeLchComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'oklab',
				$this->buildRelativeColorSyntax( $originColor, $relativeLabComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'oklch',
				$this->buildRelativeColorSyntax( $originColor, $relativeLchComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'color',
				$this->buildRelativeColorSyntax( $originColor, $relativeColorFuncColorComponents, $optionalAlphaCalc )
			),
		];

		$this->cache[__METHOD__] = [
			...$absoluteColorFuncs,
			...$relativeColorFuncs
		];

		return $this->cache[__METHOD__];
	}

	/**
	 * Helper to build the syntax for rgb() and rgba() functions.
	 * @param Matcher $n Number matcher (including var)
	 * @param Matcher $p Percentage matcher (including var)
	 * @param Matcher $nPNone Number, percentage, or none matcher (including var)
	 * @param Matcher $optionalAlpha Modern alpha syntax matcher
	 * @param Matcher $optionalLegacyAlpha Legacy alpha syntax matcher
	 * @return Alternative
	 */
	protected function buildRgbSyntax(
		Matcher $n,
		Matcher $p,
		Matcher $nPNone,
		Matcher $optionalAlpha,
		Matcher $optionalLegacyAlpha
	): Alternative {
		return new Alternative( [
			// <legacy-rgb-syntax>
			new Juxtaposition( [ Quantifier::hash( $p, 3, 3 ), $optionalLegacyAlpha ], true ),
			new Juxtaposition( [ Quantifier::hash( $n, 3, 3 ), $optionalLegacyAlpha ], true ),
			// <modern-rgb-syntax>
			new Juxtaposition( [ Quantifier::count( $nPNone, 3, 3 ), $optionalAlpha ] ),
		] );
	}

	/**
	 * Helper to build the syntax for hsl() and hsla() functions.
	 * @param Matcher $hueWithVar Hue matcher (number, angle, including var)
	 * @param Matcher $p Percentage matcher (including var)
	 * @param Matcher $hueNone Hue or none matcher (including var)
	 * @param Matcher $nPNone Number, percentage, or none matcher (including var)
	 * @param Matcher $optionalAlpha Modern alpha syntax matcher
	 * @param Matcher $optionalLegacyAlpha Legacy alpha syntax matcher
	 * @return Alternative
	 */
	protected function buildHslSyntax(
		Matcher $hueWithVar,
		Matcher $p,
		Matcher $hueNone,
		Matcher $nPNone,
		Matcher $optionalAlpha,
		Matcher $optionalLegacyAlpha
	): Alternative {
		return new Alternative( [
			// <legacy-hsl-syntax>
			new Juxtaposition( [ $hueWithVar, $p, $p, $optionalLegacyAlpha ], true ),
			// <modern-hsl-syntax>
			new Juxtaposition( [ $hueNone, $nPNone, $nPNone, $optionalAlpha ] ),
		] );
	}

	/**
	 * Helper to build standard color syntax (components + optional alpha).
	 * @param Matcher[] $components
	 * @param Matcher $optionalAlpha
	 * @return Juxtaposition
	 */
	protected function buildStandardColorSyntax( array $components, Matcher $optionalAlpha ): Juxtaposition {
		return new Juxtaposition( [ ...$components, $optionalAlpha ] );
	}

	/**
	 * Helper to create a relative color channel matcher.
	 * @param string $channel Character representing the channel (e.g., 'r', 'h')
	 * @param Matcher $typeMatcher Matcher for the channel's type (e.g., $nPNone, $hueNone)
	 * @param string $calcType Type for calc() function, defaults to 'number'
	 * @return Alternative
	 */
	protected function createRelativeColorChannel(
		string $channel,
		Matcher $typeMatcher,
		string $calcType = 'number'
	): Alternative {
		return new Alternative( [
			new KeywordMatcher( [ $channel ] ),
			$typeMatcher,
			$this->calc( new KeywordMatcher( [ $channel ] ), $calcType )
		] );
	}

	/**
	 * Helper to build the Juxtaposition for relative color syntax.
	 * @param Matcher $originColor Matcher for the base color
	 * @param Matcher[] $componentMatchers Array of matchers for the color components
	 * @param Matcher $optionalAlphaCalc Matcher for the optional alpha calculation
	 * @return Juxtaposition
	 */
	protected function buildRelativeColorSyntax(
		Matcher $originColor,
		array $componentMatchers,
		Matcher $optionalAlphaCalc
	): Juxtaposition {
		return new Juxtaposition( [
			new KeywordMatcher( [ 'from' ] ),
			$originColor,
			...$componentMatchers,
			$optionalAlphaCalc
		] );
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
