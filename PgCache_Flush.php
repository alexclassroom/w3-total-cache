<?php
/**
 * File: PgCache_Flush.php
 *
 * @package W3TC
 */

namespace W3TC;

/**
 * Class PgCache_Flush
 *
 * phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
 */
class PgCache_Flush extends PgCache_ContentGrabber {
	/**
	 * Queued URLs
	 *
	 * @var array
	 */
	private $queued_urls = array();

	/**
	 * Queued groups
	 *
	 * @var array
	 */
	private $queued_groups = array();

	/**
	 * Queued post IDs
	 *
	 * @var array
	 */
	private $queued_post_ids = array();

	/**
	 * Flush all operation requested flag
	 *
	 * @var bool
	 */
	private $flush_all_operation_requested = false;

	/**
	 * Debug purge flag
	 *
	 * @var bool
	 */
	private $debug_purge = false;

	/**
	 * Constructor for PgCache_Flush class.
	 *
	 * Initializes necessary configuration options and sets up the debug mode for purging.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
		$this->debug_purge = $this->_config->get_boolean( 'pgcache.debug_purge' );
	}

	/**
	 * Flushes all cached pages.
	 *
	 * Initiates the flushing of all cached pages and logs the purge if debug mode is enabled.
	 *
	 * @return bool Returns true if the flush operation is initiated successfully.
	 */
	public function flush() {
		if ( $this->debug_purge ) {
			Util_Debug::log_purge( 'pagecache', 'flush_all' );
		}

		$this->flush_all_operation_requested = true;

		return true;
	}

	/**
	 * Flushes a specific group of cached pages.
	 *
	 * Logs the purge operation for a given group if debug mode is enabled.
	 *
	 * @param string $group The cache group to flush.
	 *
	 * @return void
	 */
	public function flush_group( $group ) {
		if ( $this->debug_purge ) {
			Util_Debug::log_purge( 'pagecache', 'flush_group', $group );
		}

		$this->queued_groups[ $group ] = '*';
	}

