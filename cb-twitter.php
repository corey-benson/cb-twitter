<?php
/*
Plugin Name: CB Twitter API Plugin
Plugin URI: https://github.com/corey-benson/cb-twitter
Description: A plugin that fetches a users tweets
Author: 
Version: 1.0
Author URI: https://github.com/corey-benson/
*/

define('CBTAPI_PATH', WP_PLUGIN_URL . '/' . plugin_basename( dirname(__FILE__) ) . '/' );
define('CBTAPI_NAME', "Twitter");
define("CBTAPI_VERSION", "1.0");
define("CBTAPI_SLUG", 'cb_twitter');


class OAuth2_Twitter {

	public function __construct() {
		add_shortcode('tweets', array($this, 'render_shortcode'));
		add_action('admin_menu', array($this, 'add_twitter_admin_menu'));
		add_action('wp_footer', array($this, 'enqueue_twitter_style'));	
	}

	public function add_twitter_admin_menu() {
		add_options_page('Twitter Options', 'Twitter', 'manage_options', 'cb-twitter.php', array($this, 'add_twitter_admin_page'));
		add_action('admin_print_scripts', array($this, 'enqueue_twitter_style'));
	}

	public function add_twitter_admin_page() {
		if (!current_user_can('manage_options')) {
			wp_die( __('You do not have sufficient permissions to access this page.') );
		}

		if ( $_SERVER["REQUEST_METHOD"] == "POST" ) {
			update_option( 'TWITTER_CONSUMER_KEY', $_POST['consumer_key'] );
			update_option( 'TWITTER_CONSUMER_SECRET', $_POST['consumer_secret'] );
			update_option( 'TWITTER_SCREEN_NAME', $_POST['screen_name'] );
			$this->twitter_authenticate(true);
		}
		?>
		<div class="twitter-admin-options">
		<h1>Twitter Options</h1>
		<form name="options" method="POST" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
			<label for="screen_name">Screen Name<span class="required">(*)</span>: </label>
			<input type="text" name="screen_name" value="<?php echo get_option( 'TWITTER_SCREEN_NAME', '' ); ?>" size="70">
			<br>
			<label for="consumer_key">Consumer Key<span class="required">(*)</span>: </label>
			<input type="text" name="consumer_key" value="<?php echo get_option( 'TWITTER_CONSUMER_KEY', '' ); ?>" size="70">
			<br>
			<label for="consumer_secret">Consumer Secret<span class="required">(*)</span>: </label>
			<input type="text" name="consumer_secret" value="<?php echo get_option( 'TWITTER_CONSUMER_SECRET', '' ); ?>" size="70">	
			<br>				
			<label for="bearer_token">Bearer Token: </label>
			<input type="text" disabled value="<?php echo get_option( 'TWITTER_BEARER_TOKEN', '' ); ?>" size="70">
			<br>
			<input class="button-primary" type="submit" name="save" />				
			<br>
			<small>You can sign up for a API key <a href="https://dev.twitter.com/" target="_blank">here</a></small>				
		</form>
		<br>
			<h3>Latest Tweet: </h3>
			<?php echo do_shortcode('[tweets]'); ?>
		</div>
		<?php
	}

	public function twitter_authenticate($force = false) {

		$api_key = get_option( 'TWITTER_CONSUMER_KEY' );
		$api_secret = get_option( 'TWITTER_CONSUMER_SECRET' );
		$token = get_option( 'TWITTER_BEARER_TOKEN' );

		if ($api_key && $api_secret && ( !$token || $force )) {
			$bearer_token_credential = $api_key . ':' . $api_secret;
			$credentials = base64_encode($bearer_token_credential);

			$args = array(
				'method' => 'POST',
				'httpversion' => '1.1',
				'blocking' => true,
				'headers' => array(
					'Authorization' => 'Basic ' . $credentials,
					'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'
				),
				'body' => array( 'grant_type' => 'client_credentials' )
			);

			add_filter( 'https_ssl_verify', '__return_false' );
			$response = wp_remote_post( 'https://api.twitter.com/oauth2/token', $args );

			$keys = json_decode($response['body']);

			if ($keys) {
				update_option( 'TWITTER_BEARER_TOKEN', $keys->{'access_token'} );
			}
		}
	}

	public function render_shortcode($atts) {
		$tweets_return = '';

		extract( shortcode_atts( array(
			'id' => 'tweets-1',
			'count' => '1',
			'exclude_replies' => '1',
			'include_rts' => '1'
		), $atts ) );

		$token = get_option( 'TWITTER_BEARER_TOKEN' );
		$screen_name = get_option( 'TWITTER_SCREEN_NAME' );

		if ($token && $screen_name) {
			$args = array(
				'httpversion' => '1.1',
				'blocking' => true,
				'headers' => array(
					'Authorization' => "Bearer $token"
				)
			);
			add_filter( 'https_ssl_verify', '__return_false' );
			$api_url = "https://api.twitter.com/1.1/statuses/user_timeline.json?screen_name=$screen_name&count=$count&exclude_replies=$exclude_replies&include_rts=$include_rts";

			$response = wp_remote_get( $api_url, $args );

			$tweets = json_decode($response['body']);


			foreach($tweets as $i => $tweet) {
				$text = $tweet->{'text'}; 
				
				foreach($tweet->{'entities'} as $type => $entity) {
					if($type == 'urls') {						
						foreach($entity as $j => $url) {
							$update_with = '<a href="' . $url->{'url'} . '" target="_blank" title="' . $url->{'expanded_url'} . '">' . $url->{'display_url'} . '</a>';
							$text = str_replace($url->{'url'}, $update_with, $text);
						}
					} else if($type == 'hashtags') {
						foreach($entity as $j => $hashtag) {
							$update_with = '<a href="https://twitter.com/search?q=%23' . $hashtag->{'text'} . '&src=hash" target="_blank" title="' . $hashtag->{'text'} . '">#' . $hashtag->{'text'} . '</a>';
							$text = str_replace('#'.$hashtag->{'text'}, $update_with, $text);
						}
					} else if($type == 'user_mentions') {
						foreach($entity as $j => $user) {
							$update_with = '<a href="https://twitter.com/' . $user->{'screen_name'} . '" target="_blank" title="' . $user->{'name'} . '">@' . $user->{'screen_name'} . '</a>';
							$text = str_replace('@'.$user->{'screen_name'}, $update_with, $text);
						}
					}					
				}
				
				$user = $tweet->{'user'};
				$tweets_return .= $text . "<br>";
			}

			return $tweets_return;

		}
	}

	public function enqueue_twitter_style() {
		wp_register_style('twitter-style', CBTAPI_PATH . 'cb-twitter.css');
		wp_enqueue_style('twitter-style');
	}

}

$ca_twitter = new OAuth2_Twitter();

?>