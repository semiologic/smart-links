<?php
/*
Plugin Name: Smart Links
Plugin URI: http://www.semiologic.com/software/smart-links/
Description: Lets you write links as [link text->link ref] (explicit link), or as [link text->] (implicit link).
Author: Denis de Bernardy & Mike Koepke
Version: 4.6.1
Author URI: http://www.getsemiologic.com
Text Domain: smart-links
Domain Path: /lang
License: Dual licensed under the MIT and GPLv2 licenses
*/

/*
Terms of use
------------

This software is copyright Denis de Bernardy & Mike Koepke, and is distributed under the terms of the MIT and GPLv2 licenses.
**/


if ( !defined('smart_links_debug') )
	define('smart_links_debug', false);


/**
 * wp_smart_links
 *
 * @package Smart Links
 **/

class wp_smart_links {
	/**
	 * Plugin instance.
	 *
	 * @see get_instance()
	 * @type object
	 */
	protected static $instance = NULL;

	/**
	 * URL to this plugin's directory.
	 *
	 * @type string
	 */
	public $plugin_url = '';

	/**
	 * Path to this plugin's directory.
	 *
	 * @type string
	 */
	public $plugin_path = '';

	/**
	 * Access this plugin’s working instance
	 *
	 * @wp-hook plugins_loaded
	 * @return  object of this class
	 */
	public static function get_instance()
	{
		NULL === self::$instance and self::$instance = new self;

		return self::$instance;
	}

	/**
	 * Loads translation file.
	 *
	 * Accessible to other classes to load different language files (admin and
	 * front-end for example).
	 *
	 * @wp-hook init
	 * @param   string $domain
	 * @return  void
	 */
	public function load_language( $domain )
	{
		load_plugin_textdomain(
			$domain,
			FALSE,
			dirname(plugin_basename(__FILE__)) . '/lang'
		);
	}

	/**
	 * Constructor.
	 *
	 *
	 */
	public function __construct() {
		$this->plugin_url    = plugins_url( '/', __FILE__ );
		$this->plugin_path   = plugin_dir_path( __FILE__ );
		$this->load_language( 'smart-links' );

		include dirname(__FILE__) . '/smart-links-helpers.php';

		add_action( 'plugins_loaded', array ( $this, 'init' ) );
    }

	/**
	 * init()
	 *
	 * @return void
	 **/

	function init() {
		// more stuff: register actions and filters
		add_filter('the_content', array($this, 'replace'), 8);
		add_filter('the_excerpt', array($this, 'replace'), 8);

		foreach ( array('default', 'wp', 'wordpress') as $domain ) {
			smart_links::register_engine($domain, array($this, 'wp'));
		}

		foreach ( array('entries', 'pages', 'posts') as $domain ) {
			smart_links::register_engine($domain, array($this, 'entries'));
			smart_links::register_engine('wp_' . $domain, array($this, 'entries'));
			smart_links::register_engine('wordpress_' . $domain, array($this, 'entries'));
		}

		foreach ( array('terms', 'cats', 'tags') as $domain ) {
			smart_links::register_engine($domain, array($this, 'terms'));
			smart_links::register_engine('wp_' . $domain, array($this, 'terms'));
			smart_links::register_engine('wordpress_' . $domain, array($this, 'terms'));
		}

		foreach ( array('links', 'blogroll') as $domain ) {
			smart_links::register_engine($domain, array($this, 'links'));
			smart_links::register_engine('wp_' . $domain, array($this, 'links'));
			smart_links::register_engine('wordpress_' . $domain, array($this, 'links'));
		}

		smart_links::register_engine_factory(array($this, 'factory'));

		foreach ( array(
			'add_link',
			'edit_link',
			'delete_link',
			'update_option_active_plugins',
			'update_option_show_on_front',
			'update_option_page_on_front',
			'update_option_page_for_posts',
			'generate_rewrite_rules',
			  'clean_post_cache',
			  'clean_page_cache',
			'flush_cache',
			'after_db_upgrade',
			'wp_upgrade'
			) as $hook ) {
			add_action($hook, array($this, 'flush_cache'));
		}

		add_action('pre_post_update', array($this, 'pre_flush_post'));

		foreach ( array(
			'save_post',
			'delete_post',
			) as $hook ) {
			add_action($hook, array($this, 'flush_post'), 1); // before _save_post_hook()
		}

		add_action('post_widget_config_affected', array($this, 'widget_config_affected'));
		add_action('page_widget_config_affected', array($this, 'widget_config_affected'));

		register_activation_hook(__FILE__, array($this, 'flush_cache'));
		register_deactivation_hook(__FILE__, array($this, 'flush_cache'));

		add_action('save_post', array($this, 'save_post'), 15);

		wp_cache_add_non_persistent_groups(array('widget_queries', 'pre_flush_post'));
	}


