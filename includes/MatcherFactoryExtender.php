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
use Wikimedia\CSS\Grammar\CheckedMatcher;
use Wikimedia\CSS\Grammar\CustomPropertyMatcher;
use Wikimedia\CSS\Grammar\DelimMatcher;
use Wikimedia\CSS\Grammar\FunctionMatcher;
use Wikimedia\CSS\Grammar\GrammarMatch;
use Wikimedia\CSS\Grammar\Juxtaposition;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\Matcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\Quantifier;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Grammar\UnorderedGroup;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\Token;

class MatcherFactoryExtender extends MatcherFactory {

	private bool $varEnabled = false;
	private MatcherFactory $baseMatcherFactory;

	public function __construct( MatcherFactory $baseMatcherFactory ) {
		$this->baseMatcherFactory = $baseMatcherFactory;
	}

	/**
	 * Preserve URL validation supplied by TemplateStyles.
	 * @inheritDoc
	 */
	public function urlstring( $type ): Matcher {
		return $this->baseMatcherFactory->urlstring( $type );
	}

	/**
	 * Preserve URL validation supplied by TemplateStyles.
	 * @inheritDoc
	 */
	public function url( $type ): Matcher {
		return $this->baseMatcherFactory->url( $type );
	}

	public function setVarEnabled( bool $varEnabled ): void {
		$this->varEnabled = $varEnabled;
	}

	/**
	 * Add revert-layer to the list of CSS-wide value keywords
	 * @inheritDoc
	 */
	public function cssWideKeywords(): Matcher {
		$this->cache[__METHOD__]
			??= new KeywordMatcher( [
				'initial', 'inherit', 'unset', 'revert', 'revert-layer'
			] );

		return $this->cache[__METHOD__];
	}

	/**
	 * Pseudo-classes from Selectors Level 4 (#67)
	 * @inheritDoc
	 */
	public function cssPseudo(): Matcher {
		if ( isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}

		$ows = $this->optionalWhitespace();

		// Not cssSelector(): it reaches cssSimpleSelectorSeq(), which calls this method, so
		// the argument grammar is built here instead -- as colorFuncs() has to. That bounds
		// it one level deep, which also keeps it from backtracking on hostile input.
		$innerPseudo = new Alternative( [
			new Juxtaposition( [ new TokenMatcher( Token::T_COLON ), $this->level4Keywords() ] ),
			parent::cssPseudo(),
		] );
		$inner = $this->boundedSelectorList( $innerPseudo );

		// :has() takes relative selectors, so an entry may open with a combinator
		$relative = Quantifier::hash( new Juxtaposition( [
			Quantifier::optional( $this->cssCombinator() ),
			$this->boundedSelector( $innerPseudo ),
		] ) );

		$this->cache[__METHOD__] = new Alternative( [
			new Juxtaposition( [
				new TokenMatcher( Token::T_COLON ),
				new Alternative( [
					$this->level4Keywords(),
					new FunctionMatcher( 'is', new Juxtaposition( [ $ows, $inner, $ows ] ) ),
					new FunctionMatcher( 'where', new Juxtaposition( [ $ows, $inner, $ows ] ) ),
					new FunctionMatcher( 'has', new Juxtaposition( [ $ows, $relative, $ows ] ) ),
				] ),
			] ),
			parent::cssPseudo(),
		] );
		$this->cache[__METHOD__]->setDefaultOptions( [ 'skip-whitespace' => false ] );

		return $this->cache[__METHOD__];
	}

	/**
	 * `:not()` over a selector list, as Selectors Level 4 has it
	 *
	 * Takes a full cssPseudo() rather than the bounded one :is() gets: nothing calls back
	 * into cssNegation(), so there is no cycle to break here.
	 *
	 * @inheritDoc
	 */
	public function cssNegation(): Matcher {
		if ( isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}

		$ows = $this->optionalWhitespace();

		$this->cache[__METHOD__] = new Juxtaposition( [
			new TokenMatcher( Token::T_COLON ),
			new FunctionMatcher( 'not', new Juxtaposition( [
				$ows,
				$this->boundedSelectorList( $this->cssPseudo() ),
				$ows,
			] ) ),
		] );
		$this->cache[__METHOD__]->setDefaultOptions( [ 'skip-whitespace' => false ] );

		return $this->cache[__METHOD__];
	}

