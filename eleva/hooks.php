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
 * Pick the product category template by the term's depth in the hierarchy:
 *
 *   depth 0 (macro, no parent)  -> "Categoria L1"      (1290)
 *   depth 1 or 2 (L2 / L3)      -> "Categorie L2/L3"   (1277)
 *   "Extra" (32) and anything deeper -> no template, default WooCommerce archive
 *
 * The category tree is designed to be three levels deep. L2 and L3 share one
 * template because their only difference - L3 terms have no children, so the
 * "Meta filtri" sub-category nav has nothing to list - is already handled by
 * that nav's own {wkf_term_child_count} condition.
 *
 * Bricks template conditions can target a taxonomy, single terms, or "all terms
 * + include children", but have no notion of depth, and listing every L2/L3
 * term id by hand would need updating every time a category is added or moved.
 * So both templates keep the broad "product_cat: all terms" condition and this
 * filter decides between them, using the hook Bricks fires right after it
 * resolves the active templates:
 * @see https://academy.bricksbuilder.io/article/filter-bricks-active_templates/
 *
 * It must *assign*, not merely drop: Bricks resolves one template per area, so
 * clearing the one it picked leaves the page with none rather than falling
 * through to the other candidate. Only its own two templates (or an empty slot)
 * are overwritten, so a future template with a narrower condition still wins.
 * Builder/preview requests are left alone so both templates stay editable.
 */
add_filter( 'bricks/active_templates', function( $active_templates, $post_id, $content_type ) {
    $category_templates = array(
        'l1'    => 1290,
        'l2_l3' => 1277,
    );

    if ( ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) ||
         ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() ) ||
         in_array( (int) $post_id, $category_templates, true ) ) {
        return $active_templates;
    }

    if ( ! is_tax( 'product_cat' ) ) {
        return $active_templates;
    }

    $term = get_queried_object();

    if ( ! $term instanceof WP_Term ) {
        return $active_templates;
    }

    $extra_term_id = 32;
    $depth         = count( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );

    if ( $term->term_id === $extra_term_id ) {
        $template_id = 0;
    } elseif ( 0 === $depth ) {
        $template_id = $category_templates['l1'];
    } elseif ( $depth <= 2 ) {
        $template_id = $category_templates['l2_l3'];
    } else {
        $template_id = 0;
    }

    // 'content' plus the content-type alias Bricks copies it into (wc_archive).
    $slots = array( 'content' );

    if ( is_string( $content_type ) && '' !== $content_type && array_key_exists( $content_type, $active_templates ) ) {
        $slots[] = $content_type;
    }

    foreach ( $slots as $slot ) {
        $current = isset( $active_templates[ $slot ] ) && is_numeric( $active_templates[ $slot ] ) ? (int) $active_templates[ $slot ] : 0;

        if ( 0 === $current || in_array( $current, $category_templates, true ) ) {
            $active_templates[ $slot ] = $template_id;
        }
    }

    return $active_templates;
}, 10, 3 );

/**
 * Remove the bogus "Pagina 1" crumb from the WooCommerce breadcrumb.
 *
 * WC_Breadcrumb::paged_trail() appends a page crumb whenever the `paged` query
 * var is truthy. On these archives it always is: a Bricks query loop set to
 * "is archive main query" has its query vars merged into the main query by
 * Bricks\Database::set_main_archive_query() (pre_get_posts, priority 10), and
 * those vars always carry paged = 1, even on the first page with no /page/N/
 * in the URL. So every category archive using the L2/L3 template ended its
 * breadcrumb with "… / Pagina 1".
 *
 * Fixed here rather than by stopping Bricks from setting `paged`, which is what
 * drives that loop's own pagination. Real page 2+ keeps its crumb.
 */
add_filter( 'woocommerce_get_breadcrumb', function( $crumbs ) {
    if ( ! is_array( $crumbs ) || (int) get_query_var( 'paged' ) > 1 ) {
        return $crumbs;
    }

    $last = end( $crumbs );
    reset( $crumbs );

    if ( ! is_array( $last ) || ! isset( $last[0] ) ) {
        return $crumbs;
    }

    /* translators: %d: page number - must match WooCommerce's own paged crumb. */
    if ( $last[0] === sprintf( __( 'Page %d', 'woocommerce' ), 1 ) ) {
        array_pop( $crumbs );
    }

    return $crumbs;
}, 20 );

