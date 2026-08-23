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
use Wikimedia\CSS\Grammar\DelimMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\GrammarMatch;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Grammar\UnorderedGroup;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class TemplateStylesExtender {

	private static ?Config $config = null;

	/**
	 * Whole-value matcher for a declaration that contains a var().
	 *
	 * Reached only once a known property's own grammar has refused the value, and never
	 * told which property that was -- so it applies only where a var() is present, and
	 * leaves the property's own grammar to judge anything else.
	 *
	 * A value holding a var() is unknowable, since a custom property may hold any token
	 * stream. So the list admits keywords, strings and dimensions whole and draws the line
	 * at functions: an arbitrary one, a url() outside $wgTemplateStylesAllowedUrls and a
	 * block stay out.
	 *
	 * Every alternative must consume exactly one component value. A variable-length one
	 * makes the Quantifier::plus enumerate every way of splitting a failing value, which
	 * gets expensive fast; one that can match nothing makes Quantifier throw.
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
			$factory->time(),
			$factory->resolution(),
			$factory->cssSingleEasingFunction(),
			// A bare string is a URL only inside image-set(), and a function's arguments
			// are matched by its own grammar, never by this list.
			$factory->string(),
			// Which keywords a property takes is its business, and it is not known here.
			// Subsumes the css-wide keywords, the line styles and <position>.
			$factory->ident(),
			$factory->comma(),
			// <flex>, which no factory method builds
			new TokenMatcher( Token::T_DIMENSION, static function ( Token $t ) {
				return strcasecmp( (string)$t->unit(), 'fr' ) === 0;
			} ),
			// the separator in `font`, `grid-area` and `border-radius`
			new DelimMatcher( [ '/' ] ),
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
	 * The list may be empty -- `var( --x, )` is the guaranteed-invalid value -- hence the
	 * star, kept behind the comma so the Juxtaposition always consumes a token and no
	 * enclosing quantifier is offered an empty match.
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
				// none | strict | content | [ [size | inline-size] || layout || style || paint ]
				'contain' => new Alternative( [
					new KeywordMatcher( [ 'none', 'strict', 'content' ] ),
					UnorderedGroup::someOf( [
						// Level 3 adds inline-size, an alternative to size rather than a sibling
						new KeywordMatcher( [ 'size', 'inline-size' ] ),
						new KeywordMatcher( 'layout' ),
						new KeywordMatcher( 'style' ),
						new KeywordMatcher( 'paint' ),
					] ),
				] ),
				'content-visibility' => new KeywordMatcher( [ 'visible', 'hidden', 'auto' ] ),
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Adds the overscroll-behavior matchers (#75)
	 *
	 * Keywords only. The property decides whether a scroll gesture that reaches the end of a
	 * scroll container chains to the page behind it, and can neither move nor size anything.
	 */
	public function addCssOverscrollBehavior1( StylePropertySanitizerExtender $sanitizer ): void {
		try {
			$behavior = new KeywordMatcher( [ 'auto', 'contain', 'none' ] );

			$sanitizer->addKnownProperties( [
				'overscroll-behavior' => Quantifier::count( $behavior, 1, 2 ),
				'overscroll-behavior-block' => $behavior,
				'overscroll-behavior-inline' => $behavior,
				'overscroll-behavior-x' => $behavior,
				'overscroll-behavior-y' => $behavior,
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Adds the scrollbar-color and scrollbar-width matchers (#75)
	 *
	 * The colours come from the factory rather than a matcher of their own, so they carry
	 * whatever colour syntax the rest of this extension allows.
	 */
	public function addCssScrollbars1(
		StylePropertySanitizerExtender $sanitizer,
		MatcherFactoryExtender $factory
	): void {
		try {
			$sanitizer->addKnownProperties( [
				// thumb then track, both required: one colour is not a valid value
				'scrollbar-color' => new Alternative( [
					new KeywordMatcher( 'auto' ),
					Quantifier::count( $factory->color(), 2, 2 ),
				] ),
				'scrollbar-width' => new KeywordMatcher( [ 'auto', 'thin', 'none' ] ),
			] );
		} catch ( InvalidArgumentException ) {
			// Fail silently
		}
	}

	/**
	 * Adds the anonymous half of CSS Scroll-driven Animations Module Level 1
	 *
	 * `scroll()` and `view()` name no timeline, so nothing here leaves an identifier behind.
	 * The named half -- scroll-timeline-*, view-timeline-* and timeline-scope -- stays out:
	 * TemplateStyles concatenates every transcluded page's CSS into one scope and namespaces
	 * no identifier, so a timeline name would be page-global. It buys little in return, since
	 * a named timeline is only needed where the animated element is neither the scroller nor
	 * the subject, which inside one component it usually is.
	 *
	 * These add no capability: everything they can animate is already animatable, and
	 * `@keyframes` is already allowed. What changes is what drives the animation -- a scroll
	 * offset rather than a clock.
	 */
	public function addCssScrollDrivenAnimations1(
		StylePropertySanitizerExtender $sanitizer,
		MatcherFactoryExtender $factory
	): void {
		try {
			$axis = new KeywordMatcher( [ 'block', 'inline', 'x', 'y' ] );
			$inset = Quantifier::count( new Alternative( [
				new KeywordMatcher( 'auto' ),
				$factory->lengthPercentage(),
			] ), 1, 2 );

			$timeline = new Alternative( [
				new KeywordMatcher( [ 'auto', 'none' ] ),
				new FunctionMatcher( 'scroll', Quantifier::optional( UnorderedGroup::someOf( [
					new KeywordMatcher( [ 'root', 'nearest', 'self' ] ),
					$axis,
				] ) ) ),
				new FunctionMatcher( 'view', Quantifier::optional( UnorderedGroup::someOf( [
					$axis,
					$inset,
				] ) ) ),
			] );

			// <timeline-range-name>, per the published draft:
			// https://www.w3.org/TR/scroll-animations-1/#named-ranges
			// The editor's draft adds `scroll`, which no engine takes; see AGENTS.md.
			$rangeName = new KeywordMatcher( [
				'contain',
				'cover',
				'entry',
				'entry-crossing',
				'exit',
				'exit-crossing',
			] );
			$range = new Alternative( [
				new KeywordMatcher( 'normal' ),
				$factory->lengthPercentage(),
				new Juxtaposition( [
					$rangeName,
					Quantifier::optional( $factory->lengthPercentage() ),
				] ),
			] );

			$sanitizer->addKnownProperties( [
				'animation-range' => Quantifier::hash( new Juxtaposition( [
					$range,
					Quantifier::optional( $range ),
				] ) ),
				'animation-range-end' => Quantifier::hash( $range ),
				'animation-range-start' => Quantifier::hash( $range ),
				'animation-timeline' => Quantifier::hash( $timeline ),
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
