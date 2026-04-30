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
