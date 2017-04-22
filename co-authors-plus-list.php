<?php
/**
 * Plugin Name: Co-Authors Plus get_authors
 * Version 0.1
 * Description: Provides the `coauthors_plus_get_authors` function.
 * Plugin URI: https://github.com/soulseekah/co-authors-plus-list
 * Author: Gennady Kovshenin
 * Author URI: https://codeseekah.com
 */

/**
 * Retrieve a list of all authors (including guest authors) sorted in alphabetical order.
 *
 * Build one huge complicated query for Users and Guest Authors, execute it.
 *
 * @param array $atts An array with the following keys:
 *
 *  - `user_roles` array An array of user roles (Default: array( 'author' ))
 *
 *  - `user_fields` array An user fields to fetch, $alias => array( 'meta'|'column' => $key|$column )
 *  - `guest_fields` array An guest fields to fetch, $alias => array( 'meta'|'column' => $key|$column )
 *
 *     Note: the order of columns has to match between users and guests, otherwise
 *           they will be mixed in other keys!
 *
 *  - `resolve_images` array An array of fields that contains attachment IDs to resolve to image URLs (Default: image)
 *  - `hide_empty` boolean Hide authors that have no posts  (Default: false)
 *  - `order` array An associative array of alias => ASC|DESC (Default: see below)
 *  - `number` int The amount to return per page (Default: 5)
 *  - `paged` int The page number to return (offset) (Default: 1)
 *
 * @return stdClass[] An array of objects with the following members:
 *  - `is_guest` bool Whether this is a guest author or not
 *  - `ID` int The ID of either the guest-author WP_Post or WP_User
 *  - `display_name` string The display name
 *  - `last_name` string The last name
 *  - `first_name` string The first name
 *  - `user_login` string Their login
 *  - `user_nicename` string Their generated nicename
 *  - `user_email` string Their email
 *  - `website` string Their user_url
 *  - `description` string Their description
 *  - `post_count` int The amount of posts the author posted
 *  - any other added column aliases
 */