    /**
	 * widget_config_affected()
	 *
	 * @return void
	 **/
	
	function widget_config_affected() {
		echo '<li>'
			. __('Smart Links (exclude only)', 'smart-links')
			. '</li>';
	} # widget_config_affected()
	
	
	/**
	 * wp()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function wp($links) {
		if ( !in_the_loop() || !$links )
			return $links;
		
		$cache = array_keys($links);
		
		$_cache = array();

		foreach ( $cache as $key ) {
			if ( $links[$key] )
				continue;
			$_cache[$key] = false;
		}
		
		$cache = $_cache;
		
		if ( !$cache )
			return $links;
		
		#dump($cache);
		
		$cache = wp_smart_links::entries($cache);
		$cache = wp_smart_links::links($cache);
		$cache = wp_smart_links::terms($cache);
		
		#dump($links, $cache);
		
		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			$ref = trim(strip_tags($ref));
			if ( isset($cache[$ref]) ) {
				$links[$ref] = $cache[$ref];
				continue;
			}
			$_ref = sanitize_title($ref);
			if ( isset($cache[$_ref]) )
				$links[$ref] = $cache[$_ref];
		}
		
		return $links;
	} # wp()
	
	
	/**
	 * entries()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function entries($links) {
		if ( !in_the_loop() )
			return $links;
		else
			$object_id = get_the_ID();
		
		# pages: check in section first
		if ( is_page() ) {
			if ( !get_transient('cached_section_ids') )
				wp_smart_links::cache_section_ids();
			global $wp_the_query;
			$page = get_post($wp_the_query->get_queried_object_id());
			$section_id = get_post_meta($page->ID, '_section_id', true);
			$links = wp_smart_links::section($section_id, $links);
		}
		
		global $wpdb;
		
		$cache = array();
		$match_sql = array();
		$seek_sql = array();
		
	    foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			
			if ( preg_match("/^[a-z0-9_-]+$/", $ref) ) {
				if ( isset($seek_sql[$ref]) )
					continue;
				$seek_sql[$ref] = 'posts.post_name LIKE "' . $wpdb->_real_escape($ref) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
			} else {
				$ref = trim(strip_tags($ref));
				if ( isset($seek_sql[$ref]) )
					continue;

				$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
				$ref_slug = sanitize_title($ref);
				$seek_sql[$ref_slug] = 'posts.post_name LIKE "' . $wpdb->_real_escape($ref) . '%"';
				$match_sql[$ref_slug] = 'WHEN ' . $seek_sql[$ref_slug] . ' THEN \'' . $wpdb->_real_escape($ref_slug) . '\'';
				$seek_sql[$ref] = 'posts.post_title LIKE "' . $wpdb->_real_escape($ref_sql) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
			}
		}
		
		#dump($ref, $ref_sql);

		if ( !empty($seek_sql) ) {	
			$match_sql = implode(" ", $match_sql);
			$seek_sql = implode(" OR ", $seek_sql);
	
			$filter_sql = "post_type = 'page' AND ID <> " . intval($object_id)
				. " OR post_type = 'post' AND ID <> " . intval($object_id);
	
			if ( !is_page() )
				$filter_sql .= " AND post_date > '" . get_the_time('Y-m-d') . "'";
			
			$sql = "
				# wp_smart_links::entries()
				SELECT
					CASE $match_sql END as ref,
					CASE
					WHEN post_type = 'page' THEN 1
					ELSE 0
					END as is_page,
					posts.*
				FROM
					$wpdb->posts as posts
				LEFT JOIN $wpdb->postmeta as widgets_exclude
				ON		widgets_exclude.post_id = posts.ID
				AND		widgets_exclude.meta_key = '_widgets_exclude'
				LEFT JOIN $wpdb->postmeta as widgets_exception
				ON		widgets_exception.post_id = posts.ID
				AND		widgets_exception.meta_key = '_widgets_exception'
				WHERE
					post_status = 'publish' AND ( $filter_sql ) AND ( $seek_sql )
					AND	( widgets_exclude.post_id IS NULL OR widgets_exception.post_id IS NOT NULL )
				ORDER BY
					is_page DESC, post_title, post_date DESC
				";
			
			#dump($sql);
			#dump($wpdb->get_results($sql));
			
			if ( $res = $wpdb->get_results($sql) ) {
				$res = (array) $res;
				update_post_cache($res);
		
				#dump($res);
				#dump($links);
				
				# prefer direct page children
				foreach ( $res as $row ) {
					if ( $row->post_type == 'page' && $row->post_parent == $object_id ) {
						$ref = sanitize_title($row->ref);
						if ( empty($cache[$ref]) ) {
							$cache[$ref] = array(
								'link' => apply_filters('the_permalink', get_permalink($row->ID)),
								'title' => $row->post_title,
								);
						}
					}
				}
				
				foreach ( $res as $row ) {
					$ref = sanitize_title($row->ref);
					if ( empty($cache[$ref]) ) {
						$cache[$ref] = array(
							'link' => apply_filters('the_permalink', get_permalink($row->ID)),
							'title' => $row->post_title,
							);
					}
				}
			}
		}
		
		if ( !$cache )
			return $links;
		
		#dump($cache, $links);
		
		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			$ref = trim(strip_tags($ref));
			if ( isset($cache[$ref]) ) {
				$links[$ref] = $cache[$ref];
				continue;
			}
			$_ref = sanitize_title($ref);
			if ( isset($cache[$_ref]) )
				$links[$ref] = $cache[$_ref];
		}
		
		return $links;
	} # entries()
	
	
	/**
	 * terms()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function terms($links) {
		if ( !in_the_loop() )
			return $links;
		
		global $wpdb;
		
		$cache = array();
		$match_sql = array();
		$seek_sql = array();

		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			
			$ref = trim(strip_tags($ref));
			if ( isset($seek_sql[$ref]) )
				continue;
			
			if ( preg_match("/^[a-z0-9_-]+$/", $ref) ) {
				if ( isset($seek_sql[$ref]) )
					continue;
				$seek_sql[$ref] = 'terms.slug = "' . $wpdb->_real_escape($ref) . '"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
			} else {
				$ref = trim(strip_tags($ref));
				if ( isset($seek_sql[$ref]) )
					continue;

				$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
				$ref_slug = sanitize_title($ref);
				$seek_sql[$ref_slug] = 'terms.slug = "' . $wpdb->_real_escape($ref) . '"';
				$match_sql[$ref_slug] = 'WHEN ' . $seek_sql[$ref_slug] . ' THEN \'' . $wpdb->_real_escape($ref_slug) . '\'';
				$seek_sql[$ref] = 'terms.name LIKE "' . $wpdb->_real_escape($ref_sql) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
			}
		}

		if ( !empty($seek_sql) ) {
			$match_sql = implode(" ", $match_sql);
			$seek_sql = implode(" OR ", $seek_sql);
		
			$sql = "
				# wp_smart_links::terms()
				SELECT
					CASE $match_sql END as ref,
					terms.term_id as id,
					terms.name as title,
					CASE
					WHEN taxonomy = 'category' THEN 1
					ELSE 0
					END as is_cat
				FROM
					$wpdb->terms as terms
				JOIN
					$wpdb->term_taxonomy as term_taxonomy
				ON	term_taxonomy.term_id = terms.term_id
				AND	term_taxonomy.taxonomy IN ( 'category', 'post_tag' )
				AND	term_taxonomy.count <> 0
				WHERE
					( $seek_sql )
				GROUP BY
					ref, id, title
				HAVING
					is_cat = MAX( CASE
					WHEN taxonomy = 'category' THEN 1
					ELSE 0
					END )
				ORDER BY title
				";

			#dump($sql);
		
			if ( $res = $wpdb->get_results($sql) ) {
				$res = (array) $res;
				#dump($res);
				
				foreach ( $res as $row ) {
					$ref = sanitize_title($row->ref);
					if ( empty($cache[$ref]) ) {
						$cache[$ref] = array(
							'link' => $row->is_cat ? get_category_link($row->id) : get_tag_link($row->id),
							'title' => $row->title,
							);
					}
				}
			}
		}
		
		if ( !$cache )
			return $links;

		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			$ref = trim(strip_tags($ref));
			if ( isset($cache[$ref]) ) {
				$links[$ref] = $cache[$ref];
				continue;
			}
			$_ref = sanitize_title($ref);
			if ( isset($cache[$_ref]) )
				$links[$ref] = $cache[$_ref];
		}

		return $links;
	} # terms()
	
	
	/**
	 * links()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function links($links) {
		if ( !in_the_loop() )
			return $links;
		
		global $wpdb;

		$cache = array();
		$match_sql = array();
		$seek_sql = array();

		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
		
			$ref = trim(strip_tags($ref));
			if ( isset($seek_sql[$ref]) )
				continue;
			
			$ref_sql = preg_replace("/[^a-z0-9]/i", "%", $ref);
			$seek_sql[$ref] = 'links.link_name LIKE "' . $wpdb->_real_escape($ref_sql) . '%"';
			$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
		}

		if ( !empty($seek_sql) ) {
			$match_sql = implode(" ", $match_sql);
			$seek_sql = implode(" OR ", $seek_sql);
		
			$sql = "
				# wp_smart_links::links()
				SELECT
					CASE $match_sql END as ref,
					links.*
				FROM
					$wpdb->links as links
				WHERE
					( $seek_sql )
				";

			#dump($sql);
			
			if ( $res = $wpdb->get_results($sql) ) {
				$res = (array) $res;
				#dump($res);
			
				foreach ( $res as $row ) {
					$ref = sanitize_title($row->ref);
					if ( empty($cache[$ref]) ) {
						$cache[$ref] = array(
							'link' => $row->link_url,
							'title' => $row->link_name,
							);
					}
				}
			}
		}
		
		if ( !$cache )
			return $links;
		
		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			$ref = trim(strip_tags($ref));
			if ( isset($cache[$ref]) ) {
				$links[$ref] = $cache[$ref];
				continue;
			}
			$_ref = sanitize_title($ref);
			if ( isset($cache[$_ref]) )
				$links[$ref] = $cache[$_ref];
		}
		
		return $links;
	} # links()
	
	
	/**
	 * section()
	 *
	 * @param int $section_id
	 * @param array $links
	 * @return array $links
	 **/
	
