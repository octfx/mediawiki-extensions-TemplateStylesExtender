<?php

namespace MediaWiki\Extension\TemplateStylesExtender\Hooks;

use MediaWiki\Extension\TemplateStyles\Hooks;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MWException;
use Parser;
use PPFrame;

/**
 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class MainHooks implements ParserFirstCallInitHook {

	/**
	 * @param Parser $parser
	 * @throws MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'templatestyles', [ __CLASS__, 'handleTag' ] );
	}

	/**
	 * This is a wrapper for <templatestyles> tags,
	 * that allows unscoping of css for users with 'editinterface' permissions
	 *
	 * Note this is a potentially expensive operation, as a lookup for the user of the current revision is done.
	 * The unscoping will only happen, if the editor of the current revision has the rights to do so
	 *
	 * @param string $text
	 * @param string[] $params
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 * @see Hooks::handleTag()
	 */
	public static function handleTag( $text, $params, $parser, $frame ): string {
		$getOutput = static fn() => Hooks::handleTag( $text, $params, $parser, $frame );

		if (
			!isset( $params['wrapclass'] ) ||
			$parser->getOptions() === null ||
			!TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderEnableUnscopingSupport' )
		) {
			return $getOutput();
		}

		// 'wrapclass' option is set...

		/** @var \ParserOptions $options - Fix typehint */
		$options = $parser->getOptions();
		$wrapClass = $options->getWrapOutputClass();

		$permission = TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderUnscopingPermission' );

		$rev = MediaWikiServices::getInstance()->getRevisionLookup()->getRevisionByTitle( $frame->getTitle() );

		if ( $rev === null || $rev->getUser() === null ) {
			return $getOutput();
		}

		/** @var UserIdentity $user - Fix typehint */
		$user = $rev->getUser();
		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $user );
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		$userCan = $permissionManager->userHasRight( $user, $permission )
			|| $permissionManager->userCan( $permission, $user, $frame->getTitle() );

		// If the editor of the last revision is allowed to change the wrapclass, set it
		if ( $userCan ) {
			$options->setOption( 'wrapclass', $params['wrapclass'] );
		}

		$out = Hooks::handleTag( $text, $params, $parser, $frame );
		$options->setOption( 'wrapclass', $wrapClass );

		return $out;
	}
}
