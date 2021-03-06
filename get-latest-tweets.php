<?php
/**
Plugin Name: Get Latest Tweets
Plugin URI: http://paulschreiber.com/blog/2011/02/11/how-to-display-tweets-on-a-wordpress-page/
Description: Adds a shortcode tag [get_latest_tweets] to display an recent tweets
Version: 0.3
Author: Paul Schreiber
Author URI: http://paulschreiber.com/
 */

/**
 * Copyright 2011-15 Paul Schreiber <paul at paulschreiber.com>
 *
 * Released under the GPL, version 2.
 *
 * formatting code adapted from Twitter http://twitter.com/javascripts/widgets/widget.js
 * includes tmhOAuth from https://github.com/themattharris/tmhOAuth/
 * (which includes cacert.pem from http://curl.haxx.se/ca/cacert.pem)
 */

class Get_Latest_Tweets {

	const CACHE_LIVE_TIME = 30; // 24 seconds == 150 (rate limit per hour) / 60 (minutes)

	private static $cache_path;

	public static function time_ago( $then ) {
		$diff = time() - strtotime( $then );

		$second = 1;
		$minute = $second * 60;
		$hour = $minute * 60;
		$day = $hour * 24;
		$week = $day * 7;

		if ( is_nan( $diff ) || $diff < 0 ) {
			return ''; // Return blank string if unknown.
		}

		if ( $diff < $second * 2 ) {
			return 'right now';
		}

		if ( $diff < $minute ) {
			return floor( $diff / $second ) . ' seconds ago';
		}

		if ( $diff < $minute * 2 ) {
			return 'about 1 minute ago';
		}

		if ( $diff < $hour ) {
			return floor( $diff / $minute ) . ' minutes ago';
		}

		if ( $diff < $hour * 2 ) {
			return 'about 1 hour ago';
		}

		if ( $diff < $day ) {
			return  floor( $diff / $hour ) . ' hours ago';
		}

		if ( $diff > $day && $diff < $day * 2 ) {
			return 'yesterday';
		}

		if ( $diff < $day * 365 ) {
			return floor( $diff / $day ) . ' days ago';
		}

		return 'over a year ago';
	}

	public static function get_json_from_twitter( $username, $count ) {
		require 'tmhOAuth.php';

		$config = self::get_twitter_keys();

		if( !$config ){
			die( 'You do not have your constants set, please set them in your wp-config.php file.' );
		}

		$tmhOAuth = new tmhOAuth($config);

		$code = $tmhOAuth->request('GET', $tmhOAuth->url( '1.1/statuses/user_timeline' ), array(
			'screen_name' => $username,
		));

		$json = $tmhOAuth->response['response'];

		if ( ! $json ) {
			die( 'Could not get JSON data from Twitter' );
		}

		return $json;
	}

	public static function cache_file_name( $username ) {
		return self::$cache_path . "/$username.json";
	}

	public static function cache_json( $username, $count ) {
		$cacheDirectory = dirname( self::$cache_path );

		if ( ! file_exists( $cacheDirectory ) ) {
			if ( ! mkdir( $cacheDirectory ) ) {
				die( 'Could not create cache directory. Make sure ' . esc_html( dirname( $cacheDirectory ) ) . ' is writable by the web server.' );
			}
		}

		if ( ! file_exists( self::$cache_path ) ) {
			if ( ! mkdir( self::$cache_path ) ) {
				die( 'Could not create cache directory. Make sure ' . esc_html( dirname( self::$cache_path ) ) . ' is writable by the web server.' );
			}
		}

		$json = self::get_json_from_twitter( $username, $count );
		return file_put_contents( self::cache_file_name( $username ), $json );
	}

	public static function get_twitter_keys() {
		$config = array();

		if ( defined( 'GLT_TWITTER_CONSUMER_KEY' ) ) {
			$config['consumer_key'] = GLT_TWITTER_CONSUMER_KEY;
		}
		if ( defined( 'GLT_TWITTER_CONSUMER_SECRET' ) ) {
			$config['consumer_secret'] = GLT_TWITTER_CONSUMER_SECRET;
		}
		if ( defined( 'GLT_TWITTER_USER_TOKEN' ) ) {
			$config['user_token'] = GLT_TWITTER_USER_TOKEN;
		}
		if ( defined( 'GLT_TWITTER_USER_SECRET' ) ) {
			$config['user_secret'] = GLT_TWITTER_USER_SECRET;
		}

		if( count($config) == 4 ){
			return $config;
		} else {
			return false;
		}

	}

	public static function read_cached_json( $username ) {
		return file_get_contents( self::cache_file_name( $username ) );
	}

	public static function get_json( $username, $count ) {
		$cacheFile = self::cache_file_name( $username );
		$staleCache = true;
		clearstatcache();
		if ( (file_exists( $cacheFile ) && filesize( $cacheFile )) ) {
			$cacheInfo = stat( $cacheFile );
			$modTime = $cacheInfo[9];

			if ( (time() - $modTime) < self::CACHE_LIVE_TIME ) {
				$staleCache = false;
			}
		}

		if ( $staleCache ) {
			if ( ! self::cache_json( $username, $count ) ) {
				die( 'Could not write to JSON cache. Make sure ' . esc_html( self::$cache_path ) . ' is writeable by the web server' );
			}
		}

		return self::read_cached_json( $username );
	}

	public static function format_tweet( $tweet ) {
		// Add @reply links.
		$tweet_text = preg_replace('/\B[@＠]([a-zA-Z0-9_]{1,20})/',
			"@<a class='atreply' href='http://twitter.com/$1'>$1</a>",
		$tweet);

		// Make other links clickable.
		$matches = array();
		$link_info = preg_match_all( "/\b(((https*\:\/\/)|www\.)[^\"\']+?)(([!?,.\)]+)?(\s|$))/", $tweet_text, $matches, PREG_SET_ORDER );

		if ( $link_info ) {
			foreach ( $matches as $match ) {
				$http = preg_match( '/w/', $match[2] ) ? 'http://' : '';
				$tweet_text = str_replace($match[0],
					"<a href='" . $http . $match[1] . "'>" . $match[1] . '</a>' . $match[4],
				$tweet_text);
			}
		}

		return $tweet_text;
	}


	public static function show( $attributes ) {
		self::$cache_path = WP_CONTENT_DIR . '/cache/latest_tweets/';

		$attributes = shortcode_atts( array(
			'username' => null,
			'count' => 5,
		), $attributes );

		$count = intval( $attributes['count'] );
		if ( $count < 1 or $count > 100 ) {
			return 'Numbers of tweets must be between 1 and 100.';
		}

		if ( ! $attributes['username'] ) {
			return 'Please specify a twitter username';
		}

		$json = self::get_json( $attributes['username'], $count );
		$tweetData = json_decode( $json, true );

		if ( isset( $tweetData['errors'] ) ) {
			return esc_html( 'Error: ' . $tweetData['errors'][0]['message'] . ' (' . $tweetData['errors'][0]['code'] . ')' );
		}

		$content = "<ul class='tweets'>\n";
		foreach ( $tweetData as $index => $tweet ) {
			if ( $index === $count ) { break; }
			$content .= '<li>' . self::format_tweet( $tweet['text'] ) . " <span class='date'><a href='http://twitter.com/" . $attributes['username'] . '/status/' . $tweet['id_str'] . "'>" . self::time_ago( $tweet['created_at'] ) . "</a></span></li>\n";
		}
		$content .= "</ul>\n";

		return $content;
	}
}

add_shortcode( 'get_latest_tweets', array( 'Get_Latest_Tweets', 'show' ) );
?>
