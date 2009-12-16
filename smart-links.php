<?php
/*
Plugin Name: Smart Links
Plugin URI: http://www.semiologic.com/software/smart-links/
Description: Lets you write links as [link text->link ref] (explicit link), or as [link text->] (implicit link).
Author: Denis de Bernardy
Version: 4.2.2 alpha
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
	 * init()
	 *
	 * @param $in
	 * @return void
	 **/

	function init($in = null) {
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
		
		return $in;
	} # init()
	
	
	/**
	 * replace()
	 *
	 * @param string $str
	 * @return string $str
	 **/
	
	function replace($str) {
		if ( strpos($str, '[') === false || strpos($str, ']') === false )
			return $str;
		
		smart_links::init();
		
		#dump(esc_html($str));
		
		$str = smart_links::pre_process($str);
		
		#dump(esc_html($str));
		
		# fetch links
		smart_links::fetch();
		
		$str = smart_links::process($str);
		
		#dump(esc_html($str));
		
		return $str;
	} # replace()
	
	
	/**
	 * pre_process()
	 *
	 * @param string $str
	 * @return string $str
	 **/

	function pre_process($str) {
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
			/ix", array('smart_links', 'pre_process_callback'), $str);
		
		return $str;
	} # pre_process()
	
	
	/**
	 * pre_process_callback()
	 *
	 * @param array $in regex match
	 * @return string $out
	 **/

	function pre_process_callback($in) {
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
		if ( !isset($smart_links_cache[$domain][$ref]) )
			$smart_links_cache[$domain][$ref] = false;
		
		#dump($label, $ref, $domain);
		
		return '[' . $label . '-&gt;' . $ref . ' @ ' . $domain . ']';
	} # pre_process_callback()
	
	
	/**
	 * process()
	 *
	 * @param string $str
	 * @return string $str
	 **/

	function process($str) {
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
			/ix", array('smart_links', 'process_callback'), $str);
		
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
		
		return $str;
	} # process()
	
	
	/**
	 * process_callback()
	 *
	 * @param array $in regex match
	 * @return string $out
	 **/

	function process_callback($in) {
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
	} # process_callback()
	
	
	/**
	 * fetch()
	 *
	 * @return void
	 **/

	function fetch() {
		global $smart_links_cache;
		global $smart_links_engines;
		global $smart_links_engine_factory;
		
		# fetch links
		foreach ( $smart_links_cache as $domain => $links ) {
			if ( !isset($smart_links_engines[$domain])
				&& isset($smart_links_engine_factory)
			) {
				$smart_links_engines[$domain] = call_user_func($smart_links_engine_factory, $domain);
			}
			
			# ksort links and reverse it, so as to scan longer refs first
			ksort($links);
			$links = array_reverse($links, true);
			
			if ( isset($smart_links_engines[$domain]) ) {
				$smart_links_cache[$domain] = call_user_func($smart_links_engines[$domain], $links);
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
	 * @return array $links
	 **/
	
	function wp($links) {
		if ( !in_the_loop() || !$links )
			return $links;
		else
			$object_id = get_the_ID();
		
		if ( $bail )
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
				$seek_sql[$ref] = 'posts.post_name LIKE "' . $wpdb->escape($ref) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
			} else {
				$ref = trim(strip_tags($ref));
				if ( isset($seek_sql[$ref]) )
					continue;

				$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
				$ref_slug = sanitize_title($ref);
				$seek_sql[$ref_slug] = 'posts.post_name LIKE "' . $wpdb->escape($ref) . '%"';
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
		else
			$object_id = get_the_ID();
		
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
		else
			$object_id = get_the_ID();
		
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
				$seek_sql[$ref] = 'posts.post_name LIKE "' . $wpdb->escape($ref) . '%"';
				$match_sql[$ref] = 'WHEN ' . $seek_sql[$ref] . ' THEN \'' . $wpdb->escape($ref) . '\'';
			} else {
				$ref = trim(strip_tags($ref));
				if ( isset($seek_sql[$ref]) )
					continue;

				$ref_sql = preg_replace("/[^a-z0-9]+/i", "%", $ref);
				$ref_slug = sanitize_title($ref);
				$seek_sql[$ref_slug] = 'posts.post_name LIKE "' . $wpdb->escape($ref) . '%"';
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
		$seek_sql = 'posts.post_name LIKE "' . $wpdb->escape(sanitize_title($ref)) . '%"';

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
		if ( !get_transient('cached_section_ids') )
			return;
		
		$post_id = (int) $post_id;
		$post = get_post($post_id);
		
		if ( $post->post_type != 'page' )
			return;
		
		$section_id = get_post_meta($post_id, '_section_id', true);
		$refresh = false;
		if ( !$section_id ) {
			$refresh = true;
		} else {
			_get_post_ancestors($post);
			if ( !$post->ancestors ) {
				if ( $section_id != $post_id )
					$refresh = true;
			} elseif ( $section_id != $post->ancestors[0] ) {
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
			AND		post_status <> 'trash'
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
	 * @param string $tr
	 * @return string $tr
	 **/

	function replace($str) {
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

	function cache() {
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
	 * @return void
	 **/

	function flush_post($post_id) {
		$post_id = (int) $post_id;
		if ( !$post_id )
			return;
		
		# prevent mass-flushing when the permalink structure hasn't changed
		remove_action('generate_rewrite_rules', array('wp_smart_links', 'flush_cache'));
		
		$post = get_post($post_id);
		if ( !$post || wp_is_post_revision($post_id) )
			return;
		
		$old = wp_cache_get($post_id, 'pre_flush_post');
		
		if ( $post->post_status != 'publish' && ( !$old || $old['post_status'] != 'publish' ) )
			return;
		
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


# Obsolete function

function sem_smart_link_set_engine($domain, $callback) {
	return smart_links::register_engine($domain, $callback);
} # sem_smart_link_set_engine()

add_filter('the_content', array('wp_smart_links', 'replace'), 8);
add_filter('the_excerpt', array('wp_smart_links', 'replace'), 8);

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
	'add_link',
	'edit_link',
	'delete_link',
	'update_option_active_plugins',
	'update_option_show_on_front',
	'update_option_page_on_front',
	'update_option_page_for_posts',
	'generate_rewrite_rules',
		
	'flush_cache',
	'after_db_upgrade',
	) as $hook ) {
	add_action($hook, array('wp_smart_links', 'flush_cache'));
}

add_action('pre_post_update', array('wp_smart_links', 'pre_flush_post'));

foreach ( array(
	'save_post',
	'delete_post',
	) as $hook ) {
	add_action($hook, array('wp_smart_links', 'flush_post'), 1); // before _save_post_hook()
}

add_action('post_widget_config_affected', array('wp_smart_links', 'widget_config_affected'));
add_action('page_widget_config_affected', array('wp_smart_links', 'widget_config_affected'));

register_activation_hook(__FILE__, array('wp_smart_links', 'flush_cache'));
register_deactivation_hook(__FILE__, array('wp_smart_links', 'flush_cache'));

add_action('save_post', array('wp_smart_links', 'save_post'));

wp_cache_add_non_persistent_groups(array('widget_queries', 'pre_flush_post'));
?>