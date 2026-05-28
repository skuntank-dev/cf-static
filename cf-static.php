<?php
/**
 * Plugin Name: Cloudflare Access-Friendly Static Page Generator
 * Description: Generate a static version of your WordPress site, bypassing Cloudflare Access via service tokens. If wrangler CLI is installed, you can also push to Pages with your API token.
 * Version: 1.3.0
 * Author: skuntank.dev
 * Author URI: https://skuntank.dev
 * Plugin URI: https://github.com/skuntank-dev/cf-static/
 */

if (!defined('ABSPATH')) exit;

define('CF_STATIC_GITHUB_REPO', 'skuntank-dev/cf-static');

class CFStatic {

    private $option_name = 'cf_static_tokens';
    private $selected_plugins_option = 'cf_static_selected_plugins';
    private $schedule_option = 'cf_static_schedule';
    private $cron_hook = 'cf_static_scheduled_run';
    private $log = [];
    private $log_title_override = '';
    private $site_url;
    private $cf_cookie = '';

    private $plugin_dir;
    private $plugin_url;
    private $last_zip_url = '';
    private $cron_mode = false;
    private $cache_bust = ''; // unique per generation run, appended to fetch URLs

private function get_plugin_version() {
    if (!function_exists('get_file_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_file = __FILE__;
    $plugin_data = get_file_data($plugin_file, ['Version' => 'Version']);
    return $plugin_data['Version'] ?? '0.0.0';
}

    public function __construct() {
        $this->site_url   = get_site_url();
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_cf_static_generate', [$this, 'handle_generate']);
        add_action('admin_post_cf_static_deploy', [$this, 'handle_deploy']);
        add_action('admin_post_cf_static_generate_and_deploy', [$this, 'handle_generate_and_deploy']);
        add_action('admin_post_cf_static_save_schedule', [$this, 'handle_save_schedule']);
        add_action($this->cron_hook, [$this, 'run_scheduled_task']);

    }

    public function add_admin_menu() {
        add_menu_page(
            'Cloudflare Static Generator',
            'CF Static Generator',
            'manage_options',
            'cf-static',
            [$this, 'admin_page'],
            'dashicons-cloud',
            90
        );
    }

private function check_github_version() {
    $url = 'https://api.github.com/repos/' . CF_STATIC_GITHUB_REPO . '/releases/latest';

    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'User-Agent' => 'WordPress'
        ]
    ]);

    if (is_wp_error($response)) {
        return [
            'error' => true,
            'message' => 'Cannot find the latest version. Please ensure that your WordPress is able to access the Internet for version checking.'
        ];
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return [
            'error' => true,
            'message' => 'Cannot find the latest version. Please ensure that your WordPress is able to access the Internet for version checking.'
        ];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['tag_name'])) {
        return [
            'error' => true,
            'message' => 'Cannot find the latest version. Please ensure that your WordPress is able to access the Internet for version checking.'
        ];
    }

    $latest_version = ltrim($body['tag_name'], 'v');
    $current_version = $this->get_plugin_version();

    if (version_compare($latest_version, $current_version, '>')) {
        return [
            'update' => true,
            'latest' => $latest_version,
            'zip'    => 'https://github.com/' . CF_STATIC_GITHUB_REPO . '/archive/refs/tags/v' . $latest_version . '.zip'
        ];
    }

    return ['update' => false];
}

    public function admin_page() {
        if (!current_user_can('manage_options')) return;

        $tokens   = get_option($this->option_name, ['client_id' => '', 'client_secret' => '']);
        // CF Access partial credential warning (only one field filled)
$cf_id_filled     = !empty($tokens['client_id']);
$cf_secret_filled = !empty($tokens['client_secret']);
$cf_partial_auth  = ($cf_id_filled xor $cf_secret_filled);

        $message  = isset($_GET['message']) ? esc_html($_GET['message']) : '';
        $last_zip = get_option('cf_static_last_zip');
        $generate_404_checked = get_option('cf_static_generate_404', false);
        $generate_404_path = get_option('cf_static_generate_404_path', 'example-page');

        // --- Plugin JS selection ---
        $active_plugins = get_option('active_plugins', []);
        $plugin_choices  = [];
        foreach ($active_plugins as $plugin_file) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            $plugin_choices[$plugin_file] = $plugin_data['Name'];
        }
        $selected_plugins = get_option($this->selected_plugins_option, []);

        ?>
        <div class="wrap">
            <?php if ($cf_partial_auth): ?>
    <div class="notice notice-warning">
        <p>
            <strong>Cloudflare Access configuration warning:</strong><br>
            You have provided only one of the two required CF Access credentials.
            Static generation will <strong>fail</strong> unless both
            <em>Client ID</em> and <em>Client Secret</em> are filled — or both are left blank.
        </p>
    </div>
<?php endif; ?>

<?php
$version_check = $this->check_github_version();

if (!empty($version_check['error'])) {
    echo '<div class="notice notice-error"><p>' . esc_html($version_check['message']) . '</p></div>';
} elseif (!empty($version_check['update'])) {
    echo '<div class="notice notice-warning"><p>';
    echo 'New version found: <strong>v' . esc_html($version_check['latest']) . '</strong>. ';
    echo 'Please download the latest version at ';
    echo '<a href="' . esc_url($version_check['zip']) . '" target="_blank">this link</a>.';
    echo '</p></div>';
}
?>

            <h1>Cloudflare Access-Friendly Static Page Generator</h1>
