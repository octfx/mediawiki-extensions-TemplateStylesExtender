<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\TemplateStylesExtender\Tests\Integration;

use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * MainHooks re-registers the <templatestyles> tag so a `wrapclass` attribute can unscope
 * the emitted CSS, and it is the only place this extension makes a security decision of
 * its own: unscoping is permitted only when the last editor of the page holding the tag
 * has $wgTemplateStylesExtenderUnscopingPermission.
 *
 * Nothing exercised the file before, and the gap was not theoretical. The permission check
 * once passed a UserIdentity to a method that required a User -- an unconditional
 * TypeError that `||` short-circuiting reached only after the first check had already
 * returned false, which is to say only on the deny path. A page whose last editor lacked
 * the permission took a fatal during parse instead of rendering the error message.
 *
 * So these cases go through the real parser rather than calling handleTag() directly.
 * Calling it directly would not notice whether the tag hook is registered at all: the
 * hijack wins only because TemplateStyles is loaded first, and if that order ever flips,
 * TemplateStyles takes the tag back and every unscoping feature here silently stops
 * working while still passing any test that invokes the method by hand.
 *
 * @group TemplateStylesExtender
 * @group Database
 * @covers \MediaWiki\Extension\TemplateStylesExtender\Hooks\MainHooks
 */
class MainHooksTest extends MediaWikiIntegrationTestCase {

	/** Resolved by TemplateStyles against $wgTemplateStylesDefaultNamespace, i.e. NS_TEMPLATE. */
	private const STYLES_SRC = 'TemplateStylesExtender test/styles.css';
	private const STYLES_PAGE = 'Template:' . self::STYLES_SRC;
	private const WRAPPER_PAGE = 'Template:TemplateStylesExtender test wrapper';
	private const MODULE_PAGE = 'Module:TemplateStylesExtender test';
	private const HOST_PAGE = 'TemplateStylesExtender test host';
	private const OTHER_HOST_PAGE = 'TemplateStylesExtender test host 2';

	private const WRAP_CLASS = 'tse-unscoped';

	/** Stands in for a wiki that already parses with a non-default wrapper, e.g. a skin. */
	private const HOST_WRAP_CLASS = 'tse-host-wrap';

	/**
	 * As TemplateStyles emits it: minified, so no spaces around the braces or the colon.
	 *
	 * This is the only place in the suite that depends on css-sanitizer's exact output, and
	 * the pin moves independently of MediaWiki across the CI matrix -- so if every positive
	 * assertion in this file fails at once, suspect the stringifier before the sanitizer.
	 * The negative assertions are what keep that honest: a format change makes them vacuous
	 * rather than red, so each is paired with a positive one.
	 */
	private const SCOPED = '.mw-parser-output .foo{color:red}';
	private const UNSCOPED = '.' . self::WRAP_CLASS . ' .foo{color:red}';

	/**
	 * A right no stock group holds, in a group that holds nothing else.
	 *
	 * 'editsitecss' is the obvious second right and is not usable: sysop does not have it,
	 * and interface-admin, which does, also has editinterface -- so the two rights are
	 * never disjoint and the configured-permission cases could not fail. It must also not
	 * be one of $wgImplicitRights, for which userHasRight() returns true for everyone.
	 */
	private const UNSCOPE_RIGHT = 'templatestyles-unscope';
	private const UNSCOPE_GROUP = 'templatestyles-unscopers';

	protected function setUp(): void {
		parent::setUp();

		// Off by default in extension.json, and every case here is about a wiki that has
		// turned it on. The one case that asserts the disabled behaviour turns it back off.
		$this->overrideConfigValue( 'TemplateStylesExtenderEnableUnscopingSupport', true );
		$this->setGroupPermissions( self::UNSCOPE_GROUP, self::UNSCOPE_RIGHT, true );

		// Deliberately unprivileged: the gate reads the last editor of the page carrying
		// the tag, not of the page carrying the CSS, and no page in the fixture except the
		// one under test should hold the right -- so a deny case denies for one reason. An
		// implementation that read the stylesheet's editor instead is caught either way;
		// only which cases fail changes.
		$this->savePage( self::STYLES_PAGE, '.foo { color: red }', $this->unprivilegedUser() );
	}

