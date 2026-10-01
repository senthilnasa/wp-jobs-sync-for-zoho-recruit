<?php
/**
 * Listing and job-info layouts.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * The built-in layouts, the custom ones an administrator writes, and the
 * sanitizers that keep both safe.
 *
 * A preset is itself a token template, so choosing "Custom" and starting from a
 * preset gives an administrator working markup to edit rather than a blank box.
 */
class Layouts {

	/**
	 * HTML permitted inside a layout template.
	 *
	 * Deliberately structural: enough to build a card, not enough to run
	 * anything. No script, no style, no iframe, no form, no event attributes.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$common = array(
			'class'            => true,
			'id'               => true,
			'title'            => true,
			'dir'              => true,
			'lang'             => true,
			'style'            => true,
			'role'             => true,
			'data-*'           => true,
			'aria-label'       => true,
			'aria-hidden'      => true,
			'aria-describedby' => true,
		);

		$tags = array(
			'div',
			'span',
			'p',
			'section',
			'article',
			'header',
			'footer',
			'aside',
			'ul',
			'ol',
			'li',
			'dl',
			'dt',
			'dd',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'strong',
			'em',
			'b',
			'i',
			'small',
			'mark',
			'sup',
			'sub',
			'abbr',
			'code',
			'pre',
			'blockquote',
			'figure',
			'figcaption',
			'hr',
			'br',
			'time',
			'address',
			'table',
			'thead',
			'tbody',
			'tfoot',
			'tr',
			'th',
			'td',
			'caption',
		);

		$allowed = array();

		foreach ( $tags as $tag ) {
			$allowed[ $tag ] = $common;
		}

		$allowed['a'] = array_merge(
			$common,
			array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			)
		);

		$allowed['img'] = array_merge(
			$common,
			array(
				'src'      => true,
				'srcset'   => true,
				'sizes'    => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'loading'  => true,
				'decoding' => true,
			)
		);

		$allowed['time']['datetime'] = true;
		$allowed['abbr']['title']    = true;
		$allowed['th']['scope']      = true;
		$allowed['td']['colspan']    = true;
		$allowed['th']['colspan']    = true;

		/*
		 * Design exports lean on three more things: an inline SVG icon (the
		 * arrow on a card), a button-shaped element, and responsive images.
		 * None of these can run anything without an attribute that is not in
		 * the list -- no event handlers, no href on <use>, no script child.
		 */
		$allowed['button'] = array_merge(
			$common,
			array(
				'type'     => true,
				'disabled' => true,
				'name'     => true,
				'value'    => true,
			)
		);

		$allowed['picture'] = $common;
		$allowed['source']  = array_merge(
			$common,
			array(
				'src'    => true,
				'srcset' => true,
				'sizes'  => true,
				'media'  => true,
				'type'   => true,
			)
		);

		$svg_common = array_merge(
			$common,
			array(
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'opacity'         => true,
				'transform'       => true,
			)
		);

		$allowed['svg'] = array_merge(
			$svg_common,
			array(
				'xmlns'     => true,
				'viewbox'   => true,
				'width'     => true,
				'height'    => true,
				'focusable' => true,
			)
		);

