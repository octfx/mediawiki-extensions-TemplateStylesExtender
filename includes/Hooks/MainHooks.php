<?php

namespace MediaWiki\Extension\TemplateStylesExtender\Hooks;

use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\TemplateStyles\Hooks;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use MediaWiki\Hook\EditPage__attemptSaveHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserFactory;
use MWException;
use Parser;
use PermissionsError;
use PPFrame;

/**
 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class MainHooks implements ParserFirstCallInitHook, EditPage__attemptSaveHook {

	/**
	 * @var PermissionManager
	 */
	private PermissionManager $manager;

	/**
	 * @var UserFactory
	 */
	private UserFactory $factory;

	/**
	 * @param PermissionManager $manager
	 * @param UserFactory $factory
	 */
	public function __construct( PermissionManager $manager, UserFactory $factory ) {
		$this->manager = $manager;
		$this->factory = $factory;
	}

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
	 * @param string $text
	 * @param string[] $params
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 * @see Hooks::handleTag()
	 */
	public static function handleTag( $text, $params, $parser, $frame ): string {
		if (
			$parser->getOptions() === null ||
			!TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderEnableUnscopingSupport' )
		) {
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
	 * @param EditPage $editpage_Obj
	 * @return true
	 * @throws PermissionsError
	 */
	public function onEditPage__attemptSave( $editpage_Obj ): bool {
		$revision = $editpage_Obj->getExpectedParentRevision();

		if (
			$revision === null ||
			!TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderEnableUnscopingSupport' )
		) {
			return true;
		}

		$content = $revision->getContent( SlotRecord::MAIN );
		if ( $content === null ) {
			return true;
		}

		$permission = TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderUnscopingPermission' );

		$user = $this->factory->newFromUserIdentity( $editpage_Obj->getContext()->getUser() );

		$userCan = $this->manager->userHasRight( $user, $permission ) ||
			$this->manager->userCan( $permission, $user, $editpage_Obj->getTitle() );

		if ( strpos( $content->getText(), 'wrapclass' ) !== false && !$userCan ) {
			throw new PermissionsError( $permission, [ 'templatestylesextender-unscope-no-permisson' ] );
		}

		return true;
	}
}
