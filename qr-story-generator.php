<?php
/**
 * Plugin Name:       QR Story Generator
 * Description:       A plugin that uses a device camera to scan QR codes and generate stories using AI. Integrates with WordPress 7.0 Native AI Connectors.
 * Version:           9.2
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

// Enqueue Admin Scripts for the repeater field
add_action('admin_enqueue_scripts', 'qrsg_admin_scripts');
function qrsg_admin_scripts($hook) {
    if ($hook != 'toplevel_page_qr_story_generator') {
        return;
    }
    wp_enqueue_script('qrsg-admin-js', plugin_dir_url(__FILE__) . 'qrsg-admin.js', ['jquery'], '1.0', true);
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
    <?php
}

function qrsg_settings_page_html() {
    if (!current_user_can('manage_options')) return;

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
                <p>By default, scanning any QR code will prompt the AI to write a generic story based on the code's raw text. However, you can map exact QR codes to highly specific prompts in the <strong>Settings</strong> tab.</p>
                <ul>
                    <li><strong>QR Code Data:</strong> Enter the exact string the QR code contains (e.g., <code>https://my-site.com/treasure-1</code>).</li>
                    <li><strong>Custom Prompt:</strong> Provide specific instructions to the AI.</li>
                    <li>Use the <code>%%QR_DATA%%</code> variable in your prompt to dynamically inject the scanned text.</li>
                </ul>
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
        'all_items'             => __('All QR Stories', 'qr-story-generator'),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'rewrite'            => ['slug' => 'qr-stories'],
        'capability_type'    => 'post',
        'has_archive'        => true,
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
    wp_enqueue_style('qrsg-bootstrap-css', plugin_dir_url(__FILE__) . 'bootstrap.min.css', [], '5.3.3');
    wp_enqueue_style('qrsg-fontawesome-css', plugin_dir_url(__FILE__) . 'all.min.css', [], '6.4.0');
    wp_enqueue_style('qrsg-app-style', plugin_dir_url(__FILE__) . 'qrsg-app.css', [], '1.0.0');

    wp_enqueue_script('qrsg-bootstrap-js', plugin_dir_url(__FILE__) . 'bootstrap.bundle.min.js', [], '5.3.3', true);
    wp_enqueue_script('qrsg-jsqr', plugin_dir_url(__FILE__) . 'jsQR.js', [], '1.4.0', true);
    wp_enqueue_script('qrsg-qrcode', plugin_dir_url(__FILE__) . 'qrcode.min.js', [], '1.0.0', true);
    wp_enqueue_script('qrsg-app-script', plugin_dir_url(__FILE__) . 'qrsg-app.js', [], '1.0.0', true);
    
    if (!is_user_logged_in()) {
        ob_start();
        ?>
        <div id="qrsg-login-screen" class="d-flex align-items-center justify-content-center" style="min-height: 70vh; background-color: transparent; padding: 1rem;">
            <div class="card shadow-lg border-0" style="max-width: 420px; width: 100%; border-radius: 15px; overflow: hidden; background: var(--qrsg-bg); backdrop-filter: blur(15px); border: 1px solid var(--qrsg-border) !important; color: var(--qrsg-text); transition: all 0.4s ease;">
                <div class="card-body p-4 p-md-5 text-center">
                    <div class="mb-4">
                        <i class="fas fa-camera-retro fa-3x" style="color: var(--qrsg-primary);"></i>
                    </div>
                    <h3 class="mb-2 fw-bold">Welcome Back</h3>
                    <p class="small mb-4" style="color: var(--qrsg-text-muted);">Please log in to keep your session active and submit stories.</p>
                    
                    <form name="qrsg-login-form" id="qrsg-login-form" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" method="post">
                        <div class="form-floating mb-3 text-start">
                            <input type="text" name="log" id="user_login" class="form-control" placeholder="Username" style="background: rgba(128,128,128,0.1); border-color: var(--qrsg-border); color: var(--qrsg-text);" required>
                            <label for="user_login" style="color: var(--qrsg-text-muted);">Username or Email</label>
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
                        <button type="submit" name="wp-submit" id="wp-submit" class="btn w-100 py-3 fw-bold" style="background-color: var(--qrsg-primary); color: #fff; border: none; border-radius: 8px;">Log In</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    $options = get_option('qrsg_settings');
    $default_blueprint_id = !empty($options['default_blueprint_id']) ? $options['default_blueprint_id'] : 0;
    $default_blueprint_url = $default_blueprint_id ? get_permalink($default_blueprint_id) : '';

    // Pass PHP variables to our external JS file safely
    wp_localize_script('qrsg-app-script', 'qrsgData', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('qrsg_ajax_nonce'),
        'default_blueprint_url' => $default_blueprint_url
    ]);

    ob_start();
    ?>
    <div id="app-container">
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

    <div class="modal fade" id="qrsg-reader-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header shadow-sm" style="z-index: 10;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-book-reader me-2"></i>Story Log</h5>
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
                            <textarea id="user-prompt" rows="3" placeholder="e.g., 'The hero discovers a hidden trapdoor...'"></textarea>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button id="continue-story-btn" class="btn btn-success flex-grow-1 py-2 fw-bold">Write Next Part</button>
                                <div class="dropdown flex-grow-1">
                                    <button class="btn btn-outline-primary dropdown-toggle w-100 py-2" type="button" data-bs-toggle="dropdown"><i class="fas fa-bolt"></i> Quick Actions</button>
                                    <ul class="dropdown-menu dropdown-menu-end" id="quick-actions-menu">
                                        <li><a class="dropdown-item quick-action-item" href="#">Continue what happens next</a></li>
                                        <li><a class="dropdown-item quick-action-item" href="#">Rephrase the last part</a></li>
                                        <li><a class="dropdown-item quick-action-item" href="#">Describe the surroundings</a></li>
                                        <li><a class="dropdown-item quick-action-item" href="#">Introduce a surprising twist</a></li>
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
    
    <div class="modal fade" id="qrsg-suggestions-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">AI Suggestions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                        <button class="btn btn-info dropdown-toggle text-white" type="button" data-bs-toggle="dropdown">Launch as a Blueprint</button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-format="english">English</a></li>
                            <li><a class="dropdown-item" href="#" data-format="runes">Runes</a></li>
                            <li><a class="dropdown-item" href="#" data-format="combined">Combined</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="qrsg-submit-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit Story for Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <p>By submitting, you are saving this story as a draft post.</p>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="include-history-toggle">
                        <label class="form-check-label" for="include-history-toggle">Include full history</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="final-submit-btn" class="btn text-white" style="background-color: var(--qrsg-primary);">Submit</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="qrsg-info-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>About</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <p>Welcome! This application transforms a simple QR code scan into a unique, AI-generated story.</p>
                </div>
            </div>
        </div>
    </div>
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
            if (!$prompt_found) $final_prompt = "Create a short story based on the following prompt: \"{$qr_data}\".";
            
            // ==========================================
            // JERRY: The Runic Timestamp Mechanism
            // ==========================================
            $timestamp = (string) time(); 
            
            $rune_cipher = [
                '1' => 'Fehu (Essence: Wealth, beginnings, dynamic power, creation)',
                '2' => 'Uruz (Essence: Raw strength, endurance, survival, untamed potential)',
                '3' => 'Thurisaz (Essence: Conflict, reactive force, defense, breaking barriers)',
                '4' => 'Ansuz (Essence: Wisdom, communication, divine inspiration)',
                '5' => 'Raidho (Essence: The journey, rhythm, movement, natural cycles)',
                '6' => 'Kenaz (Essence: Knowledge, illumination, the controlled fire)',
                '7' => 'Gebo (Essence: Gifts, generosity, exchange, partnerships)',
                '8' => 'Wunjo (Essence: Joy, harmony, fellowship, realization of wishes)',
                '9' => 'Hagalaz (Essence: Disruption, radical change, uncontrollable forces)',
                '0' => 'Jera (Essence: The harvest, patience, turning of the wheel, rewards)'
            ];

            $jerry_instructions = "\n\n--- JERRY'S TEMPORAL RUNIC DIRECTIVE ---\n";
            $jerry_instructions .= "The universal timestamp for this generation is {$timestamp}. You must structure your story into exactly " . strlen($timestamp) . " paragraphs.\n";
            $jerry_instructions .= "You must apply a runic substitution cipher to the timestamp, imbuing each paragraph with the thematic essence of its corresponding rune in exact order:\n";
            
            $timestamp_digits = str_split($timestamp);
            foreach ($timestamp_digits as $index => $digit) {
                $para_num = $index + 1;
                $jerry_instructions .= "- Paragraph {$para_num} corresponds to the digit {$digit}: Imbue this paragraph heavily with the essence of {$rune_cipher[$digit]}.\n";
            }
            
            $jerry_instructions .= "\nDo not explicitly name the runes or numbers in the text; weave their conceptual themes naturally into the narrative progression.";
            
            $final_prompt .= $jerry_instructions;
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
    
    if (empty($final_prompt)) wp_send_json_error('Could not determine a prompt.', 400);
    
    $builder = wp_ai_client_prompt($final_prompt);
    
    // Explicitly request Gemini 3.1 Flash Lite
    if (method_exists($builder, 'set_model')) {
        $builder->set_model('gemini-3.1-flash-lite');
    } elseif (method_exists($builder, 'with_model')) {
        $builder->with_model('gemini-3.1-flash-lite');
    }
    
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
    
    if (!is_user_logged_in()) wp_send_json_error('You must be logged in to submit a story.', 401);
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
