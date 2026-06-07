<?php
/**
 * Field Detector
 *
 * Detects custom fields registered by third-party plugins:
 *  - Advanced Custom Fields (ACF) — free and Pro
 *  - MetaBox (by MetaBox.io)
 *  - WooCommerce product attributes, price, stock, SKU
 *
 * Each method checks whether the relevant plugin is active before
 * attempting to call its API. If the plugin is absent, the method
 * returns an empty array — this is safe to call unconditionally.
 *
 * Consuming third-party filters and functions requires phpcs:ignore
 * comments per WordPress Plugin Standards (WORDPRESS-PLUGIN-STANDARDS.md §2).
 *
 * @package AIChatMate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AICM_Field_Detector
 */
class AICM_Field_Detector {

	// -------------------------------------------------------------------------
	// ACF — Advanced Custom Fields
	// -------------------------------------------------------------------------

	/**
	 * Detect ACF fields registered for a given post type.
	 *
	 * ACF stores field groups and their fields in its own tables (or CPTs in
	 * older versions). We use ACF's PHP API to read them — much more reliable
	 * than querying postmeta directly.
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string, array> Map of field_name => field_info.
	 *                              Each field_info: { label, type, acf_type }
	 */
	public static function get_acf_fields( string $post_type ): array {
		// Guard: ACF not active.
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$fields = array();

		// Retrieve all field groups that apply to this post type.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming ACF's public PHP API
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );

		if ( empty( $groups ) || ! is_array( $groups ) ) {
			return array();
		}

