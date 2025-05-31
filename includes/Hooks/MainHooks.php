<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Hooks;

use MediaWiki\Extension\TemplateStyles\Hooks as TemplateStylesHooks;
use MediaWiki\Extension\TemplateStylesExtender\TemplateStylesExtender;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

/**
 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class MainHooks implements ParserFirstCallInitHook {

	/**
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ) {
		// Hijack the templatestyles tag
		$parser->setHook( 'templatestyles', [ __CLASS__, 'handleTag' ] );
	}

	/**
	 * This is a wrapper for <templatestyles> tags,
	 * that allows unscoping of css for users with 'editinterface' permissions
	 *
	 * Note this is a potentially expensive operation, as a lookup for the user of the current revision is done.
	 * The unscoping will only happen, if the editor of the current revision has the rights to do so
	 *
	 * @inheritDoc TemplateStylesHooks::handleTag()
	 */
	public static function handleTag( ?string $text, array $params, Parser $parser, PPFrame $frame ): string {
		$getOutput = static fn() => TemplateStylesHooks::handleTag( $text, $params, $parser, $frame );
		$options = self::getParserOptions( $parser );

		if (
			!isset( $params['wrapclass'] ) ||
			$options === null ||
			!TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderEnableUnscopingSupport' )
		) {
			return $getOutput();
		}

		$services = MediaWikiServices::getInstance();

		$title = self::getTitle( $frame );
		$rev = self::getRevision( $services, $title );

		// Should not happen
		if ( $rev === null ) {
			return $getOutput();
		}

		$user = self::getUserIdentity( $rev );
		// Should not happen
		if ( $user === null ) {
			return $getOutput();
		}

		if ( !self::isUserAllowedToUnscope( $services, $user, $title ) ) {
			return self::formatTagError( $parser, [
				'templatestylesextender-unscoping-no-permission'
			] ) . $getOutput();
		}

		$wrapClass = $options->getWrapOutputClass();
		$options->setOption( 'wrapclass', $params['wrapclass'] );

		$out = TemplateStylesHooks::handleTag( $text, $params, $parser, $frame );
		$options->setOption( 'wrapclass', $wrapClass );

		return $out;
	}

	private static function getParserOptions( Parser $parser ): ?ParserOptions {
		return $parser->getOptions();
	}

	private static function getTitle( PPFrame $frame ): Title {
		return $frame->getTitle();
	}

	private static function getRevision(
		MediaWikiServices $services,
		Title $title
	): ?RevisionRecord {
		return $services->getRevisionLookup()->getRevisionByTitle( $title );
	}

	private static function getUserIdentity( RevisionRecord $rev ): ?UserIdentity {
		return $rev->getUser();
	}

	private static function isUserAllowedToUnscope(
		MediaWikiServices $services,
		UserIdentity $user,
		Title $title
	): bool {
		$permissionManager = $services->getPermissionManager();
		$permission = TemplateStylesExtender::getConfigValue( 'TemplateStylesExtenderUnscopingPermission' );

		return $permissionManager->userHasRight( $user, $permission )
			|| $permissionManager->userCan( $permission, $user, $title );
	}

	/** @inheritDoc TemplateStylesHooks::formatTagError() */
	private static function formatTagError( Parser $parser, array $msg ): string {
		$parser->addTrackingCategory( 'templatestyles-page-error-category' );
		return '<strong class="error">' .
			wfMessage( ...$msg )->inContentLanguage()->parse() .
			'</strong>';
	}
}
