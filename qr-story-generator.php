<?php
/**
 * Plugin Name:       QR Story Generator
 * Description:       A plugin that uses a device camera to scan QR codes and generate stories using AI. Integrates with WordPress 7.0 Native AI Connectors.
 * Version:           9.1
 * Requires at least: 7.0
 * Tested up to:      7.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qr-story-generator
 * Author:            AI Assistant
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// #################### 1. ADMIN SETTINGS PAGE ####################

add_action('admin_menu', 'qrsg_add_admin_menu');
function qrsg_add_admin_menu() {
    add_menu_page('QR Story Generator Settings', 'QR Story Generator', 'manage_options', 'qr_story_generator', 'qrsg_settings_page_html', 'dashicons-camera-alt', 20);
}

add_action('admin_init', 'qrsg_settings_init');
function qrsg_settings_init() {
    register_setting('qrsg_settings_group', 'qrsg_settings', ['sanitize_callback' => 'qrsg_sanitize_settings']);
    
    add_settings_section('qrsg_default_blueprint_section', 'Default Blueprint Settings', function() {
        echo '<p>Select a default blueprint to use for "Plant a Seed" and "Launch" functions when a more specific blueprint is not assigned to a custom prompt. <strong>This is required for these features to work.</strong></p>';
    }, 'qr_story_generator');
    add_settings_field('qrsg_default_blueprint_id', 'Default Fallback Blueprint', 'qrsg_default_blueprint_field_html', 'qr_story_generator', 'qrsg_default_blueprint_section');

    add_settings_section('qrsg_prompts_section', 'Custom QR Code Prompts', function() {
        echo '<p>Define specific QR code data and a custom prompt. You can optionally assign a specific Blueprint to override the default setting for each prompt. Use <code>%%QR_DATA%%</code> to insert the scanned data.</p>';
    }, 'qr_story_generator');
    add_settings_field('qrsg_custom_prompts', 'QR Prompts', 'qrsg_custom_prompts_field_html', 'qr_story_generator', 'qrsg_prompts_section');
}

function qrsg_sanitize_settings($input) {
    $new_input = [];
    if (isset($input['default_blueprint_id'])) { $new_input['default_blueprint_id'] = absint($input['default_blueprint_id']); }

    if (isset($input['custom_prompts']) && is_array($input['custom_prompts'])) {
        foreach ($input['custom_prompts'] as $p) {
            if (!empty($p['qr_data']) || !empty($p['prompt'])) {
                $sanitized_prompt = [
                    'qr_data' => sanitize_text_field(wp_unslash($p['qr_data'])), 
                    'prompt' => sanitize_textarea_field(wp_unslash($p['prompt']))
                ];
                if (isset($p['blueprint_id'])) {
                    $sanitized_prompt['blueprint_id'] = absint($p['blueprint_id']);
                }
                $new_input['custom_prompts'][] = $sanitized_prompt;
            }
        }
    }
    return $new_input;
}

function qrsg_default_blueprint_field_html() {
    $options = get_option('qrsg_settings');
    $selected_id = isset($options['default_blueprint_id']) ? $options['default_blueprint_id'] : 0;
    $blueprints = get_posts(['post_type' => 'blueprint', 'post_status' => 'publish', 'numberposts' => -1]);
    echo '<select name="qrsg_settings[default_blueprint_id]">';
    echo '<option value="">-- None (Features will be hidden) --</option>';
    if ($blueprints) {
        foreach ($blueprints as $blueprint) {
            printf('<option value="%s"%s>%s</option>', esc_attr($blueprint->ID), selected($selected_id, $blueprint->ID, false), esc_html($blueprint->post_title));
        }
    }
    echo '</select>';
}

function qrsg_custom_prompts_field_html() {
    $options = get_option('qrsg_settings');
    $prompts = !empty($options['custom_prompts']) && is_array($options['custom_prompts']) ? $options['custom_prompts'] : [['qr_data' => '', 'prompt' => '', 'blueprint_id' => 0]];
    $all_blueprints = get_posts(['post_type' => 'blueprint', 'post_status' => 'publish', 'numberposts' => -1]);
    ?>
    <table id="qrsg-prompts-table" class="wp-list-table widefat striped">
        <thead><tr>
            <th style="width: 25%;">QR Code Data (Exact Match)</th>
            <th>Custom Prompt</th>
            <th style="width: 20%;">Target Blueprint (Overrides Default)</th>
            <th style="width: 10%;">Actions</th>
        </tr></thead>
        <tbody>
            <?php foreach ($prompts as $index => $item) : 
                $selected_blueprint_id = $item['blueprint_id'] ?? 0;
            ?>
                <tr>
                    <td><input type="text" name="qrsg_settings[custom_prompts][<?php echo esc_attr($index); ?>][qr_data]" value="<?php echo esc_attr($item['qr_data'] ?? ''); ?>" class="widefat" placeholder="e.g., https://my-site.com/treasure-1"></td>
                    <td><textarea name="qrsg_settings[custom_prompts][<?php echo esc_attr($index); ?>][prompt]" class="widefat" rows="3" placeholder="e.g., Write a sci-fi story..."><?php echo esc_textarea($item['prompt'] ?? ''); ?></textarea></td>
                    <td>
                        <select name="qrsg_settings[custom_prompts][<?php echo esc_attr($index); ?>][blueprint_id]" class="widefat">
                            <option value="">Use Default Blueprint</option>
                            <?php if ($all_blueprints) : ?>
                                <?php foreach ($all_blueprints as $blueprint) : ?>
                                    <option value="<?php echo esc_attr($blueprint->ID); ?>" <?php selected($selected_blueprint_id, $blueprint->ID); ?>>
                                        <?php echo esc_html($blueprint->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </td>
                    <td><button type="button" class="button qrsg-remove-prompt-row">&times;</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <button type="button" id="qrsg-add-prompt-row" class="button button-secondary" style="margin-top: 10px;">Add New Prompt</button>
    <script>
    jQuery(document).ready(function($) {
        var rowTemplate = $('#qrsg-prompts-table tbody tr:first').clone();
        rowTemplate.find('input, textarea, select').val('');
        $('#qrsg-add-prompt-row').on('click', function() {
            var rowCount = $('#qrsg-prompts-table tbody tr').length;
            var newRow = rowTemplate.clone();
            newRow.find('input, textarea, select').each(function() {
                this.name = this.name.replace(/\[\d+\]/, '[' + rowCount + ']');
            });
            $('#qrsg-prompts-table tbody').append(newRow);
        });
        $('#qrsg-prompts-table').on('click', '.qrsg-remove-prompt-row', function() {
            if ($('#qrsg-prompts-table tbody tr').length > 1) {
                $(this).closest('tr').remove();
            } else {
                $(this).closest('tr').find('input, textarea, select').val('');
            }
        });
    });
    </script>
    <?php
}

function qrsg_settings_page_html() {
    if (!current_user_can('manage_options')) return;

    // Determine the active tab (default to 'settings')
    $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=qr_story_generator&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
            <a href="?page=qr_story_generator&tab=tutorial" class="nav-tab <?php echo $active_tab === 'tutorial' ? 'nav-tab-active' : ''; ?>">Setup & Tutorial</a>
        </h2>

        <?php if ($active_tab === 'settings') : ?>
            <div style="margin-top: 20px;">
                <div class="notice notice-info inline">
                    <p><strong>Note:</strong> API credentials are now managed natively via WordPress 7.0 in <strong>Settings > Connectors</strong>.</p>
                </div>
                <form action="options.php" method="post">
                    <?php 
                        settings_fields('qrsg_settings_group'); 
                        do_settings_sections('qr_story_generator'); 
                        submit_button('Save Settings'); 
                    ?>
                </form>
            </div>

        <?php elseif ($active_tab === 'tutorial') : ?>
            <div class="qrsg-tutorial-content" style="max-width: 800px; margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">Welcome to QR Story Generator</h2>
                <p>QR Story Generator transforms simple QR code scans into unique, interactive AI-generated stories. Follow these steps to get your app up and running:</p>
                
                <hr>

                <h3>1. Configure the Native AI Client</h3>
                <p>This plugin is optimized for the <strong>WordPress 7.0 Native AI Client</strong>. You no longer need to enter API keys directly into this plugin.</p>
                <ul>
                    <li>Navigate to <strong>Settings > Connectors</strong> in your WordPress dashboard.</li>
                    <li>Ensure your preferred AI provider (e.g., Gemini) is connected and set as the active text generation engine.</li>
                </ul>
                
                <h3>2. Display the App on the Front-End</h3>
                <p>To display the QR scanner and story reader interface to your users, create a new page (or edit an existing one) and add the following shortcode:</p>
                <p><code>[qr_story_generator]</code></p>
                <p><strong>Bonus:</strong> You can also display a list of recently submitted "QR Stories" in your sidebar or on a page using the recent stories shortcode:</p>
                <p><code>[recent_qr_stories count="5"]</code></p>
                
                <h3>3. Configure Custom Prompts (Optional)</h3>
                <p>By default, scanning any QR code will prompt the AI to write a generic 250-word story based on the code's raw text. However, you can map exact QR codes to highly specific prompts in the <strong>Settings</strong> tab.</p>
                <ul>
                    <li><strong>QR Code Data:</strong> Enter the exact string the QR code contains (e.g., <code>https://my-site.com/treasure-1</code>).</li>
                    <li><strong>Custom Prompt:</strong> Provide specific instructions to the AI (e.g., "Write a sci-fi story about a Martian discovering an ancient artifact.").</li>
                    <li>Use the <code>%%QR_DATA%%</code> variable in your prompt to dynamically inject the scanned text.</li>
                </ul>
                
                <h3>4. Setup Blueprints (Optional)</h3>
                <p>Blueprints allow users to use the "Plant a Seed" or "Launch" features to branch out into new story templates. To utilize this:</p>
                <ol>
                    <li>Create your custom templates under the <strong>Blueprints</strong> post type (if registered on your site).</li>
                    <li>Go back to the <strong>Settings</strong> tab and select a "Default Fallback Blueprint".</li>
                    <li><em>Note: If no blueprint is selected, the "Plant a Seed" button will remain hidden from users.</em></li>
                </ol>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// #################### 1.5 CUSTOM POST TYPE & ADMIN BADGE ####################

add_action('init', 'qrsg_register_story_cpt');
function qrsg_register_story_cpt() {
    $labels = [
        'name'                  => _x('QR Stories', 'Post type general name', 'qr-story-generator'),
        'singular_name'         => _x('QR Story', 'Post type singular name', 'qr-story-generator'),
        'menu_name'             => _x('QR Stories', 'Admin Menu text', 'qr-story-generator'),
        'name_admin_bar'        => _x('QR Story', 'Add New on Toolbar', 'qr-story-generator'),
        'add_new'               => __('Add New', 'qr-story-generator'),
        'add_new_item'          => __('Add New QR Story', 'qr-story-generator'),
        'new_item'              => __('New QR Story', 'qr-story-generator'),
        'edit_item'             => __('Edit QR Story', 'qr-story-generator'),
        'view_item'             => __('View QR Story', 'qr-story-generator'),
        'all_items'             => __('All QR Stories', 'qr-story-generator'),
        'search_items'          => __('Search QR Stories', 'qr-story-generator'),
        'parent_item_colon'     => __('Parent QR Stories:', 'qr-story-generator'),
        'not_found'             => __('No QR stories found.', 'qr-story-generator'),
        'not_found_in_trash'    => __('No QR stories found in Trash.', 'qr-story-generator'),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => ['slug' => 'qr-stories'],
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-format-aside',
        'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'revisions'],
        'taxonomies'         => ['post_tag'],
    ];

    register_post_type('qr_story', $args);
}

add_action('admin_menu', 'qrsg_add_story_draft_badge', 99);
function qrsg_add_story_draft_badge() {
    global $menu;
    $draft_count = wp_count_posts('qr_story')->draft;
    
    if ($draft_count > 0) {
        foreach ($menu as $key => $value) {
            if ($value[2] === 'edit.php?post_type=qr_story') {
                $menu[$key][0] .= sprintf(' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>', $draft_count);
                break;
            }
        }
    }
}

// #################### 2. SHORTCODES & FRONT-END APP ####################

add_shortcode('recent_qr_stories', 'qrsg_recent_stories_shortcode');
function qrsg_recent_stories_shortcode($atts) {
    $atts = shortcode_atts(['count' => 5], $atts);
    $query = new WP_Query([
        'post_type' => 'qr_story',
        'post_status' => 'publish',
        'posts_per_page' => absint($atts['count'])
    ]);
    
    if (!$query->have_posts()) {
        return '<p>No stories generated yet.</p>';
    }
    
    $html = '<div class="qrsg-sidebar-widget"><ul style="list-style: none; padding-left: 0;">';
    while ($query->have_posts()) {
        $query->the_post();
        $html .= '<li style="margin-bottom: 8px;"><strong><a href="' . get_permalink() . '">' . get_the_title() . '</a></strong><br><small>' . get_the_date() . '</small></li>';
    }
    wp_reset_postdata();
    $html .= '</ul></div>';
    
    return $html;
}

add_shortcode('qr_story_generator', 'qrsg_render_app_shortcode');
function qrsg_render_app_shortcode() {
    // Localized resources to comply with repository rules
    wp_enqueue_style('qrsg-bootstrap-css', plugin_dir_url(__FILE__) . 'assets/css/bootstrap.min.css', [], '5.3.3');
    wp_enqueue_style('qrsg-fontawesome-css', plugin_dir_url(__FILE__) . 'assets/css/all.min.css', [], '6.4.0');
    wp_enqueue_script('qrsg-bootstrap-js', plugin_dir_url(__FILE__) . 'assets/js/bootstrap.bundle.min.js', [], '5.3.3', true);
    
    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <style>
            :root {
                --qrsg-bg: rgba(15, 20, 30, 0.7);
                --qrsg-border: rgba(255, 255, 255, 0.1);
                --qrsg-text: #ffffff;
                --qrsg-text-muted: #aaaaaa;
                --qrsg-primary: #00f2ff;
            }
            html[data-theme="light"] {
                --qrsg-bg: rgba(255, 255, 255, 0.85);
                --qrsg-border: rgba(0, 0, 0, 0.15);
                --qrsg-text: #111111;
                --qrsg-text-muted: #555555;
                --qrsg-primary: #008db3;
            }
        </style>
        <div id="qrsg-login-screen" class="d-flex align-items-center justify-content-center" style="min-height: 70vh; background-color: transparent; padding: 1rem;">
            <div class="card shadow-lg border-0" style="max-width: 420px; width: 100%; border-radius: 15px; overflow: hidden; background: var(--qrsg-bg); backdrop-filter: blur(15px); border: 1px solid var(--qrsg-border) !important; color: var(--qrsg-text); transition: all 0.4s ease;">
                <div class="card-body p-4 p-md-5 text-center">
                    <div class="mb-4">
                        <i class="fas fa-camera-retro fa-3x" style="color: var(--qrsg-primary);"></i>
                    </div>
                    <h3 class="mb-2 fw-bold">Welcome Back</h3>
                    <p class="small mb-4" style="color: var(--qrsg-text-muted);">Please log in to keep your session active and submit stories to QR Story Generator.</p>
                    
                    <form name="qrsg-login-form" id="qrsg-login-form" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" method="post">
                        <div class="form-floating mb-3 text-start">
                            <input type="text" name="log" id="user_login" class="form-control" placeholder="Username or Email Address" style="background: rgba(128,128,128,0.1); border-color: var(--qrsg-border); color: var(--qrsg-text);" required>
                            <label for="user_login" style="color: var(--qrsg-text-muted);">Username or Email Address</label>
                        </div>
                        <div class="form-floating mb-3 text-start">
                            <input type="password" name="pwd" id="user_pass" class="form-control" placeholder="Password" style="background: rgba(128,128,128,0.1); border-color: var(--qrsg-border); color: var(--qrsg-text);" required>
                            <label for="user_pass" style="color: var(--qrsg-text-muted);">Password</label>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input class="form-check-input" name="rememberme" type="checkbox" value="forever" id="rememberme" checked>
                                <label class="form-check-label small" for="rememberme" style="color: var(--qrsg-text-muted);">Remember Me</label>
                            </div>
                            <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="small text-decoration-none" style="color: var(--qrsg-primary);">Forgot Password?</a>
                        </div>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url(get_permalink()); ?>">
                        <button type="submit" name="wp-submit" id="wp-submit" class="btn w-100 py-3 fw-bold" style="background-color: var(--qrsg-primary); color: #fff; border: none; border-radius: 8px; transition: background-color 0.3s;">Log In</button>
                    </form>
                </div>
                <?php if (class_exists('UM') && function_exists('um_get_core_page')): ?>
                <div class="card-footer text-center py-3 border-0" style="background: rgba(0,0,0,0.05); border-top: 1px solid var(--qrsg-border) !important;">
                    <span class="small" style="color: var(--qrsg-text-muted);">Don't have an account? <a href="<?php echo esc_url(um_get_core_page('register')); ?>" class="text-decoration-none fw-bold" style="color: var(--qrsg-primary);">Register Here</a></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('qrsg_ajax_nonce');
    $options = get_option('qrsg_settings');

    $default_blueprint_id = !empty($options['default_blueprint_id']) ? $options['default_blueprint_id'] : 0;
    $default_blueprint_url = $default_blueprint_id ? get_permalink($default_blueprint_id) : '';

    ob_start();
    ?>
    <style>
    :root {
        --qrsg-bg: rgba(15, 20, 30, 0.6);
        --qrsg-border: rgba(255, 255, 255, 0.1);
        --qrsg-text: #ffffff;
        --qrsg-text-muted: #aaaaaa;
        --qrsg-primary: #00f2ff;
        --qrsg-primary-hover: #00c3cc;
        --qrsg-panel: rgba(0, 0, 0, 0.3);
        --qrsg-input-bg: rgba(255, 255, 255, 0.05);
        --qrsg-highlight: rgba(0, 242, 255, 0.2);
        --qrsg-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    }
    html[data-theme="light"] {
        --qrsg-bg: rgba(255, 255, 255, 0.75);
        --qrsg-border: rgba(0, 0, 0, 0.15);
        --qrsg-text: #1a1a1a;
        --qrsg-text-muted: #666666;
        --qrsg-primary: #008db3;
        --qrsg-primary-hover: #006b88;
        --qrsg-panel: rgba(255, 255, 255, 0.6);
        --qrsg-input-bg: rgba(255, 255, 255, 0.8);
        --qrsg-highlight: rgba(0, 141, 179, 0.2);
        --qrsg-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    }

    #app-container {
        position: relative;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background: var(--qrsg-bg);
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        padding: 2rem;
        border-radius: 15px;
        border: 1px solid var(--qrsg-border);
        box-shadow: var(--qrsg-shadow);
        text-align: center;
        max-width: 500px;
        width: 90%;
        margin: 2rem auto;
        color: var(--qrsg-text);
        transition: all 0.4s ease;
    }
    
    #app-container h1 { margin-top: 0; color: var(--qrsg-primary); font-weight: 900; }
    #app-container p { color: var(--qrsg-text-muted); margin-bottom: 1.5rem; }
    
    #start-scan-btn, #scan-another-btn, #open-reader-btn, .submit-btn {
        background-color: var(--qrsg-primary);
        color: #fff;
        border: none;
        padding: 12px 24px;
        font-size: 1rem;
        font-weight: bold;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color .3s ease;
    }
    #scan-another-btn {
        background-color: transparent;
        border: 1px solid var(--qrsg-border);
        color: var(--qrsg-text);
    }
    #scan-another-btn:hover { background-color: var(--qrsg-input-bg); }
    
    #start-scan-btn:disabled, #scan-another-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    #start-scan-btn:hover:not(:disabled), #open-reader-btn:hover { background-color: var(--qrsg-primary-hover); }
    
    #video-container {
        margin-top: 1.5rem;
        position: relative;
        border-radius: 8px;
        overflow: hidden;
        border: 2px solid var(--qrsg-border);
    }
    #qr-video { width: 100%; height: auto; display: block; }
    
    .hidden { display: none !important; }
    .loader { border: 4px solid var(--qrsg-panel); border-top: 4px solid var(--qrsg-primary); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
    @keyframes spin { 0% { transform: rotate(0deg) } 100% { transform: rotate(360deg) } }
    
    .modal-content {
        background: var(--qrsg-bg) !important;
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
        border: 1px solid var(--qrsg-border) !important;
        color: var(--qrsg-text);
        border-radius: 15px;
        transition: all 0.4s ease;
    }
    .modal-header, .modal-footer {
        border-color: var(--qrsg-border) !important;
        background-color: rgba(0,0,0,0.05);
    }
    .modal-title { color: var(--qrsg-primary); font-weight: bold; }
    .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    html[data-theme="light"] .btn-close { filter: none; }

    #qrsg-reader-modal .modal-body {
        background-color: transparent !important;
    }
    #story-result-area { white-space: pre-wrap; margin-bottom: 2rem; font-size: 1.25rem; line-height: 1.8; font-family: 'Georgia', serif; color: var(--qrsg-text); }
    .story-sentence { display: inline; }
    .highlighted-sentence { background-color: var(--qrsg-highlight); transition: background-color .3s ease-out; border-radius: 4px; }
    
    .tts-btn { background-color: var(--qrsg-input-bg); color: var(--qrsg-text); border: 1px solid var(--qrsg-border); font-size: 1.2rem; border-radius: 50%; cursor: pointer; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; transition: all .2s ease; }
    .tts-btn:hover { border-color: var(--qrsg-primary); transform: scale(1.1); color: var(--qrsg-primary); }
    .tts-rate-select { background-color: var(--qrsg-input-bg); color: var(--qrsg-text); border: 1px solid var(--qrsg-border); border-radius: 8px; padding: 0 .5rem; font-weight: bold; height: 38px; cursor: pointer; -webkit-appearance: none; -moz-appearance: none; appearance: none; }
    
    #user-prompt { width: 100%; border-radius: 8px; background: var(--qrsg-input-bg); color: var(--qrsg-text); border: 1px solid var(--qrsg-border); padding: 1rem; font-family: inherit; font-size: 1.1rem; }
    #user-prompt:focus { outline: none; border-color: var(--qrsg-primary); }
    
    .modal-backdrop.show { z-index: 2147483646 !important; }
    .modal.show { z-index: 2147483647 !important; }
    </style>
    
    <div id="app-container" data-default-blueprint-url="<?php echo esc_url($default_blueprint_url); ?>">
        <button id="info-modal-btn" class="btn btn-sm btn-outline-secondary rounded-circle" style="position: absolute; top: 1rem; right: 1rem; width: 32px; height: 32px; z-index: 10;" data-bs-toggle="modal" data-bs-target="#qrsg-info-modal" title="About this App"><i class="fas fa-info"></i></button>
        <h1 id="app-title">QR Story Generator</h1>
        <p id="app-instructions">Click "Start Scan" to activate your camera.</p>
        <button id="start-scan-btn">Start Scan</button>
        <div id="video-container" class="hidden"><video id="qr-video" muted playsinline></video></div>
        
        <div id="result-container" class="hidden mt-4 pt-3 border-top" style="border-color: var(--qrsg-border) !important;">
            <h3 id="result-title" class="mb-2" style="color: var(--qrsg-primary);"></h3>
            <p id="result-instructions" class="small" style="color: var(--qrsg-text-muted);"></p>
            <div id="loading-indicator"></div>
            
            <div id="post-generation-actions" class="hidden mt-4">
                <button id="open-reader-btn" class="w-100 mb-3" data-bs-toggle="modal" data-bs-target="#qrsg-reader-modal">
                    <i class="fas fa-book-open me-2"></i>Open Story Reader
                </button>
                <button id="scan-another-btn" class="w-100">Scan Another QR Code</button>
            </div>
        </div>
    </div>
    <canvas id="qr-canvas" class="hidden"></canvas>

    <div class="modal fade" id="qrsg-reader-modal" tabindex="-1" aria-labelledby="qrsgReaderModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header shadow-sm" style="z-index: 10;">
                    <h5 class="modal-title fw-bold" id="qrsgReaderModalLabel"><i class="fas fa-book-reader me-2"></i>Story Log</h5>
                    
                    <div id="story-tts-controls" class="d-flex align-items-center gap-2 ms-auto me-3 hidden">
                        <button id="story-tts-rewind-btn" class="tts-btn" title="Restart">⏪</button>
                        <button id="story-tts-play-pause-btn" class="tts-btn" title="Play">▶️</button>
                        <button id="story-tts-stop-btn" class="tts-btn" title="Stop">⏹️</button>
                        <button id="story-tts-loop-btn" class="tts-btn" title="Toggle Loop"><i class="fas fa-sync-alt"></i></button>
                        <select id="story-tts-rate-select" class="tts-rate-select" title="Playback Speed">
                            <option value="0.8">Slow</option>
                            <option value="1" selected>1x</option>
                            <option value="1.2">Fast</option>
                            <option value="1.5">Max</option>
                        </select>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body" style="padding: 2rem 5% 4rem 5%;">
                    <div class="container" style="max-width: 800px; margin: 0 auto;">
                        
                        <div id="story-result-area"></div>
                        
                        <div id="story-tts-settings" class="hidden text-start small mt-4 p-3 border rounded" style="background: var(--qrsg-panel); border-color: var(--qrsg-border) !important;">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="restart-on-continuation-toggle">
                                <label class="form-check-label" for="restart-on-continuation-toggle">Restart audio from the top when continuing the story</label>
                            </div>
                        </div>
                        
                        <div id="story-input-area" class="hidden mt-5 pt-4 border-top" style="border-color: var(--qrsg-border) !important;">
                            <h5 class="fw-bold" style="color: var(--qrsg-primary);">Continue the Story</h5>
                            <p style="color: var(--qrsg-text-muted);">Give feedback, add a twist, or suggest what happens next.</p>
                            <textarea id="user-prompt" rows="3" placeholder="e.g., 'The hero discovers a hidden trapdoor...'"></textarea>
                            
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button id="continue-story-btn" class="btn btn-success flex-grow-1 py-2 fw-bold">Write Next Part</button>
                                <div class="dropdown flex-grow-1">
                                    <button class="btn btn-outline-primary dropdown-toggle w-100 py-2" type="button" id="quick-actions-btn" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-bolt"></i> Quick Actions
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="quick-actions-btn" id="quick-actions-menu" style="background: var(--qrsg-bg); backdrop-filter: blur(15px); border-color: var(--qrsg-border);">
                                        <li><a class="dropdown-item quick-action-item" href="#" style="color: var(--qrsg-text);">Continue what happens next</a></li>
                                        <li><a class="dropdown-item quick-action-item" href="#" style="color: var(--qrsg-text);">Rephrase the last part</a></li>
                                        <li><a class="dropdown-item quick-action-item" href="#" style="color: var(--qrsg-text);">Describe the surroundings</a></li>
                                        <li><a class="dropdown-item quick-action-item" href="#" style="color: var(--qrsg-text);">Introduce a surprising twist</a></li>
                                    </ul>
                                </div>
                                <button id="get-suggestions-btn" class="btn btn-info flex-grow-1 py-2 fw-bold text-white" data-bs-toggle="modal" data-bs-target="#qrsg-suggestions-modal" disabled>Get AI Suggestions</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="action-buttons-area" class="modal-footer hidden d-flex justify-content-between flex-wrap gap-3">
                    <button type="button" class="btn btn-outline-secondary px-4 fw-bold" data-bs-dismiss="modal">Close & Save Progress</button>
                    <div class="d-flex gap-2 flex-grow-1 justify-content-end">
                        <button id="plant-seed-btn" class="btn btn-success px-4" data-bs-toggle="modal" data-bs-target="#qrsg-qr-modal" disabled><i class="fas fa-seedling me-2"></i>Plant Seed</button>
                        <button id="submit-story-text-btn" class="btn btn-primary submit-btn px-4" style="background-color: var(--qrsg-primary); border: none;" data-bs-toggle="modal" data-bs-target="#qrsg-submit-modal" disabled><i class="fas fa-paper-plane me-2"></i>Submit for Review</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="qrsg-suggestions-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">AI Suggestions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="suggestions-modal-body"></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="qrsg-qr-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Your Story Seed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <p>Scan this QR code to start a new story with these keywords.</p>
                    <div id="qrcode-container" class="d-flex justify-content-center my-3 bg-white p-3 rounded d-inline-block"></div>
                    <p><small>Keywords: <span id="top-words-display" class="fw-bold" style="color: var(--qrsg-primary);"></span></small></p>
                    <?php if (!empty($default_blueprint_url)): ?>
                    <div class="dropdown mt-3">
                        <button class="btn btn-info dropdown-toggle text-white" type="button" id="blueprint-dropdown" data-bs-toggle="dropdown" aria-expanded="false">Launch as a Blueprint</button>
                        <ul class="dropdown-menu" aria-labelledby="blueprint-dropdown" style="background: var(--qrsg-bg); border-color: var(--qrsg-border);">
                            <li><a class="dropdown-item" href="#" data-format="english" style="color: var(--qrsg-text);">English</a></li>
                            <li><a class="dropdown-item" href="#" data-format="runes" style="color: var(--qrsg-text);">Runes</a></li>
                            <li><a class="dropdown-item" href="#" data-format="combined" style="color: var(--qrsg-text);">Combined</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="qrsg-submit-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit Story for Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-start">
                    <p>By submitting, you are saving this story as a draft post on the website. An editor will review it for potential publication.</p>
                    <p class="small" style="color: var(--qrsg-text-muted);">Please ensure your story adheres to community guidelines.</p>
                    <hr style="border-color: var(--qrsg-border);">
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="include-history-toggle">
                        <label class="form-check-label" for="include-history-toggle"><strong>Include full history</strong><br><span class="small" style="color: var(--qrsg-text-muted);">Check this to include all your prompts and the AI's continuations in the draft.</span></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="final-submit-btn" class="btn text-white" style="background-color: var(--qrsg-primary);">Acknowledge & Submit</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="qrsg-info-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>About QR Story Generator</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-start">
                    <p>Welcome! This application transforms a simple QR code scan into a unique, AI-generated story.</p>
                    <ol>
                        <li>Click <strong style="color: var(--qrsg-primary);">Start Scan</strong> and point your camera at a QR code.</li>
                        <li>Our AI generates a short story based on the decoded prompt.</li>
                        <li>Read the story in the <strong>Reader Mode</strong> and continue it with your own ideas.</li>
                        <li><strong>Submit for Review</strong> to save the story as a draft on this website.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <?php wp_enqueue_script('qrsg-jsqr', plugin_dir_url(__FILE__) . 'assets/js/jsQR.js', [], '1.4.0', true); ?>
    <?php wp_enqueue_script('qrsg-qrcode', plugin_dir_url(__FILE__) . 'assets/js/qrcode.min.js', [], '1.0.0', true); ?>
    
    <script type="text/javascript">
        const wp_ajax_obj = { ajax_url: '<?php echo esc_url($ajax_url); ?>', nonce: '<?php echo esc_js($nonce); ?>' };
        
        document.addEventListener('DOMContentLoaded', function() {
            
            let voicesLoaded = false;
            function loadVoices() {
                if (window.speechSynthesis) {
                    const voices = window.speechSynthesis.getVoices();
                    if (voices.length > 0) voicesLoaded = true;
                }
            }
            if (window.speechSynthesis) {
                loadVoices();
                if ('onvoiceschanged' in window.speechSynthesis) {
                    window.speechSynthesis.onvoiceschanged = loadVoices;
                }
            }

            const readerModalEl = document.getElementById('qrsg-reader-modal');
            const readerModal = new bootstrap.Modal(readerModalEl);

            const appContainer = document.getElementById('app-container');
            const defaultBlueprintUrl = appContainer.dataset.defaultBlueprintUrl || '';
            const video = document.getElementById('qr-video');
            const canvasElement = document.getElementById('qr-canvas');
            const canvas = canvasElement.getContext('2d');
            const videoContainer = document.getElementById('video-container');
            const startScanBtn = document.getElementById('start-scan-btn');
            const scanAnotherBtn = document.getElementById('scan-another-btn');
            
            const resultContainer = document.getElementById('result-container');
            const resultTitle = document.getElementById('result-title');
            const resultInstructions = document.getElementById('result-instructions');
            const loadingIndicator = document.getElementById('loading-indicator');
            const postGenerationActions = document.getElementById('post-generation-actions');
            
            const storyResultArea = document.getElementById('story-result-area');
            const storyTtsControls = document.getElementById('story-tts-controls');
            const storyTtsPlayPauseBtn = document.getElementById('story-tts-play-pause-btn');
            const storyTtsStopBtn = document.getElementById('story-tts-stop-btn');
            const storyTtsRewindBtn = document.getElementById('story-tts-rewind-btn');
            const storyTtsRateSelect = document.getElementById('story-tts-rate-select');
            const storyTtsSettings = document.getElementById('story-tts-settings');
            
            const plantSeedBtn = document.getElementById('plant-seed-btn');
            const finalSubmitBtn = document.getElementById('final-submit-btn');
            const storyInputArea = document.getElementById('story-input-area');
            const userPromptInput = document.getElementById('user-prompt');
            const continueStoryBtn = document.getElementById('continue-story-btn');
            const getSuggestionsBtn = document.getElementById('get-suggestions-btn');
            const suggestionsModalBody = document.getElementById('suggestions-modal-body');
            const qrcodeContainer = document.getElementById('qrcode-container');
            const topWordsDisplay = document.getElementById('top-words-display');
            const actionButtonsArea = document.getElementById('action-buttons-area');
            const includeHistoryToggle = document.getElementById('include-history-toggle');
            const storyTtsLoopBtn = document.getElementById('story-tts-loop-btn');
            const restartOnContinuationToggle = document.getElementById('restart-on-continuation-toggle');
            
            let stream = null; let isProcessing = false; let storyHistory = []; let lastGeneratedStoryContent = ''; let lastGeneratedKeywords = []; let lastGeneratedStoryTitle = ''; let lastGeneratedSuggestions = null;
            let lastGeneratedRedirectUrl = ''; let currentBlueprintBaseUrl = '';
            
            let storyUtteranceQueue = [];
            let storySentenceSpans = [];
            let currentUtteranceIndex = 0;
            let currentTtsRate = 1;
            let isLooping = false;
            let storyTextBeforeContinuation = '';

            const stopWords = new Set(['the','be','to','of','and','a','in','that','have','i','it','for','not','on','with','he','as','you','do','at','this','but','his','by','from','they','we','say','her','she','or','an','will','my','one','all','would','there','their','what','so','up','out','if','about','who','get','which','go','me','when','make','can','like','time','no','just','him','know','take','person','into','year','your','good','some','could','them','see','other','than','then','now','look','only','come','its','over','think','also','back','after','use','two','how','our','work','first','well','way','even','new','want','because','any','these','give','day','most','us']);
            const futharkMap={'a':'ᚨ','b':'ᛒ','c':'ᚲ','d':'ᛞ','e':'ᛖ','f':'ᚠ','g':'ᚷ','h':'ᚺ','i':'ᛁ','j':'ᛃ','k':'ᚲ','l':'ᛚ','m':'ᛗ','n':'ᚾ','o':'ᛟ','p':'ᛈ','q':'ᚲ','r':'ᚱ','s':'ᛊ','t':'ᛏ','u':'ᚢ','v':'ᚹ','w':'ᚹ','x':'ᛉ','y':'ᛁ','z':'ᛉ','ng':'ᛜ','th':'ᚦ'};
            function transliterateToFuthark(word){let lowerWord=word.toLowerCase();let runes='';let i=0;while(i<lowerWord.length){if(i+1<lowerWord.length){let twoChar=lowerWord.substring(i,i+2);if(futharkMap[twoChar]){runes+=futharkMap[twoChar];i+=2;continue;}}let oneChar=lowerWord[i];runes+=futharkMap[oneChar]||oneChar;i+=1;}return runes;}

            async function generateStory(qrData, isContinuation = false, userPrompt = '') {
                storyTextBeforeContinuation = isContinuation ? lastGeneratedStoryContent : '';
                
                if (!isContinuation) {
                    loadingIndicator.innerHTML = '<div class="loader"></div>';
                    postGenerationActions.classList.add('hidden');
                } else {
                    storyResultArea.innerHTML += '<div id="temp-loader" class="loader my-4"></div>';
                }
                
                storyInputArea.classList.add('hidden');
                actionButtonsArea.classList.add('hidden');
                plantSeedBtn.disabled = true;
                document.getElementById('submit-story-text-btn').disabled = true;
                getSuggestionsBtn.disabled = true;

                const formData = new FormData();
                formData.append('action', 'qrsg_generate_content');
                formData.append('nonce', wp_ajax_obj.nonce);
                
                if (isContinuation) {
                    formData.append('request_type', 'continuation');
                    formData.append('story_text', lastGeneratedStoryContent);
                    formData.append('user_prompt', userPrompt);
                } else {
                    isProcessing = true;
                    formData.append('request_type', 'story');
                    formData.append('qr_data', qrData);
                }

                try {
                    const response = await fetch(wp_ajax_obj.ajax_url, { method: 'POST', body: formData });
                    const result = await response.json();
                    
                    if (!result.success) throw new Error(result.data || 'The API returned an error.');

                    let storyText = result.data.story;

                    // Failsafe: Prevent AI from regurgitating the existing story
                    if (isContinuation) {
                        let cleanOld = storyTextBeforeContinuation.trim();
                        let snippet = cleanOld.substring(0, 40);
                        if (snippet.length > 10 && storyText.trim().indexOf(snippet) !== -1) {
                            let endSnippet = cleanOld.substring(cleanOld.length - 40);
                            let endIndex = storyText.lastIndexOf(endSnippet);
                            if (endIndex !== -1) {
                                storyText = storyText.substring(endIndex + endSnippet.length).trim();
                            }
                        }
                    }
                    
                    storyText = storyText.trim();
                    if (!isContinuation) lastGeneratedRedirectUrl = result.data.redirect_url || '';

                    lastGeneratedStoryContent = isContinuation ? lastGeneratedStoryContent + "\n\n" + storyText : storyText;
                    
                    lastGeneratedKeywords = getTop10Words(lastGeneratedStoryContent);
                    lastGeneratedStoryTitle = (lastGeneratedKeywords.length > 0 ? lastGeneratedKeywords.slice(0, 3).map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ') : 'Generated') + ' Story';

                    if(isContinuation) {
                        storyHistory.push({ type: 'continuation', prompt: userPrompt, result: storyText });
                    } else {
                        storyHistory = [{ type: 'initial', prompt: qrData, result: storyText }];
                    }

                    initializeStoryTTS(lastGeneratedStoryContent, isContinuation);
                    
                    loadingIndicator.innerHTML = '';
                    postGenerationActions.classList.remove('hidden');
                    actionButtonsArea.classList.remove('hidden');
                    if (lastGeneratedRedirectUrl || defaultBlueprintUrl) plantSeedBtn.disabled = false;
                    document.getElementById('submit-story-text-btn').disabled = false;
                    storyInputArea.classList.remove('hidden');
                    continueStoryBtn.disabled = false;
                    userPromptInput.disabled = false;
                    userPromptInput.value = '';
                    lastGeneratedSuggestions = null;
                    getSuggestionsBtn.disabled = false;

                    if (!isContinuation) {
                        readerModal.show();
                    }

                } catch (error) {
                    loadingIndicator.innerHTML = `
                        <div class="alert alert-warning mt-3">
                            <p class="mb-2"><strong>Oops! The request failed.</strong></p>
                            <p class="small text-muted mb-2">${error.message}</p>
                            <button id="retry-story-btn" class="btn btn-warning btn-sm">Retry</button>
                        </div>`;
                    
                    document.getElementById('retry-story-btn').addEventListener('click', () => {
                        generateStory(qrData, isContinuation, userPrompt);
                    });
                    
                    if(isContinuation) {
                        const tempLoader = document.getElementById('temp-loader');
                        if (tempLoader) tempLoader.remove();
                        storyInputArea.classList.remove('hidden');
                        continueStoryBtn.disabled = false;
                        userPromptInput.disabled = false;
                    }
                } finally {
                    if (!isContinuation) isProcessing = false;
                }
            }
            
            function initializeStoryTTS(fullText, isContinuation = false) {
                handleStoryTtsStop();
                
                let textToProcess = fullText;
                let startIndex = 0;

                if (isContinuation && !restartOnContinuationToggle.checked) {
                    textToProcess = fullText.substring(storyTextBeforeContinuation.length);
                    startIndex = storyUtteranceQueue.length;
                    const tempLoader = document.getElementById('temp-loader');
                    if (tempLoader) tempLoader.remove();
                } else {
                    storySentenceSpans = [];
                    storyUtteranceQueue = [];
                    currentUtteranceIndex = 0;
                    storyResultArea.innerHTML = '';
                }

                // Dynamic TTS check: Hide controls if browser lacks voice support
                let ttsCapable = false;
                if (window.speechSynthesis) {
                    const availableVoices = window.speechSynthesis.getVoices();
                    if (availableVoices.length > 0 || voicesLoaded) {
                        ttsCapable = true;
                    }
                }

                if (!ttsCapable) {
                    storyTtsControls.classList.add('hidden');
                    storyTtsSettings.classList.add('hidden');
                }

                if (startIndex === 0) {
                     const sentencesForRedraw = fullText.match(/[^.!?\n]+[.!?\n]*|\n+/g) || [];
                     sentencesForRedraw.forEach(sentenceText => {
                        const span = document.createElement('span');
                        span.textContent = sentenceText;
                        span.className = 'story-sentence';
                        storySentenceSpans.push(span);
                        storyResultArea.appendChild(span);
                    });
                }
                
                const newSentences = textToProcess.match(/[^.!?\n]+[.!?\n]*|\n+/g) || [];
                if (newSentences.length === 0 && startIndex === 0) return;

                newSentences.forEach((sentenceText) => {
                    if (startIndex > 0) {
                        const span = document.createElement('span');
                        span.textContent = sentenceText;
                        span.className = 'story-sentence';
                        storySentenceSpans.push(span);
                        storyResultArea.appendChild(span);
                    }
                    
                    if (/\w/.test(sentenceText) && ttsCapable) { 
                        const utterance = new SpeechSynthesisUtterance(sentenceText);
                        utterance.rate = currentTtsRate;
                        
                        const utteranceIndex = storyUtteranceQueue.length; 
                        
                        utterance.onstart = () => {
                            currentUtteranceIndex = utteranceIndex;
                            storySentenceSpans[utteranceIndex].classList.add('highlighted-sentence');
                            storySentenceSpans[utteranceIndex].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        };
                        utterance.onend = () => {
                            storySentenceSpans[utteranceIndex].classList.remove('highlighted-sentence');
                        };
                        storyUtteranceQueue.push(utterance);
                    }
                });

                if (storyUtteranceQueue.length > 0 && ttsCapable) {
                    const lastUtterance = storyUtteranceQueue[storyUtteranceQueue.length - 1];
                    lastUtterance.addEventListener('end', () => {
                        if (isLooping) {
                            setTimeout(() => {
                                handleStoryTtsStop();
                                handleStoryTtsPlayPause();
                            }, 500); 
                        } else {
                            storyTtsPlayPauseBtn.innerHTML = '▶️';
                            currentUtteranceIndex = 0;
                        }
                    });
                    
                    storyTtsControls.classList.remove('hidden');
                    storyTtsSettings.classList.remove('hidden');
                    storyTtsPlayPauseBtn.innerHTML = '▶️';
                }
                
                if (isContinuation && !restartOnContinuationToggle.checked && ttsCapable) {
                    handleStoryTtsPlayPause(startIndex);
                }
            }

            function handleStoryTtsPlayPause(startIndex = 0) {
                if (!window.speechSynthesis) return;
                if (storyUtteranceQueue.length === 0) return;

                if (window.speechSynthesis.speaking && !window.speechSynthesis.paused) {
                    window.speechSynthesis.pause();
                    storyTtsPlayPauseBtn.innerHTML = '▶️';
                } else if (window.speechSynthesis.paused) {
                    window.speechSynthesis.resume();
                    storyTtsPlayPauseBtn.innerHTML = '⏸️';
                } else {
                    const playFrom = Math.max(currentUtteranceIndex, startIndex);
                    for (let i = playFrom; i < storyUtteranceQueue.length; i++) {
                        window.speechSynthesis.speak(storyUtteranceQueue[i]);
                    }
                    storyTtsPlayPauseBtn.innerHTML = '⏸️';
                }
            }
            
            async function handleSubmitPost(title, content, keywords) { const submitBtn = document.getElementById('final-submit-btn'); const submitModal = bootstrap.Modal.getInstance(document.getElementById('qrsg-submit-modal')); if (!submitBtn) return; const originalText = submitBtn.textContent; submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting...'; let finalContent = ""; if(includeHistoryToggle.checked) { finalContent = '<h3>Full Story History</h3>'; storyHistory.forEach((entry, index) => { finalContent += `<h4>Part ${index + 1}</h4>`; finalContent += `<p><strong>Prompt (${entry.type}):</strong><br><em>${entry.prompt.replace(/\n/g, '<br>')}</em></p>`; finalContent += `<p><strong>Result:</strong></p><div>${entry.result.replace(/\n/g, '<br>')}</div><hr>`; }); finalContent += '<h3>Final Compiled Story</h3>'; } finalContent += `<p>${content.replace(/\n/g, '<br>')}</p>`; const formData = new FormData(); formData.append('action', 'qrsg_submit_story'); formData.append('nonce', wp_ajax_obj.nonce); formData.append('title', title); formData.append('content', finalContent); formData.append('keywords', keywords.join(',')); try { const response = await fetch(wp_ajax_obj.ajax_url, { method: 'POST', body: formData }); const result = await response.json(); if (!result.success) throw new Error(result.data || 'Unknown error.'); submitBtn.textContent = 'Submission Successful!'; submitBtn.classList.remove('btn-primary'); submitBtn.classList.add('btn-success'); } catch (error) { submitBtn.textContent = 'Submission Failed!'; submitBtn.classList.remove('btn-primary'); submitBtn.classList.add('btn-danger'); alert(`Submission Failed: ${error.message}`); } finally { setTimeout(() => { if (submitModal) submitModal.hide(); submitBtn.textContent = originalText; submitBtn.classList.remove('btn-success', 'btn-danger'); submitBtn.classList.add('btn-primary'); submitBtn.disabled = false; }, 2000); } }
            
            function resetApp() { 
                if (isProcessing) return; 
                isProcessing = false; 
                handleStoryTtsStop(); 
                stopScan(); 
                resultContainer.classList.add('hidden'); 
                startScanBtn.classList.remove('hidden'); 
                storyResultArea.innerHTML = ''; 
                storyTtsControls.classList.add('hidden'); 
                storyTtsSettings.classList.add('hidden'); 
                storyInputArea.classList.add('hidden'); 
                actionButtonsArea.classList.add('hidden'); 
                postGenerationActions.classList.add('hidden');
                getSuggestionsBtn.disabled = true; 
                lastGeneratedStoryContent = ''; 
                lastGeneratedSuggestions = null; 
                storyHistory = []; 
                lastGeneratedRedirectUrl = ''; 
                currentBlueprintBaseUrl = ''; 
            }
            
            function handlePlantSeed(textToAnalyze) { if (!textToAnalyze) return; currentBlueprintBaseUrl = lastGeneratedRedirectUrl || defaultBlueprintUrl; if (!currentBlueprintBaseUrl) { alert("Cannot generate QR Code: No default or specific blueprint is configured in the plugin settings."); return; } const topWords = getTop10Words(textToAnalyze); lastGeneratedKeywords = topWords; topWordsDisplay.textContent = topWords.join(' '); const encodedKeywords = topWords.map(w => encodeURIComponent(w)).join(','); const separator = currentBlueprintBaseUrl.includes('?') ? '&' : '?'; const qrCodeText = `${currentBlueprintBaseUrl}${separator}keywords=${encodedKeywords}`; qrcodeContainer.innerHTML = ""; new QRCode(qrcodeContainer, { text: qrCodeText, width: 200, height: 200, colorDark: "#000000", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H }); }
            function handleBlueprintLink(event) { event.preventDefault(); if (!currentBlueprintBaseUrl) { alert("Cannot launch blueprint. Base URL not found. Try generating a 'Plant a Seed' QR code first."); return; } const format = event.target.dataset.format; const topWords = lastGeneratedKeywords; if (topWords.length === 0) return; let keywordsString; switch (format) { case 'runes': keywordsString = topWords.map(word => encodeURIComponent(transliterateToFuthark(word))).join(','); break; case 'combined': keywordsString = topWords.map(word => `${encodeURIComponent(word)}:${encodeURIComponent(transliterateToFuthark(word))}`).join(','); break; default: keywordsString = topWords.map(word => encodeURIComponent(word)).join(','); break; } const separator = currentBlueprintBaseUrl.includes('?') ? '&' : '?'; const url = `${currentBlueprintBaseUrl}${separator}keywords=${keywordsString}`; qrcodeContainer.innerHTML = ''; new QRCode(qrcodeContainer, { text: url, width: 200, height: 200, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.H }); window.open(url, '_blank'); }
            async function handleStartScanClick() { if (isProcessing) return; startScanBtn.disabled = true; startScanBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Starting Camera...`; try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { exact: "environment" } } }); } catch (err) { try { stream = await navigator.mediaDevices.getUserMedia({ video: true }); } catch (fallbackErr) { document.getElementById('app-instructions').textContent = "Camera permission denied or no camera found. Please grant access in your browser's site settings and try again."; startScanBtn.disabled = false; startScanBtn.innerHTML = 'Start Scan'; return; } } video.srcObject = stream; video.muted = true; video.setAttribute('playsinline', true); try { await video.play(); videoContainer.classList.remove('hidden'); startScanBtn.classList.add('hidden'); document.getElementById('app-instructions').textContent = 'Point the camera at a QR Code...'; requestAnimationFrame(tick); } catch (playErr) { alert("Camera was found, but could not be played. Please check browser policies."); stopScan(); } }
            function stopScan() { if (stream) { stream.getTracks().forEach(track => track.stop()); stream = null; } videoContainer.classList.add('hidden'); startScanBtn.classList.remove('hidden'); startScanBtn.disabled = false; startScanBtn.innerHTML = 'Start Scan'; document.getElementById('app-instructions').textContent = 'Click "Start Scan" to activate your camera.';}
            function tick() { if (isProcessing) return; if (video.readyState === video.HAVE_ENOUGH_DATA) { canvasElement.height = video.videoHeight; canvasElement.width = video.videoWidth; canvas.drawImage(video, 0, 0, canvasElement.width, canvasElement.height); const imageData = canvas.getImageData(0, 0, canvasElement.width, canvasElement.height); const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' }); if (code && code.data.trim()) { isProcessing = true; if (navigator.vibrate) { navigator.vibrate(200); } stopScan(); document.getElementById('app-instructions').textContent = 'QR Code detected! Processing...'; handleQRCodeData(code.data); } } if (!isProcessing) { requestAnimationFrame(tick); } }
            function handleQRCodeData(qrData) { 
                document.getElementById('app-instructions').classList.add('hidden'); 
                resultContainer.classList.remove('hidden'); 
                startScanBtn.classList.add('hidden'); 
                resultTitle.textContent = "Story Generated"; 
                resultInstructions.textContent = `Based on: "${qrData}"`; 
                generateStory(qrData); 
            }
            function handleContinueStory() { const userPrompt = userPromptInput.value.trim(); if (!lastGeneratedStoryContent || !userPrompt) { return alert('Please write something to continue the story.'); } generateStory(null, true, userPrompt); }
            async function generateSuggestions() { const formData = new FormData(); formData.append('action', 'qrsg_generate_content'); formData.append('nonce', wp_ajax_obj.nonce); formData.append('request_type', 'suggestion'); formData.append('story_text', lastGeneratedStoryContent); try { const response = await fetch(wp_ajax_obj.ajax_url, { method: 'POST', body: formData }); const result = await response.json(); if (!result.success) throw new Error(result.data); let suggestionsText = result.data.story; const jsonMatch = suggestionsText.match(/\{[\s\S]*\}/); if (!jsonMatch) throw new Error("AI response was not valid JSON."); lastGeneratedSuggestions = JSON.parse(jsonMatch[0]); } catch (error) { console.error('Suggestion fetch failed:', error); lastGeneratedSuggestions = { error: `Failed to load suggestions: ${error.message}` }; } }
            function displaySuggestionsInModal() { suggestionsModalBody.innerHTML = ''; const list = document.createElement('ul'); list.className = 'list-unstyled mb-0'; if (lastGeneratedSuggestions.error) { list.innerHTML = `<li class="text-danger">${lastGeneratedSuggestions.error}</li>`; } else { const items = [lastGeneratedSuggestions.question1, lastGeneratedSuggestions.question2, lastGeneratedSuggestions.statement]; items.forEach(text => { if (text) { const li = document.createElement('li'); li.className = 'py-3 d-flex justify-content-between align-items-center'; li.innerHTML = `<span class="me-3">${text}</span><button class="btn btn-sm btn-outline-primary use-suggestion-btn flex-shrink-0 rounded-circle" style="width:32px;height:32px;" title="Use" data-bs-dismiss="modal"><i class="fas fa-plus" style="pointer-events:none;"></i></button>`; li.querySelector('.use-suggestion-btn').dataset.suggestion = text; list.appendChild(li); } }); } suggestionsModalBody.appendChild(list); }
            function getTop10Words(text) { const words = text.toLowerCase().match(/\b\w+\b/g) || []; const wordCounts = {}; words.forEach(word => { if (word.length > 2 && !stopWords.has(word)) { wordCounts[word] = (wordCounts[word] || 0) + 1; } }); return Object.keys(wordCounts).sort((a, b) => wordCounts[b] - wordCounts[a]).slice(0, 10); }

            function handleStoryTtsStop() { 
                if (!window.speechSynthesis) return; 
                
                if (window.speechSynthesis.speaking || window.speechSynthesis.paused) { 
                    const currentSpan = storySentenceSpans[currentUtteranceIndex]; 
                    if (currentSpan) { 
                        currentSpan.classList.remove('highlighted-sentence'); 
                    } 
                    window.speechSynthesis.cancel(); 
                } 
                storyTtsPlayPauseBtn.innerHTML = '▶️'; 
                currentUtteranceIndex = 0; 
            }

            // ########### EVENT LISTENERS ###########
            startScanBtn.addEventListener('click', handleStartScanClick);
            scanAnotherBtn.addEventListener('click', resetApp);
            continueStoryBtn.addEventListener('click', handleContinueStory);
            finalSubmitBtn.addEventListener('click', () => handleSubmitPost(lastGeneratedStoryTitle, lastGeneratedStoryContent, lastGeneratedKeywords));
            plantSeedBtn.addEventListener('click', () => handlePlantSeed(lastGeneratedStoryContent));
            storyTtsPlayPauseBtn.addEventListener('click', () => handleStoryTtsPlayPause());
            storyTtsStopBtn.addEventListener('click', handleStoryTtsStop);
            storyTtsRewindBtn.addEventListener('click', () => { handleStoryTtsStop(); setTimeout(() => handleStoryTtsPlayPause(), 100); });
            storyTtsLoopBtn.addEventListener('click', () => {
                isLooping = !isLooping;
                storyTtsLoopBtn.style.color = isLooping ? 'var(--qrsg-primary)' : 'inherit';
                storyTtsLoopBtn.style.backgroundColor = isLooping ? 'rgba(0, 242, 255, 0.2)' : '';
            });
            storyTtsRateSelect.addEventListener('change', (event) => { 
                currentTtsRate = parseFloat(event.target.value); 
                storyUtteranceQueue.forEach(utterance => utterance.rate = currentTtsRate); 
                if (window.speechSynthesis && (window.speechSynthesis.speaking || window.speechSynthesis.paused)) { 
                    handleStoryTtsStop(); 
                    setTimeout(() => handleStoryTtsPlayPause(), 100); 
                } 
            });
            
            document.querySelectorAll('#quick-actions-menu .quick-action-item').forEach(item => {
                item.addEventListener('click', (event) => {
                    event.preventDefault();
                    userPromptInput.value = item.textContent;
                    handleContinueStory();
                });
            });
            
            readerModalEl.addEventListener('hidden.bs.modal', function () {
                handleStoryTtsStop();
            });
            
            document.getElementById('qrsg-suggestions-modal').addEventListener('show.bs.modal', async function() {
                if (!lastGeneratedSuggestions) {
                    suggestionsModalBody.innerHTML = '<div class="loader"></div>';
                    await generateSuggestions();
                }
                displaySuggestionsInModal();
            });
            suggestionsModalBody.addEventListener('click', e => { if(e.target.classList.contains('use-suggestion-btn')) { userPromptInput.value = e.target.dataset.suggestion; } });

            document.querySelectorAll('#qrsg-qr-modal .dropdown-item').forEach(item => { item.addEventListener('click', handleBlueprintLink); });
        });
    </script>
    <?php
    return ob_get_clean();
}

// #################### 3. AJAX HANDLERS ####################

add_action('wp_ajax_qrsg_generate_content', 'qrsg_handle_gemini_request');
add_action('wp_ajax_nopriv_qrsg_generate_content', 'qrsg_handle_gemini_request');
function qrsg_handle_gemini_request() {
    if (!check_ajax_referer('qrsg_ajax_nonce', 'nonce', false)) {
        wp_send_json_error('Security check failed.', 403);
    }
    
    // Check for WordPress 7.0 Native AI Client capability.
    if (!function_exists('wp_ai_client_prompt')) {
        wp_send_json_error('WordPress 7.0 Native AI Client is required to generate stories.', 500);
    }
    
    $options = get_option('qrsg_settings');
    $request_type = isset($_POST['request_type']) ? sanitize_text_field(wp_unslash($_POST['request_type'])) : 'story';
    $final_prompt = '';
    $redirect_url = ''; 
    
    switch ($request_type) {
        case 'story':
            $qr_data = isset($_POST['qr_data']) ? sanitize_text_field(wp_unslash($_POST['qr_data'])) : '';
            if (empty($qr_data)) wp_send_json_error('QR Data is missing.', 400);
            $custom_prompts = isset($options['custom_prompts']) ? $options['custom_prompts'] : [];
            $prompt_found = false;
            
            if (is_array($custom_prompts)) {
                foreach ($custom_prompts as $p) {
                    if (isset($p['qr_data']) && !empty($p['qr_data']) && strtolower(trim($p['qr_data'])) === strtolower(trim($qr_data))) {
                        $final_prompt = str_replace('%%QR_DATA%%', esc_html($qr_data), $p['prompt']);
                        if (!empty($p['blueprint_id'])) {
                            $permalink = get_permalink(absint($p['blueprint_id']));
                            if($permalink) {
                                $redirect_url = $permalink;
                            }
                        }
                        $prompt_found = true;
                        break;
                    }
                }
            }
            if (!$prompt_found) $final_prompt = "Create a short story (around 250 words) based on the following prompt: \"{$qr_data}\". Please format the story with multiple paragraphs for readability.";
            break;
            
        case 'suggestion':
            $story_text = isset($_POST['story_text']) ? sanitize_textarea_field(wp_unslash($_POST['story_text'])) : '';
            if (empty($story_text)) wp_send_json_error('Story text is missing for suggestions.', 400);
            $final_prompt = "Based on the following story, provide two engaging questions and one declarative statement that could be used as a prompt to continue the story. Format the output as a single, valid JSON object with three keys: \"question1\", \"question2\", and \"statement\". Do not include any other text, comments, or markdown formatting. The JSON object should be the only thing in your response. Story: \"{$story_text}\"";
            break;
            
        case 'continuation':
            $story_text = isset($_POST['story_text']) ? sanitize_textarea_field(wp_unslash($_POST['story_text'])) : '';
            $user_prompt = isset($_POST['user_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['user_prompt'])) : '';
            if (empty($story_text) || empty($user_prompt)) wp_send_json_error('Missing data for story continuation.', 400);
            $final_prompt = "Here is the story so far:\n\"{$story_text}\"\n\nWrite a 1-2 paragraph continuation based on this suggestion: \"{$user_prompt}\".\n\nCRITICAL RULE: Return ONLY the new continuation text. Do NOT repeat, summarize, or include any of the existing story text provided above.";
            break;
    }
    
    if (empty($final_prompt)) {
        wp_send_json_error('Could not determine a prompt.', 400);
    }
    
    // Construct the builder for WordPress 7.0 Native AI Client
    $builder = wp_ai_client_prompt($final_prompt);
    
    // Explicitly enforce valid JSON generation for our suggestions hook.
    if ($request_type === 'suggestion') {
        $builder->as_json_response();
    }
    
    $result = $builder->generate_text();
    
    if (is_wp_error($result)) {
        wp_send_json_error('API Error: ' . esc_html($result->get_error_message()), 500);
    } 
    
    wp_send_json_success(['story' => $result, 'redirect_url' => $redirect_url]);
}

add_action('wp_ajax_qrsg_submit_story', 'qrsg_submit_story_for_review');
function qrsg_submit_story_for_review() {
    if (!check_ajax_referer('qrsg_ajax_nonce', 'nonce', false)) wp_send_json_error('Security check failed.', 403);
    
    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in to submit a story.', 401);
    }

    if (!current_user_can('edit_posts')) wp_send_json_error('You do not have permission to submit posts.', 401);
    
    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Generated Story';
    $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
    $keywords = isset($_POST['keywords']) ? sanitize_text_field(wp_unslash($_POST['keywords'])) : '';
    
    if (empty($content)) wp_send_json_error('Content is required.', 400);

    $post_data = [
        'post_title'   => $title, 
        'post_content' => $content, 
        'post_status'  => 'draft', 
        'post_author'  => get_current_user_id(),
        'post_type'    => 'qr_story',
    ];

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        wp_send_json_error($post_id->get_error_message(), 500);
    } else {
        if (!empty($keywords)) wp_set_post_tags($post_id, explode(',', $keywords), false);
        wp_send_json_success(['message' => 'Post submitted successfully!']);
    }
}
