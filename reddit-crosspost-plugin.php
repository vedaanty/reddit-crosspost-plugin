<?php
/*
Plugin Name: Reddit Cross-Post Plugin
Plugin URI: https://github.com/vedaanty/reddit-crosspost-plugin/
Description: Cross-posts WordPress posts to specified subreddits based on category or custom input. Includes Reddit OAuth authentication, multiple subreddits per category, and error display on the post page.
Version: 1.1.4
Author: Vedaant
Author URI: https://github.com/vedaanty/
*/

// Suppress output to prevent unintended whitespace
ob_start();

// Define the redirect URI dynamically
define('REDDIT_REDIRECT_URI', admin_url('admin.php?page=reddit-cross-poster'));

// Add the admin menu for plugin settings
add_action('admin_menu', 'reddit_cross_poster_menu');
function reddit_cross_poster_menu() {
    add_menu_page('Reddit Cross Poster', 'Reddit Cross Poster', 'manage_options', 'reddit-cross-poster', 'reddit_cross_poster_admin_page');
}

// Display the admin settings page
function reddit_cross_poster_admin_page() {
    if (isset($_GET['reddit_oauth'])) {
        reddit_cross_poster_start_oauth();
    }

    if (isset($_GET['code']) && isset($_GET['state'])) {
        reddit_cross_poster_handle_oauth_response($_GET['code']);
    }

    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;">Reddit Cross Poster Settings</h1>

        <form method="post" action="options.php" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
            <?php
            settings_fields('reddit_cross_poster_options');
            do_settings_sections('reddit-cross-poster');
            submit_button();
            ?>
        </form>

        <h2 style="margin-top: 30px;">OAuth Authentication</h2>
        <p>To allow Reddit Cross Poster to post on your behalf, you must authenticate with Reddit. Click the button below to begin the authentication process.</p>
        <p>Ensure that the redirect uri in the <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a> is set to: </p> <code><?php echo esc_html(REDDIT_REDIRECT_URI); ?></code>
		<br>
        <a href="<?php echo esc_url(add_query_arg('reddit_oauth', '1')); ?>" class="button button-primary" style="margin-top: 10px;">Authenticate with Reddit</a>

        <p style="margin-top: 15px; font-size: 14px;">
            <strong>Status:</strong> 
            <?php if (get_option('reddit_access_token')) : ?>
                <span style="color: green;">Logged in</span>
            <?php else : ?>
                <span style="color: red;">Not logged in</span>
            <?php endif; ?>
        </p>
    </div>
    <?php
}

// Start Reddit OAuth process
function reddit_cross_poster_start_oauth() {
    $client_id = get_option('reddit_client_id');
    $state = wp_generate_uuid4();

    $oauth_url = "https://www.reddit.com/api/v1/authorize?client_id={$client_id}&response_type=code&state={$state}&redirect_uri=" . urlencode(REDDIT_REDIRECT_URI) . "&duration=permanent&scope=submit";

    wp_redirect($oauth_url);
    exit;
}

// Handle Reddit OAuth response
function reddit_cross_poster_handle_oauth_response($code) {
    $client_id = get_option('reddit_client_id');
    $client_secret = get_option('reddit_client_secret');

    $response = wp_remote_post('https://www.reddit.com/api/v1/access_token', array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'User-Agent' => 'YourAppName/0.1 by YourUsername'
        ),
        'body' => array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => REDDIT_REDIRECT_URI
        )
    ));

    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['access_token'])) {
            update_option('reddit_access_token', $body['access_token']);
            update_option('reddit_refresh_token', $body['refresh_token']);
        }
    }
}

//Cleanup on deletion 
// Register the uninstall hook
register_uninstall_hook(__FILE__, 'reddit_cross_poster_uninstall');

// Uninstall function to clean up plugin data
function reddit_cross_poster_uninstall() {
    // Delete plugin options
    delete_option('reddit_client_id');
    delete_option('reddit_client_secret');
    delete_option('reddit_access_token');
    delete_option('reddit_refresh_token');
    delete_option('reddit_category_subreddit_map');

    // Delete all user meta related to the plugin
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
            '_reddit_cross_post_enabled',
            '_reddit_cross_post_manual_subreddit'
        )
    );

    // Log uninstall event (useful for debugging)
    error_log('Reddit Cross Poster plugin uninstalled and data cleaned up.');
}

