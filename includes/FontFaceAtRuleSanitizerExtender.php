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
use Wikimedia\CSS\Sanitizer\FontFaceAtRuleSanitizer;

class FontFaceAtRuleSanitizerExtender extends FontFaceAtRuleSanitizer {

	/**
	 * @param MatcherFactory $matcherFactory
	 */
	public function __construct( MatcherFactory $matcherFactory ) {
		parent::__construct( $matcherFactory );

		$matcher = new Alternative( [ new KeywordMatcher( 'normal' ), $matcherFactory->percentage() ] );

		$this->propertySanitizer->setKnownProperties( [
			// CSS Fonts Module Level 4
			'ascent-override' => $matcher,
			'descent-override' => $matcher,
			'font-display' => new KeywordMatcher( [ 'auto', 'block', 'swap', 'fallback', 'optional' ] ),
			'line-gap-override' => $matcher,
			// CSS Fonts Module Level 5
			'size-adjust' => $matcher,
		] + $this->propertySanitizer->getKnownProperties() );
	}
}