/**
 * Drop the query trail markup from the two decorative loops of the "Categoria
 * L2" template: the sub-category chips (mfchp1) and the brand badge inside each
 * product card (cdbrnd).
 *
 * Bricks appends a hidden "query trail" node after every query loop to carry
 * the query vars for infinite scroll / AJAX filtering. Neither of these loops
 * is ever filtered or paginated - the chips are the term's children, the badge
 * is the product's single brand - so the trail is dead markup, and the badge
 * one is repeated once per card. The product grid loop keeps its trail: that
 * query is the one AJAX filters would target.
 *
 * @see https://academy.bricksbuilder.io/article/filter-bricks-render_query_loop_trail/
 */
add_filter( 'bricks/render_query_loop_trail', function( $render, $element_instance ) {
    $element_id = $element_instance->element['id'] ?? '';

    if ( in_array( $element_id, array( 'mfchp1', 'cdbrnd' ), true ) ) {
        return false;
    }

    return $render;
}, 10, 2 );

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
 * Related-products loop for the three brand single-product templates —
 * ITW (65) "Prodotti correlati", Akifix (434) "Prodotti correlati",
 * Baufloor (439) "Altri clienti hanno acquistato anche".
 *
 * Bricks' query-loop builder has no "related products" object type, so each
 * template's loop element (`wkfrelloop` / `akrelloop` / `bfrelloop`) carries
 * only a placeholder product query and this filter swaps in the real id set:
 * WooCommerce related products (shared category / tags), falling back to
 * other products of the same product_brand when WC returns none — e.g. a
 * product that is the only one in its category. The `bricks/element/render`
 * gate below removes the whole section (heading + "filetto righello"
 * included) — `wkfrelsec` / `akrelsec` / `bfrelsec` — when even the fallback
 * is empty.
 *
 * IDs are resolved once per request and cached so the render gate and the
 * loop query agree.
 */
function wkf_related_product_ids() {
	static $cache = null;

	$product_id = (int) get_the_ID();

	if ( is_array( $cache ) && $cache['id'] === $product_id ) {
		return $cache['ids'];
	}

	$ids = array();

	if ( $product_id && function_exists( 'wc_get_related_products' ) ) {
		$ids = wc_get_related_products( $product_id, 4 );
	}

	if ( $product_id && count( $ids ) < 4 ) {
		$brands = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'ids' ) );

		if ( ! is_wp_error( $brands ) && $brands ) {
			$fill = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 4 - count( $ids ),
					'post__not_in'   => array_merge( array( $product_id ), $ids ),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array(
						array(
							'taxonomy' => 'product_brand',
							'field'    => 'term_id',
							'terms'    => $brands,
						),
					),
				)
			);

			$ids = array_merge( $ids, $fill );
		}
	}

	$ids   = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 4 );
	$cache = array( 'id' => $product_id, 'ids' => $ids );

	return $ids;
}

add_filter( 'bricks/posts/query_vars', function ( $query_vars, $settings, $element_id ) {
	if ( ! in_array( $element_id, array( 'wkfrelloop', 'akrelloop', 'bfrelloop' ), true ) ) {
		return $query_vars;
	}

	$ids = wkf_related_product_ids();

	$query_vars['post_type']           = 'product';
	$query_vars['post__in']            = $ids ? $ids : array( 0 );
	$query_vars['posts_per_page']      = 4;
	$query_vars['orderby']             = 'post__in';
	$query_vars['ignore_sticky_posts'] = true;

	unset( $query_vars['tax_query'], $query_vars['meta_query'], $query_vars['s'] );

	return $query_vars;
}, 10, 3 );

add_filter( 'bricks/element/render', function ( $render, $element ) {
	// Bricks passes the element OBJECT here, not the settings array.
	$element_id = is_object( $element ) ? ( $element->element['id'] ?? '' ) : ( $element['id'] ?? '' );

	if ( in_array( $element_id, array( 'wkfrelsec', 'akrelsec', 'bfrelsec' ), true ) ) {
		return $render && ! empty( wkf_related_product_ids() );
	}

	return $render;
}, 10, 2 );

