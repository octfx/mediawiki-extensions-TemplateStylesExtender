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
use Wikimedia\CSS\Grammar\CustomPropertyMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\UnorderedGroup;

class TemplateStylesExtender {

	private static ?Config $config = null;

	/**
	 * Adds a CSS wide keyword matcher for CSS variables
	 * Matches 0-INF preceding CSS declarations at least one var( --content ) and 0-INF following declarations
	 */
	public function addVarSelector(
		StylePropertySanitizerExtender $propertySanitizer,
		MatcherFactoryExtender $factory
	): void {
		$anyProperty = new Alternative( [
			$factory->color(),
			$factory->image(),
			$factory->length(),
			$factory->integer(),
			$factory->percentage(),
			$factory->number(),
			$factory->angle(),
			$factory->frequency(),
			$factory->resolution(),
			$factory->position(),
			$factory->cssSingleEasingFunction(),
			$factory->comma(),
			$factory->cssWideKeywords(),
			new KeywordMatcher( [
				'solid', 'double', 'dotted', 'dashed', 'wavy'
			] )
		] );

		$var = new FunctionMatcher(
			'var',
			new Juxtaposition( [
				new CustomPropertyMatcher(),
				Quantifier::optional( new Juxtaposition( [
					$factory->comma(),
					$anyProperty,
				] ) ),
			] )
		);

		// Match anything*\s?[var anything|anything var]+\s?anything*(!important)?
		// The problem is, that var() can be used more or less anywhere
		// Setting ONLY var as a CssWideKeywordMatcher would limit the matching to one property
		// E.g.: color: var( --color-base );             would work
		//       border: 1px var( --border-type ) black; would not
		// So we need to construct a matcher that matches anything + var somewhere
		$propertySanitizer->setCssWideKeywordsMatcher(
			new Alternative( [
				$factory->cssWideKeywords(),
				new Juxtaposition( [
					Quantifier::plus( new Alternative( [ $anyProperty, $var ] ) ),
					Quantifier::optional( new KeywordMatcher( [ '!important' ] ) )
				] ),
			] ),
		);
	}

