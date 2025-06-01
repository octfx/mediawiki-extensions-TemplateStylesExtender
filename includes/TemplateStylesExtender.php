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
use Wikimedia\CSS\Grammar\DelimMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\UnorderedGroup;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class TemplateStylesExtender {

	private static ?Config $config = null;

	/**
	 * Adds a CSS wide keyword matcher for CSS variables
	 * Matches 0-INF preceding CSS declarations at least one var( --content ) and 0-INF following declarations
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 * @param MatcherFactory $factory
	 */
	public function addVarSelector( StylePropertySanitizer $propertySanitizer, MatcherFactory $factory ): void {
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
	 * Adds the image-rendering matcher
	 * T222678
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 */
	public function addImageRendering( StylePropertySanitizer $propertySanitizer ): void {
		try {
			$propertySanitizer->addKnownProperties( [
				'image-rendering' => new KeywordMatcher( [
					'auto',
					'crisp-edges',
					'pixelated',
				] )
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds the ruby-position and ruby-align matcher
	 * T277755
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 */
	public function addRuby( StylePropertySanitizer $propertySanitizer ): void {
		try {
			$propertySanitizer->addKnownProperties( [
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

			$propertySanitizer->addKnownProperties( [
				'ruby-align' => new KeywordMatcher( [
					'start',
					'center',
					'space-between',
					'space-around',
				] )
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds scroll-margin-* and scroll-padding-* matcher
	 * TODO: This is not well tested
	 * T271598
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 * @param MatcherFactory $factory
	 */
	public function addScrollMarginProperties( $propertySanitizer, $factory ): void {
		$suffixes = [
			'margin-block-end',
			'margin-block-start',
			'margin-block',
			'margin-bottom',
			'margin-inline-end',
			'margin-inline-start',
			'margin-inline',
			'margin-left',
			'margin-right',
			'margin-top',
			'margin',
			'padding-block-end',
			'padding-block-start',
			'padding-block',
			'padding-bottom',
			'padding-inline-end',
			'padding-inline-start',
			'padding-inline',
			'padding-left',
			'padding-right',
			'padding-top',
			'padding',
		];

		foreach ( $suffixes as $suffix ) {
			try {
				$propertySanitizer->addKnownProperties( [
					sprintf( 'scroll-%s', $suffix ) => new Alternative( [
						$factory->length()
					] )
				] );
			} catch ( InvalidArgumentException $e ) {
				// Fail silently
			}
		}
	}

	/**
	 * Adds padding|margin-inline|block support
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 * @param MatcherFactory $factory
	 */
	public function addInlineBlockMarginPaddingProperties( $propertySanitizer, $factory ): void {
		$auto = new KeywordMatcher( 'auto' );
		$autoLengthPct = new Alternative( [ $auto, $factory->lengthPercentage() ] );

		$props = [];

		$props['margin-block-end'] = $autoLengthPct;
		$props['margin-block-start'] = $autoLengthPct;
		$props['margin-block'] = Quantifier::count( $autoLengthPct, 1, 2 );
		$props['margin-inline-end'] = $autoLengthPct;
		$props['margin-inline-start'] = $autoLengthPct;
		$props['margin-inline'] = Quantifier::count( $autoLengthPct, 1, 2 );
		$props['padding-block-end'] = $autoLengthPct;
		$props['padding-block-start'] = $autoLengthPct;
		$props['padding-block'] = Quantifier::count( $autoLengthPct, 1, 2 );
		$props['padding-inline-end'] = $autoLengthPct;
		$props['padding-inline-start'] = $autoLengthPct;
		$props['padding-inline'] = Quantifier::count( $autoLengthPct, 1, 2 );

		try {
			$propertySanitizer->addKnownProperties( $props );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds padding|margin-inline|block support
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 * @param MatcherFactory $factory
	 */
	public function addInsetProperties( $propertySanitizer, $factory ): void {
		$auto = new KeywordMatcher( 'auto' );
		$autoLengthPct = new Alternative( [ $auto, $factory->lengthPercentage() ] );

		$props = [];

		$props['inset'] = Quantifier::count( $autoLengthPct, 1, 4 );

		$props['inset-block'] = Quantifier::count( $autoLengthPct, 1, 2 );
		$props['inset-block-end'] = $autoLengthPct;
		$props['inset-block-start'] = $autoLengthPct;

		$props['inset-inline'] = Quantifier::count( $autoLengthPct, 1, 2 );
		$props['inset-inline-end'] = $autoLengthPct;
		$props['inset-inline-start'] = $autoLengthPct;

		try {
			$propertySanitizer->addKnownProperties( $props );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds the pointer-events matcher
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 */
	public function addPointerEvents( StylePropertySanitizer $propertySanitizer ): void {
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
	 * Adds the aspect-ratio matcher
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 * @param MatcherFactory $factory
	 */
	public function addAspectRatio( StylePropertySanitizer $propertySanitizer, MatcherFactory $factory ): void {
		try {
			$propertySanitizer->addKnownProperties( [
				'aspect-ratio' => UnorderedGroup::someOf( [
					new KeywordMatcher( [ 'auto' ] ),
					new Juxtaposition( [
						$factory->number(),
						Quantifier::optional( new Juxtaposition( [ new DelimMatcher( '/' ), $factory->number() ] ) )
					] )
				] ),
			] );
		} catch ( InvalidArgumentException $e ) {
			// Fail silently
		}
	}

	/**
	 * Adds the backdrop-filter matcher
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 */
	public function addBackdropFilter( StylePropertySanitizer $propertySanitizer ): void {
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
	 *
	 * @param StylePropertySanitizer $propertySanitizer
	 */
	public function addFontOpticalSizing( StylePropertySanitizer $propertySanitizer ): void {
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
	 *
	 * @param StylePropertySanitizer $sanitizer
	 * @param MatcherFactory $factory
	 */
	public function addFontVariationSettings( StylePropertySanitizer $sanitizer, MatcherFactory $factory ): void {
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
	 * Adds the content-visibility matcher
	 *
	 * #28
	 *
	 * @param StylePropertySanitizer $sanitizer
	 */
	public function addContentVisibility( StylePropertySanitizer $sanitizer ): void {
		try {
			$sanitizer->addKnownProperties( [
				'content-visibility' => new KeywordMatcher( [ 'visible', 'hidden', 'auto' ] ),
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
