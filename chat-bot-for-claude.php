<?php
/*
 * Plugin Name: Chat Bot For Claude
 * Plugin URI: https://github.com/TurtleEngr/WP-chat-bot-for-claude
 * Description: Add a Claude AI chat interface to your WordPress site using a shortcode.
 * Version: VERSION
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: TurtleEngr
 * Author URI: https://github.com/TurtleEngr
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/* Lock out script kiddies: die an direct call */
if (! defined('ABSPATH')) {
    exit;
}

/*
 * ========================================
 * Globals  Prefix: cb4c_gVariableName
 * Function Prefix: cb4c_fFunctionName
 * ========================================
 */


/*
 * ========================================
 * Globals
 * ========================================
 */
 
/*
 * (memory): Tunable limits to protect against pathological inputs and
 * responses. Raising these should only be necessary if a legitimate use case
 * is being clipped — in which case something is probably also wrong.
 *
 * Max body size (bytes) we will read from the Claude API. Well above any
 * legitimate response; an overage means something is wrong and we should
 * fail fast rather than consume all available PHP memory inside
 * wp-includes/Requests/src/Requests.php during response parsing.
 */
define('cb4c_gMaxResponseBytes', 4 * 1024 * 1024); /* 4 MB */

/* Max characters dumped into the PHP error log when an API error occurs. The raw
 * response body is *not* a useful debugging aid past the first few KB, and
 * we don't want a single bad response to fill the disk or hold a huge
 * string in memory.
 */
define('cb4c_gMaxLogDumpChars', 4096);

/* Max length (bytes) of the Prefix Prompt stored as an option. 64 KB is
 * roughly 10,000 words — more than a page. Anything larger almost
 * certainly means a mispaste.
 */
define('cb4c_gMaxPrefixPrompt', 65536);

/*
 * fetch_url tool limits.
 *
 * cb4c_gFetchTimeOut - Seconds allowed for a single URL fetch. On
 * overrun the fetch is abandoned and no content is returned for it.
 *                                
 * cb4c_gResponseBudget - Wall-clock seconds allowed for the
 * whole tool_use loop. Once exceeded, no further URLs are fetched and
 * we answer with whatever text we already have.
 *
 * cb4c_gRateLimit - The number of questions per minute for each
 * IP.
 *
 * CAVEAT: the budget is checked *between* steps.  An in-flight Claude
 * API call is not interrupted, so a slow API round trip can overrun
 * the budget (the API call timeout is still 60s). If that matters,
 * lower the wp_remote_post() timeout in cb4c_fApiSend().
 */
define('cb4c_gFetchTimeOut', 5);
define('cb4c_gResponseBudget', 20);
define('cb4c_gRateLimit', 10);

/* Max bytes read from a fetched page, and max characters of extracted text
 * handed back to Claude. The byte cap protects PHP memory; the character cap
 * protects the token budget — a single large page can otherwise crowd out the
 * Prefix Prompt and the user's actual question.
 */
define('cb4c_gMaxFetchBytes', 256 * 1024); /* 256 KB */
define('cb4c_gMaxFetchChars', 20000);

/* Max number of send/tool_result round trips. The response budget is the
 * primary stop condition; this is a backstop so a model that keeps asking for
 * cheap, fast fetches cannot loop indefinitely inside the budget.
 */
define('cb4c_gMaxToolRounds', 5);

/* Pre-fetch list limits. Content is cached in a transient for this many
 * seconds, keyed by a hash of the URL list.
 */
define('cb4c_gPreFetchTtl', 3600); /* 1 hour */
define('cb4c_gMaxPreFetchUrls', 10);

/* Chat log location. The directory is one level above the WordPress
 * root (ABSPATH), e.g. /home/user/chat-bot-for-claude-log when
 * WordPress is in /home/user/public_html, so the web server cannot
 * serve it. If WordPress is installed in a subdirectory of
 * public_html, change cb4c_gLogDir.
 */
define('cb4c_gLogDir', dirname(ABSPATH) . '/chat-bot-for-claude-log');
define('cb4c_gLogFile', 'claude_log.org');

/*
 * ========================================
 * Register settings
 * ========================================
 */
 
function cb4c_fRegisterSettings() {
    register_setting('claude_chat_options', 'claude_chat_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    register_setting('claude_chat_options', 'claude_chat_model', [
            'sanitize_callback' => 'cb4c_fSanitizeModel',
        ]);
    register_setting('claude_chat_options', 'claude_chat_max_tokens', [
            'sanitize_callback' => 'cb4c_fSanitizeMaxTokens',
        ]);
    register_setting('claude_chat_options', 'claude_chat_follow_links', [
            'sanitize_callback' => 'cb4c_fSanitizeFollowLinks',
        ]);
    register_setting('claude_chat_options', 'claude_chat_prefetch_urls', [
            'sanitize_callback' => 'cb4c_fSanitizePreFetchUrls',
        ]);
    /* FIX (memory): custom sanitize callback enforces a length cap so a
     * pathologically large paste cannot be written to wp_options.
     */
    register_setting('claude_chat_options', 'claude_chat_prefix_prompt', [
            'sanitize_callback' => 'cb4c_fSanitizePrefixPrompt',
        ]);
}
add_action('admin_init', 'cb4c_fRegisterSettings');