<?php
// Check if Wrangler CLI is installed
exec('wrangler --version', $wrangler_output, $wrangler_return);
if ($wrangler_return !== 0) {
    echo '<div class="notice notice-warning"><p><strong>Warning:</strong> Wrangler CLI is not installed. Deploy to Cloudflare Pages will not work until Wrangler is installed. See <a href="https://developers.cloudflare.com/workers/cli-wrangler/install-update/" target="_blank">Wrangler installation guide</a>.</p></div>';
}
?>

<?php if ($last_zip && file_exists($this->plugin_dir . basename($last_zip))): 
    $zip_file = $this->plugin_dir . basename($last_zip);
    $zip_name = basename($zip_file);
    $zip_age  = human_time_diff(filemtime($zip_file), time());
?>
    <p>
        <a href="<?php echo esc_url($last_zip); ?>" class="button button-primary" download>
            Download Latest Static ZIP
        </a>
        <span style="margin-left:10px;">
            <?php echo esc_html($zip_name); ?> (generated <?php echo esc_html($zip_age); ?> ago)
        </span>
    </p>
<?php endif; ?>

            <?php if ($message): ?>
                <div class="notice notice-success"><p><?php echo $message; ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="cf_static_generate">
                <?php wp_nonce_field('cf_static_generate_nonce', 'cf_static_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th>CF-Access Client ID</th>
                        <td><input type="text" name="client_id" value="<?php echo esc_attr($tokens['client_id']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>CF-Access Client Secret</th>
                        <td><input type="text" name="client_secret" value="<?php echo esc_attr($tokens['client_secret']); ?>" class="regular-text"></td>
                        <?php $remember_cf = get_option('cf_static_remember_cf', false); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="remember_cf" value="1" <?php checked($remember_cf); ?>>
                            Remember CF Access token (not recommended on non-private servers)
                        </label>
                    </p>

                    </tr>
                    <tr>
                        <th>Options</th>
                        <td>
                            <label>
                                <input type="checkbox" id="generate_404_checkbox" name="generate_404" value="1" <?php checked($generate_404_checked); ?>>
                                Generate 404.html
                            </label>

                            <div id="generate_404_path_wrapper" style="margin-top:8px; <?php echo $generate_404_checked ? '' : 'display:none;'; ?>">
                                Generate from: <?php echo esc_html(parse_url($this->site_url, PHP_URL_HOST)); ?>/
                                <input type="text" name="generate_404_path" value="<?php echo esc_attr($generate_404_path); ?>" class="regular-text" placeholder="example-page">
                            </div>

                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const checkbox = document.getElementById('generate_404_checkbox');
                                const wrapper  = document.getElementById('generate_404_path_wrapper');

                                checkbox.addEventListener('change', function() {
                                    wrapper.style.display = this.checked ? 'block' : 'none';
                                });
                            });
                            </script>
                        </td>
                    </tr>
                </table>

                <h2>Plugin JS Selection</h2>
                <table class="form-table">
                    <tr>
                        <th>Select Plugins for JS Crawl</th>
                        <td>
                            <?php foreach ($plugin_choices as $file => $name): ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="selected_plugins[]" value="<?php echo esc_attr($file); ?>" <?php echo in_array($file, $selected_plugins) ? 'checked' : ''; ?>>
                                    <?php echo esc_html($name); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

<?php submit_button('Generate Static Site'); ?>

<h2>Cloudflare Pages Deployment</h2>

<table class="form-table">
    <?php
    $cf_pages_options = get_option('cf_static_pages_options', [
        'project_name' => '',
        'branch' => 'main',
        'account_id' => '',
        'api_token' => ''
    ]);
    ?>
    <tr>
        <th>Project Name</th>
        <td><input type="text" name="cf_pages_project_name" value="<?php echo esc_attr($cf_pages_options['project_name']); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th>Branch</th>
        <td><input type="text" name="cf_pages_branch" value="<?php echo esc_attr($cf_pages_options['branch']); ?>" class="regular-text"><p class="description">Use "main" for production or any branch name for preview</p></td>
    </tr>
    <tr>
        <th>Account ID</th>
        <td><input type="text" name="cf_pages_account_id" value="<?php echo esc_attr($cf_pages_options['account_id']); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th>API Token</th>
        <td><input type="text" name="cf_pages_api_token" value="<?php echo esc_attr($cf_pages_options['api_token']); ?>" class="regular-text"><p class="description">Must have Pages Edit permissions</p></td>
        <?php $remember_pages = get_option('cf_static_remember_pages', false); ?>
<p>
    <label>
        <input type="checkbox" name="remember_pages" value="1" <?php checked($remember_pages); ?>>
        Remember Account ID and API Token (not recommended on non-private servers)
    </label>
</p>

    </tr>
</table>

<?php 
$last_zip_exists = !empty($last_zip) && file_exists($this->plugin_dir . basename($last_zip));
$disable_deploy = ($wrangler_return !== 0 || !$last_zip_exists) ? 'disabled' : ''; 
$disable_gen_deploy = ($wrangler_return !== 0) ? 'disabled' : '';
?>

<p>
    <button type="submit" name="action" value="cf_static_deploy" class="button button-primary" <?php echo $disable_deploy; ?>>Deploy to Cloudflare Pages</button>
    <button type="submit" name="action" value="cf_static_generate_and_deploy" class="button button-secondary" style="margin-left:6px;" <?php echo $disable_gen_deploy; ?>>Generate and Deploy</button>
</p>

            </form>

