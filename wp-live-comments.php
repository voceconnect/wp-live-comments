<?php
/*
Plugin Name: WP Live Comments
Plugin URI: https://github.com/voceconnect/wp-live-comments/
Description: Live commenting plugin implemented with Backbone.js
Version: 1.0
Author: Jeff Stieler
Author URI: http://voceconnect.com
License: GPL2
*/

class WP_Live_Comments {

	/**
	 * Attach actions to front-end hook
	 */
	function init() {

		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
		self::handle_ajax_wp_comment_post();

		add_action('comment_form_logged_in_after', array(__CLASS__, 'output_comment_form_error_span'));
	}

	/**
	 * Attach actions to back-end hook
	 */
	function admin_init() {

		add_action('wp_ajax_nopriv_json_comments', array(__CLASS__, 'ajax_json_comments'));
		add_action('wp_ajax_json_comments', array(__CLASS__, 'ajax_json_comments'));

	}

	/**
	 * Check if the built in comment_form() is being submitted over AJAX and attach a handler
	 */
	function handle_ajax_wp_comment_post() {

		if (('/wp-comments-post.php' === $_SERVER['REQUEST_URI']) &&
			!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
			('xmlhttprequest' === strtolower($_SERVER['HTTP_X_REQUESTED_WITH']))) {

			define('DOING_AJAX', true);

			add_filter('comment_post_redirect', array(__CLASS__, 'hijack_comment_post_redirect_for_ajax'), 10, 2);

			add_filter('wp_die_ajax_handler', function() {
				return array(__CLASS__, 'wp_die_ajax_handler');
			});

		}

	}

	/**
	 * Filter "comment_post_redirect" to return a JSON comment object instead of redirecting
	 *
	 * @param string $location
	 * @param object $comment
	 */
	function hijack_comment_post_redirect_for_ajax($location, $comment) {

		$comments = self::flesh_out_comment_objects(array($comment));

		die(json_encode($comments[0]));

	}

	/**
	 * Wrap wp_die() calls in an object with "error" property
	 *
	 * @param string $message
	 */
	function wp_die_ajax_handler($message = '') {

		$response = array(
			'error' => is_scalar($message) ? (string)$message : 'There was an error processing your request.'
		);
		die(json_encode($response));

	}

	/**
	 * Enqueue necessary scripts/stylesheets as well as front-end templates and initialization objects
	 */
	function enqueue_scripts() {

		$dir = plugins_url('js/', __FILE__);
		wp_enqueue_script('underscore', $dir . 'lib/underscore-min.js', array(), false, true);
		wp_enqueue_script('backbone', $dir . 'lib/backbone-min.js', array('underscore', 'json2', 'jquery'), false, true);
		wp_enqueue_script('moment', $dir . 'lib/moment.min.js', array(), false, true);
		wp_enqueue_script('wp-live-comments', $dir . 'wp-live-comments.min.js', array('backbone', 'jquery-form', 'moment'), false, true);
		wp_enqueue_style('wp-live-comments', plugins_url('css/', __FILE__) . 'wp-live-comments.css', array(), false);
		if (is_single()) {

			add_action('wp_footer', array(__CLASS__, 'output_underscore_templates'));
			add_action('wp_footer', array(__CLASS__, 'output_initialization_object'));

		}
	}

	/**
	 * Handle AJAX requests for post comments. Optionally grab comments after a certain ID.
	 */
	function ajax_json_comments() {

		$post_id  = isset($_GET['post_id'])  ? (int)$_GET['post_id']  : 0;
		$since_id = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;

		if (0 === $post_id) {

			die(json_encode(array('error' => '`post_id` parameter required.')));

		}

		if ($since_id > 0) {

			add_filter('comments_clauses', function($clauses) use ($since_id) {
				global $wpdb;

				$clauses['where'] .= $wpdb->prepare(' AND comment_ID > %d ', $since_id);

				return $clauses;
			});

		}

		$comments = get_comments(array('post_id' => $post_id));
		$comments = self::flesh_out_comment_objects($comments);

		die(json_encode($comments));
	}

	/**
	 * Add avatar, comment_class(), and post URL to comment objects
	 *
	 * @param $comments
	 * @return mixed
	 */
	function flesh_out_comment_objects($comments) {

		foreach ($comments as $comment) {

			$avatar_size = ('0' != $comment->comment_parent) ? 39 : 68;

			$comment->comment_author_avatar = get_avatar($comment, $avatar_size);
			$comment->comment_class         = implode(' ', get_comment_class('', $comment->comment_ID, $comment->comment_post_ID));
			$comment->comment_post_url      = get_permalink($comment->comment_post_ID);
		}
		return $comments;

	}

	/**
	 * Add an area for our Backbone.js app to put comment submission errors
	 */
	function output_comment_form_error_span() {
		?><span id="comment-form-error"></span><?php
	}

	/**
	 * Output templates used by the Backbone.js app as script tags
	 */
	function output_underscore_templates() {
	?>
		<script type="text/template" id="wp-live-comment-template">
			<article id="comment-<%= comment_ID %>" class="comment">
				<footer class="comment-meta">
					<div class="comment-author vcard">
						<%= comment_author_avatar %>
						<span class="fn"><a href="<%= comment_author_url %>" rel="external nofollow" class="url"><%= comment_author %></a></span> on <a href="<%= comment_post_url %>#comment-<%= comment_ID %>"><time pubdate="" datetime="<%= comment_date_gmt %>"><%= moment(new Date(comment_date_gmt + "+0000")).format("MMMM D, YYYY \\\\at h:mm a") %></time></a> <span class="says">said:</span>
					</div>
				</footer>
				<div class="comment-content"><p><%= comment_content %></p></div>
				<div class="reply">
					<a class="comment-reply-link" href="#">Reply <span>&darr;</span></a>
				</div>
			</article>
			<ul class="children"></ul>
		</script>
	<?php
	}

	/**
	 * Output an object to use for initialization of the Backbone.js app
	 */
	function output_initialization_object() {
		global $wp_query;
		if(!empty($wp_query->comments)){
			$since_id = max(array_map(function($a){return (int)$a->comment_ID;}, $wp_query->comments));
		} else {
			$since_id = 0;
		}
		wp_localize_script('wp-live-comments', 'wpLiveCommentsInit', array(
			'post_id'  => $wp_query->queried_object_id,
			'comments' => self::flesh_out_comment_objects($wp_query->comments),
			'ajaxUrl'  => home_url('wp-admin/admin-ajax.php'),
			'since_id' => $since_id
		));
	}
}

add_action('init', array('WP_Live_Comments', 'init'));
add_action('admin_init', array('WP_Live_Comments', 'admin_init'));