/*
 * Only accept a well-formed model id, e.g. claude-opus-5-5. The list
 * itself comes from the API (cb4c_fGetModels()), so it is not
 * fetched again on save.
 */
function cb4c_fSanitizeModel( $value ) {
    $value = sanitize_text_field( (string) $value );
    return preg_match( '/^claude-[a-z0-9.-]+$/', $value ) ? $value : '';
}

/*
 * Max Tokens: clamp to 1..8096 (the range shown on the settings page).
 */
function cb4c_fSanitizeMaxTokens( $value ) {
    return (string) min( 8096, max( 1, absint( $value ) ) );
}

/*
 * FIX (memory): Sanitize callback for the Prefix Prompt.
 * Runs sanitize_textarea_field first, then clamps the length to
 * cb4c_gMaxPrefixPrompt. If truncation occurs, a
 * settings-error notice is registered so the user sees what happened
 * on the settings screen.
 */
function cb4c_fSanitizePrefixPrompt( $value ) {
    $value = sanitize_textarea_field( $value );
    $len   = strlen( $value );
    if ( $len > cb4c_gMaxPrefixPrompt ) {
        $value = substr( $value, 0, cb4c_gMaxPrefixPrompt );
        add_settings_error(
            'claude_chat_prefix_prompt',
            'claude_chat_prefix_prompt_truncated',
            sprintf(
                /* translators: 1: submitted size, 2: allowed size */
                esc_html__( 'Prefix Prompt was %1$d bytes; truncated to the %2$d-byte limit.', 'chat-bot-for-claude' ),
                $len,
                cb4c_gMaxPrefixPrompt
            ),
            'warning'
        );
    }
    return $value;
}

/*
 * Normalise the Follow Links checkbox to '1' or ''.
 *
 * The settings form posts a hidden '0' before the checkbox (see
 * cb4c_fCheckboxFieldCallback), so an unchecked box still submits a
 * value and the option is correctly cleared.
 */
function cb4c_fSanitizeFollowLinks( $value ) {
    return ( $value === '1' ) ? '1' : '';
}

/*
 * Validate the pre-fetch URL list: one URL per line.
 *
 * Invalid lines are dropped with a settings-error notice rather than silently
 * accepted, so a typo does not turn into a silent no-op at request time. The
 * list is capped at cb4c_gMaxPreFetchUrls entries.
 */
function cb4c_fSanitizePreFetchUrls( $value ) {
    $lines   = preg_split( '/\r\n|\r|\n/', (string) $value );
    $urls    = array();
    $skipped = 0;

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' ) {
            continue;
        }
        if ( count( $urls ) >= cb4c_gMaxPreFetchUrls ) {
            $skipped++;
            continue;
        }
        $url = esc_url_raw( $line );
        /* wp_http_validate_url() rejects non-http(s) schemes and blocks
           loopback / private / link-local addresses. */
        if ( $url === '' || ! wp_http_validate_url( $url ) ) {
            $skipped++;
            continue;
        }
        $urls[] = $url;
    }

    if ( $skipped > 0 ) {
        add_settings_error(
            'claude_chat_prefetch_urls',
            'claude_chat_prefetch_urls_skipped',
            sprintf(
                /* translators: 1: number skipped, 2: allowed maximum */
                esc_html__( '%1$d pre-fetch line(s) dropped: invalid, non-http(s), private address, or beyond the %2$d-URL limit.', 'chat-bot-for-claude' ),
                $skipped,
                cb4c_gMaxPreFetchUrls
            ),
            'warning'
        );
    }

    return implode( "\n", $urls );
}


/* Enqueue necessary scripts and styles */
function cb4c_fEnqueueScripts() {
    wp_enqueue_style('claude-chat-style', plugin_dir_url(__FILE__) . 'css/chat-bot-for-claude.css', array(), 'VERSION');
    wp_enqueue_script('claude-chat-script', plugin_dir_url(__FILE__) . 'js/chat-bot-for-claude.js', array('jquery'), 'VERSION', true);
    wp_localize_script('claude-chat-script', 'claudeChat', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('claude-chat-nonce'),
        ));
}
add_action('wp_enqueue_scripts', 'cb4c_fEnqueueScripts');