	/**
	 * Implements CSS Ruby Module Level 1
	 * T277755
	 */
	public function addCssRuby1( StylePropertySanitizerExtender $propertySanitizer ): void {
		try {
			$propertySanitizer->addKnownProperties( [
				'ruby-align' => new KeywordMatcher( [
					'start',
					'center',
					'space-between',
					'space-around',
				] ),
				'ruby-position' => new Alternative( [
					UnorderedGroup::someOf( [
						new KeywordMatcher( [ 'alternate' ] ),
						new Alternative( [
							new KeywordMatcher( [ 'over' ] ),
							new KeywordMatcher( [ 'under' ] ),
						] ),
					] ),
					new KeywordMatcher( [ 'inter-character' ] ),
				] )
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Implements Scroll Snap Module Level 1
	 * T271598
	 */
	public function addCssScrollSnap1(
		StylePropertySanitizerExtender $propertySanitizer,
		MatcherFactoryExtender $factory
	): void {
		$auto = new KeywordMatcher( 'auto' );
		$autoLengthPct = new Alternative( [ $auto, $factory->lengthPercentage() ] );

		try {
			$propertySanitizer->addKnownProperties( [
				'scroll-margin' => Quantifier::count( $factory->length(), 1, 4 ),
				'scroll-margin-block' => Quantifier::count( $factory->length(), 1, 2 ),
				'scroll-margin-block-end' => $factory->length(),
				'scroll-margin-block-start' => $factory->length(),
				'scroll-margin-bottom' => $factory->length(),
				'scroll-margin-inline' => Quantifier::count( $factory->length(), 1, 2 ),
				'scroll-margin-inline-end' => $factory->length(),
				'scroll-margin-inline-start' => $factory->length(),
				'scroll-margin-left' => $factory->length(),
				'scroll-margin-right' => $factory->length(),
				'scroll-margin-top' => $factory->length(),
				'scroll-padding' => Quantifier::count( $autoLengthPct, 1, 4 ),
				'scroll-padding-block' => Quantifier::count( $autoLengthPct, 1, 2 ),
				'scroll-padding-block-end' => $autoLengthPct,
				'scroll-padding-block-start' => $autoLengthPct,
				'scroll-padding-bottom' => $autoLengthPct,
				'scroll-padding-inline' => Quantifier::count( $autoLengthPct, 1, 2 ),
				'scroll-padding-inline-end' => $autoLengthPct,
				'scroll-padding-inline-start' => $autoLengthPct,
				'scroll-padding-left' => $autoLengthPct,
				'scroll-padding-right' => $autoLengthPct,
				'scroll-padding-top' => $autoLengthPct,
				'scroll-snap-align' => new Alternative( [
					new KeywordMatcher( [ 'none', 'center', 'start', 'end' ] ),
					Quantifier::count( new KeywordMatcher( [ 'start', 'end', 'center' ] ), 1, 2 ),
				] ),
				'scroll-snap-stop' => new KeywordMatcher( [ 'normal', 'always' ] ),
				'scroll-snap-type' => new Alternative( [
					new KeywordMatcher( [ 'none', 'x', 'y', 'block', 'inline', 'both' ] ),
					new Juxtaposition( [
						new KeywordMatcher( [ 'x', 'y', 'both' ] ),
						new KeywordMatcher( [ 'mandatory', 'proximity' ] ),
					] ),
				] ),
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
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
		} catch ( InvalidArgumentException $e ) {
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
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds the font-optical-sizing matcher
	 */
	public function addFontOpticalSizing( StylePropertySanitizerExtender $propertySanitizer ): void {
		try {
			$propertySanitizer->addKnownProperties( [
				'font-optical-sizing' => new KeywordMatcher( [
					'none',
					'auto',
				] ),
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds the font-variation-settings matcher
	 */
	public function addFontVariationSettings(
		StylePropertySanitizerExtender $sanitizer,
		MatcherFactoryExtender $factory
	): void {
		try {
			$sanitizer->addKnownProperties( [
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
		} catch ( InvalidArgumentException $e ) {
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
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Backport CSS Box Sizing Level 4 from master branch
	 * @see https://github.com/wikimedia/css-sanitizer/commit/ffe10a21512f00405b4d0d124eb2c4866749e300
	 */
	public function addCssSizing4( StylePropertySanitizerExtender $sanitizer, MatcherFactoryExtender $factory ): void {
		try {
			$auto = new KeywordMatcher( 'auto' );
			$containIntrinsic = new Juxtaposition( [
				Quantifier::optional( $auto ),
				new Alternative( [
					new KeywordMatcher( 'none' ),
					$factory->lengthPercentage(),
				] ),
			] );

			$sanitizer->addKnownProperties( [
				'aspect-ratio' => UnorderedGroup::someOf( [ $auto, $factory->ratio() ] ),
				'contain-intrinsic-width' => $containIntrinsic,
				'contain-intrinsic-height' => $containIntrinsic,
				'contain-intrinsic-block-size' => $containIntrinsic,
				'contain-intrinsic-inline-size' => $containIntrinsic,
				'contain-intrinsic-size' => Quantifier::count( $containIntrinsic, 1, 2 ),
				'min-intrinsic-sizing' => new Alternative( [
					new KeywordMatcher( 'legacy' ),
					UnorderedGroup::someOf( [
						new KeywordMatcher( 'zero-if-scroll' ),
						new KeywordMatcher( 'zero-if-extrinsic' ),
					] ),
				] )
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Loads a config value for a given key from the main config
	 * Returns null on if an ConfigException was thrown
	 *
	 * @param string $key The config key
	 * @param null $default
	 * @return mixed|null
	 */
	public static function getConfigValue( string $key, mixed $default = null ): mixed {
		if ( self::$config === null ) {
			self::$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'TemplateStylesExtender' );
		}

		try {
			// @phan-suppress-next-line PhanPossiblyNullPropertyReal
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
