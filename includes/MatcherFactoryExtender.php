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
use Wikimedia\CSS\Grammar\AnythingMatcher;
use Wikimedia\CSS\Grammar\BlockMatcher;
use Wikimedia\CSS\Grammar\DelimMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\NothingMatcher;
use Wikimedia\CSS\Grammar\NoWhitespace;
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
	 * This is in reality a complete copy of the parent hook with line 68 and 110 extended
	 * This can very easily break if there is an update upstream
	 *
	 * @inheritDoc
	 * T241946
	 */
	public function cssMediaQuery( $strict = true ) {
		$key = __METHOD__ . ':' . ( $strict ? 'strict' : 'unstrict' );
		if ( self::$extendedCssMediaQuery === false || !isset( $this->cache[$key] ) ) {
			if ( $strict ) {
				$generalEnclosed = new NothingMatcher();

				$mediaType = new KeywordMatcher( [
					'all', 'print', 'screen', 'speech',
					// deprecated
					'tty', 'tv', 'projection', 'handheld', 'braille', 'embossed', 'aural'
				] );

				$rangeFeatures = [
					'width', 'height', 'aspect-ratio', 'resolution', 'color', 'color-index', 'monochrome',
					// deprecated
					'device-width', 'device-height', 'device-aspect-ratio'
				];
				$discreteFeatures = [
					'orientation', 'scan', 'grid', 'update', 'overflow-block', 'overflow-inline', 'color-gamut',
					'pointer', 'hover', 'any-pointer', 'any-hover', 'scripting', 'prefers-color-scheme'
				];
				$mfName = new KeywordMatcher( array_merge(
					$rangeFeatures,
					array_map( function ( $f ) {
						return "min-$f";
					}, $rangeFeatures ),
					array_map( function ( $f ) {
						return "max-$f";
					}, $rangeFeatures ),
					$discreteFeatures
				) );
			} else {
				$anythingPlus = new AnythingMatcher( [ 'quantifier' => '+' ] );
				$generalEnclosed = new Alternative( [
					new FunctionMatcher( null, $anythingPlus ),
					new BlockMatcher( Token::T_LEFT_PAREN,
						new Juxtaposition( [ $this->ident(), $anythingPlus ] )
					),
				] );
				$mediaType = $this->ident();
				$mfName = $this->ident();
			}

			$posInt = $this->calc(
				new TokenMatcher( Token::T_NUMBER, function ( Token $t ) {
					return $t->typeFlag() === 'integer' && preg_match( '/^\+?\d+$/', $t->representation() );
				} ),
				'integer'
			);
			$eq = new DelimMatcher( '=' );
			$oeq = Quantifier::optional( new Juxtaposition( [ new NoWhitespace, $eq ] ) );
			$ltgteq = Quantifier::optional( new Alternative( [
				$eq,
				new Juxtaposition( [ new DelimMatcher( [ '<', '>' ] ), $oeq ] ),
			] ) );
			$lteq = new Juxtaposition( [ new DelimMatcher( '<' ), $oeq ] );
			$gteq = new Juxtaposition( [ new DelimMatcher( '>' ), $oeq ] );
			$mfValue = new Alternative( [
				$this->number(),
				$this->dimension(),
				$this->ident(),
				new KeywordMatcher( [ 'light', 'dark' ] ),
				new Juxtaposition( [ $posInt, new DelimMatcher( '/' ), $posInt ] ),
			] );

			$mediaInParens = new NothingMatcher(); // temporary
			$mediaNot = new Juxtaposition( [ new KeywordMatcher( 'not' ), &$mediaInParens ] );
			$mediaAnd = new Juxtaposition( [ new KeywordMatcher( 'and' ), &$mediaInParens ] );
			$mediaOr = new Juxtaposition( [ new KeywordMatcher( 'or' ), &$mediaInParens ] );
			$mediaCondition = new Alternative( [
				$mediaNot,
				new Juxtaposition( [
					&$mediaInParens,
					new Alternative( [
						Quantifier::star( $mediaAnd ),
						Quantifier::star( $mediaOr ),
					] )
				] ),
			] );
			$mediaConditionWithoutOr = new Alternative( [
				$mediaNot,
				new Juxtaposition( [ &$mediaInParens, Quantifier::star( $mediaAnd ) ] ),
			] );
			$mediaFeature = new BlockMatcher( Token::T_LEFT_PAREN, new Alternative( [
				new Juxtaposition( [ $mfName, new TokenMatcher( Token::T_COLON ), $mfValue ] ), // <mf-plain>
				$mfName, // <mf-boolean>
				new Juxtaposition( [ $mfName, $ltgteq, $mfValue ] ), // <mf-range>, 1st alternative
				new Juxtaposition( [ $mfValue, $ltgteq, $mfName ] ), // <mf-range>, 2nd alternative
				new Juxtaposition( [ $mfValue, $lteq, $mfName, $lteq, $mfValue ] ), // <mf-range>, 3rd alt
				new Juxtaposition( [ $mfValue, $gteq, $mfName, $gteq, $mfValue ] ), // <mf-range>, 4th alt
			] ) );
			$mediaInParens = new Alternative( [
				new BlockMatcher( Token::T_LEFT_PAREN, $mediaCondition ),
				$mediaFeature,
				$generalEnclosed,
			] );

			$this->cache[$key] = new Alternative( [
				$mediaCondition,
				new Juxtaposition( [
					Quantifier::optional( new KeywordMatcher( [ 'not', 'only' ] ) ),
					$mediaType,
					Quantifier::optional( new Juxtaposition( [
						new KeywordMatcher( 'and' ),
						$mediaConditionWithoutOr,
					] ) )
				] )
			] );
		}

		self::$extendedCssMediaQuery = true;

		return $this->cache[$key];
	}
}