/* Shortcode to display the chat bot */
function cb4c_fShortCode() {
    ob_start();
?>
    <div id="chat-bot-for-claude">
        <div id="claude-chat-messages"></div>
        <textarea id="claude-chat-input" placeholder="Ask Claude something..." rows="3"></textarea>
        <button id="claude-chat-submit">Send</button> (Version: VERSION)
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('claude_chat', 'cb4c_fShortCode');

/*
 * Transient-based rate limiter — max 10 requests per minute per IP.
 * Returns true when the request is allowed, false when the limit is exceeded.
 */
function cb4c_fCheckRateLimit() {
    $ip            = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $transient_key = 'claude_chat_rate_' . md5($ip);
    $count         = get_transient($transient_key);

    if ($count === false) {
        /* First request in this window — start the counter with a
           60-second TTL. */
        set_transient($transient_key, 1, 60);
        return true;
    }

    if (intval($count) >= cb4c_gRateLimit) {
        return false; /* Rate limit exceeded. */
    }

    /* Increment without resetting the existing TTL by reusing the same key. */
    set_transient($transient_key, intval($count) + 1, 60);
    return true;
}

/* AJAX handler for chat requests */
function cb4c_fAjaxHandler() {
    check_ajax_referer('claude-chat-nonce', 'nonce');

    /* Enforce rate limit before doing any further work. */
    if ( ! cb4c_fCheckRateLimit() ) {
        wp_send_json_error('Rate limit exceeded. Please wait a moment before sending another message.');
        return;
    }

    /* Use sanitize_textarea_field so newlines in multi-line messages
       are preserved (sanitize_text_field strips them). */
    $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
    if ($message === '') {
        wp_send_json_error('Error: Empty message');
        return;
    }

    $response = cb4c_fApiRequest($message);
    if ($response) {
        /* The JS inserts the reply with .html(), so escape it here:
           allow normal post HTML, strip scripts and event handlers. */
        wp_send_json_success(wp_kses_post($response));
    } else {
        wp_send_json_error('Error: No response from API');
    }
}
add_action('wp_ajax_claude_chat',        'cb4c_fAjaxHandler');
add_action('wp_ajax_nopriv_claude_chat', 'cb4c_fAjaxHandler');

/*
 * ========================================
 * Logging helpers
 * ========================================
 */

/*
 * Returns the absolute filesystem path to the chat log file
 * (cb4c_gLogDir/cb4c_gLogFile), creating the directory if
 * it does not yet exist.
 *
 * @return string|false       Absolute path on success, false on failure.
 */
function cb4c_fGetLogPath() {
    $dir = cb4c_gLogDir;

    if ( ! is_dir( $dir ) ) {
        /* wp_mkdir_p() creates intermediate directories and returns
           false on failure. */
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }
    }

    return trailingslashit( $dir ) . cb4c_gLogFile;
}


/*
 * (memory): Truncate a value for safe inclusion in an error log.
 *
 * Non-strings are first rendered with wp_json_encode(); the result is then
 * clamped to $max_chars characters with a trailing marker noting the
 * original length. This prevents a large API error payload — e.g. an
 * HTML error page from a misrouted request — from being held in PHP
 * memory and then written to the PHP error log in full.
 *
 * @param mixed $value     The value to render.
 * @param int   $max_chars Maximum characters in the returned string.
 * @return string          Safe-to-log string, never longer than
 *                         $max_chars + a short truncation marker.
 */
function cb4c_fTruncateForLog( $value, $max_chars = cb4c_gMaxLogDumpChars ) {
    if ( ! is_string( $value ) ) {
        $value = (string) wp_json_encode( $value, JSON_PRETTY_PRINT );
    }
    $len = strlen( $value );
    if ( $len > $max_chars ) {
        $value = substr( $value, 0, $max_chars )
               . "\n... [truncated, {$len} bytes total]";
    }
    return $value;
}

/*
 * Appends a user-message / Claude-response entry to claude_log.org in
 * Org-mode format:
 *
 *   ** YYYY-MM-DD HH:MM message
 *   $message
 *   *** response
 *   $response
 *
 * @param string  $message  The sanitised user message sent to the API.
 * @param string  $response The text returned by the Claude API.
 */
function cb4c_fLogMessage( $message, $response ) {
    $path = cb4c_fGetLogPath();
    if ( $path === false ) {
        return; /* Could not resolve / create the directory — fail silently. */
    }

    $date = new DateTime('now', new DateTimeZone('America/Los_Angeles'));
    $timestamp = $date->format('Y-m-d H:i:s T');

    $entry  = "** {$timestamp} message\n";
    $entry .= $message . "\n";
    $entry .= "*** response\n";
    $entry .= $response . "\n\n";

    file_put_contents( $path, $entry, FILE_APPEND | LOCK_EX );
}


/*
 * Writes an error entry to the PHP error log (error_log()). Where that
 * goes is set by the server's PHP error_log setting, or by
 * WP_DEBUG_LOG (wp-content/debug.log).
 *
 * @param string  $error_type    Short label, e.g. 'HTTP Error', 'API Error'.
 * @param string  $error_message Full error detail.
 */
function cb4c_fLogError( $error_type, $error_message ) {
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional run-time error logging.
    error_log( "chat-bot-for-claude - {$error_type}: {$error_message}" );
}

/*
 * ========================================
 * URL fetching — shared by the fetch_url tool and the pre-fetch list
 * ========================================
 */

/*
 * Reduce an HTML document to plain visible text.
 *
 * script/style/noscript bodies are removed first: wp_strip_all_tags() drops
 * the tags but keeps their contents, which would otherwise hand Claude a pile
 * of JavaScript. The result is whitespace-collapsed and clamped to
 * cb4c_gMaxFetchChars characters.
 *
 * @param string $html Raw response body.
 * @return string      Plain text, possibly truncated.
 */
