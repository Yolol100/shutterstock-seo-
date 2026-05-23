<?php
namespace Company\SeoShutterstockAssistant\ACF;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Logs\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldMapper {
	public function attach( int $post_id, array $attachment_ids ): bool|WP_Error {
		$settings       = Settings::get();
		$mapping        = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		$attachment_ids = array_values( array_filter( array_map( 'absint', $attachment_ids ) ) );
		$updated        = 0;
		$cursor         = 0;

		if ( empty( $attachment_ids ) ) {
			return new WP_Error( 'ssia_no_attachments', __( 'No imported attachments were available to place.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		if ( ! has_post_thumbnail( $post_id ) && ! empty( $attachment_ids[ $cursor ] ) ) {
			if ( set_post_thumbnail( $post_id, absint( $attachment_ids[ $cursor ] ) ) ) {
				++$updated;
				++$cursor;
			}
		}

		if ( ! empty( $settings['acf_support'] ) ) {
			if ( ! function_exists( 'update_field' ) ) {
				( new Logger() )->error( 'acf_missing', array( 'post_id' => $post_id ) );
				return $updated > 0 ? true : new WP_Error( 'ssia_acf_missing', __( 'ACF is not active. The featured image may have been updated, but ACF fields were not updated.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
			}

			foreach ( array( 'image_1', 'image_2', 'image_3' ) as $key ) {
				if ( empty( $attachment_ids[ $cursor ] ) ) {
					break;
				}
				$field = (string) ( $mapping[ $key ] ?? $key );
				if ( '' === $field ) {
					continue;
				}
				$current = get_field( $field, $post_id );
				if ( ! empty( $current ) ) {
					continue;
				}
				if ( ! $this->field_exists_for_post( $field, $post_id ) ) {
					( new Logger() )->error( 'acf_field_unverified', array( 'post_id' => $post_id, 'field' => $field ) );
				}
				$result = update_field( $field, $this->format_value( absint( $attachment_ids[ $cursor ] )), $post_id );
				if ( false !== $result ) {
					++$updated;
					++$cursor;
				}
			}
		}

		if ( 0 === $updated ) {
			return new WP_Error( 'ssia_no_empty_slots_updated', __( 'No empty image slots were updated. Check whether the page already has images or the ACF mapping is correct.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		( new Logger() )->info( 'images_attached', array( 'post_id' => $post_id, 'count' => $updated ) );
		return true;
	}


	public function maybe_set_featured_image_from_first_acf( int $post_id ): bool|WP_Error {
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'ssia_forbidden', __( 'You cannot edit this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}

		if ( 'page' !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return new WP_Error( 'ssia_invalid_page', __( 'Only published pages can be updated.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		if ( ! self::is_default_page_template( $post_id ) ) {
			return new WP_Error( 'ssia_not_default_template', __( 'Only default-template pages can be updated.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		if ( has_post_thumbnail( $post_id ) ) {
			return new WP_Error( 'ssia_already_has_featured_image', __( 'This page already has a featured image.', 'seo-shutterstock-image-assistant' ), array( 'status' => 409 ) );
		}

		if ( ! self::acf_image_fields_complete( $post_id ) ) {
			return new WP_Error( 'ssia_acf_images_incomplete', __( 'All mapped ACF image fields must be filled before using the first ACF image as featured image.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$attachment_id = self::first_acf_image_attachment_id( $post_id );
		if ( ! $attachment_id ) {
			return new WP_Error( 'ssia_no_valid_first_acf_attachment', __( 'The first ACF image field does not point to a valid Media Library image.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			return new WP_Error( 'ssia_featured_image_not_set', __( 'The featured image could not be updated.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		( new Logger() )->info( 'featured_image_set_from_acf', array( 'post_id' => $post_id, 'attachment_id' => $attachment_id ) );
		return true;
	}

	public static function can_set_featured_image_from_first_acf( int $post_id ): bool {
		return 'page' === get_post_type( $post_id )
			&& 'publish' === get_post_status( $post_id )
			&& self::is_default_page_template( $post_id )
			&& ! has_post_thumbnail( $post_id )
			&& self::acf_image_fields_complete( $post_id )
			&& self::first_acf_image_attachment_id( $post_id ) > 0;
	}

	public static function acf_image_fields_complete( int $post_id ): bool {
		$fields = self::mapped_acf_image_fields();
		if ( empty( $fields ) ) {
			return false;
		}

		foreach ( $fields as $field ) {
			$value = self::acf_image_value( $post_id, $field );
			if ( self::is_empty_acf_image_value( $value ) ) {
				return false;
			}
		}

		return true;
	}

	public static function first_acf_image_attachment_id( int $post_id ): int {
		$fields = self::mapped_acf_image_fields();
		if ( empty( $fields ) ) {
			return 0;
		}

		return self::resolve_acf_image_attachment_id( self::acf_image_value( $post_id, $fields[0] ) );
	}

	public static function resolve_acf_image_attachment_id( mixed $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			$attachment_id = absint( $value );
		} elseif ( is_array( $value ) ) {
			if ( isset( $value['ID'] ) ) {
				$attachment_id = absint( $value['ID'] );
			} elseif ( isset( $value['id'] ) ) {
				$attachment_id = absint( $value['id'] );
			} elseif ( isset( $value['url'] ) ) {
				$attachment_id = self::attachment_id_from_url( (string) $value['url'] );
			} else {
				$attachment_id = 0;
			}
		} elseif ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$attachment_id = self::attachment_id_from_url( $value );
		} else {
			$attachment_id = 0;
		}

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	private static function mapped_acf_image_fields(): array {
		$settings = Settings::get();
		if ( empty( $settings['acf_support'] ) ) {
			return array();
		}

		$mapping = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		$fields  = array();
		foreach ( array( 'image_1', 'image_2', 'image_3' ) as $slot ) {
			$field = sanitize_key( (string) ( $mapping[ $slot ] ?? $slot ) );
			if ( '' !== $field ) {
				$fields[] = $field;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	private static function acf_image_value( int $post_id, string $field ): mixed {
		return function_exists( 'get_field' ) ? get_field( $field, $post_id ) : get_post_meta( $post_id, $field, true );
	}

	private static function is_empty_acf_image_value( mixed $value ): bool {
		if ( null === $value || false === $value || '' === $value || 0 === $value || '0' === $value ) {
			return true;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}
		return false;
	}

	private static function attachment_id_from_url( string $url ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return 0;
		}
		return absint( attachment_url_to_postid( $url ) );
	}

	private static function is_default_page_template( int $post_id ): bool {
		$template_slug = get_page_template_slug( $post_id );
		$template_meta = (string) get_post_meta( $post_id, '_wp_page_template', true );
		return '' === $template_slug && in_array( $template_meta, array( '', 'default' ), true );
	}

	private function field_exists_for_post( string $field, int $post_id ): bool {
		if ( str_starts_with( $field, 'field_' ) ) {
			return true;
		}
		if ( function_exists( 'get_field_object' ) ) {
			$object = get_field_object( $field, $post_id, false, false );
			return is_array( $object );
		}
		return true;
	}

	private function format_value( int $attachment_id ): mixed {
		// ACF image fields store the attachment ID in postmeta regardless of the
		// configured return format; the return format only affects retrieval via
		// get_field(). Passing the bare ID keeps storage consistent across formats.
		return $attachment_id;
	}
}