<?php
$schedule = $this->get_schedule();
$next_run_ts = wp_next_scheduled($this->cron_hook);
$tz = wp_timezone();
$now_ts = time();
?>

<h2>Automatic Generation &amp; Deployment</h2>
<p class="description" style="margin-top:-6px;">
    Schedules generation and/or deployment via WP-Cron using the settings saved above.
    Toggling this ON or OFF saves the schedule settings immediately.
</p>

<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="cf-static-schedule-form">
    <input type="hidden" name="action" value="cf_static_save_schedule">
    <?php wp_nonce_field('cf_static_schedule_nonce', 'cf_static_schedule_nonce'); ?>
    <input type="hidden" name="schedule_enabled" id="cf_schedule_enabled_hidden" value="<?php echo !empty($schedule['enabled']) ? '1' : '0'; ?>">

    <table class="form-table">
        <tr>
            <th>Status</th>
            <td>
                <span id="cf_schedule_status_label" style="font-weight:600; color:<?php echo !empty($schedule['enabled']) ? '#1a7f37' : '#777'; ?>;">
                    <?php echo !empty($schedule['enabled']) ? 'ENABLED' : 'DISABLED'; ?>
                </span>
                <?php if (!empty($schedule['enabled']) && $next_run_ts): ?>
                    <span style="margin-left:12px; color:#555;">
                        Next run: <?php echo esc_html(wp_date('Y-m-d H:i:s', $next_run_ts)); ?>
                        (<?php echo esc_html(human_time_diff($now_ts, $next_run_ts)); ?>
                        <?php echo $next_run_ts > $now_ts ? 'from now' : 'ago'; ?>)
                    </span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Action</th>
            <td>
                <label style="display:block; margin-bottom:4px;">
                    <input type="radio" name="schedule_mode" value="generate" <?php checked($schedule['mode'], 'generate'); ?>>
                    Generate static site only
                </label>
                <label style="display:block; margin-bottom:4px;">
                    <input type="radio" name="schedule_mode" value="deploy" <?php checked($schedule['mode'], 'deploy'); ?>>
                    Deploy existing static site only
                </label>
                <label style="display:block;">
                    <input type="radio" name="schedule_mode" value="generate_and_deploy" <?php checked($schedule['mode'], 'generate_and_deploy'); ?>>
                    Generate and deploy
                </label>
            </td>
        </tr>
        <tr>
            <th>Schedule</th>
            <td>
                <label style="display:block; margin-bottom:6px;">
                    <input type="radio" name="schedule_type" value="interval" class="cf-schedule-type" <?php checked($schedule['schedule_type'], 'interval'); ?>>
                    Run every
                    <input type="number" name="interval_hours" min="0" max="999"
                           value="<?php echo esc_attr($schedule['interval_hours']); ?>" style="width:70px;"> hours
                    <input type="number" name="interval_minutes" min="0" max="59"
                           value="<?php echo esc_attr($schedule['interval_minutes']); ?>" style="width:70px;"> minutes
                </label>
                <label style="display:block;">
                    <input type="radio" name="schedule_type" value="daily" class="cf-schedule-type" <?php checked($schedule['schedule_type'], 'daily'); ?>>
                    Daily at
                    <input type="time" name="daily_time"
                           value="<?php echo esc_attr($schedule['daily_time']); ?>" step="60">
                    <span class="description">(site timezone: <?php echo esc_html(wp_timezone_string()); ?>)</span>
                </label>
            </td>
        </tr>
        <tr>
            <th>Last automatic run</th>
            <td>
                <?php if (!empty($schedule['last_run'])): ?>
                    <?php echo esc_html(wp_date('Y-m-d H:i:s', (int)$schedule['last_run'])); ?>
                    (<?php echo esc_html(human_time_diff((int)$schedule['last_run'], $now_ts)); ?> ago)
                    <?php if (!empty($schedule['last_status'])): ?>
                        — <em><?php echo esc_html($schedule['last_status']); ?></em>
                    <?php endif; ?>
                <?php else: ?>
                    <em>Never</em>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <p>
        <button type="submit" id="cf_schedule_toggle_btn"
                class="button <?php echo !empty($schedule['enabled']) ? 'button-secondary' : 'button-primary'; ?>"
                data-on-label="Turn Auto Generate/Deploy OFF"
                data-off-label="Turn Auto Generate/Deploy ON">
            <?php echo !empty($schedule['enabled']) ? 'Turn Auto Generate/Deploy OFF' : 'Turn Auto Generate/Deploy ON'; ?>
        </button>
        <button type="submit" id="cf_schedule_save_btn" class="button button-secondary" style="margin-left:6px;">
            Save Schedule Settings
        </button>
    </p>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var hidden    = document.getElementById('cf_schedule_enabled_hidden');
        var toggleBtn = document.getElementById('cf_schedule_toggle_btn');
        var saveBtn   = document.getElementById('cf_schedule_save_btn');

        // Toggle button flips the hidden enabled flag, then submits.
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            hidden.value = (hidden.value === '1') ? '0' : '1';
            toggleBtn.form.submit();
        });

        // "Save Schedule Settings" keeps enabled state as-is and just saves.
        saveBtn.addEventListener('click', function(e) {
            // Default form submission — hidden value already reflects current state.
        });
    });
    </script>
</form>

            <?php if (!empty($this->log)): ?>
                <h2>Log</h2>
                <ul>
                    <?php foreach ($this->log as $line): ?>
                        <li><?php echo esc_html($line); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <p style="margin-top:20px; font-size:12px; color:#555;">
    Developed by <a href="https://skuntank.dev" target="_blank">skuntank.dev</a>
