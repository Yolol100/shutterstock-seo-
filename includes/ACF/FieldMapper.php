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
		$settings = Settings::get();
		$mapping  = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		if ( empty( $settings['acf_support'] ) ) {
			return true;
		}
		if ( ! function_exists( 'update_field' ) ) {
			( new Logger() )->error( 'acf_missing', array( 'post_id' => $post_id ) );
			return new WP_Error( 'ssia_acf_missing', __( 'ACF is not active. Images were imported but ACF fields were not updated.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		$updated = 0;
		$fields  = array( 'image_1', 'image_2', 'image_3' );
		foreach ( $fields as $index => $key ) {
			$field = (string) ( $mapping[ $key ] ?? '' );
			if ( '' === $field || empty( $attachment_ids[ $index ] ) ) {
				continue;
			}
			if ( ! $this->field_exists_for_post( $field, $post_id ) ) {
				( new Logger() )->error( 'acf_field_unverified', array( 'post_id' => $post_id, 'field' => $field ) );
			}
			$result = update_field( $field, $this->format_value( absint( $attachment_ids[ $index ] ), (string) $settings['acf_return_format'] ), $post_id );
			if ( false !== $result ) {
				++$updated;
			}
		}

		if ( ! empty( $mapping['featured_image'] ) && ! empty( $attachment_ids[0] ) ) {
			if ( set_post_thumbnail( $post_id, absint( $attachment_ids[0] ) ) ) {
				++$updated;
			}
		}

		if ( 0 === $updated ) {
			return new WP_Error( 'ssia_acf_no_fields_updated', __( 'No ACF image fields were updated. Check the ACF Mapping settings.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		( new Logger() )->info( 'acf_attached', array( 'post_id' => $post_id, 'count' => $updated ) );
		return true;
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

	private function format_value( int $attachment_id, string $format ): mixed {
		return match ( $format ) {
			'array' => array(
				'ID'  => $attachment_id,
				'id'  => $attachment_id,
				'url' => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
			),
			'url'   => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
			default => $attachment_id,
		};
	}
}