/**
 * Header «Preventivo» quote-list count.
 *
 * woo-rfq-for-woocommerce runs in RFQ-only mode here: the "Lista preventivo"
 * IS the WooCommerce cart (prices hidden), so the count is WC()->cart's item
 * count. The plugin ships no live fragment, but every add-to-list reloads the
 * page, so a server-rendered count is always current.
 *
 *   [wkf_rfq_count]           -> "" when empty, else zero-padded ("03").
 *   [wkf_rfq_count wrap="1"]  -> the header badge form: same value wrapped in
 *                               <span class="wkf-rfq-n"> when non-empty, and
 *                               nothing at all when empty — so the badge can
 *                               hide itself with :not(:has(.wkf-rfq-n)).
 *   [wkf_rfq_count plain="1"] -> the raw integer ("0" included). Phase 5 modal
 *                               header ("N articoli").
 */
function wkf_rfq_cart_count() {
	if ( function_exists( 'WC' ) && WC()->cart ) {
		return (int) WC()->cart->get_cart_contents_count();
	}
	if ( function_exists( 'gpls_woo_rfq_get_rfq_cart_quantity' ) ) {
		return (int) gpls_woo_rfq_get_rfq_cart_quantity();
	}

	return 0;
}

function wkf_rfq_count_value() {
	$count = wkf_rfq_cart_count();

	return $count > 0 ? str_pad( (string) $count, 2, '0', STR_PAD_LEFT ) : '';
}

add_shortcode( 'wkf_rfq_count', function ( $atts ) {
	$atts = shortcode_atts( array( 'wrap' => '', 'plain' => '' ), $atts, 'wkf_rfq_count' );

	if ( $atts['plain'] ) {
		return (string) wkf_rfq_cart_count();
	}

	$value = wkf_rfq_count_value();

	if ( '' === $value ) {
		return '';
	}

	return $atts['wrap']
		? '<span class="wkf-rfq-n">' . esc_html( $value ) . '</span>'
		: esc_html( $value );
} );

/**
 * Phase 5 quote-cart modal — the modal body renders [wkf_mini_cart] (there is
 * no core [woocommerce_mini_cart] shortcode; the mini-cart is a template
 * function). RFQ-only mode: the cart IS the quote list, prices already
 * suppressed by the plugin. Two mini-cart-only tweaks to match the design row
 * [ thumb | name + SKU | qty ]:
 *   - append the product SKU under the name
 *   - reduce the quantity cell to the bare number (no "× price")
 * Scoped with a flag so the full cart / checkout are untouched.
 */
add_shortcode( 'wkf_mini_cart', function () {
	if ( ! function_exists( 'woocommerce_mini_cart' ) ) {
		return '';
	}

	ob_start();
	echo '<div class="widget_shopping_cart_content">';
	woocommerce_mini_cart();
	echo '</div>';

	return ob_get_clean();
} );

add_action( 'woocommerce_before_mini_cart', function () { $GLOBALS['wkf_in_mini_cart'] = true; } );
add_action( 'woocommerce_after_mini_cart', function () { unset( $GLOBALS['wkf_in_mini_cart'] ); } );

add_filter( 'woocommerce_cart_item_name', function ( $name, $cart_item ) {
	if ( empty( $GLOBALS['wkf_in_mini_cart'] ) ) {
		return $name;
	}

	$product = $cart_item['data'] ?? null;
	$sku     = ( $product instanceof WC_Product ) ? $product->get_sku() : '';

	return $sku ? $name . '<span class="wkf-rfq-sku">' . esc_html( $sku ) . '</span>' : $name;
}, 10, 2 );

add_filter( 'woocommerce_widget_cart_item_quantity', function ( $html, $cart_item ) {
	if ( empty( $GLOBALS['wkf_in_mini_cart'] ) ) {
		return $html;
	}

	return '<span class="quantity">' . intval( $cart_item['quantity'] ) . '</span>';
}, 200, 2 );

/**
 * Main navigation (mega menu) assets.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$base = get_stylesheet_directory_uri() . '/eleva/assets';
		$path = get_stylesheet_directory() . '/eleva/assets';

		wp_enqueue_style(
			'wkf-components',
			"$base/css/wkf-components.css",
			[],
			file_exists( "$path/css/wkf-components.css" ) ? filemtime( "$path/css/wkf-components.css" ) : '1.0.0'
		);

		wp_enqueue_style(
			'wkf-megamenu',
			"$base/css/wkf-megamenu.css",
			[ 'wkf-components' ],
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