// Register plugin settings
add_action('admin_init', 'reddit_cross_poster_settings');
function reddit_cross_poster_settings() {
    // Reddit API Settings
    add_settings_section(
        'reddit_cross_poster_main', 
        'Reddit API Settings', 
        function() {
            echo '<p>Enter your Reddit API credentials to enable the plugin to post on your behalf.</p>';
        }, 
        'reddit-cross-poster'
    );

    add_settings_field(
        'reddit_client_id',
        'Client ID',
        function() {
            echo '<input type="text" name="reddit_client_id" value="' . esc_attr(get_option('reddit_client_id')) . '" style="width: 100%;">';
            echo '<p class="description">Your Reddit API Client ID. Get this from the <a href="https://www.reddit.com/prefs/apps" target="_blank">Reddit Developer Portal</a>.</p>';
        },
        'reddit-cross-poster',
        'reddit_cross_poster_main'
    );

    add_settings_field(
        'reddit_client_secret',
        'Client Secret',
        function() {
            echo '<input type="text" name="reddit_client_secret" value="' . esc_attr(get_option('reddit_client_secret')) . '" style="width: 100%;">';
            echo '<p class="description">Your Reddit API Client Secret.</p>';
        },
        'reddit-cross-poster',
        'reddit_cross_poster_main'
    );

    // Subreddit Mapping
    add_settings_section(
        'reddit_cross_poster_mapping', 
        'Subreddit Mapping', 
        function() {
            echo '<p>Map WordPress categories to specific subreddits. Posts in these categories will automatically cross-post to the mapped subreddits.</p>';
        }, 
        'reddit-cross-poster'
    );

    add_settings_field(
        'reddit_category_subreddit_map',
        'Category to Subreddit Mapping',
        function() {
            echo '<textarea name="reddit_category_subreddit_map" rows="5" style="width: 100%;">' . esc_textarea(get_option('reddit_category_subreddit_map')) . '</textarea>';
            echo '<p class="description">Enter mappings in the format <code>category:subreddit1,subreddit2</code>, one per line. Example:</p>';
            echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">news:worldnews,politics
tech:technology,gadgets</pre>';
        },
        'reddit-cross-poster',
        'reddit_cross_poster_mapping'
    );

    // Default Subreddit Setting
    add_settings_section(
        'reddit_cross_poster_default', 
        'Default Subreddit', 
        function() {
            echo '<p>Specify a default subreddit to use when no category is assigned or no mapping is found.</p>';
        }, 
        'reddit-cross-poster'
    );

    add_settings_field(
        'reddit_default_subreddit',
        'Default Subreddit',
        function() {
            echo '<input type="text" name="reddit_default_subreddit" value="' . esc_attr(get_option('reddit_default_subreddit')) . '" style="width: 100%;">';
            echo '<p class="description">Enter the default subreddit. Example: <code>generalnews</code></p>';
        },
        'reddit-cross-poster',
        'reddit_cross_poster_default'
    );

    // Register the settings
    register_setting('reddit_cross_poster_options', 'reddit_client_id');
    register_setting('reddit_cross_poster_options', 'reddit_client_secret');
    register_setting('reddit_cross_poster_options', 'reddit_category_subreddit_map');
    register_setting('reddit_cross_poster_options', 'reddit_default_subreddit'); // New setting
}

// Add meta box for post editor
add_action('add_meta_boxes', 'reddit_cross_poster_meta_box');
function reddit_cross_poster_meta_box() {
    add_meta_box(
        'reddit_cross_poster_meta',
        'Reddit Cross Poster',
        'reddit_cross_poster_meta_callback',
        'post', // Restrict to posts
        'side' // Places it in the sidebar
    );
}

