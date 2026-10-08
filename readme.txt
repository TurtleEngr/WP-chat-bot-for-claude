=== Chat Bot For Claude ===
Contributors: TurtleEngr
Tags: chat, chatbot, ai, claude, anthropic
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: VERSION
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add a Claude AI chat bot to any page or post with the [claude_chat] shortcode.

== Description ==

The "Chat Bot For Claude" plugin lets you add a Claude AI chat
interface to your WordPress website. Configure it from the WordPress
admin panel and use a shortcode to embed the chat interface anywhere
on your site.

Features:

* Put the `[claude_chat]` shortcode on any page or post and the chat
  box will be ready for user questions.

* Choice of Claude model and max tokens.

* A "Prefix Prompt" is sent as the system prompt on every request.
  This is where the "personality," "goals," and "limits" are
  specified.  To save costs the system prompt is cached for an hour.
  
* "Follow Links" checkbox. If checked, Claude is allowed to fetch
  external web links named in the prompt or in the visitor's question.

* A list of pre-fetch URLs can be added to the system prompt (cached
  for one hour).

* To keep costs down, there are limits to internal query times, and
  the number of external links followed. (See Code Customization,
  Constants.)

* Per-IP rate limit of 10 requests per minute.

* The "log" of questions and answers is stored outside the web root so
  only the admin interface can view the log. The "View Log" and "Clear
  Logs" buttons are on the settings page.

== Installation ==

1. Install and activate this plugin.
2. Go to Settings > Claude Chat, enter your Anthropic API key and save.
3. After saving the API Key the "Model" pick list should show some
   model names. 
3. Add the `[claude_chat]` shortcode to a page or post.
4. On the page, let the user's know their questions and answers are
   being logged, and how often the log is cleared.

== Frequently Asked Questions ==

= Where do I get the API key? =

Create an account at https://console.anthropic.com/ and generate an
API key there. API usage is billed by Anthropic to that account.

= How do I display the chat interface? =

Put the shortcode `[claude_chat]` on any page or post.

= Where can I find more help? =

See https://github.com/TurtleEngr/WP-chat-bot-for-claude for more
set-up documentation. For example, what do you put in the "Prefix
Prompt"?

= I have privacy concerns =

Every user question and Claude's answer are saved to `claude_log.org`,
in the directory one level above the WordPress root, so the file
cannot be read with a web browser.  Administrators can open it with
the "View Log" button on the settings page, and empty it with the
"Clear Logs" button. The admin should tell users how long they keep
the logs.

User messages might be written to the PHP error log.

A user's IP address is used only as an MD5-hashed key for the
one-minute rate limit. It is not logged or sent to Anthropic.

See "External Service" for more details.

== External Service ==

This plugin connects to the Anthropic Claude API to generate chat
replies. It does nothing until an administrator enters an Anthropic
API key on the settings page.

* Service: Anthropic Messages API, https://api.anthropic.com/v1/messages
* When: each time a user sends a message from the chat box.
* Data sent: the user's message, the Prefix Prompt, any pre-fetched
  page text, the selected model and settings, and the site's API key.
  The user's IP address is not sent.
* Also: each time an administrator opens the settings page, the
  plugin sends the API key to https://api.anthropic.com/v1/models to
  get the list of models that key can use. No user data is sent.
* Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy

When "Follow Links" is checked, or when pre-fetch URLs are entered,
the site's server also makes HTTP GET requests to those web pages
(only public http/https addresses; private and local addresses are
blocked). Only the URL is sent; no user data is included. The terms
and privacy policy of each site you list apply.

== Screenshots ==

1. Admin settings page for Chat Bot For Claude.
2. Chat interface displayed on a WordPress page.

== Changelog ==

= 2.5 =
* Changes for WordPress.org plugin directory guidelines: GPL license,
  text domain, settings sanitizing, input sanitizing, output escaping.
* Cleaned up build process.
* The Model list is read from the Anthropic Models API.
* The chat log moved to `chat-bot-for-claude-log/claude_log.org`,
  above the WordPress root, so it is not publicly readable.
* Added a "View Log" button. "Clear Logs" only clears `claude_log.org`.
* Errors are written to the PHP error log.

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
  Easy Onboarding' is disabled and I'll consider replacing
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
* License changed to GPLv2 or later. Chat log moved out of the web root.
* Delete the old public logs in wp-content/uploads/claude/ and choose a
  new model; the old models are retired.