</p>
</div>

        <?php
    }

    public function handle_generate($chain_to_deploy = false) {
        if (!$this->cron_mode) {
            if (!current_user_can('manage_options')) wp_die('Unauthorized');
            if (!wp_verify_nonce($_POST['cf_static_nonce'], 'cf_static_generate_nonce')) wp_die('Nonce failed');
        }

        if ($this->cron_mode) {
            // Pull all inputs from previously saved options.
            $saved_tokens     = get_option($this->option_name, ['client_id' => '', 'client_secret' => '']);
            $client_id        = $saved_tokens['client_id'] ?? '';
            $client_secret    = $saved_tokens['client_secret'] ?? '';
            $generate_404     = (bool) get_option('cf_static_generate_404', false);
            $generate_404_path = get_option('cf_static_generate_404_path', 'example-page');
            if (empty($generate_404_path)) $generate_404_path = 'example-page';
            $selected_plugins = get_option($this->selected_plugins_option, []);
            if (!is_array($selected_plugins)) $selected_plugins = [];
        } else {
            $client_id     = sanitize_text_field($_POST['client_id']);
            $client_secret = sanitize_text_field($_POST['client_secret']);
            $remember_cf = !empty($_POST['remember_cf']);
            update_option('cf_static_remember_cf', $remember_cf);

            $generate_404  = !empty($_POST['generate_404']);
            update_option('cf_static_generate_404', $generate_404);
            $generate_404_path = sanitize_text_field($_POST['generate_404_path'] ?? '');

            if (empty($generate_404_path)) {
                $generate_404_path = 'example-page';
            }

            update_option('cf_static_generate_404_path', $generate_404_path);

            $selected_plugins = !empty($_POST['selected_plugins']) ? array_map('sanitize_text_field', $_POST['selected_plugins']) : [];

            if ($remember_cf) {
                update_option($this->option_name, compact('client_id', 'client_secret'));
            } else {
                update_option($this->option_name, ['client_id' => '', 'client_secret' => '']);
            }

            update_option($this->selected_plugins_option, $selected_plugins);
        }

        $output_dir = $this->plugin_dir . 'static';
        if (!file_exists($output_dir)) mkdir($output_dir, 0755, true);

        // Unique token for this generation run, appended to every fetched URL so
        // no cache layer (origin, Cloudflare edge, intermediate proxy) can serve
        // a stale copy. It never touches the saved filename — only the request.
        $this->cache_bust = 'cfsb' . time() . bin2hex(random_bytes(4));

        // Only authenticate with Cloudflare Access if BOTH fields are provided
        if (!empty($client_id) && !empty($client_secret)) {
            $this->cf_cookie = $this->authenticate_cf($client_id, $client_secret);
            if (!$this->cf_cookie) {
                $this->log[] = 'Cloudflare authentication failed';
                if ($this->cron_mode) {
                    throw new \RuntimeException('Cloudflare authentication failed');
                }
                $this->display_log_and_exit();
            }
        } else {
            // No CF Access credentials provided — proceed without authentication
            $this->cf_cookie = '';
            $this->log[] = 'CF Access credentials not provided. Skipping Cloudflare authentication.';
        }

        $urls_to_crawl = ['/'];
        $crawled = [];
        $skip_paths = ['cdn-cgi', 'comments', 'feed', 'wp-json', 'xmlrpc.php'];

        while ($urls_to_crawl) {
            $path = array_shift($urls_to_crawl);
            if (in_array($path, $crawled)) continue;

            $skip = false;
            foreach ($skip_paths as $sp) {
                if (strpos($path, "/$sp") === 0 || $path === $sp) { $skip = true; break; }
            }
            if ($skip) continue;

            $crawled[] = $path;

            $url  = rtrim($this->site_url, '/') . $path;
            $html = $this->fetch_url($url);
            if (!$html) continue;

            preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $links);
            foreach ($links[1] as $link) {
                if (strpos($link, $this->site_url) === 0 || strpos($link, '/') === 0) {
                    $p = strpos($link, $this->site_url) === 0 ? parse_url($link, PHP_URL_PATH) : $link;
                    if (!in_array($p, $crawled) && !in_array($p, $urls_to_crawl)) $urls_to_crawl[] = $p;
                }
            }

            $html = str_replace($this->site_url, '', $html);

            $file = $output_dir . $path;
            if (substr($file, -1) === '/') $file .= 'index.html';
            if (!file_exists(dirname($file))) mkdir(dirname($file), 0755, true);
            file_put_contents($file, $html);

            $this->crawl_assets($html, $output_dir);
        }

        $this->copy_core_and_plugin_js($output_dir, $selected_plugins);

        if ($generate_404) {
            $this->generate_404($output_dir, $generate_404_path);
        }

        // Remove old ZIPs
        foreach (glob($this->plugin_dir . '*.zip') as $zip) unlink($zip);

        $timestamp = date('HisdmY');
        $zip_name  = "cf-static-site-$timestamp.zip";
        $zip_path  = $this->plugin_dir . $zip_name;

        // --- SANITIZE ADMIN JS before zipping ---
        $this->sanitize_admin_js($output_dir);

        // Zip the static site
        $this->zip_directory($output_dir, $zip_path);

        update_option('cf_static_last_zip', $this->plugin_url . $zip_name);

        if ($chain_to_deploy) {
            // Caller will continue into the deploy step; don't redirect or exit.
            $this->log[] = 'Static site generated.';
            $this->log[] = '';
            $this->log[] = '=== Starting deployment ===';
            return;
        }

        if ($this->cron_mode) {
            $this->log[] = 'Static site generated.';
            return;
        }

        wp_redirect(admin_url('admin.php?page=cf-static&message=Static+site+generated'));
        exit;
    }