function reddit_cross_poster_meta_callback($post) {
    $enabled = get_post_meta($post->ID, '_reddit_cross_post_enabled', true);
    $manual_subreddit = get_post_meta($post->ID, '_reddit_cross_post_manual_subreddit', true);
    ?>
    <div style="margin-bottom: 10px;">
        <label for="reddit_cross_post_enabled">
            <input type="checkbox" name="reddit_cross_post_enabled" id="reddit_cross_post_enabled" value="1" <?php checked($enabled, '1'); ?>>
            <strong>Auto Cross-Post</strong>
        </label>
        <p style="font-size: 12px; color: #555;">
            Automatically post to Reddit upon saving or publishing.
        </p>
    </div>

    <div style="margin-bottom: 10px;">
        <label for="reddit_cross_post_manual_subreddit">
            <strong>Manual Subreddit:</strong>
        </label>
        <input type="text" name="reddit_cross_post_manual_subreddit" id="reddit_cross_post_manual_subreddit" value="<?php echo esc_attr($manual_subreddit); ?>" placeholder="e.g., pics, news" style="width: 100%;">
        <p style="font-size: 12px; color: #555;">
            Enter subreddit names separated by commas (e.g., <code>pics, news</code>). Only letters, numbers, and underscores are allowed.
        </p>
    </div>

    <div style="margin-bottom: 10px;">
        <button type="button" id="reddit_cross_poster_send_now" class="button button-primary">
            Manual Submit Now
        </button>
    </div>

    <script>
document.getElementById('reddit_cross_poster_send_now').addEventListener('click', function() {
    const button = this;
    button.disabled = true; // Disable button during request

    const postId = <?php echo $post->ID; ?>;
    const manualSubreddit = document.getElementById('reddit_cross_post_manual_subreddit').value;

    if (!manualSubreddit) {
        alert('Error: Please specify at least one subreddit.');
        button.disabled = false; // Re-enable button
        return;
    }

    const data = {
        action: 'reddit_cross_post',
        post_id: postId,
        manual_subreddit: manualSubreddit, // Include the manual subreddit
        _ajax_nonce: '<?php echo wp_create_nonce("reddit_cross_post_nonce"); ?>'
    };

    jQuery.post(ajaxurl, data, function(response) {
        if (response.success) {
            alert('Post successfully submitted to Reddit!');
        } else {
            alert('Failed to submit post: ' + response.data);
        }
        button.disabled = false; // Re-enable button
    });
});

    </script>
    <?php
}

// Save meta box data when the post is saved
add_action('save_post', 'reddit_cross_poster_save_meta');
function reddit_cross_poster_save_meta($post_id) {
    if (array_key_exists('reddit_cross_post_manual_subreddit', $_POST)) {
        $manual_subreddit = sanitize_text_field($_POST['reddit_cross_post_manual_subreddit']);
        
        // Validate subreddit names (letters, numbers, underscores, and commas)
        if (preg_match('/^[a-zA-Z0-9_]+(,[a-zA-Z0-9_]+)*$/', $manual_subreddit)) {
            update_post_meta($post_id, '_reddit_cross_post_manual_subreddit', $manual_subreddit);
        } else {
            // Clear invalid input
            delete_post_meta($post_id, '_reddit_cross_post_manual_subreddit');
        }
    }

    $enabled = isset($_POST['reddit_cross_post_enabled']) ? '1' : '';
    update_post_meta($post_id, '_reddit_cross_post_enabled', $enabled);
}


// AJAX handler for the "Send Now" button
add_action('wp_ajax_reddit_cross_post', 'reddit_cross_poster_ajax_handler');
function reddit_cross_poster_ajax_handler() {
    check_ajax_referer('reddit_cross_post_nonce', '_ajax_nonce');

    $post_id = intval($_POST['post_id']);
    if (!$post_id) wp_send_json_error('Invalid post ID.');

    // Retrieve manual subreddit directly from the AJAX request
    $manual_subreddit = isset($_POST['manual_subreddit']) ? sanitize_text_field($_POST['manual_subreddit']) : '';

    // If manual subreddit is not provided via AJAX, retrieve from post meta
    if (empty($manual_subreddit)) {
        $manual_subreddit = get_post_meta($post_id, '_reddit_cross_post_manual_subreddit', true);
    }

    if (empty($manual_subreddit)) {
        wp_send_json_error('No subreddit specified. Add a subreddit in the "Manual Subreddit" field.');
    }

    $token = get_option('reddit_access_token');
    if (!$token) wp_send_json_error('No OAuth token available.');

    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    if (!$image_url) wp_send_json_error('No featured image found.');

    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $post_url = get_permalink($post_id);
    $title = get_the_title($post_id);
    $target_subreddits = explode(',', $manual_subreddit);

    $submission_errors = [];
    foreach ($target_subreddits as $subreddit) {
        $subreddit = trim($subreddit);
        if (empty($subreddit)) continue;

        $success = reddit_cross_poster_submit_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit);
        if (!$success) {
            $submission_errors[] = "Failed to post to r/$subreddit.";
        }
    }

    if (!empty($submission_errors)) {
        wp_send_json_error(implode(' ', $submission_errors));
    }

    wp_send_json_success('Post successfully submitted to Reddit.');
}