		$allowed['g']        = $svg_common;
		$allowed['path']     = array_merge( $svg_common, array( 'd' => true ) );
		$allowed['circle']   = array_merge(
			$svg_common,
			array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			)
		);
		$allowed['rect']     = array_merge(
			$svg_common,
			array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			)
		);
		$allowed['line']     = array_merge(
			$svg_common,
			array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			)
		);
		$allowed['polyline'] = array_merge( $svg_common, array( 'points' => true ) );
		$allowed['polygon']  = array_merge( $svg_common, array( 'points' => true ) );

		/**
		 * Filter the HTML allowed in a custom layout template.
		 *
		 * @param array $allowed Tag => attributes, in wp_kses() form.
		 */
		return (array) apply_filters( 'jszr_allowed_template_html', $allowed );
	}

	/**
	 * Inline CSS properties a layout template may use beyond WordPress's list.
	 *
	 * WordPress keeps most inline styles but drops the `list-style` shorthand,
	 * `inset` and `transition`. A design export uses all three, and losing
	 * `list-style: none` is exactly how a card grows bullet points. None of
	 * them can load a resource or run anything.
	 *
	 * @param string[] $properties WordPress's safe property list.
	 * @return string[]
	 */
	public static function allowed_css_properties( $properties ) {
		$extra = array(
			'list-style',
			'list-style-position',
			'list-style-image',
			'inset',
			'inset-inline',
			'inset-block',
			'transition',
			'transition-property',
			'transition-duration',
			'transition-timing-function',
			'transition-delay',
			'object-position',
			'pointer-events',
			'appearance',
			'outline',
			'outline-offset',
			'overflow-wrap',
			'word-break',
			'text-overflow',
			'line-clamp',
			'-webkit-line-clamp',
			'-webkit-box-orient',
			'aspect-ratio',
			'place-items',
			'place-content',
			'align-content',
		);

		/**
		 * Filter the extra inline CSS properties allowed in layout templates.
		 *
		 * @param string[] $extra Property names.
		 */
		$extra = (array) apply_filters( 'jszr_allowed_template_css', $extra );

		return array_values( array_unique( array_merge( (array) $properties, $extra ) ) );
	}

	/**
	 * Filter template markup through the plugin's allow-list.
	 *
	 * The same call serves saving and rendering, so what an administrator sees
	 * survive on save is exactly what reaches the page. The CSS property
	 * filter is attached only for the duration of this call: it must not
	 * widen what post content elsewhere on the site may carry.
	 *
	 * @param string $html Markup to filter.
	 * @return string
	 */
	public static function kses( $html ) {
		add_filter( 'safe_style_css', array( __CLASS__, 'allowed_css_properties' ) );

		$html = wp_kses( (string) $html, self::allowed_html() );

		remove_filter( 'safe_style_css', array( __CLASS__, 'allowed_css_properties' ) );

		return $html;
	}

	/**
	 * Listing layout choices.
	 *
	 * @return array<string,string>
	 */
	public static function listing_layouts() {
		return array(
			'default' => __( 'Default — the plugin\'s standard card', 'jobs-sync-for-zoho-recruit' ),
			'card'    => __( 'Card — image, badges and summary', 'jobs-sync-for-zoho-recruit' ),
			'compact' => __( 'Compact — one line per job', 'jobs-sync-for-zoho-recruit' ),
			'table'   => __( 'Columns — aligned columns on wide screens', 'jobs-sync-for-zoho-recruit' ),
			'custom'  => __( 'Custom — your own HTML', 'jobs-sync-for-zoho-recruit' ),
		);
	}

	/**
	 * Job info layout choices.
	 *
	 * @return array<string,string>
	 */
	public static function job_info_layouts() {
		return array(
			'default' => __( 'Default — a labelled list', 'jobs-sync-for-zoho-recruit' ),
			'inline'  => __( 'Inline — pills in a row', 'jobs-sync-for-zoho-recruit' ),
			'custom'  => __( 'Custom — your own HTML', 'jobs-sync-for-zoho-recruit' ),
		);
	}

	/**
	 * The token template behind a listing preset.
	 *
	 * @param string $layout Layout key.
	 * @return string Empty string for the default layout, which is PHP.
	 */
	public static function listing_preset( $layout ) {
		$closes = sprintf(
			/* translators: %s: the {closing_date} token, replaced with a date. */
			__( 'Closes %s', 'jobs-sync-for-zoho-recruit' ),
			'{closing_date}'
		);

		$presets = array(
			'card'    => '<article class="jszr-job-card jszr-job-card--card">
	{if:thumbnail}<div class="jszr-job-card__media">{thumbnail}</div>{/if:thumbnail}
	<h3 class="jszr-job-card__title"><a href="{permalink}">{title}</a></h3>
	<p class="jszr-job-card__badges">
		{if:employment_type}<span class="jszr-tag">{employment_type}</span>{/if:employment_type}
		{if:remote}<span class="jszr-tag">{remote}</span>{/if:remote}
		{if:experience}<span class="jszr-tag">{experience}</span>{/if:experience}
	</p>
	{if:location}<p class="jszr-job-card__meta">{location}</p>{/if:location}
	{if:excerpt}<p class="jszr-job-card__excerpt">{excerpt}</p>{/if:excerpt}
	<p class="jszr-job-card__footer">
		{view_link}
		{if:closing_date}<span class="jszr-job-card__closing">' . $closes . '</span>{/if:closing_date}
	</p>
</article>',
			'compact' => '<article class="jszr-job-card jszr-job-card--compact">
	<h3 class="jszr-job-card__title"><a href="{permalink}">{title}</a></h3>
	<p class="jszr-job-card__meta">{department}{if:location} &middot; {location}{/if:location}{if:employment_type} &middot; {employment_type}{/if:employment_type}{if:remote} &middot; {remote}{/if:remote}</p>
	<p class="jszr-job-card__footer">{view_link}</p>
</article>',
			'table'   => '<article class="jszr-job-card jszr-job-card--table">
	<span class="jszr-job-card__col jszr-job-card__title"><a href="{permalink}">{title}</a></span>
	<span class="jszr-job-card__col">{department}</span>
	<span class="jszr-job-card__col">{location}{if:remote} &middot; {remote}{/if:remote}</span>
	<span class="jszr-job-card__col">{employment_type}</span>
	<span class="jszr-job-card__col jszr-job-card__col--action">{view_link}</span>
</article>',
		);

		/**
		 * Filter the built-in listing layout templates.
		 *
		 * @param array $presets Layout key => token template.
		 */
		$presets = (array) apply_filters( 'jszr_listing_presets', $presets );

		return isset( $presets[ $layout ] ) ? (string) $presets[ $layout ] : '';
	}

	/**
	 * The token template behind a job info preset.
	 *
	 * @param string $layout Layout key.
	 * @return string
	 */
	public static function job_info_preset( $layout ) {
		$presets = array(
			'inline' => '<p class="jszr-job-meta jszr-job-meta--inline">
	{if:department}<span class="jszr-tag">{department}</span>{/if:department}
	{if:location}<span class="jszr-tag">{location}</span>{/if:location}
	{if:employment_type}<span class="jszr-tag">{employment_type}</span>{/if:employment_type}
	{if:experience}<span class="jszr-tag">{experience}</span>{/if:experience}
	{if:remote}<span class="jszr-tag">{remote}</span>{/if:remote}
	{if:salary}<span class="jszr-tag">{salary}</span>{/if:salary}
</p>',
		);

		/**
		 * Filter the built-in job info layout templates.
		 *
		 * @param array $presets Layout key => token template.
		 */
		$presets = (array) apply_filters( 'jszr_job_info_presets', $presets );

		return isset( $presets[ $layout ] ) ? (string) $presets[ $layout ] : '';
	}

	/**
	 * The named card templates an administrator has saved.
	 *
	 * A site with more than one kind of listing -- faculty roles on one page,
	 * staff openings on another -- needs more than the single custom card. Each
	 * named template is picked per shortcode with `template="name"`.
	 *
	 * @return array<string,string> Name => token template.
	 */
	public static function named_templates() {
		$saved = Settings::get( 'card_templates', array() );
		$out   = array();

		foreach ( is_array( $saved ) ? $saved : array() as $name => $template ) {
			$name     = sanitize_key( (string) $name );
			$template = trim( (string) $template );

			if ( '' !== $name && '' !== $template ) {
				$out[ $name ] = $template;
			}
		}

		/**
		 * Filter the named card templates.
		 *
		 * @param array $out Name => token template.
		 */
		return (array) apply_filters( 'jszr_named_templates', $out );
	}

	/**
	 * The template that should render a job card, if any.
	 *
	 * @param string $layout   Layout key, or an empty string to read the setting.
	 * @param string $template Named template to use instead of the layout.
	 * @return string Empty when the default PHP template should be used.
	 */
	public static function listing_template( $layout = '', $template = '' ) {
		$template = sanitize_key( (string) $template );

		if ( '' !== $template ) {
			$named = self::named_templates();

			if ( isset( $named[ $template ] ) ) {
				return $named[ $template ];
			}
		}

		$layout = '' !== $layout ? $layout : (string) Settings::get( 'listing_layout', 'default' );

		if ( 'custom' === $layout ) {
			$custom = trim( (string) Settings::get( 'card_template', '' ) );

			// An empty custom template would render an empty list, which looks
			// like a broken sync. Fall back to the standard card instead.
			return '' !== $custom ? $custom : '';
		}

		return self::listing_preset( $layout );
	}

	/**
	 * The template that should render the job info block, if any.
	 *
	 * @return string Empty when the default PHP template should be used.
	 */
	public static function job_info_template() {
		$layout = (string) Settings::get( 'job_info_layout', 'default' );

		if ( 'custom' === $layout ) {
			$custom = trim( (string) Settings::get( 'job_info_template', '' ) );

			return '' !== $custom ? $custom : '';
		}

		return self::job_info_preset( $layout );
	}

	/**
	 * Render a template for the settings preview.
	 *
	 * Uses the most recently synced job so the preview shows the site's real
	 * data, and falls back to a sample when nothing has been synced yet.
	 *
	 * @param string $template Token template.
	 * @return string Markup, already filtered through the allow-list.
	 */
	public static function render_preview( $template ) {
		$template = (string) $template;

		if ( '' === trim( $template ) ) {
			return '';
		}

		$posts = get_posts(
			array(
				'post_type'        => Post_Type::POST_TYPE,
				'posts_per_page'   => 1,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$markup = ! empty( $posts )
			? Template_Tags::render( $template, (int) $posts[0] )
			: Template_Tags::render_values( $template, Template_Tags::sample_values() );

		return self::kses( $markup );
	}

	/**
	 * Sanitize a layout template on save.
	 *
	 * @param string $template Raw template.
	 * @return string
	 */
	public static function sanitize_template( $template ) {
		$template = (string) $template;

		if ( '' === trim( $template ) ) {
			return '';
		}

		/*
		 * Braces are not HTML, and wp_kses() drops a token like {title} that
		 * sits inside an attribute value because it is not a valid attribute.
		 * They are swapped for plain words first so the filter leaves them
		 * alone. The words have to be alphanumeric: control characters are
		 * stripped by kses along with everything else it does not recognise,
		 * which silently deletes every token in the template.
		 */
		$open  = 'jszrbraceopen';
		$close = 'jszrbraceclose';

		$template = str_replace( array( '{', '}' ), array( $open, $close ), $template );

		$template = self::kses( $template );

		$template = str_replace( array( $open, $close ), array( '{', '}' ), $template );

		return trim( $template );
	}

	/**
	 * Sanitize administrator CSS.
	 *
	 * The rules here are the ones that matter for a stylesheet printed inside a
	 * page: nothing may close the style element, and nothing may execute.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function sanitize_css( $css ) {
		$css = (string) $css;

		if ( '' === trim( $css ) ) {
			return '';
		}

		// Strip anything that looks like markup, including a closing style tag.
		$css = wp_strip_all_tags( $css );

		// Old IE expressions and script URLs execute; @import fetches remote
		// resources from a stylesheet the site owner may not control.
		$css = (string) preg_replace( '/expression\s*\(/i', '', $css );
		$css = (string) preg_replace( '/(javascript|vbscript|data)\s*:/i', '', $css );
		$css = (string) preg_replace( '/@import\b[^;]*;?/i', '', $css );
		$css = (string) preg_replace( '/behaviou?r\s*:/i', '', $css );
		$css = str_replace( array( '<', '>' ), '', $css );

		return trim( $css );
	}

	/**
	 * The stored custom CSS, ready to print.
	 *
	 * @return string
	 */
	public static function custom_css() {
		/*
		 * Sanitized again on the way out, not only on the way in. The settings
		 * form is not the only thing that can write this option — an import, a
		 * wp option update or a migration all reach it directly — and this
		 * string is printed inside a style element, so it is worth the few
		 * microseconds to be certain.
		 */
		$css = self::sanitize_css( (string) Settings::get( 'custom_css', '' ) );

		/**
		 * Filter the custom CSS added to job listings.
		 *
		 * @param string $css Sanitized CSS.
		 */
		return self::sanitize_css( (string) apply_filters( 'jszr_custom_css', $css ) );
	}
}
