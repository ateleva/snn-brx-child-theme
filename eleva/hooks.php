<?php
/**
 * Custom hooks and filters for finiture project.
 * Add all add_action() and add_filter() calls here.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Disable auto-update from GitHub — theme is managed via git manually.
add_action( 'after_setup_theme', function() {
    remove_filter( 'pre_set_site_transient_update_themes', 'snn_brx_check_theme_update_proxy' );
    remove_filter( 'themes_api', 'snn_brx_theme_info_from_proxy', 10 );
    remove_action( 'admin_footer', 'snn_brx_github_redirect_version_link' );
}, 99 );

add_action( 'init', function() {
    \Bricks\Elements::register_element( get_stylesheet_directory() . '/eleva/elements/title.php' );
}, 11 );

/** Abilita la tassonomia product_brand sul CPT wkf_document */
add_action( 'init', 'wkf_link_brand_to_document_cpt', 999 );

function wkf_link_brand_to_document_cpt() {
    // Verifichiamo che la tassonomia e il post type esistano per evitare errori
    if ( taxonomy_exists( 'product_brand' ) && post_type_exists( 'wkf_document' ) ) {
        register_taxonomy_for_object_type( 'product_brand', 'wkf_document' );
    }
}

/**
 * Bind Relevanssi Live Ajax Search to Bricks' native "Cerca" (search) widget.
 *
 * Relevanssi Live Ajax Search only auto-hijacks forms rendered via WP core's
 * get_search_form() or the core/search block (both filtered in the plugin).
 * Bricks' Search element includes searchform.php directly, bypassing both,
 * so its input never gets the required data-rlvlive attribute. We call the
 * plugin's own jQuery method on it manually instead.
 *
 * A results container is injected as a sibling of the input, inside the
 * Bricks search element's own wrapper (.brxe-search), rather than left to
 * the plugin's default of appending to <body>. That keeps the dropdown
 * inside the DOM subtree the element's own Custom CSS control can reach
 * (Bricks compiles that control's `%root%` selector to `#brxe-<id>`, a
 * descendant selector that only matches inside the element itself) — so
 * the dropdown can be styled from Bricks instead of a theme stylesheet.
 */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! wp_script_is( 'relevanssi-live-search-client', 'registered' ) ) {
        return;
    }
    wp_add_inline_script(
        'relevanssi-live-search-client',
        'jQuery(function($){' .
        '$(".bricks-search-form input[name=\"s\"]").each(function(){' .
        'var $input=$(this),$wrap=$input.closest(".brxe-search");' .
        'if(!$wrap.length){return;}' .
        'var rid="wkf-live-search-results-"+($wrap.attr("id")||"x");' .
        'if(!$wrap.find("#"+rid).length){$wrap.append("<div id=\""+rid+"\"></div>");}' .
        '$input.attr("data-rlvparentel","#"+rid).attr("data-rlvconfig","wkf_products");' .
        '$input.relevanssi_live_search();' .
        '});' .
        '});'
    );
}, 20 );

/**
 * Custom Relevanssi Live Ajax Search config for the header product search.
 * Width is left to CSS (see the search element's Custom CSS) instead of the
 * plugin's default of matching the input's own width, since result rows
 * need room for a thumbnail + title + brand + price.
 *
 * min_chars is set explicitly to 2 (matches relevanssi_min_word_length,
 * lowered site-wide so short alphanumeric product codes like "FS-C" are
 * indexed at all — see relevanssi_min_word_length below). The plugin's own
 * Relevanssi_Live_Search_Form::filter_configs() only ever raises min_chars
 * up to relevanssi_min_word_length, never lowers it, so this has to be set
 * here rather than left at the plugin's hardcoded default of 3.
 */
add_filter( 'relevanssi_live_search_configs', function( $configs ) {
    $configs['wkf_products']                       = $configs['default'];
    $configs['wkf_products']['results']['width']   = 'css';
    $configs['wkf_products']['input']['min_chars'] = 2;
    return $configs;
} );

/**
 * Lower Relevanssi's minimum indexed word length from the default 3 to 2.
 *
 * This catalog has product names/codes made of very short tokens (e.g.
 * "FS-C" — hyphens are tokenized as word breaks, so at the default length
 * that title indexes to zero searchable words: "fs" and "c" both fall
 * below the 3-char minimum). At length 2, "fs" is indexed and searchable.
 * Setting this via option so it also governs full-site search, not just
 * the header dropdown — the min_chars sync above keeps both in step.
 */
