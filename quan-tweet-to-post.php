<?php

/**
 * Plugin Name: Quan Tweets to Post
 * Plugin URI: https://github.com/quandigital/wp-quan-tweet-to-post
 * Author: Quan Digital GmbH
 * Author URI: http://www.quandigital.com
 * Description: Transforms Tweets to Posts
 * Version: 1.0.1
 * Author: alpipego
 * Author URI: http://alpipego.com/
 * 
 * === RELEASE NOTES ===
 * 2014-02-12 - v1.0 - first version
 */

	defined( 'ABSPATH' ) or exit;

    register_activation_hook(__FILE__, function() {
        if (!class_exists('acf')) {
            wp_die('Please activate <a href="https://wordpress.org/plugins/advanced-custom-fields/" target="_blank">Advanced Custom Fields</a> first.');
        }
    });

    $error = false;
    if (!class_exists('acf')) {
	    add_action('admin_notices', function() {
	        echo '<div class="error"><p>Please re-activate <a href="' . admin_url('plugins.php') . '">Advanced Custom Fields</a> first.</p></div>';
	    });
	    $error = true;
	}

	if (!$error) :
//set the latest ID to 0 if the option is not there, i.e. get all possible tweets for this account
function quan_tweet_activation() {
    $plugin_options = get_option( 'quan_tweet_latest' );

    if( $plugin_options === false ) {
        update_option( 'quan_tweet_latest', 12345 );
    }
}

register_activation_hook( __FILE__, 'quan_tweet_activation' );

/*
	include the twitter api wrapper
	https://github.com/J7mbo/twitter-api-php
*/
require_once( 'TwitterAPIExchange.php' );

//set up the options page (acf and acf options are required for this)
function quan_tweet_options_page( $options ){
	$options['title'] = __( 'Quan Tweet to Post' );
	$options['pages'] = array(
		__( 'API Settings' )
		);

	return $options;
}

add_filter( 'acf/options_page/settings', 'quan_tweet_options_page' );

/*
	Get the api access keys in order to access the REST API v1.1
*/
$settings = array(
    'oauth_access_token' => get_field( 'quan_tweet_access_token', 'option' ),
    'oauth_access_token_secret' => get_field( 'quan_tweet_access_token_secret', 'option' ),
    'consumer_key' => get_field( 'quan_tweet_api_key', 'option' ),
    'consumer_secret' => get_field( 'quan_tweet_api_secret', 'option' )
);

/*
	Register a post type to save the tweets to
*/
function quan_twitter_posttype() {

	$labels = array(
		'name'                => _x( 'Tweets', 'Post Type General Name', 'quan_admin' ),
		'singular_name'       => _x( 'Tweet', 'Post Type Singular Name', 'quan_admin' ),
		'menu_name'           => __( 'Tweets', 'quan_admin' ),
		'parent_item_colon'   => __( 'Parent Tweet:', 'quan_admin' ),
		'all_items'           => __( 'All Tweets', 'quan_admin' ),
		'view_item'           => __( 'View Tweet', 'quan_admin' ),
		'add_new_item'        => __( 'Add New Tweet', 'quan_admin' ),
		'add_new'             => __( 'New Tweet', 'quan_admin' ),
		'edit_item'           => __( 'Edit Tweet', 'quan_admin' ),
		'update_item'         => __( 'Update Tweet', 'quan_admin' ),
		'search_items'        => __( 'Search Tweets', 'quan_admin' ),
		'not_found'           => __( 'No Tweets found', 'quan_admin' ),
		'not_found_in_trash'  => __( 'No Tweets found in Trash', 'quan_admin' ),
	);
	$args = array(
		'label'               => __( 'jobs', 'quan_admin' ),
		'description'         => __( 'Tweets from http://twitter.com/quandigital', 'quan_admin' ),
		'labels'              => $labels,
		'supports'            => array( 'title', 'editor', 'author' ),
		'taxonomies'          => array( 'language' ),
		'rewrite'             => array( 'slug' => 'tweet' ),
		'hierarchical'        => false,
		'public'              => true,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_nav_menus'   => true,
		'show_in_admin_bar'   => true,
		'menu_position'       => 5,
		'menu_icon'           => plugins_url( 'twitter.png' , __FILE__ ),
		'can_export'          => true,
		'has_archive'         => true,
		'exclude_from_search' => false,
		'publicly_queryable'  => true,
		'capability_type'     => 'post',
	);
	register_post_type( 'quan_tweets', $args );

}	

