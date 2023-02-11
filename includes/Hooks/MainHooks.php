<?php

namespace MediaWiki\Extension\TemplateStylesExtender\Hooks;

use MediaWiki\Extension\TemplateStyles\Hooks;
use MediaWiki\Hook\EditPage__attemptSaveHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MWException;
use PermissionsError;

class MainHooks implements ParserFirstCallInitHook, EditPage__attemptSaveHook {

	/**
	 * @throws MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'templatestyles', [ __CLASS__, 'handleTag' ] );
	}

	/**
	 * This is a wrapper for <templatestyles> tags, that allows unscoping of css for users with 'editinterface' permissions
	 * @see Hooks::handleTag()
	 */
	public static function handleTag( $text, $params, $parser, $frame ): string {
		if ( $parser->getOptions() === null || !MediaWikiServices::getInstance()->getMainConfig()->get( 'TemplateStylesExtenderEnableUnscopingSupport' ) ) {
			return Hooks::handleTag( $text, $params, $parser, $frame );
		}

		$options = $parser->getOptions();
		$wrapClass = $options->getWrapOutputClass();

		if ( isset( $params['wrapclass'] ) ) {
			$options->setOption( 'wrapclass', $params['wrapclass'] );
		}

		$out = Hooks::handleTag( $text, $params, $parser, $frame );
		$options->setOption( 'wrapclass', $wrapClass );

		return $out;
	}

	/**
	 * Check if 'wrapclass' was used in the page, if so only users with 'editinterface' permissions may save the page
	 *
	 * @param $editpage_Obj
	 * @return true
	 * @throws PermissionsError
	 */
	public function onEditPage__attemptSave( $editpage_Obj ): bool {
		$revision = $editpage_Obj->getExpectedParentRevision();
		if ( $revision === null || !MediaWikiServices::getInstance()->getMainConfig()->get( 'TemplateStylesExtenderEnableUnscopingSupport' ) ) {
			return true;
		}

		$content = $revision->getContent( SlotRecord::MAIN );
		if ( $content === null ) {
			return true;
		}

		$permManager = MediaWikiServices::getInstance()->getPermissionManager();
		$user = MediaWikiServices::getInstance()->getUserFactory()->newFromUserIdentity( $editpage_Obj->getContext()->getUser() );

		$userCan = $permManager->userHasRight( $user, 'editinterface' ) || $permManager->userCan( 'editinterface', $user, $editpage_Obj->getTitle() );

		if ( strpos( $content->getText(), 'wrapclass' ) !== false && !$userCan ) {
			throw new PermissionsError( 'editinterface', [ 'templatestylesextender-unscope-no-permisson' ] );
		}

		return true;
	}
}