function coauthors_plus_get_authors( $args = array() ) {
	global $coauthors_plus;

	/** Make sure the needed stuff is active. */
	if ( empty( $coauthors_plus ) ) {
		return array();
	}

	$args = wp_parse_args( $args, array(
		'user_roles' => array( 'author', 'contributor' ),

		'user_fields' => array(
			'display_name' => array( 'column', 'display_name' ),
			'first_name' => array( 'meta', 'first_name' ),
			'last_name' => array( 'meta', 'last_name' ),
			'user_login' => array( 'column', 'user_login' ),
			'user_nicename' => array( 'column', 'user_nicename' ),
			'user_email' => array( 'column', 'user_email' ),
			'website' => array( 'column', 'user_url' ),
			'description' => array( 'meta', 'description' ),
		),

		'guest_fields' => array(
			'display_name' => array( 'meta', 'cap-display_name' ),
			'first_name' => array( 'meta', 'cap-first_name' ),
			'last_name' => array( 'meta', 'cap-last_name' ),
			'user_login' => array( 'meta', 'cap-user_login' ),
			'user_nicename' => array( 'column', 'post_name' ),
			'user_email' => array( 'meta', 'cap-user_email' ),
			'website' => array( 'meta', 'cap-website' ),
			'description' => array( 'meta', 'cap-description' ),
		),

		'resolve_images' => array(),

		'hide_empty' => false,

		'order' => array( 'display_name' => 'ASC' ),
		'number' => 5,
		'paged' => 1,
	) );

	/**
	 * @filter `coauthors_plus_get_authors_query_args` Filter the arguments to add stuff if needed.
	 * @param array $args The passed args.
	 */
	$args = apply_filters( 'coauthors_plus_get_authors_query_args', $args );

	if ( implode( '|', array_keys( $args['user_fields'] ) ) != implode( '|', array_keys( $args['guest_fields'] ) ) ) {
		throw new Exception( 'Number of column for users and guests have to match and be in the same order.' );
	}

	global $wpdb;

	/** Users. */
	$columns = array();
	$joins = array();
	$wheres = array();

	/** Header. */
	$user_header = array(
		'is_guest' => 'false',
		'ID' => 'u.ID',
	);
	foreach ( $user_header as $alias => $column ) {
		$columns []= sprintf( "$column $alias" );
	}

	/** User fields. */
	foreach ( $args['user_fields'] as $alias => $field ) {
		list( $type, $column ) = $field;
		if ( $type == 'column' ) {
			$columns []= sprintf( "u.$column $alias" );
		} else {
			$columns []= sprintf( "um_$alias.meta_value $alias" );
			$joins []= sprintf( "LEFT JOIN {$wpdb->usermeta} um_$alias ON um_$alias.user_ID = u.ID" );
			$wheres []= sprintf( "um_$alias.meta_key = '$column'" );
		}
	}

	/** Filter users by capabilities. */
	$joins []= sprintf( "LEFT JOIN {$wpdb->usermeta} caps ON caps.user_ID = u.ID" );
	$caps_like = array_map( function( $role ) {
		return "caps.meta_value LIKE '%:\"$role\";%'";
	}, $args['user_roles'] );
	$wheres []= sprintf( "caps.meta_key = '%scapabilities' AND (%s)", $wpdb->get_blog_prefix(), implode( ' OR ', $caps_like ) );

	/** Add post_count */
	$columns []= sprintf( "tt.count post_count" );
	$joins []= sprintf( "LEFT JOIN {$wpdb->terms} t ON t.slug = concat('cap-', u.user_nicename)" );
	$joins []= sprintf( "LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id" );
	if ( $args['hide_empty'] ) {
		$wheres []= sprintf( "tt.count > 0" );
	}

	/** Build half of the whole thing. */
	$sql = sprintf( "SELECT\n%s", implode( ", ", $columns ) );
	$sql .= sprintf( "\nFROM {$wpdb->users} u\n" );
	$sql .= sprintf( "%s\n", implode( "\n", $joins ) );
	$sql .= sprintf( "WHERE %s\n", implode( "\nAND ", $wheres ) );

	if ( $coauthors_plus->is_guest_authors_enabled() ) {
		/** Guests. */
		$columns = array();
		$joins = array();
		$wheres = array();

		/** Header. */
		$guest_header = array(
			'is_guest' => 'true',
			'ID' => 'g.ID',
		);
		foreach ( $guest_header as $alias => $column ) {
			$columns []= sprintf( "$column $alias" );
		}

		/** Guest fields. */
		foreach ( $args['guest_fields'] as $alias => $field ) {
			list( $type, $column ) = $field;
			if ( $type == 'column' ) {
				$columns []= sprintf( "g.$column $alias" );
			} else {
				$columns []= sprintf( "gm_$alias.meta_value $alias" );
				$joins []= sprintf( "LEFT JOIN {$wpdb->postmeta} gm_$alias ON gm_$alias.post_ID = g.ID" );
				$wheres []= sprintf( "gm_$alias.meta_key = '$column'" );
			}
		}

		/** Filter by Guest post type. */
		$wheres []= "g.post_type = '{$coauthors_plus->guest_authors->post_type}'";

		/** Add post_count */
		$columns []= sprintf( "tt.count post_count" );
		$joins []= sprintf( "LEFT JOIN {$wpdb->terms} t ON t.slug = g.post_name" );
		$joins []= sprintf( "LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id" );
		if ( $args['hide_empty'] ) {
			$wheres []= sprintf( "tt.count > 0" );
		}

		/** Build half of the whole thing. */
		$sql .= sprintf( "\nUNION SELECT\n%s", implode( ", ", $columns ) );
		$sql .= sprintf( "\nFROM {$wpdb->posts} g\n" );
		$sql .= sprintf( "%s\n", implode( "\n", $joins ) );
		$sql .= sprintf( "WHERE %s\n", implode( "\nAND ", $wheres ) );
	}

	/** Order. */
	$orders = array();
	foreach ( $args['order'] as $column => $direction ) {
		$orders []= "$column $direction";
	}

	$sql .= sprintf( "ORDER BY %s\n", implode( ", ", $orders ) );
	if ( $args['number'] > 0 ) {
		$sql .= sprintf( "LIMIT %d, %d", ( $args['paged'] - 1 ) * $args['number'], $args['number'] );
	}

	$authors = $wpdb->get_results( $sql );

	/**
	 * Fetch all attachments for resolution.
	 */
	$attachment_ids = array();
	foreach ( $authors as $author ) {
		foreach ( $args['resolve_images'] as $image_field ) {
			if ( intval( $author->$image_field ) > 0 ) {
				$attachment_ids []= $author->$image_field;
			}
		}
	}

	if ( $attachment_ids ) {
		$attachments = array();
		foreach ( $wpdb->get_results( sprintf( "SELECT post_ID, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_ID IN (%s)",
			implode( ',', array_map( 'intval', $attachment_ids ) ) ), ARRAY_N ) as $attachment ) {
				$attachments[$attachment[0]] = $attachment[1];
			}

		foreach ( $authors as $author ) {
			foreach ( $args['resolve_images'] as $image_field ) {
				if ( $author->$image_field && ! empty( $attachments[$author->$image_field] ) ) {
					$author->$image_field = $attachments[$author->$image_field];
				}
			}
		}
	}

	return $authors;
}