function cb4c_fHtmlToText( $html ) {
    $stripped = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
    /* preg_replace() returns null on failure (e.g. hitting the backtrack
       limit on a pathological document) — fall back to the raw body. */
    if ( $stripped !== null ) {
        $html = $stripped;
    }

    $text = wp_strip_all_tags( $html );
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = preg_replace( '/[ \t]+/', ' ', $text );
    $text = preg_replace( '/(\s*\n\s*){3,}/', "\n\n", $text );
    $text = trim( (string) $text );

    if ( function_exists( 'mb_strlen' ) ) {
        if ( mb_strlen( $text, 'UTF-8' ) > cb4c_gMaxFetchChars ) {
            $text = mb_substr( $text, 0, cb4c_gMaxFetchChars, 'UTF-8' )
                  . "\n... [truncated]";
        }
    } elseif ( strlen( $text ) > cb4c_gMaxFetchChars ) {
        $text = substr( $text, 0, cb4c_gMaxFetchChars ) . "\n... [truncated]";
    }

    return $text;
}

/*
 * Fetch one URL server-side and return its visible text.
 *
 * SECURITY: wp_safe_remote_get() applies wp_http_validate_url(), which rejects
 * non-http(s) schemes and blocks loopback, private, and link-local addresses.
 * That is what stops a visitor from talking the model into fetching an
 * internal service or a cloud metadata endpoint. On a public-facing page,
 * consider tightening this to an explicit hostname allow-list.
 *
 * Honours cb4c_gFetchTimeOut (cFetchN): on timeout the call is
 * abandoned and false is returned.
 *
 * @param string $url Absolute http(s) URL.
 * @return string|false Extracted text, or false on any failure.
 */
function cb4c_fFetchUrl( $url ) {
    $url = esc_url_raw( trim( (string) $url ) );

    if ( $url === '' || ! wp_http_validate_url( $url ) ) {
        cb4c_fLogError( 'Fetch Rejected', cb4c_fTruncateForLog( $url, 256 ) );
        return false;
    }

    $response = wp_safe_remote_get( $url, array(
            'timeout'             => cb4c_gFetchTimeOut,
            'redirection'         => 3,
            'limit_response_size' => cb4c_gMaxFetchBytes,
            'user-agent'          => 'WP-chat-bot-for-claude/VERSION',
        ) );

    if ( is_wp_error( $response ) ) {
        cb4c_fLogError( 'Fetch Error', $url . ' - ' . $response->get_error_message() );
        return false;
    }

    $code = intval( wp_remote_retrieve_response_code( $response ) );
    if ( $code !== 200 ) {
        cb4c_fLogError( 'Fetch Error', $url . ' - HTTP ' . $code );
        return false;
    }

    return cb4c_fHtmlToText( wp_remote_retrieve_body( $response ) );
}

/*
 * Build the pre-fetch system block from the configured URL list.
 *
 * Runs on every request regardless of the Follow Links setting, as specified.
 * The assembled block is cached in a transient keyed by a hash of the URL
 * list, so editing the list produces a new key and takes effect immediately;
 * re-saving an unchanged list reuses the existing cache until it expires.
 *
 * An empty result is cached too — if the target site is down we should not
 * retry the whole list on every single chat message.
 *
 * @return string System-prompt text, or '' when nothing is configured/fetched.
 */
function cb4c_fGetPreFetchBlock() {
    $raw = trim( get_option( 'claude_chat_prefetch_urls', '' ) );
    if ( $raw === '' ) {
        return '';
    }

    $urls = array();
    foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
        $line = trim( $line );
        if ( $line !== '' ) {
            $urls[] = $line;
        }
        if ( count( $urls ) >= cb4c_gMaxPreFetchUrls ) {
            break;
        }
    }
    if ( empty( $urls ) ) {
        return '';
    }

    $cache_key = 'claude_chat_prefetch_' . md5( implode( "\n", $urls ) );
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $parts = array();
    foreach ( $urls as $url ) {
        $text = cb4c_fFetchUrl( $url );
        if ( $text === false || $text === '' ) {
            continue;
        }
        $parts[] = "===== BEGIN {$url} =====\n{$text}\n===== END {$url} =====";
    }

    $block = '';
    if ( ! empty( $parts ) ) {
        $block = "Reference content already retrieved from the site. Use it directly; "
               . "do not fetch these URLs again.\n\n"
               . implode( "\n\n", $parts );
    }

    set_transient( $cache_key, $block, cb4c_gPreFetchTtl );
    return $block;
}


/*
 * Tool definition sent to the API when Follow Links is enabled.
 */
function cb4c_fFetchUrl_tool_spec() {
    return array(
        'name'         => 'fetch_url',
        'description'  => 'Fetch a web page and return its visible text. Use this whenever the '
                        . 'instructions or the user ask you to read, list, check, or cite the '
                        . 'contents of a URL. Fetch each URL you need before answering; never '
                        . 'guess at page contents.',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'url' => array(
                    'type'        => 'string',
                    'description' => 'Absolute http(s) URL to fetch.',
                ),
            ),
            'required'   => array( 'url' ),
        ),
    );
}


/*
 * ========================================
 * Claude API request
 * ========================================
 */