		foreach ( $groups as $group ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming ACF's public PHP API
			$group_fields = acf_get_fields( $group );

			if ( empty( $group_fields ) || ! is_array( $group_fields ) ) {
				continue;
			}

			foreach ( $group_fields as $field ) {
				$field_name = $field['name'] ?? '';
				if ( '' === $field_name ) {
					continue;
				}

				$fields[ $field_name ] = array(
					'label'    => $field['label'] ?? $field_name,
					'type'     => self::map_acf_type( $field['type'] ?? 'text' ),
					'acf_type' => $field['type'] ?? 'text',
					'source'   => 'acf',
				);

				// For choice fields (select, checkbox, radio), capture the choices
				// so the AI knows what valid values look like.
				if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$fields[ $field_name ]['choices'] = array_values( array_keys( $field['choices'] ) );
				}

				// Numeric range hints from ACF 'range' or 'number' fields.
				if ( isset( $field['min'] ) && '' !== (string) $field['min'] ) {
					$fields[ $field_name ]['min'] = (float) $field['min'];
				}
				if ( isset( $field['max'] ) && '' !== (string) $field['max'] ) {
					$fields[ $field_name ]['max'] = (float) $field['max'];
				}
			}
		}

		return $fields;
	}

	/**
	 * Map an ACF field type to one of our normalised types.
	 *
	 * Our normalised types: 'numeric', 'boolean', 'date', 'text'
	 *
	 * @param string $acf_type ACF field type string.
	 * @return string Normalised type.
	 */
	private static function map_acf_type( string $acf_type ): string {
		return match ( $acf_type ) {
			'number', 'range'                               => 'numeric',
			'true_false'                                    => 'boolean',
			'date_picker', 'date_time_picker', 'time_picker' => 'date',
			default                                         => 'text',
		};
	}

	// -------------------------------------------------------------------------
	// MetaBox
	// -------------------------------------------------------------------------

	/**
	 * Detect MetaBox fields registered for a given post type.
	 *
	 * MetaBox exposes fields via the `rwmb_meta_boxes` filter. We call
	 * apply_filters with an empty array and inspect the registered boxes.
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string, array> Map of field_name => field_info.
	 */
	public static function get_metabox_fields( string $post_type ): array {
		// Guard: MetaBox not active.
		if ( ! class_exists( 'RWMB_Loader' ) ) {
			return array();
		}

		$fields = array();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- consuming MetaBox plugin's public filter
		$meta_boxes = apply_filters( 'rwmb_meta_boxes', array() );

		if ( empty( $meta_boxes ) || ! is_array( $meta_boxes ) ) {
			return array();
		}

		foreach ( $meta_boxes as $box ) {
			// Each box has a 'post_types' (or 'post_type') key.
			$box_post_types = $box['post_types'] ?? $box['post_type'] ?? array();
			if ( is_string( $box_post_types ) ) {
				$box_post_types = array( $box_post_types );
			}

			// Skip boxes not applicable to this post type.
			if ( ! in_array( $post_type, (array) $box_post_types, true ) ) {
				continue;
			}

			foreach ( $box['fields'] ?? array() as $field ) {
				$field_id = $field['id'] ?? '';
				if ( '' === $field_id ) {
					continue;
				}

				$fields[ $field_id ] = array(
					'label'  => $field['name'] ?? $field_id,
					'type'   => self::map_metabox_type( $field['type'] ?? 'text' ),
					'source' => 'metabox',
				);

				// Capture options/choices for select/radio/checkbox_list fields.
				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					$fields[ $field_id ]['choices'] = array_values( array_keys( $field['options'] ) );
				}

				if ( isset( $field['min'] ) ) {
					$fields[ $field_id ]['min'] = (float) $field['min'];
				}
				if ( isset( $field['max'] ) ) {
					$fields[ $field_id ]['max'] = (float) $field['max'];
				}
			}
		}

		return $fields;
	}

	/**
	 * Map a MetaBox field type to a normalised type.
	 *
	 * @param string $mb_type MetaBox field type string.
	 * @return string Normalised type.
	 */
	private static function map_metabox_type( string $mb_type ): string {
		return match ( $mb_type ) {
			'number', 'range', 'slider'       => 'numeric',
			'checkbox', 'switch'              => 'boolean',
			'date', 'datetime', 'time'        => 'date',
			default                           => 'text',
		};
	}

	// -------------------------------------------------------------------------
	// WooCommerce
	// -------------------------------------------------------------------------

	/**
	 * Detect WooCommerce product fields (price, stock, SKU, attributes).
	 *
	 * Only relevant when post_type = 'product'.
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string, array> Map of field_name => field_info.
	 */
	public static function get_woocommerce_fields( string $post_type ): array {
		// Guard: only for WooCommerce product post type.
		if ( 'product' !== $post_type || ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		// Standard WooCommerce product meta fields.
		$fields = array(
			'_price'         => array(
				'label'  => __( 'Price', 'ai-chatmate' ),
				'type'   => 'numeric',
				'source' => 'woocommerce',
			),
			'_regular_price' => array(
				'label'  => __( 'Regular Price', 'ai-chatmate' ),
				'type'   => 'numeric',
				'source' => 'woocommerce',
			),
			'_sale_price'    => array(
				'label'  => __( 'Sale Price', 'ai-chatmate' ),
				'type'   => 'numeric',
				'source' => 'woocommerce',
			),
			'_sku'           => array(
				'label'  => __( 'SKU', 'ai-chatmate' ),
				'type'   => 'text',
				'source' => 'woocommerce',
			),
			'_stock'         => array(
				'label'  => __( 'Stock Quantity', 'ai-chatmate' ),
				'type'   => 'numeric',
				'source' => 'woocommerce',
			),
			'_stock_status'  => array(
				'label'   => __( 'Stock Status', 'ai-chatmate' ),
				'type'    => 'text',
				'choices' => array( 'instock', 'outofstock', 'onbackorder' ),
				'source'  => 'woocommerce',
			),
		);

		// Product attribute taxonomies (e.g. pa_color, pa_size).
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if ( ! empty( $attribute_taxonomies ) ) {
			foreach ( $attribute_taxonomies as $attribute ) {
				$tax_name            = wc_attribute_taxonomy_name( $attribute->attribute_name );
				$fields[ $tax_name ] = array(
					'label'  => $attribute->attribute_label,
					'type'   => 'text',
					'source' => 'woocommerce_attribute',
				);
			}
		}

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Availability checks (used by the admin UI to show status badges)
	// -------------------------------------------------------------------------

	/**
	 * @return bool True if ACF (free or Pro) is active.
	 */
	public static function has_acf(): bool {
		return function_exists( 'acf_get_field_groups' );
	}

	/**
	 * @return bool True if MetaBox is active.
	 */
	public static function has_metabox(): bool {
		return class_exists( 'RWMB_Loader' );
	}

	/**
	 * @return bool True if WooCommerce is active.
	 */
	public static function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}
}
