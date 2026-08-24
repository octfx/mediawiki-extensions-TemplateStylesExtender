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
use Wikimedia\CSS\Grammar\BlockMatcher;
use Wikimedia\CSS\Grammar\CustomPropertyMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Declaration;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class StylePropertySanitizerExtender extends StylePropertySanitizer {

	private const EXTERNAL_RESOURCE_FUNCTIONS = [
		'attr',
		'image',
		'image-set',
		'-webkit-image-set',
		'src',
		'url',
	];

	private bool $varEnabled = false;

	/**
	 * When true, the a9043c4 external-resource rejection below is skipped, so a custom
	 * property may hold url()/image-set()/etc. and CSP is relied on instead. See #45.
	 */
	private bool $allowExternalResources = false;

	private static bool $extendedCss1Masking = false;
	private static bool $extendedCss3Grid = false;
	private static bool $extendedCss3Backgrounds = false;

	public function __construct( MatcherFactory $matcherFactory ) {
		$extendedMatcherFactory = $matcherFactory instanceof MatcherFactoryExtender
			? $matcherFactory
			: new MatcherFactoryExtender( $matcherFactory );

		parent::__construct( $extendedMatcherFactory );
	}

	public function setVarEnabled( bool $varEnabled ): void {
		$this->varEnabled = $varEnabled;
	}

	/**
	 * Permit external-resource functions (url, image-set, ...) inside custom-property
	 * values, lifting the rejection wholesale. Only for deployments whose CSP governs
	 * these fetches -- see the config documentation.
	 */
	public function setAllowExternalResources( bool $allow ): void {
		$this->allowExternalResources = $allow;
	}

	/**
	 * @inheritDoc
	 *
	 * Let border-color take a light-dark().
	 *
	 * Upstream builds it from safeColor() rather than color(), because the property
	 * concatenates up to four colours and a var() could expand to more than one of them.
	 * That reasoning does not reach light-dark(), which is a single function and yields
	 * exactly one colour whatever it holds, so it is refused here only as a side effect of
	 * keeping var() out. The per-side longhands, which do not concatenate, take it already.
	 */
	protected function cssBackgrounds3( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCss3Backgrounds && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$props = parent::cssBackgrounds3( $matcherFactory );

		$factory = $matcherFactory instanceof MatcherFactoryExtender
			? $matcherFactory
			: new MatcherFactoryExtender( $matcherFactory );

		// Still no bare var(), which is the part of upstream's caution that holds.
		$props['border-color'] = Quantifier::count( new Alternative( [
			$factory->safeColor(),
			$factory->lightDark(),
		] ), 1, 4 );

		$this->cache[__METHOD__] = $props;
		self::$extendedCss3Backgrounds = true;

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
	 * Allow variables in grid-template-columns and grid-template-rows
	 */
	protected function cssGrid1( MatcherFactory $matcherFactory ) {
		// @codeCoverageIgnoreStart
		if ( self::$extendedCss3Grid && isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}
		// @codeCoverageIgnoreEnd

		$var = new FunctionMatcher( 'var', new CustomPropertyMatcher() );

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

		$subgrid = new Juxtaposition( [ $lineNamesO, new KeywordMatcher( 'subgrid' ), $lineNamesO ] );

		$props['grid-template-columns'] = new Alternative( [
			new KeywordMatcher( [ 'none', 'masonry' ] ),
			$trackList,
			$autoTrackList,
			$subgrid,
		] );
		$props['grid-template-rows'] = $props['grid-template-columns'];

		$props['masonry-auto-flow'] = new Juxtaposition( [
			new KeywordMatcher( [ 'pack', 'next' ] ),
			Quantifier::optional( new KeywordMatcher( 'definite-first' ) )
		] );

		$this->cache[__METHOD__] = $props;
		self::$extendedCss3Grid = true;

		return $props;
	}

	/**
	 * @inheritDoc
	 */
	protected function doSanitize( CSSObject $object ) {
		if ( !$this->varEnabled || !$object instanceof Declaration ) {
			return parent::doSanitize( $object );
		}

		$name = $object->getName();

		// Not a CSS custom property
		if ( !str_starts_with( $name, '--' ) ) {
			return parent::doSanitize( $object );
		}

		if ( !$this->allowExternalResources ) {
			foreach ( $object->toTokenArray() as $token ) {
				if (
					$token->type() === Token::T_URL ||
					$token->type() === Token::T_BAD_URL ||
					(
						$token->type() === Token::T_FUNCTION &&
						in_array( strtolower( (string)$token->value() ), self::EXTERNAL_RESOURCE_FUNCTIONS, true )
					)
				) {
					$this->sanitizationError( 'bad-value-for-property', $token, [ $name ] );
					return null;
				}
			}
		}

		// Deliberately no clearSanitizationErrors(): the early return above means nothing
		// here needs clearing, and it would discard earlier declarations' errors.
		// @phan-suppress-next-line PhanTypeMismatchReturn generics weakness, see parent
		return $object;
	}
}