add_action( 'init', 'quan_twitter_posttype', 0 );

/*
	make the actual API call
*/
function quan_tweet_get_tweets( $api_settings ) {
	$user = get_field( 'quan_tweet_twitter_username', 'option' );
	// $since_id = get_option( 'quan_tweet_latest' ); &since_id={$since_id}
	$url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
		$getfield = "?screen_name={$user}";
		$requestMethod = 'GET';
		$twitter = new TwitterAPIExchange( $api_settings );
		$response = json_decode( 
		        $twitter->setGetfield( $getfield )
		                ->buildOauth( $url, $requestMethod )
		                ->performRequest(),
		            $assoc = TRUE);

		return $response;
}

/*
	Do all the tweet handling here
*/
function write_tweets_to_db( $settings ) {
	global $wpdb;
	global $settings;
	$log = '';

	$cache_file = __DIR__ . '/twitter_result.data'; 
	
	//cache the results. only get them if they are older than...
	if( file_exists( $cache_file ) ) {
	    $data = unserialize( file_get_contents( $cache_file ) );
	    if( $data['timestamp'] > time() - 600 ) {
	        $twitter_result = $data['twitter_result'];
	    }
	}

	// if the cache doesn't exist or is older than 5 mins
	if( ! isset( $twitter_result) ) { 
	    $twitter_result = quan_tweet_get_tweets( $settings ); 

	    $data = array(
	    	'twitter_result' => $twitter_result, 
	    	'timestamp' => time()
	    	);
	    file_put_contents( $cache_file, serialize( $data ) );
	}

	//if we did not get an error or an empty array from twitter let's do this
	if( ! empty( $twitter_result ) && ! isset( $twitter_result['errors'] ) ) {
		//write the data we need to a new array
		$tweet_data = array();

		foreach( $twitter_result as $single_tweet ) {
			$tweet_id = $single_tweet['id_str'];
			$tweet_data[$tweet_id]['id'] = $single_tweet['id'];
			if( isset( $single_tweet['retweeted_status'] ) ) {
				$orig_time = strtotime( $single_tweet['retweeted_status']['created_at'] );
				$tweet_time = date( 'Y-m-d h:i:s', $orig_time );
				$tweet_data[$tweet_id]['time']       = $tweet_time; 
				$tweet_data[$tweet_id]['lang']       = $single_tweet['retweeted_status']['lang'];
				$tweet_data[$tweet_id]['text']       = $single_tweet['retweeted_status']['text'];
                $tweet_data[$tweet_id]['screenname'] = $single_tweet['retweeted_status']['user']['screen_name'];
				$tweet_data[$tweet_id]['name']       = $single_tweet['retweeted_status']['user']['name'];
				$tweet_data[$tweet_id]['userimage']  = str_replace( '_normal.', '_bigger.', $single_tweet['retweeted_status']['user']['profile_image_url_https'] );
				//if there are images get them here
				if( isset( $single_tweet['retweeted_status']['entities']['media'] ) ) {
					if( $single_tweet['retweeted_status']['entities']['media'][0]['type'] == 'photo' ) {
						$tweet_data[$tweet_id]['media']       = $single_tweet['retweeted_status']['entities']['media'][0]['media_url'];
						$tweet_data[$tweet_id]['media_url']   = $single_tweet['retweeted_status']['entities']['media'][0]['url'];
					}
				}
			} else {
				$orig_time = strtotime( $single_tweet['created_at'] );
				$tweet_time = date( 'Y-m-d h:i:s', $orig_time );
				$tweet_data[$tweet_id]['time']       = $tweet_time; 
				$tweet_data[$tweet_id]['lang']       = $single_tweet['lang'];
				$tweet_data[$tweet_id]['text']       = $single_tweet['text'];
				$tweet_data[$tweet_id]['screenname'] = $single_tweet['user']['screen_name'];
                $tweet_data[$tweet_id]['name']       = $single_tweet['user']['name'];
				$tweet_data[$tweet_id]['userimage']  = str_replace( '_normal.', '_bigger.', $single_tweet['user']['profile_image_url_https'] );
			}
		}


		/*
			create the $post foreach tweet
		*/

		//get the option for the default author if the user is not in our db
		$default_userid = get_option( 'options_quan_tweet_default_user' );

		//check the existing tweet ids, only write new ones to db
		$meta_key = 'quan_tweet_tweet_id';
		$exisiting_ids = $wpdb->get_col( $wpdb->prepare( 
				"SELECT meta_value FROM $wpdb->postmeta where meta_key = %s", 
				$meta_key
				)
			);

		//write the highest ID to the database and only get the new tweets on the next call
		if( ! empty( $exisiting_ids ) )
			update_option( 'quan_tweet_latest', max( $exisiting_ids ) );

		foreach( $tweet_data as $tweet_id => $tweet ) {
			//check if we have this author in the database
			$tweetauthor = get_users( array( 
					'meta_key' => 'twitter', 
					'meta_value' => $tweet['screenname'] 
					) 
				);
			//if the tweet author is not a user of the site, use the default author
			if( empty( $tweetauthor ) ) 
				$tweetauthor = $default_userid;

			//if the tweet is already in the db exit this
			if( in_array( $tweet_id, $exisiting_ids ) ) 
				continue;

			$post_tweet = array(
				'post_content'   => $tweet['text'],
				'post_title'      => $tweet['screenname'] . '-' . $tweet_id,
				'post_status'    => 'publish',
				'post_type'      => 'quan_tweets',
				'post_author'    => $tweetauthor,
				'post_date'      => $tweet['time']
			);

			$post_id = wp_insert_post( $post_tweet );

			if( $post_id != 0 ) {
				//manipulate the content and write it back to the db
				$post_obj = get_post( $post_id );
				$oldcontent = $post_obj->post_content;
				
				
				//replace all mentions with their respective twitter links
				preg_match_all( "/@\w+/", $oldcontent, $mentions );
				if( isset( $mentions ) && ! empty( $mentions ) ) {
					foreach( $mentions[0] as $mention ) {
						$twitterlink = '<a href="https://twitter.com/' . str_replace( '@', '', $mention ) . '" target="_blank">' . $mention . '</a>'; 
						$newcontent  = str_replace( $mention, $twitterlink, $oldcontent );
						$oldcontent = $newcontent;
					}
				}

				//write the newcontent back to old variable
				if( isset( $newcontent ) )
					$oldcontent = $newcontent;

				//replace all hashtags with their respective twitterlinks
				preg_match_all( "/#\w+/", $oldcontent, $hashtags );
				if( isset( $hashtags ) && ! empty( $hashtags ) ) {
					foreach( $hashtags[0] as $hashtag ) {
						$twitterlink = '<a href="https://twitter.com/?q=' . urlencode( $hashtag ) . '&src=hash" target="_blank">' . $hashtag . '</a>'; 
						$newcontent  = str_replace( $hashtag, $twitterlink, $oldcontent );
						$oldcontent = $newcontent;
					}
				}

				//write the newcontent back to old variable
				if( isset( $newcontent ) )
					$oldcontent = $newcontent;

				//if there is media content replace the link with the image
				if( isset( $tweet['media'] ) ) {
					$newcontent = str_replace( $tweet['media_url'], '', $oldcontent );
					update_post_meta( $post_id, 'quan_tweet_media_attachment', $tweet['media'] );
				}

				//write the newcontent back to old variable
				if( isset( $newcontent ) )
					$oldcontent = $newcontent;

				//add a real link to all remaining urls
				preg_match_all( '/http:\/\/t\.co[\w\/]+/', $oldcontent, $links );
				if( isset( $links ) && ! empty( $links ) ) {
					foreach( $links[0] as $link ) {
						$twitterlink = '<a href="' . $link . '" target="_blank">' . $link . '</a>'; 
						$newcontent  = str_replace( $link, $twitterlink, $oldcontent );
						$oldcontent = $newcontent;
					}
				}
				//write the post back to the database
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $oldcontent
					)
				);

				//update the post meta to have the avatar and the tweet id
				update_post_meta( $post_id, 'quan_tweet_avatar_url', $tweet['userimage'] );
				update_post_meta( $post_id, 'quan_tweet_tweet_id',   $tweet_id );
                update_post_meta( $post_id, 'quan_tweet_author_twitter', $tweet['screenname'] );
				update_post_meta( $post_id, 'quan_tweet_author_twitter_name', $tweet['name'] );

				//add the language
				$test = wp_set_object_terms( $post_id, $tweet['lang'], 'language' );
				$log = $tweet['name'];

			}

			//unset the content variables
			unset( $oldcontent );
			unset( $newcontent );
		}
	} //endif twitter_result not empty
	
	if( $log != '' ) {
		error_log( var_export( $log, true ) . "\n", 3, dirname(__FILE__) . '/debug.log' );
	}

}


//only get new posts if page is requested
add_action( 'init', 'write_tweets_to_db' );
endif;