	/** Holds editinterface, the default $wgTemplateStylesExtenderUnscopingPermission. */
	private function privilegedUser(): User {
		return $this->getTestSysop()->getUser();
	}

	private function unprivilegedUser(): User {
		return $this->getTestUser()->getUser();
	}

	/** Holds self::UNSCOPE_RIGHT and, deliberately, not editinterface. */
	private function customRightUser(): User {
		return $this->getTestUser( [ self::UNSCOPE_GROUP ] )->getUser();
	}

	/**
	 * Saving the same text twice is a null edit, which leaves the previous editor in place;
	 * a fixture that quietly did that would attribute the revision to the wrong user and
	 * the case would measure the opposite of what it claims. Fail on it here instead.
	 */
	private function savePage( string $titleText, string $text, User $editor ): RevisionRecord {
		// The title text rather than a Title, so the NS_MAIN default is the live thing it
		// looks like: editPage() ignores that argument entirely when handed a Title, and
		// every prefixed constant here resolves to its own namespace regardless.
		$status = $this->editPage( $titleText, $text, '', NS_MAIN, $editor );

		$this->assertStatusOK( $status, "could not create $titleText" );
		$revision = $status->getNewRevision();
		$this->assertNotNull(
			$revision,
			"$titleText was a null edit, so its last editor is not " . $editor->getName()
		);

		return $revision;
	}

	/**
	 * Save $wikitext as $host's current revision, attributed to $lastEditor, then parse it
	 * on that title.
	 *
	 * Saving and parsing the same text is not redundant. The revision is the fixture -- it
	 * is what the gate reads -- and the parse is the measurement. A parse on a title with
	 * no revision at all falls through to the same scoped CSS a refusal emits, only without
	 * the error message and the tracking category, so a case that skipped the save would
	 * still satisfy any assertion that looks only at which class the CSS is scoped to,
	 * without ever reaching the permission check.
	 */
	private function parseOnHost(
		string $wikitext,
		User $lastEditor,
		string $host = self::HOST_PAGE
	): ParserOutput {
		$this->savePage( $host, $wikitext, $lastEditor );

		return $this->parseOn( $wikitext, $host );
	}

	/**
	 * Callers read the result with getContentHolderText(). The obvious accessor is
	 * getRawText(), and it is wrong here: MediaWiki removed it after 1.46, and getText() has
	 * been deprecated since 1.42, so getContentHolderText() is the only one present on every
	 * branch this extension is tested against. On 1.43 it is a one-line alias for
	 * getRawText() and carries @internal, which is the price of spanning the range.
	 */
	private function parseOn( string $wikitext, string $host ): ParserOutput {
		// A fresh parser per parse: TemplateStyles hangs a per-instance stylesheet cache on
		// the parser and clears it only on ParserClearState, so a reused parser can answer
		// from an earlier case.
		return $this->getServiceContainer()->getParserFactory()->create()->parse(
			$wikitext,
			Title::newFromText( $host ),
			ParserOptions::newFromAnon()
		);
	}

	/**
	 * Derived the way TrackingCategories::resolveTrackingCategory() does it rather than by
	 * hand: the message text is a title, so the title parser decides the key, and a
	 * str_replace() here would only happen to agree with it for this one message.
	 */
	private function pageErrorCategory(): string {
		$category = Title::makeTitleSafe(
			NS_CATEGORY,
			wfMessage( 'templatestyles-page-error-category' )->inContentLanguage()->text()
		);
		$this->assertNotNull( $category, 'the tracking category message must name a valid title' );

		return $category->getDBkey();
	}

