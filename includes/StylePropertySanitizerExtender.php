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
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Grammar\UnorderedGroup;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;

class StylePropertySanitizerExtender extends StylePropertySanitizer {

	private static $extendedCssText3 = false;
	private static $extendedCssBorderBackground = false;
	private static $extendedCssSizing3 = false;

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

		$props['background-color'] = new Alternative( [
			$matcherFactory->color(),
			new TokenMatcher( Token::T_HASH, function ( Token $t ) {
				return preg_match( '/^([0-9a-f]{3}|[0-9a-f]{8})$/i', $t->value() );
			} ),
		] );

		$this->cache[__METHOD__] = $props;
		self::$extendedCssBorderBackground = true;

		return $props;
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
	 * @param CSSObject $object
	 * @return CSSObject|\Wikimedia\CSS\Objects\Declaration|null
	 */
	protected function doSanitize( CSSObject $object ) {
		$parent = parent::doSanitize( $object );

		if ( substr( strtolower( $object->getName() ), 0, 2 ) === '--' ) {
			$this->clearSanitizationErrors();
		}

		return $parent;
	}
}
