<?php
/**
 * Plugin Name: G Web AI Drafts (Yoast SEO + WP Auth + Featured Image + Bulk)
 * Plugin URI:  https://github.com/GWeb2015/G-Web-AI-Drafts
 * Description: Creates single or multiple draft posts via REST API with Yoast SEO fields, featured image, categories, and tags using WordPress authentication.
 * Version: 1.4
 * Author: G Web Design
 * Author URI:  https://www.gwebdesign.co.za
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: g-web-ai-drafts
 */

if (! defined('ABSPATH')) exit;

class GWeb_AI_Drafts_Yoast_WPAuth
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_endpoint']);
    }

    public function register_endpoint()
    {
        register_rest_route('gweb-ai-drafts/v1', '/create-post', [
            'methods'  => 'POST',
            'callback' => [$this, 'handle_create_post'],
            'permission_callback' => [$this, 'check_auth'],
        ]);
    }

    public function check_auth($request)
    {
        $user = wp_get_current_user();
        if ($user && $user->ID > 0) {
            return true;
        }
        return new WP_Error('unauthorized', 'Authentication required', ['status' => 401]);
    }

    public function handle_create_post($request)
    {
        // ✅ Bulk mode
        if ($posts = $request->get_param('posts')) {
            $results = [];
            foreach ($posts as $post_data) {
                $sub_request = new WP_REST_Request('POST', '/gweb-ai-drafts/v1/create-post');
                $sub_request->set_body_params($post_data);
                $response = $this->process_single_post($sub_request);
                $results[] = $response;
            }
            return $results;
        }

        // ✅ Single mode
        return $this->process_single_post($request);
    }

    private function process_single_post($request)
    {
        $title   = sanitize_text_field($request->get_param('title'));
        $content = wp_kses_post($request->get_param('content'));
        $status  = sanitize_text_field($request->get_param('status') ?: 'draft');
        $excerpt = sanitize_textarea_field($request->get_param('excerpt'));
        $slug    = sanitize_title($request->get_param('slug'));

        $post_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_excerpt' => $excerpt,
            'post_name'    => $slug,
            'post_author'  => get_current_user_id(),
        ], true);

        if (is_wp_error($post_id)) return $post_id;

        // ✅ Categories
        if ($cats = $request->get_param('categories')) {
            wp_set_post_terms($post_id, $cats, 'category');
        }

        // ✅ Tags
        if ($tags = $request->get_param('tags')) {
            wp_set_post_terms($post_id, $tags, 'post_tag');
        }

        // ✅ Featured image
        if ($img_url = $request->get_param('featured_image_url')) {
            $this->set_featured_image($post_id, esc_url_raw($img_url));
        } elseif ($img_keyword = $request->get_param('featured_image_keyword')) {
            $this->set_featured_image_from_pixabay($post_id, $img_keyword);
        }

        // ✅ Yoast SEO
        if ($yoast_title = $request->get_param('yoast_title')) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($yoast_title));
        }
        if ($yoast_metadesc = $request->get_param('yoast_metadesc')) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field($yoast_metadesc));
        }
        if ($yoast_focuskw = $request->get_param('yoast_focuskw')) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($yoast_focuskw));
        }

        return [
            'success' => true,
            'post_id' => $post_id,
            'edit_link' => get_edit_post_link($post_id, ''),
        ];
    }

    private function set_featured_image($post_id, $image_url)
    {
        if (! post_type_supports(get_post_type($post_id), 'thumbnail')) return;

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            error_log('Download failed: ' . $tmp->get_error_message());
            return;
        }

        $file_array = [
            'name'     => basename(parse_url($image_url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            error_log('Sideload failed: ' . $id->get_error_message());
            return;
        }

        set_post_thumbnail($post_id, $id);
    }

    private function set_featured_image_from_pixabay($post_id, $keyword)
    {
        $api_key = '51846420-69983371bf7679ce694293d9d';
        $query   = urlencode($keyword);
        $url     = "https://pixabay.com/api/?key=$api_key&q=$query&image_type=photo&per_page=3";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) return;

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['hits'][0]['largeImageURL'])) {
            $image_url = $data['hits'][0]['largeImageURL'];
            $this->set_featured_image($post_id, $image_url);
        }
    }
}

new GWeb_AI_Drafts_Yoast_WPAuth();


/**
 * Add Word Count Column to Admin Post List
 */
add_filter('manage_posts_columns', 'add_word_count_column');
function add_word_count_column($columns) {
    $columns['word_count'] = 'Words';
    return $columns;
}

add_action('manage_posts_custom_column', 'display_word_count_column', 10, 2);
function display_word_count_column($column, $post_id) {
    if ($column === 'word_count') {
        $content = get_post_field('post_content', $post_id);
        $word_count = str_word_count(strip_tags(strip_shortcodes($content)));
        echo $word_count;
    }
}

// Optional: Make the column sortable
add_filter('manage_edit-post_sortable_columns', 'make_word_count_sortable');
function make_word_count_sortable($columns) {
    $columns['word_count'] = 'word_count';
    return $columns;
}