	/**
	 * A refusal emits its error message prepended to the ordinary scoped stylesheet, so
	 * "scoped, and not unscoped" is satisfied by a refusal just as well as by a silent
	 * fall-through. Without this, hardening either "should not happen" branch into a
	 * refusal would leave both fall-through cases green while every preview of a page that
	 * does not exist yet grew a permission error.
	 */
	private function assertFellThroughSilently( ParserOutput $output ): void {
		$this->assertStringNotContainsString( 'class="error"', $output->getContentHolderText() );
		$this->assertNotContains(
			$this->pageErrorCategory(),
			$output->getCategoryNames(),
			'a should-not-happen fall-through must be silent, not tracked as a page error'
		);
	}

	/**
	 * Self-closing, which is how the tag is written in practice -- and the form for which
	 * the parser hands the hook a null $text rather than a string.
	 */
	private function selfClosingTag(): string {
		return '<templatestyles src="' . self::STYLES_SRC . '" wrapclass="' . self::WRAP_CLASS . '" />';
	}

	private function pairedTag(): string {
		return '<templatestyles src="' . self::STYLES_SRC . '" wrapclass="' . self::WRAP_CLASS
			. '"></templatestyles>';
	}

	/**
	 * The overwhelmingly common tag: no wrapclass at all, so MainHooks must hand straight
	 * over to TemplateStyles and change nothing about the result.
	 *
	 * Both editors, because the gate must not reach a tag that asked for nothing. Testing
	 * only the privileged one would miss a reordering that checked the permission before
	 * noticing the attribute was absent -- which would put a refusal on every ordinary
	 * <templatestyles> tag on every page whose last editor is not an administrator.
	 *
	 * @dataProvider provideLastEditorPrivilege
	 */
	public function testTagWithoutWrapclassIsLeftAlone( bool $editorMayUnscope ): void {
		$html = $this->parseOnHost(
			'<templatestyles src="' . self::STYLES_SRC . '" />',
			$editorMayUnscope ? $this->privilegedUser() : $this->unprivilegedUser()
		)->getContentHolderText();

		$this->assertStringContainsString( self::SCOPED, $html );
		$this->assertStringNotContainsString( 'class="error"', $html );
	}

	public static function provideLastEditorPrivilege(): array {
		return [
			'last editor holds the permission' => [ true ],
			'last editor does not' => [ false ],
		];
	}

	public function testWrapclassUnscopesWhenTheLastEditorHasThePermission(): void {
		$html = $this->parseOnHost( $this->selfClosingTag(), $this->privilegedUser() )->getContentHolderText();

		$this->assertStringContainsString( self::UNSCOPED, $html );
		$this->assertStringNotContainsString(
			self::SCOPED,
			$html,
			'the stylesheet must be re-scoped, not emitted a second time alongside'
		);
		$this->assertStringNotContainsString( 'class="error"', $html );
	}

	/**
	 * The regression this file exists for. The assertion that matters is not which class
	 * the CSS ends up scoped to but that the parse completes at all: the deny path is the
	 * one the TypeError sat on, and a fatal here fails as an error rather than a mismatch.
	 */
	public function testWrapclassIsRefusedWhenTheLastEditorLacksThePermission(): void {
		$output = $this->parseOnHost( $this->selfClosingTag(), $this->unprivilegedUser() );
		$html = $output->getContentHolderText();

		// Without this, a deleted message key would render the same missing-message
		// placeholder on both sides of the comparison below, and the case would pass
		// while the reader saw no message at all.
		$this->assertTrue(
			wfMessage( 'templatestylesextender-unscoping-no-permission' )->exists(),
			'the refusal message key must exist'
		);
		$this->assertStringContainsString(
			'<strong class="error">'
				. wfMessage( 'templatestylesextender-unscoping-no-permission' )->inContentLanguage()->parse()
				. '</strong>',
			$html,
			'the refusal must render its message, not fatal and not disappear'
		);
		$this->assertStringContainsString(
			self::SCOPED,
			$html,
			'refusing to unscope must still emit the stylesheet, scoped as normal'
		);
		$this->assertStringNotContainsString( self::UNSCOPED, $html );

		$this->assertContains(
			$this->pageErrorCategory(),
			$output->getCategoryNames(),
			'a refusal must be tracked like any other TemplateStyles error'
		);
	}