/*
 * Get the models the saved API key can use, from the Models API.
 * Called when the Settings page is displayed.
 *
 * Returns id => display_name, newest to oldest (sorted by created_at),
 * or false when there is no API key or the call fails (already logged).
 */
function cb4c_fGetModels() {
    $api_key = get_option('claude_chat_api_key');
    if ( empty( $api_key ) ) {
        return false;
    }

    $response = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=1000', array(
            'headers' => array(
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'timeout' => 10,
        ) );

    if ( is_wp_error( $response ) ) {
        cb4c_fLogError( 'Models Error', $response->get_error_message() );
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
        cb4c_fLogError( 'Models Error', cb4c_fTruncateForLog( $data ) );
        return false;
    }

    $list = $data['data'];
    usort( $list, function ( $a, $b ) {
        $ta = isset( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
        $tb = isset( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
        return $tb - $ta;
    } );

    $models = array();
    foreach ( $list as $model ) {
        if ( empty( $model['id'] ) ) {
            continue;
        }
        $models[ $model['id'] ] = ! empty( $model['display_name'] ) ? $model['display_name'] : $model['id'];
    }

    return empty( $models ) ? false : $models;
}

/*
 * Collect every text block from an API response.
 *
 * The previous code read $data['content'][0]['text']. With tools enabled the
 * first block is frequently a tool_use block, so text must be gathered by
 * type rather than by position.
 */
function cb4c_fCollectText( $data ) {
    if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
        return '';
    }

    $parts = array();
    foreach ( $data['content'] as $block ) {
        if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' ) {
            $parts[] = $block['text'];
        }
    }

    return trim( implode( "\n", $parts ) );
}

/*
 * Send one request to the Messages API.
 *
 * @param array $args api_key, model, max_tokens, system, messages,
 *                    tools
 * @return array|string Decoded response array on success; a user-facing error
 *                      string on failure (already logged).
 */
function cb4c_fApiSend( $args ) {
    /* Use the correct API-Endpoint. */
    $url = 'https://api.anthropic.com/v1/messages';

    $headers = array(
        'Content-Type'      => 'application/json',
        'x-api-key'         => $args['api_key'],
        'anthropic-version' => '2023-06-01',
        /* Required to enable cache_control on system/content blocks. */
        'anthropic-beta'    => 'prompt-caching-2024-07-31',
    );

    $body = array(
        'model'      => $args['model'],
        'max_tokens' => intval( $args['max_tokens'] ),
        'messages'   => $args['messages'],
    );

    if ( ! empty( $args['system'] ) ) {
        $body['system'] = $args['system'];
    }

    if ( ! empty( $args['tools'] ) ) {
        $body['tools'] = $args['tools'];
    }

    /*
     * FIX (memory): Cap the response body WordPress will read.
     *
     * Without `limit_response_size`, an unexpectedly large response —
     * e.g. an upstream proxy returning HTML, a malformed stream, or a
     * misrouted request — is read in full and then passed through
     * wp-includes/Requests/src/Requests.php::parse_response(), which
     * splits it with repeated substr() calls. Each substr roughly
     * doubles the string's memory footprint during parsing, so a
     * response of tens of MB can exhaust even a 1.5 GB memory_limit
     * and crash unrelated admin requests when another plugin later
     * triggers the same code path.
     *
     * With the cap set, an oversized response aborts cleanly inside
     * the transport and is surfaced here as a WP_Error, which the
     * existing is_wp_error() branch already handles.
     */
    $response = wp_remote_post( $url, array(
            'headers'             => $headers,
            'body'                => wp_json_encode( $body ),
            'timeout'             => 60,
            'limit_response_size' => cb4c_gMaxResponseBytes,
        ) );

    if ( is_wp_error( $response ) ) {
        cb4c_fLogError( 'HTTP Error', $response->get_error_message() );
        return 'Error: ' . $response->get_error_message();
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $data['error'] ) ) {
        /* FIX (memory): truncate the dumped payload so a huge error
           body cannot balloon the log file or PHP memory. */
        cb4c_fLogError( 'API Error', cb4c_fTruncateForLog( $data ) );
        return 'API Error: ' . $data['error']['message'];
    }

    if ( ! is_array( $data ) || ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
        /* FIX (memory): same truncation rationale as above. */
        cb4c_fLogError(
            'Unknown Error',
            'Unable to get a response from Claude API. Response: '
                . cb4c_fTruncateForLog( $data )
        );
        return 'Error: Unable to get a response from Claude API.';
    }

    return $data;
}


/*
 * Claude API request with logging and, when Follow Links is enabled, a
 * tool_use loop that resolves fetch_url calls.
 *
 * Flow:
 *   send -> if the reply contains tool_use blocks, fetch each requested URL,
 *   append the assistant turn plus a matching tool_result for every tool_use
 *   id, and send again. Repeat until the model stops asking, the round cap is
 *   hit, or cb4c_gResponseBudget (cResponseN) is exhausted.
 *
 * Every tool_use block must receive a tool_result with the same id or the
 * next request is rejected, so failed and skipped fetches return an
 * explanatory result rather than being omitted.
 */
