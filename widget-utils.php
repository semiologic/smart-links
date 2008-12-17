<?php
class widget_utils
{
	#
	# post_meta_boxes()
	#
	
	function post_meta_boxes()
	{
		static $done = false;
		
		if ( $done ) return;
		
		add_meta_box('post_widget_config', 'This Post In Widgets', array('widget_utils', 'post_widget_config'), 'post');
		add_action('save_post', array('widget_utils', 'post_save_widget_config'));

		$done = true;
	} # post_meta_boxes()
	
	
	#
	# page_meta_boxes()
	#
	
	function page_meta_boxes()
	{
		static $done = false;
		
		if ( $done ) return;
		
		add_meta_box('page_widget_config', 'This Page In Widgets', array('widget_utils', 'page_widget_config'), 'page');
		add_action('save_post', array('widget_utils', 'page_save_widget_config'));
		
		$done = true;
	} # page_meta_boxes()
	
	
	#
	# post_widget_config()
	#
	
	function post_widget_config()
	{
		widget_utils::widget_config('post');
	} # post_widget_config()
	
	
	#
	# page_widget_config()
	#
	
	function page_widget_config()
	{
		widget_utils::widget_config('page');
	} # page_widget_config()
	
	
	#
	# post_save_widget_config()
	#
	
	function post_save_widget_config($post_ID)
	{
		return widget_utils::save_widget_config($post_ID, 'post');
	} # post_save_widget_config()
	
	
	#
	# page_save_widget_config()
	#
	
	function page_save_widget_config($post_ID)
	{
		return widget_utils::save_widget_config($post_ID, 'page');
	} # page_save_widget_config()
	
	
	#
	# widget_config()
	#
	
	function widget_config($type)
	{
		$post_ID = isset($GLOBALS['post_ID']) ? $GLOBALS['post_ID'] : $GLOBALS['temp_ID'];

		echo '<p>'
			. 'The following fields let you configure options shared by:'
			. '</p>';

		echo '<ul>';
		do_action($type . '_widget_config_affected');
		echo '</ul>';
		
		echo '<p>'
			. 'It will <b>NOT</b> affect anything else. In particular WordPress\'s built-in Pages widget. (Use the Silo Pages widget instead.)'
			. '</p>';
		
		echo '<table style="width: 100%;">';
		
		echo '<tr valign="top">' . "\n"
			. '<th scope="row" width="120px;">'
			. 'Title'
			. '</th>' . "\n"
			. '<td>'
			. '<input type="text" size="58" style="width: 90%;" tabindex="5"'
			. ' name="widgets_label"'
			. ' value="' . attribute_escape(get_post_meta($post_ID, '_widgets_label', true)) . '"'
			. ' />'
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr valign="top">' . "\n"
			. '<th scope="row">'
			. 'Description'
			. '</th>' . "\n"
			. '<td>'
			. '<textarea size="58" style="width: 90%;" tabindex="5"'
			. ' name="widgets_desc"'
			. ' />'
			. format_to_edit(get_post_meta($post_ID, '_widgets_desc', true))
			. '</textarea>'
			. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr valign="top">' . "\n"
			. '<th scope="row">'
			. 'Exclude'
			. '</th>' . "\n"
			. '<td>'
			. '<label>'
			. '<input type="checkbox" tabindex="5"'
			. ' name="widgets_exclude"'
			. ( get_post_meta($post_ID, '_widgets_exclude', true)
				? ' checked="checked"'
				: ''
				)
			. ' />'
			. '&nbsp;'
			. 'Exclude this entry from automatically generated lists'
			. '</label>'
		 	. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '<tr valign="top">' . "\n"
			. '<th scope="row">'
			. '&nbsp;'
			. '</th>' . "\n"
			. '<td>'
			. '<label>'
			. '<input type="checkbox" tabindex="5"'
			. ' name="widgets_exception"'
			. ( get_post_meta($post_ID, '_widgets_exception', true)
				? ' checked="checked"'
				: ''
				)
			. ' />'
			. '&nbsp;'
			. '... except for silo stub, silo map, search reloaded and smart links.'
			. '</label>'
		 	. '</td>' . "\n"
			. '</tr>' . "\n";
		
		echo '</table>';
	} # widget_config()
	
	
	#
	# save_widget_config()
	#
	
	function save_widget_config($post_ID, $type = null)
	{
		$post = get_post($post_ID);
		
		if ( $post->post_type == 'revision' ) return;
		
		if ( !empty($_POST) )
		{
			$post =& get_post($post_ID);
			
			if ( $post->post_type != $type )
			{
				return;
			}
		
			delete_post_meta($post_ID, '_widgets_exclude');
			delete_post_meta($post_ID, '_widgets_exception');
			delete_post_meta($post_ID, '_widgets_label');
			delete_post_meta($post_ID, '_widgets_desc');

			if ( $_POST['widgets_exclude'])
			{
				add_post_meta($post_ID, '_widgets_exclude', '1', true);
				
				if ( $_POST['widgets_exception'])
				{
					add_post_meta($post_ID, '_widgets_exception', '1', true);
				}
			}
			
			$label = trim(strip_tags(stripslashes($_POST['widgets_label'])));
			
			if ( $label )
			{
				add_post_meta($post_ID, '_widgets_label', $label, true);
			}
			
			if ( current_user_can('unfiltered_html') )
				$desc = stripslashes( $_POST['widgets_desc'] );
			else
				$desc = stripslashes(wp_filter_post_kses(stripslashes($_POST['widgets_desc'])));
			
			if ( $desc )
			{
				add_post_meta($post_ID, '_widgets_desc', $desc, true);
			}
		}
		
		return $post_ID;
	} # save_widget_config()
} # widget_utils
?>