	/**
	 * Both settings in one case, because the disabled half on its own cannot fail: a wiki
	 * with no TemplateStylesExtender at all emits exactly the same scoped stylesheet, since
	 * TemplateStyles reads only src and wrapper and ignores attributes it does not know.
	 * Parsing the identical fixture with the identical editor twice makes the flag the only
	 * difference between the two outcomes.
	 *
	 * A disabled feature must also ignore the attribute rather than refuse it: an error
	 * message on a wiki that never enabled unscoping would be noise.
	 */
	public function testWrapclassIsInertWhileUnscopingSupportIsDisabled(): void {
		$enabled = $this->parseOnHost( $this->selfClosingTag(), $this->privilegedUser() )->getContentHolderText();

		$this->overrideConfigValue( 'TemplateStylesExtenderEnableUnscopingSupport', false );
		$disabled = $this->parseOnHost(
			$this->selfClosingTag(),
			$this->privilegedUser(),
			self::OTHER_HOST_PAGE
		)->getContentHolderText();

		$this->assertStringContainsString(
			self::UNSCOPED,
			$enabled,
			'the fixture must unscope while the option is on, or the disabled half proves nothing'
		);
		$this->assertStringContainsString( self::SCOPED, $disabled );
		$this->assertStringNotContainsString( self::UNSCOPED, $disabled );
		$this->assertStringNotContainsString( 'class="error"', $disabled );
	}

	/**
	 * Both directions, because only the pair proves the configured value is what is read:
	 * a wiki that ignored the setting entirely would still pass the two default rows.
	 *
	 * @dataProvider provideUnscopingPermissions
	 */
	public function testUnscopingPermissionIsConfigurable(
		string $permission,
		bool $editorIsSysop,
		bool $unscoped
	): void {
		$this->overrideConfigValue( 'TemplateStylesExtenderUnscopingPermission', $permission );

		$html = $this->parseOnHost(
			$this->selfClosingTag(),
			$editorIsSysop ? $this->privilegedUser() : $this->customRightUser()
		)->getContentHolderText();

		$this->assertStringContainsString( $unscoped ? self::UNSCOPED : self::SCOPED, $html );
		$this->assertStringNotContainsString( $unscoped ? self::SCOPED : self::UNSCOPED, $html );
	}

	public static function provideUnscopingPermissions(): array {
		return [
			'default permission, editor holds editinterface' => [ 'editinterface', true, true ],
			'default permission, editor holds only the custom right' => [ 'editinterface', false, false ],
			'configured permission, editor holds only editinterface' => [ self::UNSCOPE_RIGHT, true, false ],
			'configured permission, editor holds it' => [ self::UNSCOPE_RIGHT, false, true ],
		];
	}

	/**
	 * The parser passes null for a self-closing <templatestyles />, the normal way to write
	 * the tag, and a string for the paired form -- so the normal way is also the one that
	 * hands the hook a value its own signature has to admit. Tightening `?string $text` to
	 * `string` reads like a cleanup and would fatal on every real page.
	 *
	 * The `$text ??= ''` normalisation just below it is not what this pins: TemplateStyles
	 * types that parameter in its docblock only, so passing the null straight through is a
	 * contract violation Phan can see and the parser cannot. What is asserted here is that
	 * both spellings reach TemplateStyles and come back with the same answer.
	 */
	public function testSelfClosingAndPairedTagsAreEquivalent(): void {
		$selfClosing = $this->parseOnHost( $this->selfClosingTag(), $this->privilegedUser() )->getContentHolderText();
		$paired = $this->parseOnHost( $this->pairedTag(), $this->privilegedUser() )->getContentHolderText();

		$this->assertStringContainsString( self::UNSCOPED, $selfClosing );
		$this->assertSame( $selfClosing, $paired, 'tag content must not change the outcome' );
	}

