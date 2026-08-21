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
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class TemplateStylesExtender {

	private static ?Config $config = null;

	/**
	 * Adds a CSS wide keyword matcher for CSS variables
	 * Matches 0-INF preceding CSS declarations at least one var( --content ) and 0-INF following declarations
	 */
	public function addVarSelector(
		StylePropertySanitizer $propertySanitizer,
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