function cb4c_fApiRequest( $message ) {
    $api_key       = get_option('claude_chat_api_key');
    $model         = get_option('claude_chat_model');
    $max_tokens    = get_option('claude_chat_max_tokens');
    $prefix_prompt = trim(get_option('claude_chat_prefix_prompt', ''));
    $follow_links  = ( get_option('claude_chat_follow_links', '') === '1' );

    $started = microtime( true );

    /*
     * Move the prefix prompt to the dedicated `system` parameter.
     *
     * Placing it in `system` gives it architectural separation from
     * the conversation turn — it cannot be overridden by "Ignore
     * previous instructions…" style user inputs and benefits from
     * Claude's distinct system-prompt handling.
     *
     * The array form is used (rather than a plain string) so that
     * cache_control can be set on the block, preserving the
     * prompt-caching benefit of the original implementation.
     *
     * Pre-fetched page content is appended as a second system block, and
     * cache_control moves to the last block so the whole system prefix is
     * cached. With no pre-fetch list configured this is identical to the
     * previous single-block behaviour.
     */
    $system_blocks = array();

    if ( $prefix_prompt !== '' ) {
        $system_blocks[] = array( 'type' => 'text', 'text' => $prefix_prompt );
    }

    $prefetch = cb4c_fGetPreFetchBlock();
    if ( $prefetch !== '' ) {
        $system_blocks[] = array( 'type' => 'text', 'text' => $prefetch );
    }

    if ( ! empty( $system_blocks ) ) {
        $last = count( $system_blocks ) - 1;
        $system_blocks[ $last ]['cache_control'] = array( 'type' => 'ephemeral' );
    }

    $messages = array(
        array(
            'role'    => 'user',
            'content' => $message, /* plain string, no prefix bundled in here */
        ),
    );

    $tools      = $follow_links ? array( cb4c_fFetchUrl_tool_spec() ) : array();
    $final_text = '';
    $timed_out  = false;

    for ( $round = 0; $round <= cb4c_gMaxToolRounds; $round++ ) {

        $data = cb4c_fApiSend( array(
                'api_key'     => $api_key,
                'model'       => $model,
                'max_tokens'  => $max_tokens,
                'system'      => $system_blocks,
                'messages'    => $messages,
                'tools'       => $tools,
            ) );

        if ( is_string( $data ) ) {
            return $data; /* Already logged, and safe to show the user. */
        }

        $text = cb4c_fCollectText( $data );
        if ( $text !== '' ) {
            $final_text = $text;
        }

        /* Collect any fetch_url requests from this turn. */
        $tool_uses = array();
        foreach ( $data['content'] as $block ) {
            if ( isset( $block['type'] ) && $block['type'] === 'tool_use' ) {
                $tool_uses[] = $block;
            }
        }

        if ( ! $follow_links || empty( $tool_uses ) ) {
            break; /* Normal completion. */
        }

        if ( $round === cb4c_gMaxToolRounds ) {
            cb4c_fLogError(
                'Tool Loop',
                'Reached the ' . cb4c_gMaxToolRounds . '-round cap; returning a partial answer.'
            );
            break;
        }

        if ( ( microtime( true ) - $started ) >= cb4c_gResponseBudget ) {
            $timed_out = true;
            cb4c_fLogError(
                'Tool Loop',
                'Response budget of ' . cb4c_gResponseBudget
                    . 's exhausted before round ' . ( $round + 1 ) . '.'
            );
            break;
        }

        $results = array();
        foreach ( $tool_uses as $block ) {
            $result = array(
                'type'        => 'tool_result',
                'tool_use_id' => isset( $block['id'] ) ? $block['id'] : '',
            );

            /* Budget can run out partway through a batch of URLs. Remaining
               tool_use blocks still need a result, so return an error one
               instead of fetching. */
            if ( ( microtime( true ) - $started ) >= cb4c_gResponseBudget ) {
                $timed_out          = true;
                $result['content']  = 'Not fetched: the response time budget was exhausted. '
                                    . 'Answer with what you already have.';
                $result['is_error'] = true;
                $results[]          = $result;
                continue;
            }

            $name = isset( $block['name'] ) ? $block['name'] : '';
            $url  = isset( $block['input']['url'] ) ? $block['input']['url'] : '';

            if ( $name !== 'fetch_url' || $url === '' ) {
                $result['content']  = 'Unknown tool, or the url parameter was missing.';
                $result['is_error'] = true;
                $results[]          = $result;
                continue;
            }

            $text = cb4c_fFetchUrl( $url );

            if ( $text === false || $text === '' ) {
                /* Per spec: a fetch that fails or times out returns nothing. */
                $result['content']  = 'Could not fetch ' . $url . ' (unreachable, blocked, non-200, '
                                    . 'or timed out after ' . cb4c_gFetchTimeOut . 's). '
                                    . 'Do not invent its contents.';
                $result['is_error'] = true;
            } else {
                $result['content'] = "Content of {$url}:\n\n{$text}";
            }

            $results[] = $result;
        }

        /* The assistant turn must be echoed back verbatim, followed by one
           user turn carrying every tool_result. */
        $messages[] = array( 'role' => 'assistant', 'content' => $data['content'] );
        $messages[] = array( 'role' => 'user',      'content' => $results );
    }

    if ( $final_text === '' ) {
        $final_text = $timed_out
            ? 'Error: Timed out while retrieving linked pages. Please try again.'
            : 'Error: Unable to get a response from Claude API.';
        cb4c_fLogError( 'Empty Response', $final_text );
        return $final_text;
    }

    /* Log the user message and Claude response to claude_log.org. */
    cb4c_fLogMessage( $message, $final_text );

    return $final_text;
}