	function section($section_id, $links) {
		if ( !in_the_loop() )
			return $links;
		else
			$object_id = get_the_ID();
		
		$section_id = (int) $section_id;
		
		global $wpdb;
		
		$cache = array();
		
		if ( !get_transient('cached_section_ids') )
			wp_smart_links::cache_section_ids();

		$match_sql = array();
		$seek_sql = array();

	    foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			
			if ( preg_match("/^[a-z0-9_-]+$/", $ref) ) {
				if ( isset($seek_sql[$ref]) )
					continue;
				$seek_sql[$ref] = 'posts.post_name LIKE "' . $wpdb->_real_escape($ref) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
			} else {
				$ref = trim(strip_tags($ref));
				if ( isset($seek_sql[$ref]) )
					continue;

				$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
				$ref_slug = sanitize_title($ref);
				$seek_sql[$ref_slug] = 'posts.post_name LIKE "' . $wpdb->_real_escape($ref) . '%"';
				$match_sql[$ref_slug] = 'WHEN ' . $seek_sql[$ref_slug] . ' THEN \'' . $wpdb->_real_escape($ref_slug) . '\'';
				$seek_sql[$ref] = 'posts.post_title LIKE "' . $wpdb->_real_escape($ref_sql) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->_real_escape($ref) . '\'';
			}
		}

