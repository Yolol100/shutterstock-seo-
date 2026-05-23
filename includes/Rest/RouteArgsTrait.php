<?php
namespace Company\SeoShutterstockAssistant\Rest;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait RouteArgsTrait {
	private function post_id_args(): array {
		return array( 'post_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => absint( $value ) > 0 ) );
	}

	private function license_args(): array {
		return array_merge( $this->post_id_args(), array( 'image_ids' => array( 'required' => true, 'type' => 'array', 'validate_callback' => static fn( $value ): bool => is_array( $value ) && count( array_filter( $value ) ) >= 1 && count( array_filter( $value ) ) <= 4 ) ) );
	}

	private function recover_args(): array {
		return array(
			'post_id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => absint( $value ) > 0 ),
			'action_type' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'validate_callback' => static fn( $value ): bool => in_array( sanitize_key( (string) $value ), array( 'retry_acf', 'retry_import', 'retry_suggestions' ), true ) ),
		);
	}


	private function license_id_arg(): array {
		return array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 128,
		);
	}

	private function optional_text_arg(): array {
		return array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn( $value ): bool => null === $value || ( is_string( $value ) && strlen( $value ) <= 200 ),
		);
	}

	private function size_arg(): array {
		return array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => static fn( $value ): bool => null === $value || '' === $value || in_array( sanitize_key( (string) $value ), array( 'small', 'medium', 'huge', 'supersize', 'vector' ), true ),
		);
	}

	private function per_page_arg(): array {
		return array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn( $value ): bool => 0 === absint( $value ) || ( absint( $value ) >= 1 && absint( $value ) <= 50 ),
		);
	}

	private function assets_arg(): array {
		return array(
			'type'              => 'array',
			'required'          => true,
			'validate_callback' => static fn( $value ): bool => is_array( $value ) && count( $value ) >= 1 && count( $value ) <= 25,
			'items'             => array(
				'type'       => 'object',
				'properties' => array(
					'license_id'       => array( 'type' => 'string' ),
					'image_id'         => array( 'type' => 'string' ),
					'contributor_name' => array( 'type' => 'string' ),
				),
				'required'   => array( 'license_id' ),
			),
		);
	}


	private function search_args(): array {
		return array(
			'query'       => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 160,
			),
			'orientation' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool => null === $value || '' === $value || in_array( sanitize_key( (string) $value ), array( 'horizontal', 'vertical', 'square' ), true ),
			),
			'image_type'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool => null === $value || '' === $value || in_array( sanitize_key( (string) $value ), array( 'photo', 'illustration', 'vector' ), true ),
			),
			'category'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static fn( $value ): bool => null === $value || strlen( (string) $value ) <= 80,
			),
			'color'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_hex_color',
				'validate_callback' => static fn( $value ): bool => null === $value || '' === $value || ( is_string( $value ) && (bool) preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value ) ),
			),
			'safe'        => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
			'sort'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value ): bool => null === $value || '' === $value || in_array( sanitize_key( (string) $value ), array( 'relevance', 'newest', 'popular', 'random' ), true ),
			),
			'per_page'    => $this->per_page_arg(),
			'page'        => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => 0 === absint( $value ) || ( absint( $value ) >= 1 && absint( $value ) <= 100 ) ),
		);
	}

	private function queue_args(): array {
		return array(
			'post_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => 0 === absint( $value ) || absint( $value ) > 0 ),
			'post_ids' => array(
				'type'              => 'array',
				'validate_callback' => static fn( $value ): bool => null === $value || ( is_array( $value ) && count( $value ) <= 50 ),
				'items'             => array( 'type' => 'integer' ),
			),
		);
	}


	private function featured_from_acf_args(): array {
		return array(
			'post_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => null === $value || absint( $value ) > 0 ),
			'post_ids' => array(
				'type'              => 'array',
				'validate_callback' => static fn( $value ): bool => null === $value || ( is_array( $value ) && count( $value ) >= 1 && count( $value ) <= 50 ),
				'items'             => array( 'type' => 'integer' ),
			),
		);
	}

	private function page_args(): array {
		return array(
			'missing'   => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
			'post_type' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'status'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'per_page'  => $this->per_page_arg(),
			'page'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => 0 === absint( $value ) || absint( $value ) <= 100 ),
		);
	}
}