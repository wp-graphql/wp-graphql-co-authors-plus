<?php
/**
 * Plugin Name: GraphQL integration with Co-Authors Plus
 * Author: WPGraphQL
 * Version: 0.0.2
 * Requires at least: 4.7.0
 *
 * @package WPGraphQL_CoAuthorsPlus
 */

namespace WPGraphQL;

use GraphQL\Type\Definition\ResolveInfo;
use WPGraphQL;

/**
 * GraphQL integration with Co-Authors Plus
 */
class WPGraphQL_CoAuthorsPlus {
	/**
	 * Mapping of GraphQL field names to WordPress field names (on the user
	 * object). Attempt to line up with Types\User where possible. The type of
	 * all of these fields is assumed to be String.
	 *
	 * @var array
	 */
	private $fields = array(
		'email' => 'user_email',
		'firstName' => 'first_name',
		'lastName' => 'last_name',
		'name' => 'display_name',
		'registeredDate' => 'user_registered',
		'slug' => 'user_nicename',
		'type' => 'type',
		'url' => 'user_url',
		'username' => 'user_login',
	);

	/**
	 * CoAuthors Plus taxonomy name.
	 *
	 * @var string
	 */
	private $tax_name = 'author';

	/**
	 * Text domain slug for this plugin.
	 *
	 * @var string
	 */
	private $textdomain = 'wp-graphql-coauthors-plus';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'register_taxonomy_args', array( $this, 'update_taxonomy_args' ), 10, 2 );
		add_action( 'graphql_init', array( $this, 'init' ), 10, 0 );
	}

	/**
	 * Hook into GraphQL actions and filters.
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function init() {
		add_filter( 'graphql_coAuthor_fields', array( $this, 'add_fields' ), 10, 1 );
		add_filter( 'graphql_term_object_connection_query_args', array( $this, 'set_default_args' ), 10, 3 );
	}

	/**
	 * Add additional fields to resolve on the coAuthor type.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @param  array $fields Fields defined on the coAuthor type.
	 * @return array
	 * @since 0.0.1
	 */
	public function add_fields( $fields ) {
		// Define the fields on the Co-Authors type. This should constitute all of
		// the fields we can pull off of a default implementation of Co-Authors
		// Plus. Use the `graphql_coAuthor_fields` filter to define your own.
		foreach ( $this->fields as $name => $field ) {
			$fields [ $name ] = array(
				'type'        => Types::string(),
				'description' => __( sprintf( 'The %s of the author', $field ), $this->textdomain ),
				'resolve'     => array( $this, 'resolve_user_field' ),
			);
		}

		return $fields;
	}

	/**
	 * Set default args for get_terms on post connections.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @param  array   $query_args Args to be passed to get_terms.
	 * @param  WP_Post $source     Connection source (post object).
	 * @param  array   $args       Input args.
	 * @return array
	 * @since 0.0.2
	 */
	public function set_default_args( $query_args, $source, $args ) {
		if ( ! isset( $query_args['taxonomy'] ) || $this->tax_name !== $query_args['taxonomy'] ) {
			return $query_args;
		}

		// If the query is requesting a specific order, don't override it.
		if ( isset( $args['where']['orderby'] ) ) {
			return $query_args;
		}

		// The default sort order for CAP is "term_order"; this preserves the
		// order set by the post author. See co-authors-plus.php#L1564.
		$query_args['orderby'] = 'term_order';

		return $query_args;
	}

	/**
	 * Get a coauthor by their slug / nicename. Use Co-Authors global to take
	 * advantage of their cached methods.
	 *
	 * @param string $slug User slug / nicename to look up by.
	 *
	 * @return WP_User|object
	 * @since 0.0.1
	 */
	public function get_coauthor_by_slug( $slug ) {
		global $coauthors_plus;

		return $coauthors_plus->get_coauthor_by( 'user_nicename', $slug );
	}

	/**
	 * Catch-all resolve function that looks for the corresponding field directly
	 * on the user object.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @param  \WP_Term    $term    Co-author taxonomy term.
	 * @param  array       $args    Query args.
	 * @param  AppContext  $context AppContext object.
	 * @param  ResolveInfo $info    ResolveInfo object.
	 * @return string
	 * @since 0.0.1
	 */
	public function resolve_user_field( \WP_Term $term, $args, AppContext $context, ResolveInfo $info ) {
		$author = $this->get_coauthor_by_slug( $term->slug );

		// Get the requested field name from the ResolveInfo object, then look up
		// which user field it maps to.
		$wp_field = $this->fields[ $info->fieldName ];

		// First look directly on the object.
		if ( isset( $author->$wp_field ) ) {
			return $author->$wp_field;
		}

		// Next look in user meta.
		if ( 'wpuser' === $author->type ) {
			return get_the_author_meta( $wp_field, $author->ID );
		}

		return '';
	}

	/**
	 * Update the Co-Authors Plus taxonomy args to support GraphQL.
	 *
	 * @param array  $args Associative array of taxonomy args.
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array
	 * @since 0.0.1
	 */
	public function update_taxonomy_args( $args, $taxonomy ) {
		if ( $this->tax_name === $taxonomy ) {
			$args['show_in_graphql'] = true;
			$args['graphql_single_name'] = 'coAuthor';
			$args['graphql_plural_name'] = 'coAuthors';
		}

		return $args;
	}
}

// Instantiate the class.
new \WPGraphQL\WPGraphQL_CoAuthorsPlus();
