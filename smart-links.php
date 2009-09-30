<?php
/*
Plugin Name: Smart Links
Plugin URI: http://www.semiologic.com/software/smart-links/
Description: Lets you write links as [link text->link ref] (explicit link), or as [link text->] (implicit link).
Author: Denis de Bernardy
Version: 4.2 RC7
Author URI: http://www.getsemiologic.com
Text Domain: smart-links
Domain Path: /lang
*/

/*
Terms of use
------------

This software is copyright Mesoconcepts and is distributed under the terms of the Mesoconcepts license. In a nutshell, you may freely use it for any purpose, but may not redistribute it without written permission.

http://www.mesoconcepts.com/license/
**/


load_plugin_textdomain('smart-links', false, dirname(plugin_basename(__FILE__)) . '/lang');


if ( !defined('smart_links_debug') )
	define('smart_links_debug', false);

/**
 * smart_links
 *
 * @package Smart Links
 **/

global $smart_links_engines;
$smart_links_engines = array();

class smart_links {
	/**
	 * replace()
	 *
	 * @param string $str
	 * @param string $context
	 * @return string $str
	 **/
	
	function replace($str, $context = '') {
		# initialize
		global $smart_links_cache;
		global $smart_links_aliases;
		global $smart_links_engines;

		$smart_links_cache = array();
		
		# create alias list
		if ( !isset($smart_links_aliases) ) {
			$smart_links_aliases = array();
			
			foreach ( array_keys($smart_links_engines) as $domain ) {
				$smart_links_aliases[$domain] = array_search($smart_links_engines[$domain], $smart_links_engines);
			}
		}
		
		#dump($smart_links_aliases);
		#dump(esc_html($str));
		
		# pre-process smart links
		$str = preg_replace_callback("/
			(?<!`)							# not a backtick before
			\[								# [
				((?:						# text
					(?!(?:					# not an anchor ahead, nor a ]
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
					))
					.
				)+?)
				-(?:>|&gt;|&\#62;)			# ->
				((?:						# optional ref
					(?!(?:					# not an anchor ahead, nor a [ or a ->
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
						|
						-(?:>|&gt;|&\#62;)
					))
					.
				)*?)
			\]								# ]
			/ix", array('smart_links', 'pre_process'), $str);
		
		#dump(esc_html($str));
		
		# fetch links
		smart_links::fetch($context);
		
		# process smart links
		$str = preg_replace_callback("/
			(?<!`)							# not a backtick before
			\[								# [
				((?:						# text
					(?!(?:					# not an anchor ahead, nor brakets
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
					))
					.
				)+?)
				-(?:>|&gt;|&\#62;)			# ->
				((?:						# ref
					(?!(?:					# not an anchor ahead, nor brakets
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
						|
						-(?:>|&gt;|&\#62;)
					))
					.
				)+?)
				@
				((?:						# domain
					(?!(?:					# not an anchor ahead, nor brakets
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
						|
						-(?:>|&gt;|&\#62;)
					))
					.
				)+?)
			\]								# ]
			/ix", array('smart_links', 'process'), $str);
		
		#dump(esc_html($str));
		
		# unescape smart links
		$str = preg_replace("/
			`								# a backtick
			\[								# [
				((?:						# text
					(?!(?:					# not an anchor ahead, nor a ]
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
					))
					.
				)+?)
				-(?:>|&gt;|&\#62;)			# ->
				((?:						# optional ref
					(?!(?:					# not an anchor ahead, nor a [ or a ->
						<a\s
						|
						<\/a>
						|
						\[
						|
						\]
						|
						-(?:>|&gt;|&\#62;)
					))
					.
				)*?)
			\]								# ]
			`?								# optional backtick (greedy)
			/ix", "[$1-&gt;$2]", $str);
		
		#dump(esc_html($str));
		
		return $str;
	} # replace()
	
	
	/**
	 * pre_process()
	 *
	 * @param array $in regex match
	 * @return string $out
	 **/

	function pre_process($in) {
		global $smart_links_cache;
		global $smart_links_engines;
		global $smart_links_aliases;
		global $smart_links_engine_factory;
		
		$str = $in[0];
		
		$label = trim($in[1]);
		$ref = trim($in[2]);
		
		# set default ref
		if ( $ref == '' ) {
			$ref = strtolower($label);
		}
		
		#dump(esc_html($label));
		#dump(esc_html($ref));
		
		# catch raw urls
		if ( preg_match("/
			(?:							# something that looks like a url
				^\/
				|
				^\#
				|
				^\?
				|
				:\/\/
			)
			/x", $ref) ) {
			# process directly
			if ( $label == $ref ) {
				$label = preg_replace("/
					^.+:\/\/
					/x", '', $label);
			}
			
			return '<a href="' . esc_url($ref) . '" title="' . esc_attr($label) . '">'
				. $label
				. '</a>';
		
		# catch domains without ref
		} elseif ( strpos($ref, '@') === 0 ) {
			$domain = trim(str_replace('@', '', $ref));
			$ref = strtolower($label);
		
		# catch emails
		} elseif ( preg_match("/
			(?:mailto:\s*)?
			(							# something that looks like an email
				[a-z0-9%_|~-]+
				(?:\.[a-z0-9%_|~-]+)*
				@
				[a-z0-9%_|~-]+
				(?:\.[a-z0-9%_|~-]+)+
			)
			/ix", $ref, $match) ) {
			# process directly
			$email = trim($match[1]);

			return '<a href="mailto:' . $email . '" title="' . $email . '">'
				. $label
				. '</a>';
		
		# use default domain if none is specified
		} elseif ( strpos($ref, '@') === false ) {
			$domain = 'default';
			$ref = $ref;
		
		# else extract domain
		} else {
			$match = preg_split("/@/", $ref);
			
			$ref = trim($match[0]);
			$domain = trim($match[1]);
			
			if ( !$domain ) {
				$domain = 'default';
			}
		}
		
		# catch domain alias (i.e. every registered domain)
		if ( $alias = $smart_links_aliases[$domain] ) {
			$domain = $alias;
		
		# catch no factory
		} elseif ( !$smart_links_engine_factory ) {
			return $label;
		}
		
		# register smart link
		$smart_links_cache[$domain][$ref] = false;
		
		#dump($label, $ref, $domain);
		
		return '[' . $label . '-&gt;' . $ref . ' @ ' . $domain . ']';
	} # pre_process()
	
	
	/**
	 * process()
	 *
	 * @param array $in regex match
	 * @return string $out
	 **/

	function process($in) {
		global $smart_links_cache;

		$label = trim($in[1]);
		$ref = trim($in[2]);
		$domain = trim($in[3]);
		
		#dump($label, $ref, $domain, $smart_links_cache[$domain]);
		
		if ( !( $link = $smart_links_cache[$domain][$ref] ) ) {
			return $label;
		}
		
		return '<a href="' . esc_url($link['link']) . '"'
			. ' title="' . esc_attr($link['title']) . '"'
			. '>' . $label . '</a>';
	} # process()
	
	
	/**
	 * fetch()
	 *
	 * @param string $context
	 * @return void
	 **/

	function fetch($context = '') {
		global $smart_links_cache;
		global $smart_links_engines;
		global $smart_links_engine_factory;
		
		# fetch links
		foreach ( $smart_links_cache as $domain => $links ) {
			if ( !isset($smart_links_engines[$domain])
				&& isset($smart_links_engine_factory)
			) {
				$smart_links_engines[$domain] = call_user_func($smart_links_engine_factory, $domain, $context);
			}
			
			# ksort links and reverse it, so as to scan longer refs first
			ksort($links);
			$links = array_reverse($links, true);
			
			if ( isset($smart_links_engines[$domain]) ) {
				$smart_links_cache[$domain] = call_user_func($smart_links_engines[$domain], $links, $context, true);
			}
		}
		
		#dump($smart_links_cache);
	} # fetch()
	
	
	/**
	 * register_engine()
	 *
	 * @param string $domain
	 * @param callback $callback
	 * @return void
	 **/

	function register_engine($domain, $callback) {
		global $smart_links_engines;
		
		$domain = trim(strtolower($domain));
		
		$smart_links_engines[$domain] = $callback;
	} # register_engine()


	/**
	 * register_engine_factory()
	 *
	 * @param callback $callback
	 * @return void
	 **/

	function register_engine_factory($callback) {
		global $smart_links_engine_factory;

		$smart_links_engine_factory = $callback;
	} # register_engine_factory()
} # smart_links


/**
 * smart_links_search
 *
 * @package Smart Links
 **/

foreach ( array('g', 'google', 'evil') as $domain ) {
	smart_links::register_engine($domain, array('smart_links_search', 'google'));
}

foreach ( array('y', 'yahoo') as $domain ) {
	smart_links::register_engine($domain, array('smart_links_search', 'yahoo'));
}

foreach ( array('m', 'msn') as $domain ) {
	smart_links::register_engine($domain, array('smart_links_search', 'msn'));
}

foreach ( array('w', 'wiki', 'wikipedia') as $domain ) {
	smart_links::register_engine($domain, array('smart_links_search', 'wiki'));
}

class smart_links_search {
	/**
	 * search()
	 *
	 * @param array $links
	 * @param string $how query url
	 * @param string $where engine name
	 * @return array $links
	 **/

	function search($links, $how, $where)  {
		foreach ( array_keys($links) as $ref ) {
			if ( !$links[$ref] ) {
				$links[$ref] = array(
					'link' => ( $how . urlencode($ref) ),
					'title' => "$ref @ $where"
					);
			}
		}

		return $links;
	} # search()
	
	
	/**
	 * google()
	 *
	 * @param array $links
	 * @return array $links
	 **/

	function google($links) {
		return smart_links_search::search($links, "http://www.google.com/search?q=", 'Google');
	} # google()
	
	
	/**
	 * yahoo()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function yahoo($links) {
		return smart_links_search::search($links, "http://search.yahoo.com/search?p=", 'Yahoo!');
	} # yahoo()


	/**
	 * msn()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function msn($links) {
		return smart_links_search::search($links, "http://search.msn.com/results.aspx?q=", 'MSN');
	} # msn()
	
	
	/**
	 * wiki()
	 *
	 * @param array $links
	 * @return array $links
	 **/
	
	function wiki($links) {
		return smart_links_search::search($links, "http://en.wikipedia.org/wiki/Special:Search?search=", 'Wikipedia');
	} # wiki()
} # smart_links_search


/**
 * wp_smart_links
 *
 * @package Smart Links
 **/

class wp_smart_links {
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
	 * @param string $context
	 * @param bool $use_cache
	 * @return array $links
	 **/
	
	function wp($links, $context = '', $use_cache = true) {
		$object_id = in_the_loop() ? get_the_ID() : 0;
		#dump('Context: ' . $context);
		$use_cache &= !smart_links_debug && $object_id;
		$cache = !$use_cache ? false : get_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_wp', true);
		
		# build cache, if not available
		if ( !$use_cache || (string) $cache === '' ) {
			$cache = array_keys($links);
			
			$_cache = array();

			foreach ( $cache as $key ) {
				if ( $links[$key] )
					continue;
				$_cache[$key] = false;
			}
			
			$cache = $_cache;
			
			#dump($cache);
			
			$cache = wp_smart_links::entries($cache, $context, false);
			$cache = wp_smart_links::links($cache, $context, false);
			$cache = wp_smart_links::terms($cache, $context, false);
			
			if ( ( $use_cache || smart_links_debug ) && $object_id && !is_admin() )
				update_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_wp', $cache);
		}
		
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
	 * @param string $context
	 * @param bool $use_cache
	 * @return array $links
	 **/
	
	function entries($links, $context = '', $use_cache = true) {
		$object_id = in_the_loop() ? get_the_ID() : 0;
		#dump('Context: ' . $context);
		$use_cache &= !smart_links_debug && $object_id;
		
		# pages: check in section first
		if ( is_page() ) {
			if ( !get_transient('cached_section_ids') )
				wp_smart_links::cache_section_ids();
			global $wp_the_query;
			$page = get_post($wp_the_query->get_queried_object_id());
			$section_id = get_post_meta($page->ID, '_section_id', true);
			$links = wp_smart_links::section($section_id, $links, $context);
			$bail = true;
			foreach ( $links as $ref => $found ) {
				if ( !$found ) {
					$bail = false;
					break;
				}
			}
			if ( $bail )
				return $links;
		}
		
		$cache = !$use_cache ? false : get_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_entries', true);
		
		# build cache, if not available
		if ( !$use_cache || (string) $cache === '' ) {
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
					$seek_sql[$ref] = 'posts.post_name = "' . $wpdb->escape($ref) . '"';
					$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
				} else {
					$ref = trim(strip_tags($ref));
					if ( isset($seek_sql[$ref]) )
						continue;

					$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
					$ref_slug = sanitize_title($ref);
					$seek_sql[$ref_slug] = 'posts.post_name = "' . $wpdb->escape($ref) . '"';
					$match_sql[$ref_slug] = 'WHEN ' . $seek_sql[$ref_slug] . ' THEN \'' . $wpdb->escape($ref_slug) . '\'';
					$seek_sql[$ref] = 'posts.post_title LIKE "' . $wpdb->escape($ref_sql) . '%"';
					$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
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
			
					foreach ( $res as $row ) {
						if ( !$cache[$ref] ) {
							$cache[$ref] = array(
								'link' => apply_filters('the_permalink', get_permalink($row->ID)),
								'title' => $row->post_title
								);
						}
					}
				}
			}

			if ( ( $use_cache || smart_links_debug ) && $object_id && !is_admin() )
				update_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_entries', $cache);
		}
		
		#dump($cache);
		
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
	 * @param string $context
	 * @param bool $use_cache
	 * @return array $links
	 **/
	
	function terms($links, $context = '', $use_cache = true) {
		$object_id = in_the_loop() ? get_the_ID() : 0;
		#dump('Context: ' . $context);
		$use_cache &= !smart_links_debug && $object_id;
		$cache = !$use_cache ? false : get_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_terms', true);
		
		# build cache, if not available
		if ( !$use_cache || (string) $cache === '' ) {
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
					$seek_sql[$ref] = 'terms.slug = "' . $wpdb->escape($ref) . '"';
					$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
				} else {
					$ref = trim(strip_tags($ref));
					if ( isset($seek_sql[$ref]) )
						continue;

					$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
					$ref_slug = sanitize_title($ref);
					$seek_sql[$ref_slug] = 'terms.slug = "' . $wpdb->escape($ref) . '"';
					$match_sql[$ref_slug] = 'WHEN ' . $seek_sql[$ref_slug] . ' THEN \'' . $wpdb->escape($ref_slug) . '\'';
					$seek_sql[$ref] = 'terms.name LIKE "' . $wpdb->escape($ref_sql) . '%"';
					$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
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
						if ( !$cache[$ref] ) {
							$cache[$ref] = array(
								'link' => $row->is_cat ? get_category_link($row->id) : get_tag_link($row->id),
								'title' => $row->title
								);
						}
					}
				}
			}

			if ( ( $use_cache || smart_links_debug ) && $object_id && !is_admin() )
				update_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_terms', $cache);
		}

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
	 * @param bool $use_cache
	 * @return array $links
	 **/
	
	function links($links, $use_cache = true) {
		$object_id = in_the_loop() ? get_the_ID() : 0;
		#dump('Context: ' . $context);
		$use_cache &= !smart_links_debug && $object_id;
		$cache = !$use_cache ? false : get_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_links', true);
		
		# build cache, if not available
		if ( !$use_cache || (string) $cache === '' ) {
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
				$seek_sql[$ref] = 'links.link_name LIKE "' . $wpdb->escape($ref_sql) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
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
						if ( !$cache[$ref] ) {
							$cache[$ref] = array(
								'link' => $row->link_url,
								'title' => $row->link_name
								);
						}
					}
				}
			}

			if ( ( $use_cache || smart_links_debug ) && $object_id && !is_admin() )
				update_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_links', $cache);
		}
		
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
	 * @param string $context
	 * @param bool $use_cache
	 * @return array $links
	 **/
	
	function section($section_id, $links, $context, $use_cache = true) {
		$object_id = in_the_loop() ? get_the_ID() : 0;
		$section_id = (int) $section_id;
		#dump('Context: ' . $context);
		$use_cache &= !smart_links_debug && $object_id;
		$cache = !$use_cache ? false : get_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_section_' . $section_id, true);
		
		# build cache, if not available
		if ( !$use_cache || (string) $cache === '' ) {
			global $wpdb;
			global $page_filters;
			
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
					$seek_sql[$ref] = 'posts.post_name = "' . $wpdb->escape($ref) . '"';
					$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
				} else {
					$ref = trim(strip_tags($ref));
					if ( isset($seek_sql[$ref]) )
						continue;

					$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
					$ref_slug = sanitize_title($ref);
					$seek_sql[$ref_slug] = 'posts.post_name = "' . $wpdb->escape($ref) . '"';
					$match_sql[$ref_slug] = 'WHEN ' . $seek_sql[$ref_slug] . ' THEN \'' . $wpdb->escape($ref_slug) . '\'';
					$seek_sql[$ref] = 'posts.post_title LIKE "' . $wpdb->escape($ref_sql) . '%"';
					$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
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
					
					#dump($res);
					
					foreach ( $res as $row ) {
						$ref = sanitize_title($row->ref);
						if ( !$cache[$ref] ) {
							$cache[$ref] = array(
								'link' => apply_filters('the_permalink', get_permalink($row->ID)),
								'title' => $row->post_title
								);
						}
					}
				}
			}
			
			if ( ( $use_cache || smart_links_debug ) && $object_id && !is_admin() )
				update_post_meta($object_id, '_smart_links_cache' . ( $context ? ( '_' . $context ) : '' ) . '_section_' . $section_id, $cache);
		}
		
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
	 * @param string $context
	 * @return callback $callback
	 **/
	
	function factory($domain, $context = '') {
		global $wpdb;
		
		$ref = trim(strip_tags($domain));
		
		if ( !$ref )
			return create_function('$in', 'return $in;');
		
		$ref_sql = preg_replace("/[^a-z0-9]/i", "_", $ref);
		$seek_sql = 'posts.post_name = "' . $wpdb->escape(sanitize_title($ref)) . '"';

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
			return create_function('$in, $context', 'return wp_smart_links::section(' . $section_id . ', $in, $context);');
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
		$post = get_post($post_id);
		
		if ( $post->post_type != 'page' )
			return;
		
		delete_transient('cached_section_ids');
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
			");
		
		update_post_cache($pages);
		
		$to_cache = array();
		foreach ( $pages as $page )
			$to_cache[] = $page->ID;
		
		update_postmeta_cache($to_cache);
		
		foreach ( $pages as $page ) {
			$parent = $page;
			while ( $parent->post_parent )
				$parent = get_post($parent->post_parent);
			
			if ( "$parent->ID" !== get_post_meta($page->ID, '_section_id', true) )
				update_post_meta($page->ID, '_section_id', "$parent->ID");
		}
		
		set_transient('cached_section_ids', 1);
	} # cache_section_ids()
	
	
	/**
	 * flush_cache()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/
	
	function flush_cache($in = null) {
		global $wpdb;
		
		$post_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key LIKE '\_smart\_links\_cache%'");
		if ( $post_ids ) {
			$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '\_smart\_links\_cache%'");
			foreach ( $post_ids as $post_id )
				wp_cache_delete($post_id, 'post_meta');
		}
		
		return $in;
	} # flush_cache()
	
	
	/**
	 * disable()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/

	function disable($in) {
		if ( trim($in) ) {
			add_filter('the_excerpt', array('wp_smart_links', 'the_excerpt'), 8);
		} else {
			#dump('disable');
			remove_filter('the_content', array('wp_smart_links', 'the_content'), 8);
			remove_filter('the_excerpt', array('wp_smart_links', 'the_excerpt'), 8);
			add_filter('the_content', array('wp_smart_links', 'the_excerpt'), 8);
		}
		
		return $in;
	} # disable()
	
	
	/**
	 * enable()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/

	function enable($in) {
		if ( has_filter('the_content', array('wp_smart_links', 'the_excerpt')) ) {
			#dump('enable');
			add_filter('the_content', array('wp_smart_links', 'the_content'), 8);
			remove_filter('the_content', array('wp_smart_links', 'the_excerpt'), 8);
		}
		
		return $in;
	} # enable()
	
	
	/**
	 * the_content()
	 *
	 * @param string $text
	 * @return string $text
	 **/

	function the_content($text) {
		#dump('content');
		return smart_links::replace($text);
	} # the_content()
	
	
	/**
	 * the_excerpt()
	 *
	 * @param string $text
	 * @return string $text
	 **/

	function the_excerpt($text) {
		#dump('excerpt');
		return smart_links::replace($text, 'excerpt');
	} # the_excerpt()
} # wp_smart_links


# Obsolete function

function sem_smart_link_set_engine($domain, $callback) {
	return smart_links::register_engine($domain, $callback);
} # sem_smart_link_set_engine()


add_filter('the_content', array('wp_smart_links', 'the_content'), 8);
add_filter('get_the_excerpt', array('wp_smart_links', 'disable'), -20);
add_filter('get_the_excerpt', array('wp_smart_links', 'enable'), 20);

foreach ( array('default', 'wp', 'wordpress') as $domain ) {
	smart_links::register_engine($domain, array('wp_smart_links', 'wp'));
}

foreach ( array('entries', 'pages', 'posts') as $domain ) {
	smart_links::register_engine($domain, array('wp_smart_links', 'entries'));
	smart_links::register_engine('wp_' . $domain, array('wp_smart_links', 'entries'));
	smart_links::register_engine('wordpress_' . $domain, array('wp_smart_links', 'entries'));
}

foreach ( array('terms', 'cats', 'tags') as $domain ) {
	smart_links::register_engine($domain, array('wp_smart_links', 'terms'));
	smart_links::register_engine('wp_' . $domain, array('wp_smart_links', 'terms'));
	smart_links::register_engine('wordpress_' . $domain, array('wp_smart_links', 'terms'));
}

foreach ( array('links', 'blogroll') as $domain ) {
	smart_links::register_engine($domain, array('wp_smart_links', 'links'));
	smart_links::register_engine('wp_' . $domain, array('wp_smart_links', 'links'));
	smart_links::register_engine('wordpress_' . $domain, array('wp_smart_links', 'links'));
}

smart_links::register_engine_factory(array('wp_smart_links', 'factory'));

foreach ( array(
	'save_post',
	'delete_post',
	'add_link',
	'edit_link',
	'delete_link',
	'generate_rewrite_rules',
	'switch_theme',
	'update_option_active_plugins',
	'update_option_show_on_front',
	'update_option_page_on_front',
	'update_option_page_for_posts',
	'update_option_sidebars_widgets',
	'update_option_sem5_options',
	'update_option_sem6_options',
		
	'flush_cache',
	'after_db_upgrade',
	) as $hook ) {
	add_action($hook, array('wp_smart_links', 'flush_cache'));
}

add_action('post_widget_config_affected', array('wp_smart_links', 'widget_config_affected'));
add_action('page_widget_config_affected', array('wp_smart_links', 'widget_config_affected'));

register_activation_hook(__FILE__, array('wp_smart_links', 'flush_cache'));
register_deactivation_hook(__FILE__, array('wp_smart_links', 'flush_cache'));

add_action('save_post', array('wp_smart_links', 'save_post'));
?>