add_action( 'init', function() {
    if ( '2' !== get_option( 'relevanssi_min_word_length' ) ) {
        update_option( 'relevanssi_min_word_length', 2 );
    }
} );

/** Restrict the header live search dropdown to products only. */
add_filter( 'relevanssi_live_search_query_args', function( $args ) {
    $args['post_type'] = 'product';
    return $args;
} );

/** Show 6 results in the dropdown (a "view all" link covers the rest). */
add_filter( 'relevanssi_live_search_posts_per_page', function() {
    return 6;
} );

/**
 * Load the results template from eleva/ instead of the theme root, per this
 * project's convention that all customizations live under eleva/.
 */
add_filter( 'relevanssi_live_search_template_dir', function() {
    return 'eleva/relevanssi-live-ajax-search';
} );

/**
 * Restrict "product category" Bricks query loops on brand archive pages to
 * only the categories that actually contain a product of that brand.
 *
 * Bricks' term query builder (bricks/includes/query.php, case 'term') only
 * supports the term's own tax_query/parent/child_of — there is no builder
 * control for "terms whose objects also carry a term in another taxonomy",
 * so this can't be done with query-loop settings alone. WP_Term_Query does
 * support it via 'object_ids', and Bricks exposes exactly this filter hook
 * for adding query vars it doesn't have UI for:
 * @see https://academy.bricksbuilder.io/article/filter-bricks-terms-query_vars/
 *
 * Scoped to product_cat term queries on a product_brand archive, so it
 * applies to all brand pages (Baufloor/Akifix/ITW) without hardcoding a
 * specific template or brand.
 */
add_filter( 'bricks/terms/query_vars', function( $query_vars ) {
    $taxonomies = (array) ( $query_vars['taxonomy'] ?? [] );

    if ( ! in_array( 'product_cat', $taxonomies, true ) || ! is_tax( 'product_brand' ) ) {
        return $query_vars;
    }

    $brand = get_queried_object();

    if ( ! $brand instanceof WP_Term ) {
        return $query_vars;
    }

    $product_ids = get_posts(
        array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'product_brand',
                    'field'    => 'term_id',
                    'terms'    => $brand->term_id,
                ),
            ),
        )
    );

    // No products for this brand: force zero results rather than falling
    // back to "all categories" (an empty object_ids array is ignored by
    // WP_Term_Query, not treated as "match nothing").
    $query_vars['object_ids'] = ! empty( $product_ids ) ? $product_ids : array( 0 );

    return $query_vars;
} );

/**
 * Force the main product gallery image (not the thumbnail-slider thumbs) into a
 * square wrapper with the photo centered via `contain`. Bricks' own gallery CSS
 * only sets aspect-ratio/object-fit on thumbnail-slider thumbs, so the main image
 * wrapper otherwise just inherits whatever aspect ratio the source photo has,
 * causing tall/cropped photos to blow out the layout or crop oddly with `cover`.
 */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! wp_style_is( 'bricks-woocommerce', 'registered' ) ) {
        return;
    }
    wp_add_inline_style(
        'bricks-woocommerce',
        '.woocommerce-product-gallery__wrapper .woocommerce-product-gallery__image{aspect-ratio:1/1;position:relative;overflow:hidden;}' .
        '.woocommerce-product-gallery__wrapper .woocommerce-product-gallery__image img.wp-post-image{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;object-position:center;}'
    );
}, 20 );

/**
 * Main navigation (mega menu) assets.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$base = get_stylesheet_directory_uri() . '/eleva/assets';
		$path = get_stylesheet_directory() . '/eleva/assets';

		wp_enqueue_style(
			'wkf-megamenu',
			"$base/css/wkf-megamenu.css",
			[],
			file_exists( "$path/css/wkf-megamenu.css" ) ? filemtime( "$path/css/wkf-megamenu.css" ) : '1.0.0'
		);

		wp_enqueue_script(
			'wkf-megamenu-a11y',
			"$base/js/wkf-megamenu-a11y.js",
			[],
			file_exists( "$path/js/wkf-megamenu-a11y.js" ) ? filemtime( "$path/js/wkf-megamenu-a11y.js" ) : '1.0.0',
			true
		);
	},
	20
);
