<?php
/**
 * Plugin Name: Cloudflare Access-Friendly Static Page Generator
 * Description: Generate a static version of your WordPress site, bypassing Cloudflare Access via service tokens. If wrangler CLI is installed, you can also push to Pages with your API token.
 * Version: 1.0.0
 * Author: skuntank.dev
 * Author URI: https://skuntank.dev
 * Plugin URI: https://github.com/skuntank-dev/cf-static/
 */

if (!defined('ABSPATH')) exit;

define('CF_STATIC_GITHUB_REPO', 'skuntank-dev/cf-static');

class CFStatic {

    private $option_name = 'cf_static_tokens';
    private $selected_plugins_option = 'cf_static_selected_plugins';
    private $log = [];
    private $site_url;
    private $cf_cookie = '';

    private $plugin_dir;
    private $plugin_url;
    private $last_zip_url = '';

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
        <input type="checkbox" name="generate_404" value="1" <?php checked($generate_404_checked); ?>>
        Generate 404.html
    </label>
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
?>

<p>
    <button type="submit" name="action" value="cf_static_deploy" class="button button-primary" <?php echo $disable_deploy; ?>>Deploy to Cloudflare Pages</button>
</p>

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

    public function handle_generate() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (!wp_verify_nonce($_POST['cf_static_nonce'], 'cf_static_generate_nonce')) wp_die('Nonce failed');

        $client_id     = sanitize_text_field($_POST['client_id']);
        $client_secret = sanitize_text_field($_POST['client_secret']);
$remember_cf = !empty($_POST['remember_cf']);
update_option('cf_static_remember_cf', $remember_cf);

$generate_404  = !empty($_POST['generate_404']);
update_option('cf_static_generate_404', $generate_404);

        $selected_plugins = !empty($_POST['selected_plugins']) ? array_map('sanitize_text_field', $_POST['selected_plugins']) : [];

if ($remember_cf) {
    update_option($this->option_name, compact('client_id', 'client_secret'));
} else {
    update_option($this->option_name, ['client_id' => '', 'client_secret' => '']);
}

        update_option($this->selected_plugins_option, $selected_plugins);

        $output_dir = $this->plugin_dir . 'static';
        if (!file_exists($output_dir)) mkdir($output_dir, 0755, true);

// Only authenticate with Cloudflare Access if BOTH fields are provided
if (!empty($client_id) && !empty($client_secret)) {
    $this->cf_cookie = $this->authenticate_cf($client_id, $client_secret);
    if (!$this->cf_cookie) {
        $this->log[] = 'Cloudflare authentication failed';
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

        if ($generate_404) $this->generate_404($output_dir);

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

        wp_redirect(admin_url('admin.php?page=cf-static&message=Static+site+generated'));
        exit;
    }
public function handle_deploy() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    
    // Save/update Wrangler config
    $cf_pages_options = [
        'project_name' => sanitize_text_field($_POST['cf_pages_project_name'] ?? ''),
        'branch'       => sanitize_text_field($_POST['cf_pages_branch'] ?? 'main'),
        'account_id'   => sanitize_text_field($_POST['cf_pages_account_id'] ?? ''),
        'api_token'    => sanitize_text_field($_POST['cf_pages_api_token'] ?? ''),
    ];
$remember_pages = !empty($_POST['remember_pages']);
update_option('cf_static_remember_pages', $remember_pages);

// Always deploy using submitted credentials
// Persistence decision happens AFTER deploy


// Check Wrangler installation
exec('wrangler --version', $output, $return_var);
if ($return_var !== 0) {
    $this->log[] = "Wrangler CLI not found. Please install Wrangler to enable deployment.";
    $this->display_log_and_exit(); // stops execution and shows log
}


    $output_dir = $this->plugin_dir . 'static';
    if (!file_exists($output_dir)) {
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

    $message = $return_code === 0 ? 'Deployment completed successfully!' : 'Deployment failed. Check logs below.';
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


    // Show log on page
    add_action('admin_notices', function() use ($message) {
        echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
    });

    $this->display_log_and_exit();
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
            $asset_trim = ltrim($asset, '/');

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
        $ch = curl_init($url);
$headers = [];
if (!empty($this->cf_cookie)) {
    $headers[] = "Cookie: {$this->cf_cookie}";
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => $headers
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

    private function generate_404($dir) {
        $html = '';
        if (function_exists('get_404_template')) {
            $template = get_404_template();
            if ($template) {
                ob_start();
                include $template;
                $html = ob_get_clean();
            }
        }

        if ($html) {
            file_put_contents("$dir/404.html", $html);
            $this->log[] = "404.html generated successfully.";
        } else {
            $this->log[] = "Failed to generate 404.html.";
        }
    }

    private function display_log_and_exit() {
        echo '<ul>';
        foreach ($this->log as $l) echo '<li>' . esc_html($l) . '</li>';
        echo '</ul>';
        exit;
    }
}

new CFStatic();
