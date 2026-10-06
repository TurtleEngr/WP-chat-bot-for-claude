=== Claude Chat bot ===
Contributors: aicodecraft, turtle-engr
Donate link: https://aicodecraft.io/donate
Tags: chat, chatbot, ai, claude, anthropic
Tested up to: 7.0
Stable tag: VERSION
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a Claude AI chat box to any page or post with the [claude_chat] shortcode.

== Description ==

The Claude Chat bot plugin lets you add a Claude AI chat interface to
your WordPress website. Configure it from the WordPress admin panel
and use a shortcode to embed the chat interface anywhere on your site.

Features:

* `[claude_chat]` shortcode to place the chat box on any page or post.
* Choice of Claude model, temperature, and max tokens.
* Optional Prefix Prompt, sent as the system prompt on every request.
* Optional "Follow Links": lets Claude fetch web pages named in the
  prompt or the visitor's question.
* Optional list of pre-fetch URLs whose text is added to the system
  prompt (cached for one hour).
* Per-IP rate limit of 10 requests per minute.
* Log of questions and answers, viewable and clearable from the
  settings page.

== External services ==

This plugin connects to the Anthropic Claude API to generate chat
replies. It does nothing until an administrator enters an Anthropic
API key on the settings page.

* Service: Anthropic Messages API, https://api.anthropic.com/v1/messages
* When: each time a visitor sends a message from the chat box.
* Data sent: the visitor's message, the Prefix Prompt, any pre-fetched
  page text, the selected model and settings, and the site's API key.
  The visitor's IP address is not sent.
* Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy

When "Follow Links" is checked, or when pre-fetch URLs are entered,
the site's server also makes HTTP GET requests to those web pages
(only public http/https addresses; private and local addresses are
blocked). Only the URL is sent; no visitor data is included. The
terms and privacy policy of each site you list apply.

== Privacy ==

Every visitor question and Claude's answer are saved to
`wp-content/uploads/claude/claude_log.org`. API errors are saved to
`wp-content/uploads/claude/claude.log`. Files in the uploads
directory may be readable from the web, so tell your visitors that
chats are logged, and use the "Clear Logs" button on the settings
page to delete the logs.

The visitor's IP address is used only as an MD5-hashed key for the
one-minute rate limit. It is not logged or sent to Anthropic.

== Installation ==

1. Upload the plugin zip with Plugins > Add New > Upload Plugin,
   or install it from the WordPress plugin directory.
2. Activate the plugin.
3. Go to Settings > Claude Chat and enter your Anthropic API key,
   then choose a model.
4. Add the `[claude_chat]` shortcode to a page or post.

== Frequently Asked Questions ==

= How do I display the chat interface? =

Put the shortcode `[claude_chat]` on any page or post.

= Where do I get the API key? =

Create an account at https://console.anthropic.com/ and generate an
API key there. API usage is billed by Anthropic to that account.

= Where can I find more help? =

See https://github.com/TurtleEngr/WP-claude-chat-bot

== Screenshots ==

1. Admin settings page for Claude Chat bot.
2. Chat interface displayed on a WordPress page.

== Changelog ==

= 2.5.0 =
* Changes for WordPress.org plugin directory guidelines: GPL license,
  text domain, settings sanitizing, input sanitizing, output escaping.
* Cleaned up build process.

= 2.3 =
* Put version number on pages.

= 2.2 =
* Added new feature: fetch-url, and pre-fetch-urls

= 1.7 =
* Removed the Additional Prompt feature. It did not work well and it
  clutters the code.
* Added memory limit protections. When the 'Hostinger Easy Onboarding'
  plugin is active with 'NextGEN Gallery' plugin, an out-of-memory
  error is thrown when saving in Claude Settings form.  'Hostinger
  Easy Onboarding' is now disabled and I'll consider replacing
  NextGen.

= 1.6 =
* Scroll the output up so it is visible.

= 1.5 =
* Log user questions and responses

= 1.4 =
* Output format fixes for mobile.

= 1.3 =
* Security fixes.

= 1.2 =
* Additional prefix prompt.

= 1.1 =
* Prefix prompt enhancement.

= 1.0 =
* Initial release of Claude Chat Interface. Please configure your API
  settings after installation.

== Upgrade Notice ==

= 2.5.0 =
License changed to GPLv2 or later. Settings values are now validated
when saved.