public function handle_deploy() {
    if (!$this->cron_mode) {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
    }

    if ($this->cron_mode) {
        // Cron path: use saved credentials only.
        $saved = get_option('cf_static_pages_options', [
            'project_name' => '',
            'branch'       => 'main',
            'account_id'   => '',
            'api_token'    => ''
        ]);
        $cf_pages_options = [
            'project_name' => $saved['project_name'] ?? '',
            'branch'       => $saved['branch'] ?? 'main',
            'account_id'   => $saved['account_id'] ?? '',
            'api_token'    => $saved['api_token'] ?? '',
        ];
        if (empty($cf_pages_options['account_id']) || empty($cf_pages_options['api_token'])) {
            $this->log[] = 'Scheduled deploy: Account ID / API Token not saved. Enable "Remember Account ID and API Token" to use scheduled deployment.';
            throw new \RuntimeException('Missing saved Cloudflare Pages credentials');
        }
        $remember_pages = true; // cron path uses saved creds only
    } else {
        // Save/update Wrangler config
        $cf_pages_options = [
            'project_name' => sanitize_text_field($_POST['cf_pages_project_name'] ?? ''),
            'branch'       => sanitize_text_field($_POST['cf_pages_branch'] ?? 'main'),
            'account_id'   => sanitize_text_field($_POST['cf_pages_account_id'] ?? ''),
            'api_token'    => sanitize_text_field($_POST['cf_pages_api_token'] ?? ''),
        ];
        $remember_pages = !empty($_POST['remember_pages']);
        update_option('cf_static_remember_pages', $remember_pages);
    }

// Always deploy using submitted credentials
// Persistence decision happens AFTER deploy

// Check Wrangler installation
exec('wrangler --version', $output, $return_var);
if ($return_var !== 0) {
    $this->log[] = "Wrangler CLI not found. Please install Wrangler to enable deployment.";
    if ($this->cron_mode) {
        throw new \RuntimeException('Wrangler CLI not found');
    }
    $this->display_log_and_exit(); // stops execution and shows log
}

    $output_dir = $this->plugin_dir . 'static';
    if (!file_exists($output_dir)) {
        if ($this->cron_mode) {
            $this->log[] = 'Static folder not found. Generate site first.';
            throw new \RuntimeException('Static folder not found');
        }
        wp_redirect(admin_url('admin.php?page=cf-static&message=' . urlencode('Static folder not found. Generate site first.')));
        exit;
    }

    // Set environment variables for Wrangler
    putenv("CLOUDFLARE_API_TOKEN={$cf_pages_options['api_token']}");
    putenv("CLOUDFLARE_ACCOUNT_ID={$cf_pages_options['account_id']}");

    $project = escapeshellarg($cf_pages_options['project_name']);
    $branch  = escapeshellarg($cf_pages_options['branch']);
    $dir     = escapeshellarg($output_dir);

    // Run Wrangler deploy
    $this->log[] = "Running: wrangler pages deploy $dir --project-name=$project --branch=$branch";
    exec("npx wrangler pages deploy $dir --project-name=$project --branch=$branch 2>&1", $deploy_output, $return_code);

    foreach ($deploy_output as $line) $this->log[] = $line;
    $this->log[] = "Wrangler exit code: $return_code";
    $this->log[] = $return_code === 0 ? 'Deployment completed successfully!' : 'Deployment failed. Check logs above.';

    if (!$this->cron_mode) {
        if ($remember_pages) {
            update_option('cf_static_pages_options', $cf_pages_options);
        } else {
            update_option('cf_static_pages_options', [
                'project_name' => $cf_pages_options['project_name'],
                'branch'       => $cf_pages_options['branch'],
                'account_id'   => '',
                'api_token'    => ''
            ]);
        }
    }

    if ($this->cron_mode) {
        if ($return_code !== 0) {
            throw new \RuntimeException('Deployment failed (exit code ' . $return_code . ')');
        }
        return;
    }

    $this->display_log_and_exit('Deployment Log');
}

    public function handle_generate_and_deploy() {
        $this->log_title_override = 'Generate & Deploy Log';
        // Step 1: generate without redirecting (chain mode). Nonce is checked inside handle_generate.
        $this->handle_generate(true);
        // Step 2: continue into deploy. handle_deploy ends with display_log_and_exit(),
        // which will include all log entries accumulated above.
        $this->handle_deploy();
    }

    /**
     * Return saved schedule settings merged with defaults.
     */
    private function get_schedule() {
        $defaults = [
            'enabled'          => false,
            'mode'             => 'generate_and_deploy',
            'schedule_type'    => 'interval',
            'interval_hours'   => 24,
            'interval_minutes' => 0,
            'daily_time'       => '03:00',
            'last_run'         => 0,
            'last_status'      => '',
        ];
        $saved = get_option($this->schedule_option, []);
        if (!is_array($saved)) $saved = [];
        return array_merge($defaults, $saved);
    }

    /**
     * Compute the next run timestamp (UTC unix) for the given schedule.
     */
    private function compute_next_run($schedule) {
        $now = time();

        if ($schedule['schedule_type'] === 'daily') {
            $tz = wp_timezone();
            $time_parts = explode(':', $schedule['daily_time']);
            $hh = isset($time_parts[0]) ? max(0, min(23, (int)$time_parts[0])) : 3;
            $mm = isset($time_parts[1]) ? max(0, min(59, (int)$time_parts[1])) : 0;

            $today = new DateTime('now', $tz);
            $today->setTime($hh, $mm, 0);
            $next_ts = $today->getTimestamp();
            if ($next_ts <= $now) {
                $today->modify('+1 day');
                $next_ts = $today->getTimestamp();
            }
            return $next_ts;
        }

        // interval
        $h = (int)$schedule['interval_hours'];
        $m = (int)$schedule['interval_minutes'];
        $seconds = ($h * 3600) + ($m * 60);
        if ($seconds < 60) $seconds = 60; // safety floor: 1 minute
        return $now + $seconds;
    }

    /**
     * Unschedule any pending cf_static_scheduled_run events.
     */
    private function unschedule_all() {
        while ($ts = wp_next_scheduled($this->cron_hook)) {
            wp_unschedule_event($ts, $this->cron_hook);
        }
    }

    /**
     * Reschedule the next single cron event based on current saved schedule.
     */
    private function reschedule_next($schedule = null) {
        if ($schedule === null) $schedule = $this->get_schedule();
        $this->unschedule_all();
        if (!empty($schedule['enabled'])) {
            $next = $this->compute_next_run($schedule);
            wp_schedule_single_event($next, $this->cron_hook);
        }
    }

    /**
     * Handle POST from the schedule form. Saves settings and toggles cron.
     */
    public function handle_save_schedule() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (!isset($_POST['cf_static_schedule_nonce']) ||
            !wp_verify_nonce($_POST['cf_static_schedule_nonce'], 'cf_static_schedule_nonce')) {
            wp_die('Nonce failed');
        }

        $current = $this->get_schedule();

        $enabled = !empty($_POST['schedule_enabled']) && $_POST['schedule_enabled'] === '1';

        $mode = sanitize_text_field($_POST['schedule_mode'] ?? 'generate_and_deploy');
        $allowed_modes = ['generate', 'deploy', 'generate_and_deploy'];
        if (!in_array($mode, $allowed_modes, true)) $mode = 'generate_and_deploy';

        $schedule_type = sanitize_text_field($_POST['schedule_type'] ?? 'interval');
        if (!in_array($schedule_type, ['interval', 'daily'], true)) $schedule_type = 'interval';

        $interval_hours   = isset($_POST['interval_hours'])   ? max(0, min(999, (int)$_POST['interval_hours']))   : 24;
        $interval_minutes = isset($_POST['interval_minutes']) ? max(0, min(59,  (int)$_POST['interval_minutes'])) : 0;

        // For interval, refuse 0/0 — fall back to 24h.
        if ($schedule_type === 'interval' && $interval_hours === 0 && $interval_minutes === 0) {
            $interval_hours = 24;
        }

        $daily_time_raw = sanitize_text_field($_POST['daily_time'] ?? '03:00');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $daily_time_raw)) {
            $daily_time_raw = '03:00';
        }
        [$dh, $dm] = array_pad(explode(':', $daily_time_raw), 2, '00');
        $daily_time = sprintf('%02d:%02d', max(0, min(23, (int)$dh)), max(0, min(59, (int)$dm)));

        $new = [
            'enabled'          => $enabled,
            'mode'             => $mode,
            'schedule_type'    => $schedule_type,
            'interval_hours'   => $interval_hours,
            'interval_minutes' => $interval_minutes,
            'daily_time'       => $daily_time,
            'last_run'         => (int)($current['last_run'] ?? 0),
            'last_status'      => (string)($current['last_status'] ?? ''),
        ];

        update_option($this->schedule_option, $new);

        // (Re)schedule or clear the cron event.
        $this->reschedule_next($new);

        $msg = $enabled
            ? 'Auto generate/deploy enabled and schedule saved.'
            : 'Auto generate/deploy disabled and schedule saved.';

        wp_redirect(admin_url('admin.php?page=cf-static&message=' . urlencode($msg)));
        exit;
    }

    /**
     * WP-Cron entrypoint.
     */
    public function run_scheduled_task() {
        $schedule = $this->get_schedule();

        // If disabled, do nothing and ensure no further events are scheduled.
        if (empty($schedule['enabled'])) {
            $this->unschedule_all();
            return;
        }

        $this->cron_mode = true;
        $this->log_title_override = 'Scheduled Run Log';
        $status = 'success';

        try {
            switch ($schedule['mode']) {
                case 'generate':
                    $this->handle_generate(false);
                    $status = 'generate: success';
                    break;
                case 'deploy':
                    $this->handle_deploy();
                    $status = 'deploy: success';
                    break;
                case 'generate_and_deploy':
                default:
                    $this->handle_generate(true);
                    $this->handle_deploy();
                    $status = 'generate+deploy: success';
                    break;
            }
        } catch (\Throwable $e) {
            $status = 'failed: ' . $e->getMessage();
            error_log('[cf-static scheduled run] ' . $e->getMessage());
            if (!empty($this->log)) {
                error_log('[cf-static scheduled run] log: ' . implode(' | ', array_slice($this->log, -10)));
            }
        }

        // Update last_run + last_status. Re-read fresh in case the user changed
        // settings during the run, and only overwrite the bookkeeping fields so
        // we don't clobber the user's latest choices.
        $schedule_now = $this->get_schedule();
        $schedule_now['last_run']    = time();
        $schedule_now['last_status'] = $status;
        update_option($this->schedule_option, $schedule_now);

        $this->cron_mode = false;
        $this->reschedule_next($schedule_now);
    }

    private function crawl_assets($html, $output_dir) {
        preg_match_all('/(src|href)=["\']([^"\']+)["\']/i', $html, $assets);
        $urls = $assets[2];

        preg_match_all('/srcset=["\']([^"\']+)["\']/i', $html, $srcsets);
        foreach ($srcsets[1] as $set) {
            foreach (explode(',', $set) as $part) {
                $urls[] = trim(explode(' ', trim($part))[0]);
            }
        }

        foreach ($urls as $asset) {
            // Strip query string and fragment before using the URL for
            // filesystem paths. Cache-busters like "?t=12345" or "?ver=6.4"
            // produce invalid/awkward filenames (and the static host serves
            // the same file regardless of those params anyway).
            $asset_clean = strtok($asset, '?');
            if ($asset_clean === false) $asset_clean = $asset;
            $hash_pos = strpos($asset_clean, '#');
            if ($hash_pos !== false) {
                $asset_clean = substr($asset_clean, 0, $hash_pos);
            }
            if ($asset_clean === '') continue;

            $asset_trim = ltrim($asset_clean, '/');

            if (preg_match('#^(cdn-cgi|comments|feed|wp-json)/#', $asset_trim) || 
                preg_match('#^xmlrpc\.php$#', $asset_trim)) {
                continue;
            }

            if (preg_match('#^wp-content/(uploads|themes|plugins)/#', $asset_trim)) {
                $src = rtrim($this->site_url, '/') . '/' . $asset_trim;
                $dst = $output_dir . '/' . $asset_trim;

                if (!file_exists(dirname($dst))) mkdir(dirname($dst), 0755, true);
                if (!file_exists($dst)) {
                    $data = $this->fetch_url($src);
                    if ($data) file_put_contents($dst, $data);
                }
            }
        }
    }

    private function copy_core_and_plugin_js($output_dir, $selected_plugins) {
        $jquery_files = [
            ABSPATH . 'wp-includes/js/jquery/jquery.min.js',
            ABSPATH . 'wp-includes/js/jquery/jquery.js',
            ABSPATH . 'wp-includes/js/jquery/jquery-migrate.min.js',
            ABSPATH . 'wp-includes/js/jquery/jquery-migrate.js',
        ];

        foreach ($jquery_files as $file) {
            if (file_exists($file)) {
                $rel_path = str_replace(ABSPATH, '', $file);
                $dst = $output_dir . '/' . $rel_path;
                if (!file_exists(dirname($dst))) mkdir(dirname($dst), 0755, true);
                copy($file, $dst);
            }
        }

        if ($selected_plugins) {
            $plugin_folders = array_map(fn($file) => WP_CONTENT_DIR . '/plugins/' . dirname($file), $selected_plugins);
            $plugin_folders = array_unique($plugin_folders);

            foreach ($plugin_folders as $plugin) {
                $dist_frontend = $plugin . '/dist/frontend';
                if (file_exists($dist_frontend) && stripos($dist_frontend, 'admin') === false) {
                    $rel = str_replace(WP_CONTENT_DIR, '', $dist_frontend);
                    $dst_dir = $output_dir . '/wp-content' . $rel;
                    $this->copy_directory($dist_frontend, $dst_dir);
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($plugin, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    $pathname = $item->getPathname();
                    $filename = $item->getFilename();

                    if (stripos($pathname, 'admin') !== false) continue;

                    if ($item->isDir() && stripos($filename, 'js') !== false) {
                        $src = $pathname;
                        $rel = str_replace(WP_CONTENT_DIR, '', $src);
                        $dst_dir = $output_dir . '/wp-content' . $rel;
                        $this->copy_directory($src, $dst_dir);
                    } elseif ($item->isFile() && substr($filename, -3) === '.js') {
                        $src = $pathname;
                        $rel = str_replace(WP_CONTENT_DIR, '', $src);
                        $dst_file = $output_dir . '/wp-content' . $rel;
                        if (!file_exists(dirname($dst_file))) mkdir(dirname($dst_file), 0755, true);
                        copy($src, $dst_file);
                    }
                }
            }
        }
    }

    private function copy_directory($src, $dst) {
        $dir = opendir($src);
        if (!file_exists($dst)) mkdir($dst, 0755, true);
        while(false !== ($file = readdir($dir))) {
            if ($file == '.' || $file == '..') continue;
            $src_file = $src . '/' . $file;
            $dst_file = $dst . '/' . $file;
            if (is_dir($src_file)) {
                $this->copy_directory($src_file, $dst_file);
            } else {
                copy($src_file, $dst_file);
            }
        }
        closedir($dir);
    }

    private function sanitize_admin_js($dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && strtolower(substr($item->getFilename(), -3)) === '.js') {
                if (stripos($item->getFilename(), 'admin') !== false) {
                    unlink($item->getPathname());
                    $this->log[] = "Removed admin JS: " . $item->getPathname();
                }
            }
        }
    }

    private function authenticate_cf($id, $secret) {
        $ch = curl_init($this->site_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_HTTPHEADER     => [
                "CF-Access-Client-Id: $id",
                "CF-Access-Client-Secret: $secret"
            ]
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        if (preg_match('/CF_Authorization=([^;]+)/', $res, $m)) {
            return "CF_Authorization={$m[1]}";
        }
        return false;
    }

    private function fetch_url($url) {
        // Append a unique cache-busting param to the request URL so no cache
        // layer can return a stale copy. This affects ONLY the request — callers
        // compute filenames from the original (cleaned) URL, never from this.
        $fetch_url = $url;
        if (!empty($this->cache_bust)) {
            $sep = (strpos($fetch_url, '?') !== false) ? '&' : '?';
            $fetch_url .= $sep . $this->cache_bust . '=1';
        }

        $ch = curl_init($fetch_url);

        // Force every request to bypass caches (browser-style cache directives
        // plus a unique query param) so we always pull the freshest asset/page.
        $headers = [
            'Cache-Control: no-cache, no-store, max-age=0',
            'Pragma: no-cache',
        ];
        if (!empty($this->cf_cookie)) {
            $headers[] = "Cookie: {$this->cf_cookie}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FRESH_CONNECT  => true, // don't reuse a cached connection
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        return curl_exec($ch);
    }

    private function zip_directory($src, $zip_file) {
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            $zip->addFile($file, substr($file, strlen($src) + 1));
        }
        $zip->close();
    }

    private function generate_404($dir, $path) {

        $path = '/' . ltrim($path, '/');
        $url  = rtrim($this->site_url, '/') . $path;

        $this->log[] = "Generating 404.html from: $url";

        $html = $this->fetch_url($url);

        if (!$html) {
            $this->log[] = "Failed to fetch content for 404 from: $url";
            return;
        }

        // Make URLs relative (same behavior as crawler)
        $html = str_replace($this->site_url, '', $html);

        file_put_contents("$dir/404.html", $html);

        // Also crawl assets from this page
        $this->crawl_assets($html, $dir);

        $this->log[] = "404.html generated successfully from $path.";
    }

    private function display_log_and_exit($title = 'Log') {
        if (!empty($this->log_title_override)) {
            $title = $this->log_title_override;
        }
        // Render the log inside the standard WP admin chrome so it appears
        // on the same page (no blank plaintext dump).
        if (!function_exists('require_wp_admin_header')) {
            require_once ABSPATH . 'wp-admin/admin-header.php';
        }
        ?>
        <div class="wrap">
            <h1>Cloudflare Access-Friendly Static Page Generator</h1>
            <div class="cf-static-log-panel">
                <div class="cf-static-log-header">
                    <span class="cf-static-log-title"><?php echo esc_html($title); ?></span>
                    <button type="button" class="button button-small cf-static-log-copy" onclick="cfStaticCopyLog(this)">Copy</button>
                </div>
                <pre class="cf-static-log-body" id="cf-static-log-body"><?php
                    foreach ($this->log as $line) {
                        $s = (string)$line;
                        $cls = '';
                        if (preg_match('/(error|fail|failed|unauthorized|denied|EACCES)/i', $s)) {
                            $cls = 'cf-static-log-line-error';
                        } elseif (preg_match('/(success|completed|deployed|generated)/i', $s)) {
                            $cls = 'cf-static-log-line-success';
                        } elseif (preg_match('/^(running|fetching|crawl|generating|copying|zipping|removing|using)/i', trim($s))) {
                            $cls = 'cf-static-log-line-info';
                        }
                        echo '<span class="cf-static-log-line ' . esc_attr($cls) . '">' . esc_html($s) . "</span>\n";
                    }
                ?></pre>
            </div>
            <p style="margin-top:14px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=cf-static')); ?>" class="button button-primary">← Back to CF Static Generator</a>
            </p>
        </div>
        <style>
            .cf-static-log-panel {
                margin-top: 16px;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                background: #1e1e1e;
                overflow: hidden;
                max-width: 1000px;
            }
            .cf-static-log-header {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 12px;
                background: #2c2c2c;
                border-bottom: 1px solid #3a3a3a;
            }
            .cf-static-log-title {
                color: #e0e0e0;
                font-weight: 600;
                font-size: 13px;
            }
            .cf-static-log-copy { margin-left: auto !important; }
            .cf-static-log-body {
                margin: 0;
                padding: 12px 14px;
                background: #1e1e1e;
                color: #d4d4d4;
                font-family: Menlo, Consolas, "Courier New", monospace;
                font-size: 12px;
                line-height: 1.5;
                max-height: 520px;
                overflow: auto;
                white-space: pre-wrap;
                word-break: break-word;
            }
            .cf-static-log-line { display: block; }
            .cf-static-log-line-error   { color: #f48771; }
            .cf-static-log-line-success { color: #6ad36a; }
            .cf-static-log-line-info    { color: #6fb3d2; }
        </style>
        <script>
            function cfStaticCopyLog(btn) {
                var body = document.getElementById('cf-static-log-body');
                if (!body) return;
                var text = body.innerText;
                var done = function(){
                    var orig = btn.innerText;
                    btn.innerText = 'Copied!';
                    setTimeout(function(){ btn.innerText = orig; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch(e){}
                    document.body.removeChild(ta);
                    done();
                }
            }
        </script>
        <?php
        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }
}

new CFStatic();

// Clear scheduled cron events when the plugin is deactivated.
register_deactivation_hook(__FILE__, function () {
    while ($ts = wp_next_scheduled('cf_static_scheduled_run')) {
        wp_unschedule_event($ts, 'cf_static_scheduled_run');
    }
});