	/**
	 * Unscoping works by mutating the shared ParserOptions' wrapclass around the delegated
	 * call. ParserOutput::setFromParserOptions() reads that option at the end of the parse,
	 * not the start, so failing to restore it would not merely affect the stylesheet -- it
	 * would rename the wrapper div of the entire page.
	 */
	public function testUnscopingRestoresTheWrapClassItBorrowed(): void {
		$this->savePage( self::HOST_PAGE, $this->selfClosingTag(), $this->privilegedUser() );

		// A non-default class on the way in. Restoring the hardcoded 'mw-parser-output'
		// instead of whatever the caller had is indistinguishable from restoring correctly
		// when the caller had the default, which is the only thing an anonymous parse gives.
		$options = ParserOptions::newFromAnon();
		$options->setWrapOutputClass( self::HOST_WRAP_CLASS );

		$output = $this->getServiceContainer()->getParserFactory()->create()->parse(
			$this->selfClosingTag(),
			Title::newFromText( self::HOST_PAGE ),
			$options
		);

		$this->assertStringContainsString(
			self::UNSCOPED,
			$output->getContentHolderText(),
			'the fixture must unscope, or the restore below is asserted against nothing'
		);
		$this->assertSame( self::HOST_WRAP_CLASS, $options->getWrapOutputClass() );
		$this->assertSame( self::HOST_WRAP_CLASS, $output->getWrapperDivClass() );
	}

	/**
	 * The gate reads the parsed `wrapclass` attribute, so how the attribute was spelled in
	 * the source cannot matter. #18 supposed the check searched page text and could be
	 * evaded by assembling the attribute name through an expansion, so use exactly that
	 * shape: {{ns:0}} expands to the empty string, making the name `wrapclass`.
	 */
	public function testWrapclassAssembledByExpansionIsGatedToo(): void {
		$wikitext = '{{#tag:templatestyles||src=' . self::STYLES_SRC
			. '|wrap{{ns:0}}class=' . self::WRAP_CLASS . '}}';

		$refused = $this->parseOnHost( $wikitext, $this->unprivilegedUser() )->getContentHolderText();
		$allowed = $this->parseOnHost( $wikitext, $this->privilegedUser(), self::OTHER_HOST_PAGE )
			->getContentHolderText();

		$this->assertStringContainsString( self::SCOPED, $refused );
		$this->assertStringNotContainsString( self::UNSCOPED, $refused );
		$this->assertStringContainsString(
			self::UNSCOPED,
			$allowed,
			'the attribute must still reach the tag, or the refusal above proves nothing'
		);
	}

	/**
	 * The revision consulted is the one for the frame's title, which inside a transclusion
	 * is the template rather than the article. That is the behaviour README.md's "Unscoping
	 * with `wrapclass`" section describes and the one #18 raises: the permission belongs to
	 * whoever last edited the page carrying the tag, who need not be whoever added it and
	 * need not have edited the page being viewed at all.
	 */
	public function testTransclusionChecksTheTemplatesLastEditorNotTheArticles(): void {
		$this->savePage( self::WRAPPER_PAGE, $this->selfClosingTag(), $this->privilegedUser() );

		$html = $this->parseOnHost(
			'{{' . self::WRAPPER_PAGE . '}}',
			$this->unprivilegedUser()
		)->getContentHolderText();

		$this->assertStringContainsString(
			self::UNSCOPED,
			$html,
			"the article's last editor lacks the permission; the template's holds it"
		);
	}

