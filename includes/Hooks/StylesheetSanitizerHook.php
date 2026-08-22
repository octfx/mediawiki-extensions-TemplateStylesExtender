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

namespace MediaWiki\Extension\TemplateStylesExtender\Hooks;

use MediaWiki\Extension\TemplateStyles\Hooks\TemplateStylesStylesheetSanitizerHook;
use MediaWiki\Extension\TemplateStylesExtender\FontFaceAtRuleSanitizerExtender;
use MediaWiki\Extension\TemplateStylesExtender\MatcherFactoryExtender;
use MediaWiki\Extension\TemplateStylesExtender\StylePropertySanitizerExtender;
use MediaWiki\Extension\TemplateStylesExtender\StyleRuleSanitizerExtender;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Sanitizer\MediaAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;
use Wikimedia\CSS\Sanitizer\StyleRuleSanitizer;
use Wikimedia\CSS\Sanitizer\StylesheetSanitizer;
use Wikimedia\CSS\Sanitizer\SupportsAtRuleSanitizer;

class StylesheetSanitizerHook implements TemplateStylesStylesheetSanitizerHook {

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Extension:TemplateStyles/Hooks/TemplateStylesStylesheetSanitizer
	 */
	public function onTemplateStylesStylesheetSanitizer(
		StylesheetSanitizer &$sanitizer,
		StylePropertySanitizer $propertySanitizer,
		MatcherFactory $matcherFactory
	): void {
		$factory = new MatcherFactoryExtender( $matcherFactory );
		$extended = new TemplateStylesExtender();

		$extendCustomPropertyValues = TemplateStylesExtender::getConfigValue(
			'TemplateStylesExtenderExtendCustomPropertiesValues'
		) === true;

		// Must precede constructing the sanitizer: its parent constructor memoises matchers
		// from this factory, each capturing whether var() was enabled at that moment.
		if ( $extendCustomPropertyValues ) {
			$factory->setVarEnabled( true );
		}

		$extender = new StylePropertySanitizerExtender( $factory );

		if ( $extendCustomPropertyValues ) {
			$extended->addVarSelector( $propertySanitizer, $factory );
		}

		if (
			TemplateStylesExtender::getConfigValue(
				'TemplateStylesExtenderCustomPropertiesDeclaration'
			) === true
		) {
			$extender->setVarEnabled( true );
		}

		$newRules = $sanitizer->getRuleSanitizers();
		if ( isset( $newRules['styles'] ) && $newRules['styles'] instanceof StyleRuleSanitizer ) {
			$newRules['styles'] = StyleRuleSanitizerExtender::fromOriginal(
				$newRules['styles'], $factory
			);
		}
		$newRules['@font-face'] = new FontFaceAtRuleSanitizerExtender(
			$factory,
			(bool)TemplateStylesExtender::getConfigValue(
				'TemplateStylesExtenderRequireFontFamilyPrefix'
			)
		);
		$sanitizer->setRuleSanitizers( $newRules );
		self::propagateToNestedAtRules( $sanitizer );

		$extended->addBackdropFilter( $extender );
		$extended->addPointerEvents( $extender );

		$extended->addCssContainment3( $extender );
		$extended->addCssFonts4( $extender, $factory );
		$extended->addCssOverscrollBehavior1( $extender );
		$extended->addCssScrollbars1( $extender, $factory );

		$propertySanitizer->setKnownProperties( $extender->getKnownProperties() );
	}

	/**
	 * Push the replaced rule sanitizers down into `@media` and `@supports`.
	 *
	 * Both hold their own copy of the list, taken before this hook runs, so replacing an
	 * entry on the stylesheet alone leaves them on the original. Anything added to
	 * $newRules needs to come through here too.
	 */
	private static function propagateToNestedAtRules( StylesheetSanitizer $sanitizer ): void {
		$outer = $sanitizer->getRuleSanitizers();

		foreach ( [ '@media', '@supports' ] as $atRule ) {
			$nested = $outer[$atRule] ?? null;
			if ( !$nested instanceof MediaAtRuleSanitizer && !$nested instanceof SupportsAtRuleSanitizer ) {
				continue;
			}

			$inner = $nested->getRuleSanitizers();
			foreach ( $inner as $name => $_ ) {
				if ( isset( $outer[$name] ) ) {
					$inner[$name] = $outer[$name];
				}
			}
			$nested->setRuleSanitizers( $inner );
		}
	}
}