/*
 * Clear Logs handler
 * Removes the text in claude_log.org (leaving only the "* Log"
 * heading), then redirects back to the settings page with a
 * confirmation flag.
 */
function cb4c_fClearLogs() {
    if ( ! current_user_can('manage_options') ) {
        wp_die( esc_html__('Unauthorized', 'chat-bot-for-claude') );
    }
    check_admin_referer('cb4c_fClearLogs_action', 'cb4c_fClearLogs_nonce');

    $path = cb4c_fGetLogPath();
    if ( $path ) {
        file_put_contents( $path, "* Log\n", LOCK_EX );
    }

    wp_safe_redirect( add_query_arg(
        array('page' => 'claude-chat-settings', 'logs-cleared' => '1'),
        admin_url('options-general.php')
    ) );
    exit;
}
add_action('admin_post_cb4c_fClearLogs', 'cb4c_fClearLogs');

/*
 * View Log handler
 * Sends claude_log.org to the browser as plain text. Opened in a new
 * tab by the "View Log" button on the settings page. Plain text with
 * nosniff means the browser shows the log as text and never runs it
 * as HTML.
 */
function cb4c_fViewLog() {
    if ( ! current_user_can('manage_options') ) {
        wp_die( esc_html__('Unauthorized', 'chat-bot-for-claude') );
    }
    check_admin_referer('cb4c_fViewLog_action');

    $path = cb4c_fGetLogPath();

    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    if ( $path && file_exists($path) ) {
        readfile( $path );
    } else {
        echo "* Log\n";
    }
    exit;
}
add_action('admin_post_cb4c_fViewLog', 'cb4c_fViewLog');


/*
 * ========================================
 * Add settings page
 * ========================================
 */
 
function cb4c_fSettingsPage() {
    add_options_page(
        'Claude Chat Settings',
        'Claude Chat',
        'manage_options',
        'claude-chat-settings',
        'cb4c_fSettingsPage_html'
    );
}
add_action('admin_menu', 'cb4c_fSettingsPage');

/* Settings page HTML */
function cb4c_fSettingsPage_html() {
    $homeUrl = home_url();
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php
        /* Display-only flag set by cb4c_fClearLogs() after its own nonce check. */
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $logs_cleared = isset($_GET['logs-cleared']) ? sanitize_text_field(wp_unslash($_GET['logs-cleared'])) : '';
        ?>
        <?php if ( $logs_cleared === '1' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Chat log cleared successfully.', 'chat-bot-for-claude'); ?></p>
        </div>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php
    settings_fields('claude_chat_options');
    do_settings_sections('claude-chat-settings');
    submit_button('Save Settings');
?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="margin-top:12px;">
            <input type="hidden" name="action" value="cb4c_fClearLogs">
            <?php wp_nonce_field('cb4c_fClearLogs_action', 'cb4c_fClearLogs_nonce'); ?>
            <?php
            $view_url = wp_nonce_url(
                admin_url('admin-post.php?action=cb4c_fViewLog'),
                'cb4c_fViewLog_action'
            );
            echo '<a href="' . esc_url($view_url) . '" class="button" target="_blank" rel="noopener">'
                . esc_html__('View Log', 'chat-bot-for-claude') . '</a> ';
            submit_button('Clear Logs', 'delete', 'cb4c_fClearLogs_submit', false);
            echo '<p class="description">'
                . esc_html__('Chat log file:', 'chat-bot-for-claude') . ' '
                . esc_html(trailingslashit(cb4c_gLogDir) . cb4c_gLogFile)
                . '<br>'
                . esc_html__('Errors are written to the PHP error log.', 'chat-bot-for-claude')
                . '</p>';
            ?>
        </form>
    </div>
    <?php
}

/* Initialize settings */
function cb4c_fSettingsInit() {
    add_settings_section(
        'claude_chat_settings_section',
        'Claude API Settings',
        'cb4c_fSettingsSection',
        'claude-chat-settings'
    );

    add_settings_field(
        'claude_chat_api_key',
        'API Key',
        'cb4c_fApiKeyFieldCallback',   /* dedicated callback uses type="password" */
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_api_key')
    );

    add_settings_field(
        'claude_chat_model',
        'Model',
        'cb4c_fDropdownCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array('label_for' => 'claude_chat_model')
    );


    add_settings_field(
        'claude_chat_max_tokens',
        'Max Tokens',
        'cb4c_fNumberFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for' => 'claude_chat_max_tokens',
            'description' => 'Range: 1 to 8096',
            'min' => 1,
            'max' => 8096,
        )
    );

    add_settings_field(
        'claude_chat_follow_links',
        'Follow Links',
        'cb4c_fCheckboxFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for'   => 'claude_chat_follow_links',
            'description' => 'When checked, Claude may call the fetch_url tool to read URLs '
                           . 'named in the prompt or the user question. Each fetch times out after '
                           . cb4c_gFetchTimeOut . 's; the whole fetch loop stops after '
                           . cb4c_gResponseBudget . 's and answers with what it has. '
                           . 'Adds an API round trip per batch of fetches, so replies are slower and '
                           . 'cost more tokens.',
        )
    );

    add_settings_field(
        'claude_chat_prefetch_urls',
        'List of pre-fetch URLs',
        'cb4c_fTextareaFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for'   => 'claude_chat_prefetch_urls',
            'description' => 'Optional. One URL per line, max ' . cb4c_gMaxPreFetchUrls . '. '
                           . 'These are always fetched and added to the system prompt, whether or not '
                           . 'Follow Links is checked. Content is cached for '
                           . intval( cb4c_gPreFetchTtl / 60 ) . ' minutes and truncated to '
                           . number_format( cb4c_gMaxFetchChars ) . ' characters per page. '
                           . 'Leave blank to disable.',
        )
    );

    add_settings_field(
        'claude_chat_prefix_prompt',
        'Prefix Prompt',
        'cb4c_fTextareaFieldCallback',
        'claude-chat-settings',
        'claude_chat_settings_section',
        array(
            'label_for'   => 'claude_chat_prefix_prompt',
            'description' => 'Optional. Sent as the system prompt on every request, keeping it separate from user input. Uses cache_control to save costs. Leave blank to disable. Max ' . number_format( cb4c_gMaxPrefixPrompt ) . ' bytes.',
        )
    );
}
add_action('admin_init', 'cb4c_fSettingsInit');

