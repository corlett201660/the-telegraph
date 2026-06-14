=== QR Story Generator ===
Contributors: ai_assistant
Tags: ai, qr code, story generator, gemini, shortcode
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 9.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A plugin that uses a device camera to scan QR codes and generate stories using AI. Integrates with WordPress 7.0 Native AI Connectors.

== Description ==

**QR Story Generator** (formerly The Telegraph) transforms simple QR code scans into unique, interactive AI-generated stories. 

By leveraging the new WordPress 7.0 Native AI Client, this plugin directly interfaces with your configured AI providers to decode QR payloads and instantly write customized narratives. Users can read the generated stories, use text-to-speech (TTS) playback, and even prompt the AI to continue the story with new twists and suggestions.

### Key Features
* **Native WP 7.0 AI Integration:** No need to manage separate API keys. The plugin hooks directly into your site's native AI Connectors.
* **Custom QR Prompts:** Map specific QR code URLs or text to highly customized generation blueprints.
* **Story Continuations:** Interactive reader mode allows users to branch out and continue stories dynamically.
* **Local Asset Delivery:** Fully compliant with WordPress repository guidelines. All required CSS and JavaScript libraries (Bootstrap, jsQR, QRCode.js, FontAwesome) are loaded locally from the plugin's root directory to ensure privacy and offline stability without relying on external CDNs.
* **Text-to-Speech:** Built-in Web Speech API integration highlights sentences as they are read aloud.

== Installation ==

1. Download and extract the plugin folder.
2. Ensure the core asset files (`bootstrap.min.css`, `bootstrap.bundle.min.js`, `all.min.css`, `jsQR.js`, and `qrcode.min.js`) are located directly in the plugin's **root directory** alongside `qr-story-generator.php`.
3. Upload the `qr-story-generator` folder to your `/wp-content/plugins/` directory.
4. Activate the plugin through the 'Plugins' menu in WordPress.
5. Navigate to **Settings > Connectors** in your WordPress dashboard to ensure your primary AI Provider is active.
6. Navigate to **QR Story Generator** in the admin sidebar to review the Setup & Tutorial tab.

== Frequently Asked Questions ==

### How do I display the scanner to my users?
Create a new page or post and insert the following shortcode:
`[qr_story_generator]`

### How do I display recently generated stories?
You can display a list of the most recent stories (which are saved as drafts for admin review) using this shortcode:
`[recent_qr_stories count="5"]`

### Why isn't the camera turning on?
The WebRTC camera API requires a secure context. Ensure your website is running over HTTPS (SSL). Additionally, verify that the `jsQR.js` file is successfully loading from the root directory of the plugin.

== Changelog ==

= 9.1 =
* **Rebrand:** Officially rebranded from "The Telegraph" to "QR Story Generator".
* **Architecture Update:** Integrated the new WordPress 7.0 `wp_ai_client_prompt()` native AI connector system.
* **Compliance Fix:** Removed all external CDN dependencies. All styles and scripts (Bootstrap, FontAwesome, jsQR, QRCode.js) are now bundled and loaded directly from the plugin's root directory.
* **UI/UX:** Added a native tabbed interface in the Admin Dashboard for Settings and a new Setup & Tutorial guide.
* **Bug Fix:** Implemented robust fallback initialization for modals to prevent fatal JavaScript halts if localized UI assets fail to load.
