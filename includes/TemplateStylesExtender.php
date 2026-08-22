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

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\MediaWikiServices;
use Wikimedia\CSS\Grammar\Alternative;
use Wikimedia\CSS\Grammar\CheckedMatcher;
use Wikimedia\CSS\Grammar\CustomPropertyMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\GrammarMatch;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class TemplateStylesExtender {

	private static ?Config $config = null;

	/**
	 * Whole-value matcher for a declaration that contains a var().
	 *
	 * TemplateStyles reaches this only once the property's own grammar has refused the
	 * value. It is not told which property it is on, so it checks nothing against one --
	 * which is why it applies only where a var() is present. Without one the property's
	 * own grammar is the better judge, and is left to be it.
	 *
	 * Every alternative in the list must consume exactly one component value. A
	 * variable-length one makes the Quantifier::plus below enumerate every way of
	 * splitting a value that ends up failing, which gets expensive fast; one that can
	 * match nothing makes Quantifier throw.
	 */
	public function addVarSelector(
		StylePropertySanitizer $propertySanitizer,
		MatcherFactoryExtender $factory
	): void {
		$anyValue = new Alternative( [
			$factory->color(),
			$factory->image(),
			$factory->length(),
			$factory->integer(),
			$factory->percentage(),
			$factory->number(),
			$factory->angle(),
			$factory->frequency(),
			$factory->resolution(),
			$factory->cssSingleEasingFunction(),
			$factory->comma(),
			$factory->cssWideKeywords(),
			// <position> one keyword at a time: position() itself matches one, two or
			// four values, which this list may not hold.
			new KeywordMatcher( [ 'left', 'center', 'right', 'top', 'bottom' ] ),
			new KeywordMatcher( [ 'solid', 'double', 'dotted', 'dashed', 'wavy' ] ),
		] );

		$propertySanitizer->setCssWideKeywordsMatcher( new Alternative( [
			$factory->cssWideKeywords(),
			new CheckedMatcher(
				Quantifier::plus( new Alternative( [
					$anyValue,
					$this->varFunction( $factory, $anyValue ),
				] ) ),
				static function ( ComponentValueList $values, GrammarMatch $match, array $options ) {
					foreach ( $match->getValues() as $value ) {
						foreach ( $value->toTokenArray() as $token ) {
							if ( $token->type() === Token::T_FUNCTION
								&& strcasecmp( (string)$token->value(), 'var' ) === 0
							) {
								return true;
							}
						}
					}

					return false;
				}
			),
		] ) );
	}

	/**
	 * A var() whose fallback is a list of values, as the spec has it, rather than one.
	 *
	 * The list may be empty -- `var( --x, )` is the guaranteed-invalid value -- so it is a
	 * Quantifier::star, kept behind the comma so the Juxtaposition still consumes a token
	 * and no enclosing quantifier is offered an empty match.
	 *
	 * Nesting is spelled out rather than recursive: a fallback may hold a var(), and that
	 * one a fallback of its own, and no deeper.
	 */
	private function varFunction( MatcherFactoryExtender $factory, Matcher $anyValue ): Matcher {
		return $this->varFunctionOver( $factory, new Alternative( [
			$anyValue,
			$this->varFunctionOver( $factory, $anyValue ),
		] ) );
	}

	private function varFunctionOver( MatcherFactoryExtender $factory, Matcher $fallback ): Matcher {
		return new FunctionMatcher( 'var', new Juxtaposition( [
			new CustomPropertyMatcher(),
			Quantifier::optional( new Juxtaposition( [
				$factory->comma(),
				Quantifier::star( $fallback ),
			] ) ),
		] ) );
	}

	/**
	 * Adds the pointer-events matcher
	 */
	public function addPointerEvents( StylePropertySanitizerExtender $propertySanitizer ): void {
		try {
			$propertySanitizer->addKnownProperties( [
				'pointer-events' => new KeywordMatcher( [
					'auto',
					'none',
					'visiblePainted',
					'visibleFill',
					'visibleStroke',
					'visible',
					'painted',
					'fill',
					'stroke',
					'bounding-box',
					'all',
				] )
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Adds the backdrop-filter matcher
	 */
	public function addBackdropFilter( StylePropertySanitizerExtender $propertySanitizer ): void {
		try {
			$filter = $propertySanitizer->getKnownProperties()['filter'];

			$propertySanitizer->addKnownProperties( [
				'backdrop-filter' => Quantifier::plus( $filter ),
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Partially implements CSS Fonts Module Level 4
	 */
	public function addCssFonts4(
		StylePropertySanitizerExtender $sanitizer,
		MatcherFactoryExtender $factory
	): void {
		try {
			$sanitizer->addKnownProperties( [
				'font-optical-sizing' => new KeywordMatcher( [
					'none',
					'auto',
				] ),
				'font-variation-settings' => new Alternative( [
					new KeywordMatcher( [ 'normal' ] ),
					Quantifier::hash( new Juxtaposition( [
						new Alternative( [
							new KeywordMatcher( [
								'wght',
								'wdth',
								'slnt',
								'ital',
								'opsz',
							] ),
							Quantifier::plus( $factory->string() ),
						] ),
						$factory->number(),
					] ) )
				] ),
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Adds the contain and content-visibility matcher (#28)
	 */
	public function addCssContainment3( StylePropertySanitizerExtender $sanitizer ): void {
		try {
			$sanitizer->addKnownProperties( [
				'contain' => new KeywordMatcher( [
					// Level 1
					'none', 'strict', 'content', 'size', 'layout', 'paint',
					// Level 3
					'style', 'inline-size'
				] ),
				'content-visibility' => new KeywordMatcher( [ 'visible', 'hidden', 'auto' ] ),
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Loads a config value for a given key from this extension's config
	 *
	 * Returns $default if the lookup throws a ConfigException, as a missing key does.
	 *
	 * @param string $key The config key
	 */
	public static function getConfigValue( string $key, mixed $default = null ): mixed {
		if ( self::$config === null ) {
			self::$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'TemplateStylesExtender' );
		}

		try {
			$value = self::$config->get( $key );
		} catch ( ConfigException $e ) {
			wfLogWarning(
				sprintf(
					'Could not get config for "$wg%s". %s', $key,
					$e->getMessage()
				)
			);

			return $default;
		}

		return $value;
	}
}