	/**
	 * The pseudo-classes this extension adds that are a bare keyword.
	 *
	 * Scope is the web-platform baseline's "widely available".
	 */
	private function level4Keywords(): Matcher {
		$this->cache[__METHOD__] ??= new KeywordMatcher( [
			// state
			'focus-visible', 'focus-within', 'any-link',
			// form and input state
			'read-only', 'read-write', 'placeholder-shown', 'default', 'required',
			'optional', 'valid', 'invalid', 'in-range', 'out-of-range',
		] );

		return $this->cache[__METHOD__];
	}

	/**
	 * A comma-separated list of the complex selectors boundedSelector() builds over $pseudo.
	 */
	private function boundedSelectorList( Matcher $pseudo ): Matcher {
		$list = Quantifier::hash( $this->boundedSelector( $pseudo ) );
		$list->setDefaultOptions( [ 'skip-whitespace' => false ] );

		return $list;
	}

	/**
	 * Upstream's cssSelector(), over a supplied pseudo-class matcher and without captures.
	 *
	 * Keep it capture-free. StyleRuleSanitizer scopes whatever it finds captured as
	 * 'selector'; an inner one is absorbed before reaching it today, but only because
	 * upstream happens to capture the enclosing pseudo-class.
	 */
	private function boundedSelector( Matcher $pseudo ): Matcher {
		$seq = $this->boundedSimpleSelectorSeq( $pseudo );
		$selector = new Juxtaposition( [
			$seq,
			Quantifier::star( new Juxtaposition( [ $this->cssCombinator(), $seq ] ) ),
		] );
		$selector->setDefaultOptions( [ 'skip-whitespace' => false ] );

		return $selector;
	}

	/**
	 * Upstream's cssSimpleSelectorSeq(), over a supplied pseudo-class matcher.
	 *
	 * cssPseudo() and cssNegation() are the only parts that reach back into this chain, so
	 * negation is rebuilt from $pseudo and everything else comes from the factory.
	 */
	private function boundedSimpleSelectorSeq( Matcher $pseudo ): Matcher {
		$ows = $this->optionalWhitespace();
		$negation = new Juxtaposition( [
			new TokenMatcher( Token::T_COLON ),
			new FunctionMatcher( 'not', new Juxtaposition( [
				$ows,
				new Alternative( [
					$this->cssTypeSelector(),
					$this->cssUniversal(),
					$this->cssID(),
					$this->cssClass(),
					$this->cssAttrib(),
					$pseudo,
				] ),
				$ows,
			] ) ),
		] );

		$hashEtc = new Alternative( [
			$this->cssID(),
			$this->cssClass(),
			$this->cssAttrib(),
			$pseudo,
			$negation,
		] );

		$seq = new Alternative( [
			new Juxtaposition( [
				new Alternative( [ $this->cssTypeSelector(), $this->cssUniversal() ] ),
				Quantifier::star( $hashEtc ),
			] ),
			Quantifier::plus( $hashEtc ),
		] );
		$seq->setDefaultOptions( [ 'skip-whitespace' => false ] );

		return $seq;
	}

