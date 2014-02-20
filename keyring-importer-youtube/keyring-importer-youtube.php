<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_YouTube_Importer() {

class Keyring_YouTube_Importer extends Keyring_Importer_Base {
	const SLUG              = 'youtube';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'YouTube';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_YouTube';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 25;     // Number of images per request to ask for
	//var $api_key;
	var $auto_import = false;
	function __construct() {
		$rv = parent::__construct();
		
		add_action( 'keyring_importer_youtube_custom_options', array( $this, 'custom_options' ) );
    add_action( 'full_custom_greet', array( $this, 'custom_greet' ) );

		return $rv;
	}

	
	function handle_request_options() {
		$api_key = $this->service->get_credentials()['key'];
		
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your videos into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all videos." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'options';
		} else {
			$this->set_option( array(
				'category'    => (int) $_POST['category'],
				'tags'        => explode( ',', $_POST['tags'] ),
				'author'      => (int) $_POST['author'],
				'auto_import' => $_POST['auto_import'],
        'youtube_username' => $_POST['youtube_username'],
        'youtube_channel' => $_POST['youtube_channel'],
        'youtube_playlist' => $_POST['youtube_playlist']
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		$api_key = $this->service->get_credentials()['key'];
		// Base request URL
		$url = "https://www.googleapis.com/youtube/v3/playlistItems?part=id,snippet,contentDetails&playlistId=" . $this->get_option('youtube_playlist') . "&maxResults=" . self::NUM_PER_REQUEST . "&key=" . $api_key;
		
		return $url;
	}

	function make_request() {
		$url = $this->build_request_url();
		$res = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

		if($res) {
			$data = json_decode($res);
		}
		return $data;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-youtube-importer-failed-download', __( 'Failed to download your videos from YouTube. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Make sure we have some pictures to parse
		if ( !is_object( $importdata ) || !count( $importdata->items ) ) {
			$this->finished = true;
			return;
		}
		
		// Parse/convert everything to WP post structs
		foreach ( $importdata->items as $post ) {
			$youtube_id = $post->contentDetails->videoId;
			$youtube_url = "http://www.youtube.com/watch?v=" . $youtube_id;
			
			$post_title = __( 'Uploaded to YouTube', 'keyring' );
			if ( !empty( $post->snippet->title ) )
				$post_title = strip_tags( $post->snippet->title );

			// Parse/adjust dates
			// $post_date_gmt = $post->snippet->publishedAt;
			// $post_date_gmt = gmdate( 'Y-m-d H:i:s', $post_date_gmt );
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post->snippet->publishedAt ) );
      $post_date = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );
			$post_format = "video";
			// Construct a post body. By default we'll just link to the external image.
			// In insert_posts() we'll attempt to download/replace that with a local version.
			$post_content = '';

			if ( !empty( $post->snippet->description ) )
				$post_content .= $post->snippet->description;

			// Other bits
			$post_author      = $this->get_option( 'author' );
			$post_status      = 'publish';
			
			$youtube_img    = $post->snippet->thumbnails->maxres->url;
			$youtube_raw    = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'youtube_id',
				'youtube_url',
				'youtube_img',
				'youtube_raw'
			);
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			if (
				!$youtube_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'youtube_id' AND meta_value = %s", $youtube_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) )
					return $post_id;

				if ( !$post_id )
					continue;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an aside
				set_post_format( $post_id, 'video' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'youtube_id', $youtube_id );
				add_post_meta( $post_id, 'youtube_url', $youtube_url );
				add_post_meta( $post_id, 'youtube_img', $youtube_img );
				add_post_meta( $post_id, 'raw_import_data', json_encode( $youtube_raw ) );

				$this->sideload_media( $youtube_img, $post_id, $post, apply_filters( 'keyring_youtube_importer_image_embed_size', 'full' ) );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}

		$this->posts = array();

		// If we're doing a normal import and the last request was all skipped, then we're at "now"
		if ( !$this->auto_import && self::NUM_PER_REQUEST == $skipped )
			$this->finished = true;

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	function custom_options() {
		$api_key = $this->service->get_credentials()['key'];
    ?><tr valign="top">
      <th scope="row">
        <label for="include_rts"><?php _e( 'YouTube Username', 'keyring' ); ?></label>
      </th>
      <td>
        <input type="text" name="youtube_username" id="youtube_username" value="<?php echo $this->get_option( 'youtube_username', 'youtubeDev' ); ?>" />
      </td>
    </tr>
    <tr valign="top">
      <th scope="row">
        <label for="include_rts"><?php _e( 'Channel', 'keyring' ); ?></label>
      </th>
      <td>
      	<select name="youtube_channel" id="youtube_channel" disabled="disabled">
      		<?php echo $this->get_option( 'youtube_channel', '' ); ?>
      	</select>
      </td>
    </tr>
    <tr valign="top">
      <th scope="row">
        <label for="include_rts"><?php _e( 'Playlist', 'keyring' ); ?></label>
      </th>
      <td>
      	<select name="youtube_playlist" id="youtube_playlist" disabled="disabled">
      		<?php echo $this->get_option( 'youtube_playlist', '' ); ?>
      	</select>
      </td>
    </tr>
    
    <script>
    var kr_yt = {};
    function googleClientLoaded() {
    	console.log("google client loaded");
	      gapi.client.setApiKey('<?php echo $api_key; ?>');
	      gapi.client.load('youtube', 'v3', function() {
			    kr_yt.onYouTubeLoaded();
			  });
	    }
    $ = jQuery;
    	kr_yt.user = "";
    	kr_yt.user_channels = {};
    	kr_yt.user_playlists = {};
    	kr_yt.ui = {};
    	kr_yt.ui.username = $("#youtube_username");
    	kr_yt.ui.channel = $("#youtube_channel");
    	kr_yt.ui.playlist = $("#youtube_playlist");

	    kr_yt.onYouTubeLoaded = function() {
	    	console.log("youtube_api_loaded");
	    	setUser();
	    	
	    	kr_yt.ui.username.on("blur",function() {
		    	setUser();
		    });
	    }

	    function setPlaylists(channelId) {
			  var request_list = gapi.client.youtube.playlists.list({
					'channelId': channelId,
					'part': 'snippet'
				});

				var request_uploads = gapi.client.youtube.playlists.list({
					'id': kr_yt.user_channels[channelId].playlists.uploads,
					'part': 'snippet'
				});

				var request_likes = gapi.client.youtube.playlists.list({
					'id': kr_yt.user_channels[channelId].playlists.likes,
					'part': 'snippet'
				});

			  kr_yt.ui.playlist.html("");

				request_uploads.execute(function(response) {
					var playlist = {
						id: response.result.items[0].id,
						name: response.result.items[0].snippet.title
					};
					
					kr_yt.user_playlists[playlist.id] = playlist;

					kr_yt.ui.playlist.append('<option value="' + playlist.id + '">' + playlist.name + '</option>');
				});

				request_likes.execute(function(response) {
					var playlist = {
						id: response.result.items[0].id,
						name: response.result.items[0].snippet.title
					};

					kr_yt.user_playlists[playlist.id] = playlist;

					kr_yt.ui.playlist.append('<option value="' + playlist.id + '">' + playlist.name + '</option>');
				});

			  request_list.execute(function(response) {
			  	
			  	

			  	$.each(response.result.items,function(i,val) {
			  		var playlist = {
			  			'id': val.id,
			  			'name': val.snippet.title
			  		}

			  		kr_yt.user_playlists[val.id] = playlist;
			  		kr_yt.ui.playlist.append('<option value="' + playlist.id + '">' + playlist.name + '</option>');
			  	});

			  	kr_yt.ui.playlist.attr('disabled',false);
			  	//setPlaylists(kr_yt.ui.channel.val());
			  });
			}

	    
	    function setUserChannels(username) {
			  var request = gapi.client.youtube.channels.list({
			    forUsername: username,
			    part: 'snippet,contentDetails'
			  });
			  request.execute(function(response) {
			  	kr_yt.ui.channel.html("");
			  	$.each(response.result.items,function(i,val) {
			  		var channel = {
			  			'id': val.id,
			  			'name': val.snippet.title,
			  			'description': val.snippet.description,
			  			'playlists': val.contentDetails.relatedPlaylists
			  		}

			  		kr_yt.user_channels[val.id] = channel;
			  		kr_yt.ui.channel.append('<option value="' + channel.id + '">' + channel.name + '</option>');
			  	});

			  	kr_yt.ui.channel.attr('disabled',false);
			  	setPlaylists(kr_yt.ui.channel.val());
			  });
			}
			function setUser() {
				kr_yt.user = $("#youtube_username").val();
				if(kr_yt.user.length) {
					setUserChannels(kr_yt.user);
				}
			}
    </script>
    <script src="https://apis.google.com/js/client.js?onload=googleClientLoaded"></script>
    
    <?php
  }

	/**
	 * The first screen the user sees in the import process. Summarizes the process and allows
	 * them to either select an existing Keyring token or start the process of creating a new one.
	 * Also makes sure they have the correct service available, and that it's configured correctly.
	 */
	function custom_greet() {
		$this->header();

		// If this service is not configured, then we can't continue
		if ( ! $service = Keyring::get_service_by_name( static::SLUG ) ) : ?>
			<p class="error"><?php echo esc_html( sprintf( __( "It looks like you don't have the %s service for Keyring installed.", 'keyring' ), static::LABEL ) ); ?></p>
			<?php
			$this->footer();
			return;
			?>
		<?php elseif ( ! $service->is_configured() ) : ?>
			<p class="error"><?php echo esc_html( sprintf( __( "Before you can use this importer, you need to configure the %s service within Keyring.", 'keyring' ), static::LABEL ) ); ?></p>
			<?php
			if (
				current_user_can( 'read' ) // @todo this capability should match whatever the UI requires in Keyring
			&&
				! KEYRING__HEADLESS_MODE // In headless mode, there's nowhere (known) to link to
			&&
				has_action( 'keyring_' . static::SLUG . '_manage_ui' ) // Does this service have a UI to link to?
			) {
				$manage_kr_nonce = wp_create_nonce( 'keyring-manage' );
				$manage_nonce = wp_create_nonce( 'keyring-manage-' . static::SLUG );
				echo '<p><a href="' . esc_url( Keyring_Util::admin_url( static::SLUG, array( 'action' => 'manage', 'kr_nonce' => $manage_kr_nonce, 'nonce' => $manage_nonce ) ) ) . '" class="button-primary">' . sprintf( __( 'Configure %s Service', 'keyring' ), static::LABEL ) . '</a></p>';
			}
			$this->footer();
			return;
			?>
		<?php endif; ?>
		<div class="narrow">
			<form action="admin.php?import=<?php echo static::SLUG; ?>&amp;step=greet" method="post">
				<p><?php printf( __( "Howdy! This importer requires you to connect to %s before you can continue.", 'keyring' ), static::LABEL ); ?></p>
				<?php do_action( 'keyring_importer_' . static::SLUG . '_greet' ); ?>
				<?php if ( $service->is_connected() ) : ?>
					<p><?php echo sprintf( esc_html( __( 'It looks like you\'re already connected to %1$s via %2$s. You may use an existing connection, or create a new one:', 'keyring' ) ), static::LABEL, '<a href="' . esc_attr( Keyring_Util::admin_url() ) . '">Keyring</a>' ); ?></p>
					<?php $service->token_select_box( static::SLUG . '_token', true ); ?>
					<input type="submit" name="connect_existing" value="<?php echo esc_attr( __( 'Continue&hellip;', 'keyring' ) ); ?>" id="connect_existing" class="button-primary" />
				<?php endif; ?>
			</form>
		</div>
		<?php
		$this->footer();
	}
}

} // end function Keyring_YouTube_Importer


add_action( 'init', function() {
	Keyring_YouTube_Importer(); // Load the class code from above
	keyring_register_importer(
		'youtube',
		'Keyring_YouTube_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download copies of your YouTube photos and publish them all as individual Posts (marked as "image" format).', 'keyring' )
	);
} );
