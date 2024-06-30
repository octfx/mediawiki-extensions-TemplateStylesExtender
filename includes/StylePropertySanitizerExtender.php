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
use Wikimedia\CSS\Grammar\BlockMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Grammar\UnorderedGroup;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Declaration;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class StylePropertySanitizerExtender extends StylePropertySanitizer {

	private static $extendedCssText3 = false;
	private static $extendedCssBorderBackground = false;
	private static $extendedCssSizingAdditions = false;
	private static $extendedCssSizing3 = false;
	private static $extendedCss1Masking = false;
	private static $extendedCss1Grid = false;

	/**
	 * @param MatcherFactory $matcherFactory
	 */
	public function __construct( MatcherFactory $matcherFactory ) {
		parent::__construct( new MatcherFactoryExtender() );
	}

	/**
	 * @inheritDoc
	 * Allow overflow-wrap: anywhere
	 *
	 * T255343
	 */
	protected function cssText3( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCssText3 && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$props = parent::cssText3( $matcherFactory );

		$props['overflow-wrap'] = new Alternative( [
			new KeywordMatcher( [ 'normal' ] ),
			UnorderedGroup::someOf( [
				new KeywordMatcher( [ 'break-word' ] ),
				new KeywordMatcher( [ 'break-spaces' ] ),
				new KeywordMatcher( [ 'anywhere' ] ),
			] )
		] );

		$this->cache[__METHOD__] = $props;
		self::$extendedCssText3 = true;

		return $props;
	}

	/**
	 * @inheritDoc
	 * Allow rgba syntax like #aaaaaaaa
	 *
	 * T265675
	 */
	protected function cssBorderBackground3( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCssBorderBackground && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$props = parent::cssBorderBackground3( $matcherFactory );

		$props['border'] = UnorderedGroup::someOf( [
			new KeywordMatcher( [
				'none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'
			] ),
			new Alternative( [
				new KeywordMatcher( [ 'thin', 'medium', 'thick' ] ), $matcherFactory->length(),
			] ),
			new Alternative( [
				$matcherFactory->color(),
				new FunctionMatcher( 'var', new VarNameMatcher() ),
			] )
		] );

		$props['box-shadow'] = new Alternative( [
			new KeywordMatcher( 'none' ),
			Quantifier::hash( UnorderedGroup::allOf( [
				Quantifier::optional( new KeywordMatcher( 'inset' ) ),
				Quantifier::count( $matcherFactory->length(), 2, 4 ),
				Quantifier::optional( new Alternative( [
					$matcherFactory->color(),
					new FunctionMatcher( 'var', new VarNameMatcher() ),
				] ) ),
			] ) )
		] );

		$this->cache[__METHOD__] = $props;
		self::$extendedCssBorderBackground = true;

		return $props;
	}

	/**
	 * @inheritDoc
	 *
	 * Partly implement clamp
	 */
	protected function getSizingAdditions( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCssSizingAdditions && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$props = parent::getSizingAdditions( $matcherFactory );

		$props[] = new FunctionMatcher( 'clamp', Quantifier::hash( new Alternative( [
			$matcherFactory->length(),
			$matcherFactory->lengthPercentage(),
			$matcherFactory->frequency(),
			$matcherFactory->angle(),
			$matcherFactory->anglePercentage(),
			$matcherFactory->time(),
			$matcherFactory->number(),
			$matcherFactory->integer(),
		] ), 3, 3 ) );

		$this->cache[__METHOD__] = $props;

		self::$extendedCssSizingAdditions = true;

		return $this->cache[__METHOD__];
	}

	/**
	 * @inheritDoc
	 * Allow width: fit-content
	 *
	 * T271958
	 */
	protected function cssSizing3( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCssSizing3 && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$props = parent::cssSizing3( $matcherFactory );

		$props['width'] = new Alternative( [
			$props['width'],
			new KeywordMatcher( 'fit-content' )
		] );

		$this->cache[__METHOD__] = $props;
		self::$extendedCssSizing3 = true;

		return $props;
	}

	/**
	 * @inheritDoc
	 *
	 * Add webkit prefix for mask-image
	 */
	protected function cssMasking1( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCss1Masking && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$props = parent::cssMasking1( $matcherFactory );

		$props['-webkit-mask-image'] = $props['mask-image'];

		$this->cache[__METHOD__] = $props;
		self::$extendedCss1Masking = true;

		return $props;
	}

	/**
	 * @inheritDoc
	 *
	 * Allow variables in grid-template-columns
	 */
	protected function cssGrid1( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCss1Grid && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$var = new FunctionMatcher( 'var', new VarNameMatcher() );

		$props = parent::cssGrid1( $matcherFactory );
		$comma = $matcherFactory->comma();
		$customIdent = $matcherFactory->customIdent( [ 'span' ] );
		$lineNamesO = Quantifier::optional( new BlockMatcher(
			Token::T_LEFT_BRACKET, Quantifier::star( $customIdent )
		) );
		$trackBreadth = new Alternative( [
			$matcherFactory->lengthPercentage(),
			new TokenMatcher( Token::T_DIMENSION, static function ( Token $t ) {
				return $t->value() >= 0 && !strcasecmp( $t->unit(), 'fr' );
			} ),
			new KeywordMatcher( [ 'min-content', 'max-content', 'auto' ] ),
			$var
		] );
		$inflexibleBreadth = new Alternative( [
			$matcherFactory->lengthPercentage(),
			new KeywordMatcher( [ 'min-content', 'max-content', 'auto' ] ),
			$var
		] );
		$fixedBreadth = $matcherFactory->lengthPercentage();
		$trackSize = new Alternative( [
			$trackBreadth,
			new FunctionMatcher( 'minmax',
				new Juxtaposition( [ $inflexibleBreadth, $trackBreadth ], true )
			),
			new FunctionMatcher( 'fit-content', $matcherFactory->lengthPercentage() ),
			$var
		] );
		$fixedSize = new Alternative( [
			$fixedBreadth,
			new FunctionMatcher( 'minmax', new Juxtaposition( [ $fixedBreadth, $trackBreadth ], true ) ),
			new FunctionMatcher( 'minmax',
				new Juxtaposition( [ $inflexibleBreadth, $fixedBreadth ], true )
			),
			$var
		] );
		$trackRepeat = new FunctionMatcher( 'repeat', new Juxtaposition( [
			new Alternative( [ $matcherFactory->integer(), $var ] ),
			$comma,
			Quantifier::plus( new Juxtaposition( [ $lineNamesO, $trackSize ] ) ),
			$lineNamesO
		] ) );
		$autoRepeat = new FunctionMatcher( 'repeat', new Juxtaposition( [
			new Alternative( [ new KeywordMatcher( [ 'auto-fill', 'auto-fit' ] ), $var ] ),
			$comma,
			Quantifier::plus( new Juxtaposition( [ $lineNamesO, $fixedSize ] ) ),
			$lineNamesO
		] ) );
		$fixedRepeat = new FunctionMatcher( 'repeat', new Juxtaposition( [
			$matcherFactory->integer(),
			$comma,
			Quantifier::plus( new Juxtaposition( [ $lineNamesO, $fixedSize ] ) ),
			$lineNamesO
		] ) );
		$trackList = new Juxtaposition( [
			Quantifier::plus( new Juxtaposition( [
				$lineNamesO, new Alternative( [ $trackSize, $trackRepeat ] )
			] ) ),
			$lineNamesO
		] );
		$autoTrackList = new Juxtaposition( [
			Quantifier::star( new Juxtaposition( [
				$lineNamesO, new Alternative( [ $fixedSize, $fixedRepeat ] )
			] ) ),
			$lineNamesO,
			$autoRepeat,
			Quantifier::star( new Juxtaposition( [
				$lineNamesO, new Alternative( [ $fixedSize, $fixedRepeat ] )
			] ) ),
			$lineNamesO,
		] );

		$props['grid-template-columns'] = new Alternative( [
			new KeywordMatcher( 'none' ), $trackList, $autoTrackList
		] );
		$props['grid-template-rows'] = $props['grid-template-columns'];

		$this->cache[__METHOD__] = $props;
		return $props;
	}

	/**
	 * @param CSSObject $object
	 * @return CSSObject|Declaration|null
	 */
	protected function doSanitize( CSSObject $object ) {
		if ( strpos( $object->getName(), '--' ) !== 0 ) {
			return parent::doSanitize( $object );
		}

		$this->clearSanitizationErrors();
		return $object;
	}
}