	/**
	 * Partially implements CSS Color Module Level 4 and 5
	 *
	 * @return Matcher|Matcher[]
	 */
	protected function colorFuncs() {
		if ( isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}

		// Channels mirror upstream, which allows var() here unconditionally, except that
		// a hue may take an angle fallback -- <hue> is <number> | <angle>, so that is
		// spec-correct and upstream is the arbitrary one.
		$n = $this->rawOrCustomProp( $this->number() );
		$p = $this->rawOrCustomProp( $this->percentage() );
		$a = $this->rawOrCustomProp( $this->angle() );
		$nP = new Alternative( [ $n, $p ] );
		$hueWithVar = new Alternative( [ $n, $a ] );

		$none = new KeywordMatcher( [ 'none' ] );
		$nPNone = new Alternative( [ $n, $p, $none ] );
		$hueNone = new Alternative( [ $hueWithVar, $none ] );

		// Colorspace keywords
		$predefinedRgb = new KeywordMatcher( [
			'srgb', 'srgb-linear', 'display-p3', 'a98-rgb', 'prophoto-rgb',
			'rec2020', 'rec2100-pq', 'rec2100-hlg', 'rec2100-linear'
		] );
		$xyzSpace = new KeywordMatcher( [ 'xyz', 'xyz-d50', 'xyz-d65' ] );

		// Alpha matchers
		$optionalAlpha = Quantifier::optional( new Juxtaposition(
			[ new DelimMatcher( '/' ), new Alternative( [ $nP, $none ] ) ]
		) );
		$optionalLegacyAlpha = Quantifier::optional( $nPNone );

		// Absolute color syntaxes
		$rgbSyntax = $this->buildRgbSyntax( $n, $p, $nPNone, $optionalAlpha, $optionalLegacyAlpha );
		$hslSyntax = $this->buildHslSyntax( $hueWithVar, $p, $hueNone, $nPNone, $optionalAlpha, $optionalLegacyAlpha );
		$hwbSyntax = $this->buildStandardColorSyntax( [ $hueNone, $nPNone, $nPNone ], $optionalAlpha );
		$labSyntax = $this->buildStandardColorSyntax( [ $nPNone, $nPNone, $nPNone ], $optionalAlpha );
		$lchSyntax = $this->buildStandardColorSyntax( [ $nPNone, $nPNone, $hueNone ], $optionalAlpha );

		$colorSpaceParamsForColorFunc = new Alternative( [
			new Juxtaposition( [
				new Alternative( [ $predefinedRgb, $xyzSpace ] ),
				Quantifier::count( $nPNone, 3, 3 ),
			] )
		] );
		$colorFuncSyntax = $this->buildStandardColorSyntax( [ $colorSpaceParamsForColorFunc ], $optionalAlpha );

		$absoluteColorFuncs = [
			new FunctionMatcher( 'rgb', $rgbSyntax ),
			new FunctionMatcher( 'rgba', $rgbSyntax ),
			new FunctionMatcher( 'hsl', $hslSyntax ),
			new FunctionMatcher( 'hsla', $hslSyntax ),
			new FunctionMatcher( 'hwb', $hwbSyntax ),
			new FunctionMatcher( 'lab', $labSyntax ),
			new FunctionMatcher( 'lch', $lchSyntax ),
			new FunctionMatcher( 'oklab', $labSyntax ),
			new FunctionMatcher( 'oklch', $lchSyntax ),
			new FunctionMatcher( 'color', $colorFuncSyntax )
		];

		// Relative color syntax components
		//
		// Shaped like upstream's color(), but built by hand: color() and safeColor() both
		// call colorFuncs(), which is this method, so either would recurse. That is also
		// why a relative colour cannot itself be an origin.
		$safeOriginColor = new Alternative( [
			$this->colorWords(),
			$this->colorHex(),
			...$absoluteColorFuncs
		] );

		// var() is gated here and not in the channels above, because of what each gate
		// costs: css-sanitizer accepts var() in a channel, so gating one would reject CSS
		// plain TemplateStyles takes; it accepts nothing after `from`, so gating this
		// rejects nothing.
		$originColor = new Alternative( [
			$this->varEnabled ? $this->rawOrCustomProp( $safeOriginColor ) : $safeOriginColor,
			// ungated: the option gates var(), and this is not one. Upstream has it in
			// color() but not safeColor(), so it is an origin but not an origin's fallback.
			new FunctionMatcher( 'light-dark', new Juxtaposition( [
				$safeOriginColor,
				$safeOriginColor
			], true ) )
		] );

		$optionalAlphaCalc = new Alternative( [
			$optionalAlpha,
			Quantifier::optional( new Juxtaposition( [
				new DelimMatcher( '/' ),
				$this->mathFunction( new KeywordMatcher( [ 'alpha' ] ) )
			] ) )
		] );

		// Component definitions for relative colors
		$relativeRgbComponents = [
			$this->createRelativeColorChannel( 'r', $nPNone ),
			$this->createRelativeColorChannel( 'g', $nPNone ),
			$this->createRelativeColorChannel( 'b', $nPNone ),
		];
		$relativeHslComponents = [
			$this->createRelativeColorChannel( 'h', $hueNone ),
			$this->createRelativeColorChannel( 's', $nPNone ),
			$this->createRelativeColorChannel( 'l', $nPNone ),
		];
		$relativeHwbComponents = [
			$this->createRelativeColorChannel( 'h', $hueNone ),
			$this->createRelativeColorChannel( 'w', $nPNone ),
			$this->createRelativeColorChannel( 'b', $nPNone ),
		];
		// For lab and oklab
		$relativeLabComponents = [
			$this->createRelativeColorChannel( 'l', $nPNone ),
			$this->createRelativeColorChannel( 'a', $nPNone ),
			$this->createRelativeColorChannel( 'b', $nPNone ),
		];
		// For lch and oklch
		$relativeLchComponents = [
			$this->createRelativeColorChannel( 'l', $nPNone ),
			$this->createRelativeColorChannel( 'c', $nPNone ),
			$this->createRelativeColorChannel( 'h', $hueNone ),
		];
		$relativeColorFuncColorComponents = [
			new Alternative( [
				new Juxtaposition( [
					$predefinedRgb,
					$this->createRelativeColorChannel( 'r', $nPNone ),
					$this->createRelativeColorChannel( 'g', $nPNone ),
					$this->createRelativeColorChannel( 'b', $nPNone ),
				] ),
				new Juxtaposition( [
					$xyzSpace,
					$this->createRelativeColorChannel( 'x', $nPNone ),
					$this->createRelativeColorChannel( 'y', $nPNone ),
					$this->createRelativeColorChannel( 'z', $nPNone ),
				] )
			] )
		];

		$relativeColorFuncs = [
			new FunctionMatcher( 'rgb',
				$this->buildRelativeColorSyntax( $originColor, $relativeRgbComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'hsl',
				$this->buildRelativeColorSyntax( $originColor, $relativeHslComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'hwb',
				$this->buildRelativeColorSyntax( $originColor, $relativeHwbComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'lab',
				$this->buildRelativeColorSyntax( $originColor, $relativeLabComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'lch',
				$this->buildRelativeColorSyntax( $originColor, $relativeLchComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'oklab',
				$this->buildRelativeColorSyntax( $originColor, $relativeLabComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'oklch',
				$this->buildRelativeColorSyntax( $originColor, $relativeLchComponents, $optionalAlphaCalc )
			),
			new FunctionMatcher( 'color',
				$this->buildRelativeColorSyntax( $originColor, $relativeColorFuncColorComponents, $optionalAlphaCalc )
			),
		];

		// color-mix() = color-mix( <color-interpolation-method>? , [ <color> && <percentage [0,100]>? ]# )
		//
		// After $relativeColorFuncs, so an argument can be any colour this method makes.
		// A color-mix() cannot be one: it is the matcher being built, the same bound the
		// relative-colour origin is under.
		$mixColor = new Alternative( [ $originColor, ...$relativeColorFuncs ] );

		// Not $predefinedRgb, which is color()'s: it carries the rec2100-* spaces and
		// lacks lab, oklab and the polar four. A hue method follows a polar space only.
		$colorInterpolationMethod = new Juxtaposition( [
			new KeywordMatcher( [ 'in' ] ),
			new Alternative( [
				new KeywordMatcher( [
					'srgb', 'srgb-linear', 'display-p3', 'display-p3-linear', 'a98-rgb',
					'prophoto-rgb', 'rec2020', 'lab', 'oklab'
				] ),
				$xyzSpace,
				new Juxtaposition( [
					new KeywordMatcher( [ 'hsl', 'hwb', 'lch', 'oklch' ] ),
					Quantifier::optional( new Juxtaposition( [
						new KeywordMatcher( [ 'shorter', 'longer', 'increasing', 'decreasing' ] ),
						new KeywordMatcher( [ 'hue' ] )
					] ) )
				] )
			] )
		] );

		// allOf(), not someOf(): someOf() yields on a partial match, taking a lone
		// percentage for a whole argument. `#` is one or more, not two. The optional
		// method takes its comma with it, which Juxtaposition's comma mode already does.
		// An out-of-range percentage is left for the browser to refuse.
		$colorMixSyntax = new Juxtaposition( [
			Quantifier::optional( $colorInterpolationMethod ),
			Quantifier::hash( UnorderedGroup::allOf( [ $mixColor, Quantifier::optional( $p ) ] ) )
		], true );

		$this->cache[__METHOD__] = [
			...$absoluteColorFuncs,
			...$relativeColorFuncs,
			new FunctionMatcher( 'color-mix', $colorMixSyntax )
		];

		return $this->cache[__METHOD__];
	}

	/** @inheritDoc */
	public function resolution(): Matcher {
		// parent::mathFunction(), not $this->: the override adds a bare var(), and
		// image-set() reads a bare string as a URL -- so `--r: 2x, "https://evil/x.png" 1x`
		// would substitute into a second entry, past $wgTemplateStylesAllowedUrls.
		// addVarSelector() also calls this, unaffected: it already offers a bare var().
		$this->cache[__METHOD__] ??= parent::mathFunction( $this->rawResolution() );

		return $this->cache[__METHOD__];
	}

	/**
	 * Partially implements CSS Image Module Level 4
	 */
	public function image(): Matcher {
		if ( isset( $this->cache[__METHOD__] ) ) {
			return $this->cache[__METHOD__];
		}

		$image = parent::image();

		$this->cache[__METHOD__] = new Alternative( [
			$image,
			new FunctionMatcher( 'image-set', Quantifier::hash( new Juxtaposition( [
				new Alternative( [ $image, $this->urlstring( 'image' ) ] ),
				Quantifier::optional( $this->imageSetOptions() ),
			] ) ) ),
		] );

		return $this->cache[__METHOD__];
	}

	/**
	 * What may follow the URL in an image-set() entry: `[ <resolution> || type(<string>) ]`,
	 * so either, both, in either order -- and no var() in any of it.
	 *
	 * The caller makes the whole group optional, per CSS Images 4.
	 *
	 * resolution() keeps a bare var() out; this keeps one inside calc() out too. That costs
	 * `calc(1x * var(--d))` and buys not trusting the browser to reject the malformed calc
	 * the payload above becomes. The check spans the whole group, so `type(var(--t))` goes
	 * with it. attr() is the other substitution to watch; it cannot reach here today.
	 */
	private function imageSetOptions(): Matcher {
		$this->cache[__METHOD__] ??= new CheckedMatcher(
			UnorderedGroup::someOf( [
				$this->resolution(),
				new FunctionMatcher( 'type', $this->string() ),
			] ),
			static function ( ComponentValueList $values, GrammarMatch $match, array $options ) {
				foreach ( $match->getValues() as $value ) {
					foreach ( $value->toTokenArray() as $token ) {
						if ( $token->type() === Token::T_FUNCTION
							&& strcasecmp( (string)$token->value(), 'var' ) === 0
						) {
							return false;
						}
					}
				}

				return true;
			}
		);

		return $this->cache[__METHOD__];
	}

	/**
	 * A value of the given type, or a var() that may carry a fallback of that same type.
	 *
	 * Mirrors css-sanitizer's own rawOrCustomProp(). Restricting the fallback to the same
	 * matcher is what makes it safe: var( --x, url( ... ) ) cannot satisfy a numeric slot.
	 * Note the type is this factory's, so anything mathFunction() or rawNumber() admit is
	 * admitted in a fallback too.
	 */
	protected function rawOrCustomProp( Matcher $type ): Matcher {
		return new Alternative( [
			$type,
			new FunctionMatcher( 'var', new Juxtaposition( [
				new CustomPropertyMatcher(),
				Quantifier::optional( $type ),
			], true ) ),
		] );
	}

	/**
	 * Helper to build the syntax for rgb() and rgba() functions.
	 * @param Matcher $n Number matcher (including var)
	 * @param Matcher $p Percentage matcher (including var)
	 * @param Matcher $nPNone Number, percentage, or none matcher (including var)
	 * @param Matcher $optionalAlpha Modern alpha syntax matcher
	 * @param Matcher $optionalLegacyAlpha Legacy alpha syntax matcher
	 */
	protected function buildRgbSyntax(
		Matcher $n,
		Matcher $p,
		Matcher $nPNone,
		Matcher $optionalAlpha,
		Matcher $optionalLegacyAlpha
	): Alternative {
		return new Alternative( [
			// <legacy-rgb-syntax>
			new Juxtaposition( [ Quantifier::hash( $p, 3, 3 ), $optionalLegacyAlpha ], true ),
			new Juxtaposition( [ Quantifier::hash( $n, 3, 3 ), $optionalLegacyAlpha ], true ),
			// <modern-rgb-syntax>
			new Juxtaposition( [ Quantifier::count( $nPNone, 3, 3 ), $optionalAlpha ] ),
		] );
	}

	/**
	 * Helper to build the syntax for hsl() and hsla() functions.
	 * @param Matcher $hueWithVar Hue matcher (number, angle, including var)
	 * @param Matcher $p Percentage matcher (including var)
	 * @param Matcher $hueNone Hue or none matcher (including var)
	 * @param Matcher $nPNone Number, percentage, or none matcher (including var)
	 * @param Matcher $optionalAlpha Modern alpha syntax matcher
	 * @param Matcher $optionalLegacyAlpha Legacy alpha syntax matcher
	 */
	protected function buildHslSyntax(
		Matcher $hueWithVar,
		Matcher $p,
		Matcher $hueNone,
		Matcher $nPNone,
		Matcher $optionalAlpha,
		Matcher $optionalLegacyAlpha
	): Alternative {
		return new Alternative( [
			// <legacy-hsl-syntax>
			new Juxtaposition( [ $hueWithVar, $p, $p, $optionalLegacyAlpha ], true ),
			// <modern-hsl-syntax>
			new Juxtaposition( [ $hueNone, $nPNone, $nPNone, $optionalAlpha ] ),
		] );
	}

	/**
	 * Helper to build standard color syntax (components + optional alpha).
	 * @param Matcher[] $components
	 */
	protected function buildStandardColorSyntax( array $components, Matcher $optionalAlpha ): Juxtaposition {
		return new Juxtaposition( [ ...$components, $optionalAlpha ] );
	}

	/**
	 * Helper to create a relative color channel matcher.
	 * @param string $channel Character representing the channel (e.g., 'r', 'h')
	 * @param Matcher $typeMatcher Matcher for the channel's type (e.g., $nPNone, $hueNone)
	 */
	protected function createRelativeColorChannel(
		string $channel,
		Matcher $typeMatcher,
	): Alternative {
		return new Alternative( [
			new KeywordMatcher( [ $channel ] ),
			$typeMatcher,
			$this->mathFunction( new KeywordMatcher( [ $channel ] ) )
		] );
	}

	/**
	 * Helper to build the Juxtaposition for relative color syntax.
	 * @param Matcher $originColor Matcher for the base color
	 * @param Matcher[] $componentMatchers Array of matchers for the color components
	 * @param Matcher $optionalAlphaCalc Matcher for the optional alpha calculation
	 */
	protected function buildRelativeColorSyntax(
		Matcher $originColor,
		array $componentMatchers,
		Matcher $optionalAlphaCalc
	): Juxtaposition {
		return new Juxtaposition( [
			new KeywordMatcher( [ 'from' ] ),
			$originColor,
			...$componentMatchers,
			$optionalAlphaCalc
		] );
	}

	/**
	 * Wraps the parent `mathFunction` to allow using variables in the $typeMatcher
	 * @inheritDoc
	 */
	public function mathFunction( Matcher $typeMatcher ) {
		if ( !$this->varEnabled ) {
			return parent::mathFunction( $typeMatcher );
		}

		return parent::mathFunction( new Alternative( [
			$typeMatcher,
			new FunctionMatcher( 'var', new CustomPropertyMatcher() ),
		] ) );
	}

	/**
	 * Allow variables for numbers if enabled
	 *
	 * Alternative and TokenMatcher are both Matchers, so the union upstream's $cache
	 * declares -- Matcher|Matcher[] -- already covers what this returns.
	 *
	 * @return Matcher|Matcher[]
	 */
	public function rawNumber() {
		if ( !$this->varEnabled ) {
			return parent::rawNumber();
		}

		$this->cache[__METHOD__]
			??= new Alternative( [
				new TokenMatcher( Token::T_NUMBER ),
				new FunctionMatcher( 'var', new CustomPropertyMatcher() ),
			] );

		return $this->cache[__METHOD__];
	}
}