// Hook into post publishing to trigger auto cross-posting
add_action('publish_post', 'reddit_cross_poster_publish_to_reddit');
function reddit_cross_poster_publish_to_reddit($post_id) {
    // Skip if this is a revision or an auto-save
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        error_log("Reddit Cross Poster: Skipping revision or autosave for post ID $post_id.");
        return;
    }

    // Check if auto-posting is enabled for this post
    $enabled = get_post_meta($post_id, '_reddit_cross_post_enabled', true);
    if ($enabled !== '1') {
        error_log("Reddit Cross Poster: Auto-posting is disabled for post ID $post_id.");
        return;
    }

    // Retrieve the categories assigned to the post
    $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
    
    // Get the category-to-subreddit mapping from settings
    $category_subreddit_map = get_option('reddit_category_subreddit_map');
    $default_subreddit = get_option('reddit_default_subreddit'); // Fetch the default subreddit from settings

    // Parse the mapping and find target subreddits
    $target_subreddits = [];
    if (!empty($category_subreddit_map)) {
        foreach (explode("\n", $category_subreddit_map) as $mapping) {
            if (strpos($mapping, ':') !== false) {
                [$category, $subreddits] = explode(':', trim($mapping));
                $subreddits = array_map('trim', explode(',', $subreddits));

                // Perform case-insensitive category matching
                if (in_array(strtolower($category), array_map('strtolower', $categories))) {
                    $target_subreddits = array_merge($target_subreddits, $subreddits);
                }
            }
        }
    }

    // Use the default subreddit if no specific subreddit was found
    if (empty($target_subreddits)) {
        if (!empty($default_subreddit)) {
            $target_subreddits[] = $default_subreddit;
            error_log("Reddit Cross Poster: Using default subreddit '$default_subreddit' for post ID $post_id.");
        } else {
            error_log("Reddit Cross Poster: No target subreddits found and no default subreddit set for post ID $post_id.");
            return;
        }
    }

    // Retrieve the OAuth token
    $token = get_option('reddit_access_token');
    if (!$token) {
        error_log("Reddit Cross Poster: No OAuth token found.");
        return;
    }

    // Prepare the post data
    $image_url = has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '';
    if (empty($image_url)) {
        error_log("Reddit Cross Poster: No featured image found for post ID $post_id.");
        return;
    }

    $title = html_entity_decode(get_the_title($post_id), ENT_QUOTES);
    $excerpt = wp_trim_words(get_the_excerpt($post_id), 55);
    $post_url = get_permalink($post_id);

    // Submit the post to each target subreddit
    foreach ($target_subreddits as $subreddit) {
        $subreddit = trim($subreddit);
        if (empty($subreddit)) continue;

        $success = reddit_cross_poster_submit_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit);
        if ($success) {
            error_log("Reddit Cross Poster: Successfully posted to r/$subreddit for post ID $post_id.");
        } else {
            error_log("Reddit Cross Poster: Failed to post to r/$subreddit for post ID $post_id.");
        }
    }
}


// Submit post to Reddit
function reddit_cross_poster_submit_to_reddit($token, $title, $image_url, $excerpt, $post_url, $subreddit) {
    $decoded_title = html_entity_decode($title, ENT_QUOTES);
    $combined_text = $excerpt . " [Continue reading on our website]($post_url)";

    $response = wp_remote_post('https://oauth.reddit.com/api/submit', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'User-Agent' => 'YourAppName/1.0'
        ),
        'body' => array(
            'title' => $decoded_title,
            'url' => $image_url,
            'kind' => 'link',
            'sr' => $subreddit,
            'text' => $combined_text
        ),
    ));

    if (is_wp_error($response)) {
        error_log("Reddit Cross Poster: WP Error - " . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    error_log("Reddit Cross Poster: API Response - " . print_r($body, true));

    if (isset($body['json']['errors']) && !empty($body['json']['errors'])) {
        error_log("Reddit Cross Poster: API Errors - " . print_r($body['json']['errors'], true));
        return implode(', ', array_map(function ($error) {
            return $error[1];
        }, $body['json']['errors']));
    }

    if (isset($body['json']['data']['url'])) {
        return true;
    }

    return false;
}
