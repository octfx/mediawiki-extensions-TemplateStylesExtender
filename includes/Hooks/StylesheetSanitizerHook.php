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
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;
use Wikimedia\CSS\Sanitizer\StylesheetSanitizer;

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
		$factory = new MatcherFactoryExtender();
		$extended = new TemplateStylesExtender();
		$extender = new StylePropertySanitizerExtender( $factory );

		if (
			TemplateStylesExtender::getConfigValue(
				'TemplateStylesExtenderExtendCustomPropertiesValues'
			) === true
		) {
			$factory->setVarEnabled( true );
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
		$newRules['@font-face'] = new FontFaceAtRuleSanitizerExtender( $factory );
		$sanitizer->setRuleSanitizers( $newRules );

		$extended->addAspectRatio( $extender, $factory );
		$extended->addBackdropFilter( $extender );
		$extended->addContain( $extender );
		$extended->addContentVisibility( $extender );
		$extended->addFontOpticalSizing( $extender );
		$extended->addFontVariationSettings( $extender, $factory );
		$extended->addInlineBlockMarginPaddingProperties( $extender, $factory );
		$extended->addInsetProperties( $extender, $factory );
		$extended->addPointerEvents( $extender );
		$extended->addRuby( $extender );
		$extended->addScrollMarginProperties( $extender, $factory );

		$propertySanitizer->setKnownProperties( $extender->getKnownProperties() );
	}
}