		if ( !empty($seek_sql) ) {
			$match_sql = implode(" ", $match_sql);
			$seek_sql = implode(" OR ", $seek_sql);
			
			$filter_sql = "post_type = 'page' AND ID <> " . intval($object_id);
			
			$sql = "
				# wp_smart_links::section() / fetch links
				SELECT
					CASE $match_sql END as ref,
					posts.*
				FROM
					$wpdb->posts as posts
				JOIN
					$wpdb->postmeta as section_filter
				ON	section_filter.post_id = posts.ID
				AND	section_filter.meta_key = '_section_id'
				AND	section_filter.meta_value = '$section_id'
				LEFT JOIN $wpdb->postmeta as widgets_exclude
				ON		widgets_exclude.post_id = posts.ID
				AND		widgets_exclude.meta_key = '_widgets_exclude'
				LEFT JOIN $wpdb->postmeta as widgets_exception
				ON		widgets_exception.post_id = posts.ID
				AND		widgets_exception.meta_key = '_widgets_exception'
				WHERE
					post_status = 'publish' AND ( $filter_sql ) AND ( $seek_sql )
					AND	( widgets_exclude.post_id IS NULL OR widgets_exception.post_id IS NOT NULL )
				ORDER BY posts.post_title
				";

			#dump($sql);
			
			if ( $res = $wpdb->get_results($sql) ) {
				$res = (array) $res;
				update_post_cache($res);
				
				# prefer direct page children
				foreach ( $res as $row ) {
					if ( $row->post_type == 'page' && $row->post_parent == $object_id ) {
						$ref = sanitize_title($row->ref);
						if ( empty($cache[$ref]) ) {
							$cache[$ref] = array(
								'link' => apply_filters('the_permalink', get_permalink($row->ID)),
								'title' => $row->post_title,
								);
						}
					}
				}
				
				foreach ( $res as $row ) {
					$ref = sanitize_title($row->ref);
					if ( empty($cache[$ref]) ) {
						$cache[$ref] = array(
							'link' => apply_filters('the_permalink', get_permalink($row->ID)),
							'title' => $row->post_title,
							);
					}
				}
			}
		}
		
