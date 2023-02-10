<?php

namespace MediaWiki\Extension\TemplateStylesExtender\Hooks;

use Html;
use MediaWiki\Extension\TemplateStyles\Hooks;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;

class MainHooks implements ParserFirstCallInitHook {

	/**
	 * @throws \MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'templatestyles', [ __CLASS__, 'handleTag' ] );
	}

	/**
	 * This is a wrapper for <templatestyles> tags, that allows unscoping of css for users with 'edit-interface' permissions
	 * @see Hooks::handleTag()
	 */
	public static function handleTag( $text, $params, $parser, $frame ) {
		if ( $parser->getOptions() === null || !MediaWikiServices::getInstance()->getMainConfig()->get( 'TemplateStylesExtenderEnableUnscopingSupport' ) ) {
			return Hooks::handleTag( $text, $params, $parser, $frame );
		}

		$options = $parser->getOptions();
		$wrapClass = $options->getWrapOutputClass();

		if ( isset( $params['wrapclass'] ) ) {
			$userCan = MediaWikiServices::getInstance()->getPermissionManager()->quickUserCan(
				'editinterface',
				MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $parser->getUserIdentity() ),
				$frame->getTitle()
			);

			if ( $userCan ) {
				$options->setOption( 'wrapclass', $params['wrapclass'] );
			} else {
				return Html::element(
					'p',
					[ 'class' => 'mw-message-box mw-message-box-error' ],
					'User is not allowed to unscope this css. Needs "editinterface" rights.'
				);
			}
		}
		$out = Hooks::handleTag( $text, $params, $parser, $frame );
		$options->setOption( 'wrapclass', $wrapClass );

		return $out;
	}
}
