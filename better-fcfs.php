<?php
/**
 * Plugin Name: Partial Capture for Fluent Forms
 * Plugin URI:  https://github.com/Aversityinc/Partial-Capture-for-FFs
 * Description: Captures qualified partial leads from Fluent Forms conversational forms — settle-timer and exit triggers, conditional webhooks, and $/% number formatting.
 * Version:     0.1.4
 * Author:      Aversity
 * Author URI:  https://github.com/Aversityinc
 * Text Domain: better-fcfs
 * License:     GPLv2 or later
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
    exit;
}

define('BFCF_VERSION', '0.1.4');
define('BFCF_FILE', __FILE__);
define('BFCF_DIR', plugin_dir_path(__FILE__));
define('BFCF_URL', plugin_dir_url(__FILE__));

/**
 * Minimum Fluent Forms we have verified against. The conversational module's
 * compiled bundle is the contract here, and it moves between releases, so this
 * is a floor we actually test — not a guess.
 */
define('BFCF_MIN_FF_VERSION', '5.2.0');

spl_autoload_register(function ($class) {
    if (strpos($class, 'BetterFCF\\') !== 0) {
        return;
    }
    $path = BFCF_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen('BetterFCF\\'))) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

register_activation_hook(__FILE__, function () {
    \BetterFCF\Database\Migrator::migrate();
});

register_deactivation_hook(__FILE__, function () {
    \BetterFCF\Partials\Abandonment::unschedule();
});

add_action('plugins_loaded', function () {
    if (! defined('FLUENTFORM_VERSION')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Better FCFs</strong> needs Fluent Forms to be active.</p></div>';
        });
        return;
    }

    if (version_compare(FLUENTFORM_VERSION, BFCF_MIN_FF_VERSION, '<')) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p><strong>Better FCFs</strong> needs Fluent Forms %s or newer. You are on %s.</p></div>',
                esc_html(BFCF_MIN_FF_VERSION),
                esc_html(FLUENTFORM_VERSION)
            );
        });
        return;
    }

    (new \BetterFCF\Plugin())->boot();
}, 20);