		if ( !$cache )
			return $links;
		
		#dump($links, $cache);

		foreach ( $links as $ref => $found ) {
			if ( $found )
				continue;
			$ref = trim(strip_tags($ref));
			if ( isset($cache[$ref]) ) {
				$links[$ref] = $cache[$ref];
				continue;
			}
			$_ref = sanitize_title($ref);
			if ( isset($cache[$_ref]) )
				$links[$ref] = $cache[$_ref];
		}

		#dump($links, $cache);

		return $links;
	} # section()
	
	
	/**
	 * factory()
	 *
	 * @param string $domain
	 * @return callback $callback
	 **/
	
	function factory($domain) {
		global $wpdb;
		
		$ref = trim(strip_tags($domain));
		
		if ( !$ref )
			return create_function('$in', 'return $in;');
		
		$ref_sql = preg_replace("/[^a-z0-9]/i", "_", $ref);
		$seek_sql = 'posts.post_name LIKE "' . $wpdb->_real_escape(sanitize_title($ref)) . '%"';

		$filter_sql = "post_type = 'page' AND post_parent = 0";
		
		$sql = "
			# wp_smart_links::factory()
			SELECT
				posts.ID
			FROM
				$wpdb->posts as posts
			WHERE
				post_status = 'publish' AND ( $filter_sql ) AND ( $seek_sql )
			ORDER BY
				post_name
			LIMIT 1
			";

		#dump($sql);
		
		if ( $section_id = $wpdb->get_var($sql) )
			return create_function('$in', 'return wp_smart_links::section(' . $section_id . ', $in);');
		else
			return create_function('$in', 'return $in;');
	} # factory()
	
	
	/**
	 * save_post()
	 *
	 * @param int $post_id
	 * @return void
	 **/

	function save_post($post_id) {
		if ( isset($GLOBALS['sem_id_cache']) || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id) )
			return;

		$GLOBALS['sem_id_cache'] = true;

		$post_id = (int) $post_id;
		$post = get_post($post_id);
		
		if ( $post->post_type != 'page' || ( $post->post_status != 'publish' && $post->post_status != 'trash' ) )
			return;


		if ( $post->post_status == 'trash' ) {
			delete_transient('cached_section_ids');
			return;
		}

		$section_id = get_post_meta($post_id, '_section_id', true);
		$refresh = false;
		if ( !$section_id ) {
			$refresh = true;
		} else {
            $ancestors = get_post_ancestors($post);
			if ( empty($ancestors) ) {
				if ( $section_id != $post_id )
					$refresh = true;
			} elseif ( $section_id != $ancestors[count($ancestors)-1] ) {
				$refresh = true;
			}
		}
		
		if ( $refresh ) {
			global $wpdb;
			if ( !$post->post_parent )
				$new_section_id = $post_id;
			else
				$new_section_id = get_post_meta($post->post_parent, '_section_id', true);
			
			if ( $new_section_id ) {
				update_post_meta($post_id, '_section_id', $new_section_id);
				wp_cache_delete($post_id, 'posts');
				
				# mass-process children
				if ( $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_parent = $post_id AND post_type = 'page' LIMIT 1") )
					delete_transient('cached_section_ids');
			} else {
				# fix corrupt data
				if ( $section_id )
					delete_post_meta($post_id, '_section_id');
				delete_transient('cached_section_ids');
			}
		}
	} # save_post()
	
	
	/**
	 * cache_section_ids()
	 *
	 * @return void
	 **/

	function cache_section_ids() {
		global $wpdb;
		
		$pages = $wpdb->get_results("
			SELECT	*
			FROM	$wpdb->posts
			WHERE	post_type = 'page'
			AND		post_status IN ( 'publish', 'private' )
			");
		
		update_post_cache($pages);
		
		$to_cache = array();
		foreach ( $pages as $page )
			$to_cache[] = $page->ID;
		
		update_postmeta_cache($to_cache);
		
		foreach ( $pages as $page ) {
			$parent = $page;
			while ( $parent->post_parent && $parent->ID != $parent->post_parent )
				$parent = get_post($parent->post_parent);
			
			if ( "$parent->ID" !== get_post_meta($page->ID, '_section_id', true) )
				update_post_meta($page->ID, '_section_id', "$parent->ID");
		}
		
		set_transient('cached_section_ids', 1);
	} # cache_section_ids()


    /**
     * replace()
     *
     * @param $str
     * @internal param string $tr
     * @return string $tr
     */

	static function replace($str) {
		if ( !in_the_loop() || !trim($str) )
			return $str;
		
		smart_links::init();
		$has_links = wp_smart_links::cache();
		
		if ( !$has_links ) {
			$has_links = preg_match("/\[.+?-(?:>|&gt;|&\#62;).*?\]/ix", $str);
		}
		
		if ( !$has_links )
			return $str;
		
		$str = smart_links::pre_process($str);
		$str = smart_links::process($str);
		
		return $str;
	} # replace($str)
	
	
	/**
	 * cache()
	 *
	 * @return bool $process
	 **/

	static function cache() {
		global $smart_links_cache;
		
		$post_id = get_the_ID();
		$smart_links_cache = get_post_meta($post_id, '_smart_links_cache', true);
		
		if ( !is_array($smart_links_cache) || smart_links_debug ) {
			global $post;
			
			smart_links::init();
			
			$str = trim($post->post_content . "\n\n" . $post->post_excerpt);
			
			if ( $str && preg_match("/\[.+?-(?:>|&gt;|&\#62;).*?\]/ix", $str) ) {
				smart_links::pre_process($str);
				smart_links::fetch();
			}
			
			update_post_meta($post_id, '_smart_links_cache', $smart_links_cache);
		}
		
		foreach ( $smart_links_cache as $array ) {
			if ( !empty($array) )
				return true;
		}
		
		return false;
	} # cache()
	
	
	/**
	 * pre_flush_post()
	 *
	 * @param int $post_id
	 * @return void
	 **/

	function pre_flush_post($post_id) {
		$post_id = (int) $post_id;
		if ( !$post_id )
			return;
		
		$post = get_post($post_id);
		if ( !$post || wp_is_post_revision($post_id) )
			return;
		
		$old = wp_cache_get($post_id, 'pre_flush_post');
		if ( $old === false )
			$old = array();
		
		$update = false;
		foreach ( array(
			'post_title',
			'post_name',
			'post_status',
			'post_excerpt',
			'post_content',
			) as $field ) {
			if ( !isset($old[$field]) ) {
				$old[$field] = $post->$field;
				$update = true;
			}
		}
		
		if ( !isset($old['permalink']) ) {
			$old['permalink'] = apply_filters('the_permalink', get_permalink($post_id));
			$update = true;
		}
		
		foreach ( array(
			'widgets_label',
			'widgets_exclude', 'widgets_exception',
			) as $key ) {
			if ( !isset($old[$key]) ) {
				$old[$key] = get_post_meta($post_id, "_$key", true);
				$update = true;
			}
		}
		
		
		if ( $update )
			wp_cache_set($post_id, $old, 'pre_flush_post');
	} # pre_flush_post()
	
	
	/**
	 * flush_post()
	 *
	 * @param int $post_id
	 * @return mixed
	 **/

	function flush_post($post_id) {
		$post_id = (int) $post_id;
		if ( !$post_id )
			return null;
		
		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array($this, 'flush_cache'));
		
		$post = get_post($post_id);
		if ( !$post || wp_is_post_revision($post_id) )
			return null;
		
		$old = wp_cache_get($post_id, 'pre_flush_post');
		
		if ( $post->post_status != 'publish' && ( !$old || $old['post_status'] != 'publish' ) )
			return null;
		
		if ( $old === false )
			return wp_smart_links::flush_cache();
		
		extract($old, EXTR_SKIP);
		foreach ( array_keys($old) as $key ) {
			switch ( $key ) {
			case 'widgets_label':
			case 'widgets_exclude':
			case 'widgets_exception':
				if ( $$key != get_post_meta($post_id, "_$key", true) )
					return wp_smart_links::flush_cache();
				break;
			
			case 'permalink':
				if ( $$key != apply_filters('the_permalink', get_permalink($post_id)) )
					return wp_smart_links::flush_cache();
				break;
			
			case 'post_title':
			case 'post_name':
			case 'post_status':
			case 'post_excerpt':
			case 'post_content':
				if ( $$key != $post->$key )
					return wp_smart_links::flush_cache();
			}
		}

        return null;
	} # flush_post()
	
	
	/**
	 * flush_cache()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/
	
	function flush_cache($in = null) {
		static $done = false;
		if ( $done )
			return $in;
		
		$done = true;
		
		global $wpdb;
		
		$post_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key LIKE '\_smart\_links\_cache%'");
		if ( $post_ids ) {
			$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '\_smart\_links\_cache%'");
			foreach ( $post_ids as $post_id )
				wp_cache_delete($post_id, 'post_meta');
		}
		
		return $in;
	} # flush_cache()
} # wp_smart_links

$wp_smart_links = wp_smart_links::get_instance();
