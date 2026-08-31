<?php
/**
 * Header live-search dropdown rows: feature image, title, brand, price.
 *
 * Loaded by Relevanssi Live Ajax Search instead of its own default template
 * (see the relevanssi_live_search_template_dir filter in eleva/hooks.php).
 * Query is already restricted to post_type=product by the
 * relevanssi_live_search_query_args filter, so every result here is a
 * WooCommerce product.
 *
 * @package Relevanssi Live Ajax Search
 */

global $wp_query;

?>
<?php if ( have_posts() ) : ?>
	<p class="screen-reader-text" role="status" aria-live="polite">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of results. */
				_n( '%d result found.', '%d results found.', $wp_query->found_posts, 'wkf-woocommerce-utilities' ),
				intval( $wp_query->found_posts )
			)
		);
		?>
	</p>

	<?php
	while ( have_posts() ) :
		the_post();

		$product = wc_get_product( get_the_ID() );
		if ( ! $product ) {
			continue;
		}

		$brand_terms   = get_the_terms( get_the_ID(), 'product_brand' );
		$brand_term    = ( $brand_terms && ! is_wp_error( $brand_terms ) ) ? reset( $brand_terms ) : null;
		$brand_icon    = $brand_term ? get_field( 'brand_icon', $brand_term ) : null;
		$brand_icon_id = $brand_icon ? (int) $brand_icon['ID'] : 0;

		// Respect the site's catalog mode (NP Quote Request for WooCommerce)
		// instead of re-implementing its price-hiding rules here.
		$rfq_enabled = function_exists( 'gpls_woo_get_rfq_enable' ) ? gpls_woo_get_rfq_enable( $product ) : 'no';
		$hide_price  = function_exists( 'gpls_woo_rfq_get_hide_price' ) ? gpls_woo_rfq_get_hide_price( $product ) : 'no';
		$hide_all    = get_option( 'settings_gpls_woo_rfq_limit_to_rfq_only_hide_prices', 'no' );
		$show_price  = ! ( 'yes' === $hide_price || ( 'yes' === $hide_all && 'yes' === $rfq_enabled ) );
		?>
		<div class="relevanssi-live-search-result wkf-live-search-result" role="option" aria-selected="false" data-postype="<?php echo esc_attr( get_post_type() ); ?>" data-postid="<?php echo esc_attr( get_the_ID() ); ?>">
			<a href="<?php the_permalink(); ?>" class="wkf-live-search-result-link">
				<span class="wkf-live-search-result-image">
					<?php
					if ( has_post_thumbnail() ) {
						the_post_thumbnail( 'thumbnail' );
					} else {
						echo wp_kses_post( wc_placeholder_img( 'thumbnail' ) );
					}
					?>
				</span>
				<span class="wkf-live-search-result-info">
					<span class="wkf-live-search-result-title"><?php the_title(); ?></span>
					<?php if ( $brand_term ) : ?>
						<span class="wkf-live-search-result-brand">
							<?php if ( $brand_icon_id ) : ?>
								<?php echo wp_get_attachment_image( $brand_icon_id, 'thumbnail', false, array( 'class' => 'wkf-live-search-result-brand-logo', 'alt' => $brand_term->name ) ); ?>
							<?php else : ?>
								<?php echo esc_html( $brand_term->name ); ?>
							<?php endif; ?>
						</span>
					<?php endif; ?>
					<?php if ( $show_price ) : ?>
						<span class="wkf-live-search-result-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					<?php endif; ?>
				</span>
			</a>
		</div>
	<?php endwhile; ?>

	<?php if ( $wp_query->found_posts > 6 ) : ?>
		<a class="wkf-live-search-view-all" href="<?php echo esc_url( add_query_arg( array( 's' => rawurlencode( get_search_query( false ) ), 'post_type' => 'product' ), home_url( '/' ) ) ); ?>">
			<?php esc_html_e( 'Vedi tutti i risultati', 'wkf-woocommerce-utilities' ); ?>
		</a>
	<?php endif; ?>

<?php else : ?>
	<p class="relevanssi-live-search-no-results" role="status">
		<?php esc_html_e( 'Nessun risultato trovato.', 'wkf-woocommerce-utilities' ); ?>
	</p>
	<?php
	if ( function_exists( 'relevanssi_didyoumean' ) ) {
		relevanssi_didyoumean(
			$wp_query->query_vars['s'],
			'<p class="relevanssi-live-search-didyoumean" role="status">' . esc_html__( 'Forse cercavi', 'wkf-woocommerce-utilities' ) . ': ',
			'</p>'
		);
	}
	?>
<?php endif; ?>