	public function testTransclusionIsRefusedWhenTheTemplatesLastEditorLacksThePermission(): void {
		$this->savePage( self::WRAPPER_PAGE, $this->selfClosingTag(), $this->unprivilegedUser() );

		$html = $this->parseOnHost(
			'{{' . self::WRAPPER_PAGE . '}}',
			$this->privilegedUser()
		)->getContentHolderText();

		$this->assertStringContainsString( self::SCOPED, $html );
		$this->assertStringNotContainsString( self::UNSCOPED, $html );
	}

	/**
	 * The other half of #18: a tag emitted from Lua rather than written in wikitext. The
	 * answer turns out to be the same rule the transclusion cases pin, because Scribunto
	 * expands frame:extensionTag() on the module's own frame -- the permission is that of
	 * whoever last edited the page carrying the tag, here the module, and not of the page
	 * being viewed.
	 *
	 * Skipped rather than omitted. CI installs TemplateStyles and nothing else, so this
	 * cannot run there; but reasoning was how #18 went unresolved for years, and on a wiki
	 * that does have Scribunto this is the only thing that answers it.
	 *
	 * @dataProvider provideLastEditorPrivilege
	 */
	public function testLuaEmittedTagChecksTheModulesLastEditor( bool $moduleEditorMayUnscope ): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'Scribunto' );

		// The two pages always take opposite editors, so whichever one the gate consults,
		// the other cannot be the reason for the outcome.
		$this->savePage(
			self::MODULE_PAGE,
			"return { styles = function ( frame )\n"
				. "return frame:extensionTag{ name = 'templatestyles', args = {\n"
				. "src = '" . self::STYLES_SRC . "', wrapclass = '" . self::WRAP_CLASS . "'\n"
				. "} }\nend }",
			$moduleEditorMayUnscope ? $this->privilegedUser() : $this->unprivilegedUser()
		);

		$html = $this->parseOnHost(
			'{{#invoke:' . substr( self::MODULE_PAGE, strlen( 'Module:' ) ) . '|styles}}',
			$moduleEditorMayUnscope ? $this->unprivilegedUser() : $this->privilegedUser()
		)->getContentHolderText();

		$this->assertStringContainsString(
			$moduleEditorMayUnscope ? self::UNSCOPED : self::SCOPED,
			$html,
			"the module's last editor decides, not the page the reader is on"
		);
		$this->assertStringNotContainsString(
			$moduleEditorMayUnscope ? self::SCOPED : self::UNSCOPED,
			$html
		);
	}

	/**
	 * A tag parsed on a title with no revision -- previewing a page that does not exist yet
	 * -- reaches one of the two "should not happen" branches. It must fall through to the
	 * scoped output rather than dereference a missing revision.
	 */
	public function testTagOnAPageWithNoRevisionFallsBackToScopedCss(): void {
		$output = $this->parseOn( $this->selfClosingTag(), 'TemplateStylesExtender test no such page' );
		$html = $output->getContentHolderText();

		$this->assertStringContainsString( self::SCOPED, $html );
		$this->assertStringNotContainsString( self::UNSCOPED, $html );
		$this->assertFellThroughSilently( $output );
	}

	/**
	 * The other such branch: RevisionRecord::getUser() is null when the author is
	 * suppressed. It is the same shape as the bug this file was written for -- a value the
	 * permission check cannot accept -- so pin it rather than trust the guard by reading.
	 *
	 * The editor is the privileged one, so without suppression this page would unscope;
	 * falling back is therefore attributable to the null author and nothing else.
	 */
	public function testSuppressedRevisionAuthorFallsBackToScopedCss(): void {
		$revision = $this->savePage( self::HOST_PAGE, $this->selfClosingTag(), $this->privilegedUser() );
		$this->revisionDelete( $revision, [ RevisionRecord::DELETED_USER => 1 ] );

		$output = $this->parseOn( $this->selfClosingTag(), self::HOST_PAGE );
		$html = $output->getContentHolderText();

		$this->assertStringContainsString( self::SCOPED, $html );
		$this->assertStringNotContainsString( self::UNSCOPED, $html );
		$this->assertFellThroughSilently( $output );
	}
}
