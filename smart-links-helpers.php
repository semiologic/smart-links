<?php

$smart_links_engines = array();

/**
 * smart_links
 *
 * @package Smart Links
 **/
class smart_links {
	/**
	 * Constructor.
	 *
	 *
	 */
	public function __construct() {

    }

    /**
	 * init()
	 *
	 * @param mixed $in
	 * @return mixed $in
	 **/

	static function init($in = null) {
		# initialize
		global $smart_links_cache;
		global $smart_links_aliases;
		global $smart_links_engines;

		$smart_links_cache = array();

		# create alias list
		if ( !isset($smart_links_aliases) ) {
			$smart_links_aliases = array();

			foreach ( array_keys($smart_links_engines) as $domain ) {
				$smart_links_aliases[$domain] = $smart_links_engines[$domain];
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

	static function pre_process($str) {
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

	static function pre_process_callback($in) {
		global $smart_links_cache;
		global $smart_links_aliases;
		global $smart_links_engine_factory;

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
		if ( !empty($smart_links_aliases[$domain]) ) {
			$domain = $smart_links_aliases[$domain];

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

	static function process($str) {
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

	static function process_callback($in) {
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

	static function fetch() {
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

	static function register_engine($domain, $callback) {
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

	static function register_engine_factory($callback) {
		global $smart_links_engine_factory;

		$smart_links_engine_factory = $callback;
	} # register_engine_factory()
} # smart_links

/**
 * smart_links_search
 *
 * @package Smart Links
 **/

class smart_links_search {
	/**
	 * Plugin instance.
	 *
	 * @see get_instance()
	 * @type object
	 */
	protected static $instance = NULL;

	/**
	 * Access this plugin’s working instance
	 *
	 * @return  object of this class
	 */
	public static function get_instance()
	{
		NULL === self::$instance and self::$instance = new self;

		return self::$instance;
	}

    /**
     * smart_links_search
     */
	public function __construct() {
        foreach ( array('g', 'google') as $domain ) {
        	smart_links::register_engine($domain, array($this, 'google'));
        }

        foreach ( array('y', 'yahoo') as $domain ) {
        	smart_links::register_engine($domain, array($this, 'yahoo'));
        }

        foreach ( array('m', 'bing', 'msn') as $domain ) {
        	smart_links::register_engine($domain, array($this, 'bing'));
        }

        foreach ( array('w', 'wiki', 'wikipedia') as $domain ) {
        	smart_links::register_engine($domain, array($this, 'wiki'));
        }
    }


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
	 * bing()
	 *
	 * @param array $links
	 * @return array $links
	 **/

	function bing($links) {
		return smart_links_search::search($links, "http://http://www.bing.com//search?q=", 'Bing');
	} # bing()


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


$smart_links_search = smart_links_search::get_instance();

# Obsolete function

function sem_smart_link_set_engine($domain, $callback) {
	smart_links::register_engine($domain, $callback);
} # sem_smart_link_set_engine()