	/**
	 * Flushes cache for a specific post.
	 *
	 * Identifies post URLs and associated resources to be purged and queues them for flushing. Logs purge details if debug mode is enabled.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 *
	 * @param int|null $post_id Post ID to flush. If null, the post ID is detected automatically.
	 * @param bool     $force  Optional flag to forcefully flush the post cache even if it is not configured for purging.
	 *
	 * @return bool Returns true if the flushing is successfully queued, false if not.
	 */
	public function flush_post( $post_id = null, $force = false ) {
		if ( ! $post_id ) {
			$post_id = Util_Environment::detect_post_id();
		}

		if ( ! $post_id ) {
			return false;
		}

		global $wp_rewrite; // required by many Util_PageUrls methods.
		if ( empty( $wp_rewrite ) ) {
			if ( $this->debug_purge ) {
				Util_Debug::log_purge(
					'pagecache',
					'flush_post',
					array(
						'post_id' => $post_id,
						'error'   => 'Post flush attempt before wp_rewrite initialization. Cant flush cache.',
					)
				);
			}

			error_log( 'Post flush attempt before wp_rewrite initialization. Cant flush cache.' );

			return false;
		}

		// prevent multiple calculation of post urls.
		$queued_post_id_key = Util_Environment::blog_id() . '.' . $post_id;
		if ( isset( $this->queued_post_ids[ $queued_post_id_key ] ) ) {
			return true;
		}

		$this->queued_post_ids[ $queued_post_id_key ] = '*';

		// calculate urls to purge.
		$full_urls = array();
		$post      = get_post( $post_id );
		if ( empty( $post ) ) {
			return true;
		}

		$is_cpt = Util_Environment::is_custom_post_type( $post );
		$terms  = array();

		$feeds            = $this->_config->get_array( 'pgcache.purge.feed.types' );
		$limit_post_pages = $this->_config->get_integer( 'pgcache.purge.postpages_limit' );

		if ( 'cache' === $this->_config->get_string( 'pgcache.rest' ) ) {
			$this->flush_group( 'rest' );
		}

		if (
			$this->_config->get_boolean( 'pgcache.purge.terms' ) ||
			$this->_config->get_boolean( 'pgcache.purge.feed.terms' )
		) {
			$taxonomies = get_post_taxonomies( $post_id );
			$terms      = wp_get_post_terms( $post_id, $taxonomies );
			$terms      = $this->_append_parent_terms( $terms, $terms );
		}

		$front_page = get_option( 'show_on_front' );

		// Home (Frontpage) URL.
		if (
			(
				$this->_config->get_boolean( 'pgcache.purge.home' ) &&
				'posts' === $front_page
			) ||
			$this->_config->get_boolean( 'pgcache.purge.front_page' )
		) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_frontpage_urls( $limit_post_pages )
			);
		}

		// pgcache.purge.home becomes "Posts page" option in settings if home page and blog are set to page(s)
		// Home (Post page) URL.
		if (
			$this->_config->get_boolean( 'pgcache.purge.home' ) &&
			'posts' !== $front_page &&
			! $is_cpt
		) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_postpage_urls( $limit_post_pages )
			);
		}

		// pgcache.purge.home becomes "Posts page" option in settings if home page and blog are set to page(s)
		// Custom Post Type Archive URL.
		if ( $this->_config->get_boolean( 'pgcache.purge.home' ) && $is_cpt ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_cpt_archive_urls( $post_id, $limit_post_pages )
			);
		}

		// Post URL.
		if ( $this->_config->get_boolean( 'pgcache.purge.post' ) || $force ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_post_urls( $post_id )
			);
		}

		// Post comments URLs.
		if (
			$this->_config->get_boolean( 'pgcache.purge.comments' ) &&
			function_exists( 'get_comments_pagenum_link' )
		) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_post_comments_urls( $post_id )
			);
		}

		// Post author URLs.
		if ( $this->_config->get_boolean( 'pgcache.purge.author' ) && $post ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_post_author_urls( $post->post_author, $limit_post_pages )
			);
		}

		// Post terms URLs.
		if ( $this->_config->get_boolean( 'pgcache.purge.terms' ) ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_post_terms_urls( $terms, $limit_post_pages )
			);
		}

		// Daily archive URLs.
		if ( $this->_config->get_boolean( 'pgcache.purge.archive.daily' ) && $post ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_daily_archive_urls( $post, $limit_post_pages )
			);
		}

		// Monthly archive URLs.
		if ( $this->_config->get_boolean( 'pgcache.purge.archive.monthly' ) && $post ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_monthly_archive_urls( $post, $limit_post_pages )
			);
		}

		// Yearly archive URLs.
		if ( $this->_config->get_boolean( 'pgcache.purge.archive.yearly' ) && $post ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_yearly_archive_urls( $post, $limit_post_pages )
			);
		}

		// Feed URLs for posts.
		if ( $this->_config->get_boolean( 'pgcache.purge.feed.blog' ) && ! $is_cpt ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_feed_urls( $feeds, null )
			);
		}

		// Feed URLs for posts.
		if ( $this->_config->get_boolean( 'pgcache.purge.feed.blog' ) && $is_cpt ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_feed_urls( $feeds, $post->post_type )
			);
		}

		if ( $this->_config->get_boolean( 'pgcache.purge.feed.comments' ) ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_feed_comments_urls( $post_id, $feeds )
			);
		}

		if ( $this->_config->get_boolean( 'pgcache.purge.feed.author' ) ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_feed_author_urls( $post->post_author, $feeds )
			);
		}

		if ( $this->_config->get_boolean( 'pgcache.purge.feed.terms' ) ) {
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_feed_terms_urls( $terms, $feeds )
			);
		}

		// Purge selected pages.
		if ( $this->_config->get_array( 'pgcache.purge.pages' ) ) {
			$pages     = $this->_config->get_array( 'pgcache.purge.pages' );
			$full_urls = array_merge(
				$full_urls,
				Util_PageUrls::get_pages_urls( $pages )
			);
		}

		// add mirror urls.
		$full_urls = Util_PageUrls::complement_with_mirror_urls( $full_urls );
		$full_urls = apply_filters( 'pgcache_flush_post_queued_urls', $full_urls );

		if ( $this->debug_purge ) {
			Util_Debug::log_purge( 'pagecache', 'flush_post', $post_id, $full_urls );
		}

		// Queue flush.
		if ( count( $full_urls ) ) {
			foreach ( $full_urls as $url ) {
				$this->queued_urls[ $url ] = '*';
			}
		}

		return true;
	}

	/**
	 * Flushes the cache for a specific URL.
	 *
	 * @param string $url URL of the page to flush.
	 *
	 * @return void
	 */
	public function flush_url( $url ) {
		$parts = wp_parse_url( $url );
		$uri   = ( isset( $parts['path'] ) ? $parts['path'] : '' ) .
			( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$group = $this->get_cache_group_by_uri( $uri );

		if ( $this->debug_purge ) {
			Util_Debug::log_purge( 'pagecache', 'flush_url', array( $url, $group ) );
		}

		$this->queued_urls[ $url ] = ( empty( $group ) ? '*' : $group );
	}

	/**
	 * Cleans up after a flush operation for posts.
	 *
	 * @return int Number of items flushed.
	 */
	public function flush_post_cleanup() {
		if ( $this->flush_all_operation_requested ) {
			if ( $this->_config->get_boolean( 'pgcache.debug' ) ) {
				self::log( 'flush all' );
			}

			$groups_to_flush = array( '' );
			if ( 'cache' === $this->_config->get_string( 'pgcache.rest' ) ) {
				$groups_to_flush[] = 'rest';
			}

			$groups_to_flush = apply_filters( 'w3tc_pagecache_flush_all_groups', $groups_to_flush );

			foreach ( $groups_to_flush as $group ) {
				$cache = $this->_get_cache( $group );
				$cache->flush( $group );
			}

			$count                               = 999;
			$this->flush_all_operation_requested = false;
			$this->queued_urls                   = array();
		} else {
			$count = 0;
			if ( count( $this->queued_groups ) > 0 ) {
				$count += count( $this->queued_urls );
				foreach ( $this->queued_groups as $group => $flag ) {
					if ( $this->_config->get_boolean( 'pgcache.debug' ) ) {
						self::log( 'pgcache flush "' . $group . '" group' );
					}

					$cache = $this->_get_cache( $group );
					$cache->flush( $group );
				}
			}

			if ( count( $this->queued_urls ) > 0 ) {
				if ( $this->_config->get_boolean( 'pgcache.debug' ) ) {
					self::log( 'pgcache flush ' . $count . ' urls' );
				}

				$mobile_groups   = $this->_get_mobile_groups();
				$referrer_groups = $this->_get_referrer_groups();
				$cookies         = $this->_get_cookies();
				$encryptions     = $this->_get_encryptions();
				$compressions    = $this->_get_compressions();

				$caches = array(
					'*' => $this->_get_cache(),
				);

				foreach ( $this->queued_urls as $url => $group ) {
					if ( ! isset( $caches[ $group ] ) ) {
						$caches[ $group ] = $this->_get_cache( $group );
					}

					$this->_flush_url(
						array(
							'url'             => $url,
							'cache'           => $caches[ $group ],
							'mobile_groups'   => $mobile_groups,
							'referrer_groups' => $referrer_groups,
							'cookies'         => $cookies,
							'encryptions'     => $encryptions,
							'compressions'    => $compressions,
							'group'           => '*' === $group ? '' : $group,
						)
					);
				}

				$count += count( $this->queued_urls );

				// Purge sitemaps if a sitemap option has a regex.
				if ( $this->_config->get_string( 'pgcache.purge.sitemap_regex' ) ) {
					$cache = $this->_get_cache( 'sitemaps' );
					$cache->flush( 'sitemaps' );
					++$count;
				}

				$this->queued_urls = array();
			}
		}

		return $count;
	}

	/**
	 * Flushed a specific URL by generating cache keys for different conditions.
	 *
	 * @param array $data Data required to flush the URL.
	 *
	 * @return void
	 */
	private function _flush_url( $data ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$data['parent'] = $this;
		$data           = apply_filters( 'w3tc_pagecache_flush_url', $data );
		if ( empty( $data ) || empty( $data['url'] ) ) {
			return;
		}

		foreach ( $data['mobile_groups'] as $mobile_group ) {
			foreach ( $data['referrer_groups'] as $referrer_group ) {
				foreach ( $data['cookies'] as $cookie ) {
					foreach ( $data['encryptions'] as $encryption ) {
						foreach ( $data['compressions'] as $compression ) {
							$page_keys   = array();
							$page_keys[] = $this->_get_page_key(
								array(
									'useragent'   => $mobile_group,
									'referrer'    => $referrer_group,
									'cookie'      => $cookie,
									'encryption'  => $encryption,
									'compression' => $compression,
									'group'       => $data['group'],
								),
								$data['url']
							);

							$page_keys = apply_filters( 'w3tc_pagecache_flush_url_keys', $page_keys );

							foreach ( $page_keys as $page_key ) {
								$data['cache']->delete( $page_key, $data['group'] );
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Retrieves the ahead generation extension for a specific cache group.
	 *
	 * @param string $group Cache group identifier.
	 *
	 * @return mixed The ahead generation extension for the group.
	 */
	public function get_ahead_generation_extension( $group ) {
		$cache = $this->_get_cache( $group );
		return $cache->get_ahead_generation_extension( $group );
	}

	/**
	 * Flushes the cache group after ahead generation.
	 *
	 * @param string $group     Cache group identifier.
	 * @param mixed  $extension Extension used for the ahead generation.
	 *
	 * @return void
	 */
	public function flush_group_after_ahead_generation( $group, $extension ) {
		$cache = $this->_get_cache( $group );
		$cache->flush_group_after_ahead_generation( $group, $extension );
	}

	/**
	 * Retrieves the mobile groups for cache flushing.
	 *
	 * @return array List of mobile groups.
	 */
	private function _get_mobile_groups() {
		$mobile_groups = array( '' );

		if ( $this->_mobile ) {
			$mobile_groups = array_merge( $mobile_groups, array_keys( $this->_mobile->get_groups() ) );
		}

		return $mobile_groups;
	}

	/**
	 * Retrieves the referrer groups for cache flushing.
	 *
	 * @return array List of referrer groups.
	 */
	private function _get_referrer_groups() {
		$referrer_groups = array( '' );

		if ( $this->_referrer ) {
			$referrer_groups = array_merge( $referrer_groups, array_keys( $this->_referrer->get_groups() ) );
		}

		return $referrer_groups;
	}

	/**
	 * Retrieves the cookies for cache flushing.
	 *
	 * @return array List of cookies.
	 */
	private function _get_cookies() {
		$cookies = array( '' );

		if ( $this->_config->get_boolean( 'pgcache.cookiegroups.enabled' ) ) {
			$cookies = array_merge( $cookies, array_keys( $this->_config->get_array( 'pgcache.cookiegroups.groups' ) ) );
		}

		return $cookies;
	}

	/**
	 * Retrieves the encryption types for cache flushing.
	 *
	 * @return array List of encryption types.
	 */
	private function _get_encryptions() {
		$is_https = ( 'https' === substr( get_home_url(), 0, 5 ) );

		$encryptions = array();

		if ( ! $is_https || $this->_config->get_boolean( 'pgcache.cache.ssl' ) ) {
			$encryptions[] = '';
		}

		if ( $is_https || $this->_config->get_boolean( 'pgcache.cache.ssl' ) ) {
			$encryptions[] = 'ssl';
		}

		return $encryptions;
	}

	/**
	 * Appends parent terms to the given terms for taxonomy purposes.
	 *
	 * @param array $terms                   Initial set of terms.
	 * @param array $terms_to_check_parents Terms to check for parent relationships.
	 *
	 * @return array Modified list of terms with parent terms included.
	 */
	private function _append_parent_terms( $terms, $terms_to_check_parents ) {
		$terms_to_check_parents = $terms;
		$ids                    = null;

		for ( ;; ) {
			$parent_ids = array();
			$taxonomies = array();

			foreach ( $terms_to_check_parents as $term ) {
				if ( $term->parent ) {
					$parent_ids[ $term->parent ]   = '*';
					$taxonomies[ $term->taxonomy ] = '*';
				}
			}

			if ( empty( $parent_ids ) ) {
				return $terms;
			}

			if ( is_null( $ids ) ) {
				// build a map of ids for faster check.
				$ids = array();
				foreach ( $terms as $term ) {
					$ids[ $term->term_id ] = '*';
				}
			} else {
				// append last new items to ids map.
				foreach ( $terms_to_check_parents as $term ) {
					$ids[ $term->term_id ] = '*';
				}
			}

			// build list to extract.
			$include_ids = array();

			foreach ( $parent_ids as $id => $v ) {
				if ( ! isset( $ids[ $id ] ) ) {
					$include_ids[] = $id;
				}
			}

			if ( empty( $include_ids ) ) {
				return $terms;
			}

			$new_terms = get_terms(
				array(
					'taxonomy' => array_keys( $taxonomies ),
					'include'  => $include_ids,
				)
			);

			$terms                  = array_merge( $terms, $new_terms );
			$terms_to_check_parents = $new_terms;
		}
	}
}