/* Field render callbacks */
function cb4c_fSettingsSection($args) {
    echo '<p>Version: VERSION</p>';
    echo '<p>Click <a href="' . esc_url('https://github.com/TurtleEngr/WP-chat-bot-for-claude/blob/main/README.md') . '" target="_blank">HERE</a> for help.</p>';
    echo '<p>Enter your Claude API settings below:</p>';
}

/* Render the API key as a password field so it is masked in the
 * browser.
 */
function cb4c_fApiKeyFieldCallback($args) {
    $option = get_option($args['label_for']);
    echo '<input type="password" id="'  . esc_attr($args['label_for'])
        . '" name="'                     . esc_attr($args['label_for'])
        . '" value="'                    . esc_attr($option)
        . '" class="regular-text"'
        . ' autocomplete="new-password">';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}

function cb4c_fNumberFieldCallback($args) {
    $option = get_option($args['label_for']);
    echo '<input type="number" id="' . esc_attr($args['label_for'])
        . '" name="'                  . esc_attr($args['label_for'])
        . '" value="'                 . esc_attr($option)
        . '" class="regular-text"'
        . ' min="'                    . esc_attr($args['min'])
        . '" max="'                   . esc_attr($args['max'])
        . '" step="'                  . (isset($args['step']) ? esc_attr($args['step']) : '1')
        . '">';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}


/*
 * Checkbox field.
 *
 * The hidden input is required: an unchecked checkbox submits nothing, so
 * without it the Settings API never sees the key, the option keeps its old
 *  value, and the box would appear impossible to turn off.
 */
function cb4c_fCheckboxFieldCallback($args) {
    $option = get_option($args['label_for'], '');
    echo '<input type="hidden" name="' . esc_attr($args['label_for']) . '" value="0">';
    echo '<input type="checkbox" id="' . esc_attr($args['label_for'])
        . '" name="'                    . esc_attr($args['label_for'])
        . '" value="1" '                . checked('1', $option, false)
        . '>';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}

function cb4c_fDropdownCallback($args) {
    $selected_model = get_option($args['label_for']);

    /* Get the current list from the API. If that fails, list only the
       saved model, so Save Settings does not clear it. */
    $models = cb4c_fGetModels();
    if ( $models === false ) {
        $models = empty($selected_model) ? array() : array($selected_model => $selected_model);
        $args['description'] = 'Could not get the model list from the Anthropic API. '
                             . 'Enter or check the API Key, then Save Settings.';
    }

    echo '<select id="'   . esc_attr($args['label_for'])
        . '" name="'       . esc_attr($args['label_for'])
        . '" class="regular-text">';
    foreach ($models as $model_key => $model_name) {
        echo '<option value="' . esc_attr($model_key) . '" ' . selected($selected_model, $model_key, false) . '>'
            . esc_html($model_name) . '</option>';
    }
    echo '</select>';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}

function cb4c_fTextareaFieldCallback($args) {
    $option = get_option($args['label_for'], '');
    echo '<textarea id="'   . esc_attr($args['label_for'])
        . '" name="'         . esc_attr($args['label_for'])
        . '" rows="6" cols="60" class="large-text code">'
        . esc_textarea($option)
        . '</textarea>';
    if ( ! empty($args['description'])) {
        echo '<p class="description">' . wp_kses($args['description'], array('code' => array())) . '</p>';
    }
}
