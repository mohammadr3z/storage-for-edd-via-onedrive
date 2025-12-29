<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OneDrive Admin Settings
 * 
 * Integrates OneDrive configuration with EDD settings panel
 * and handles OAuth2 authorization flow.
 */
class ODSE_Admin_Settings
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new ODSE_OneDrive_Config();
        $this->client = new ODSE_OneDrive_Client();

        // Register EDD settings
        add_filter('edd_settings_extensions', array($this, 'addSettings'));
        add_filter('edd_settings_sections_extensions', array($this, 'registerSection'));

        // Enqueue admin scripts/styles
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));

        // OAuth callback handler (legacy admin-post handler)
        add_action('admin_post_odse_oauth_start', array($this, 'startOAuthFlow'));
        add_action('admin_post_odse_disconnect', array($this, 'handleDisconnect'));

        // Register query vars
        add_filter('query_vars', array($this, 'addQueryVars'));

        // Register clean OAuth callback endpoint (no query strings for Azure compatibility)
        add_action('init', array($this, 'registerOAuthEndpoint'));
        add_action('template_redirect', array($this, 'handleOAuthEndpoint'));

        // Auto-flush rewrite rules if version changed
        add_action('init', array($this, 'maybeFlushRewriteRules'), 99);

        // Admin notices
        add_action('admin_notices', array($this, 'showAdminNotices'));

        // Clear tokens when Client ID or Client Secret changes
        add_filter('pre_update_option_edd_settings', array($this, 'checkCredentialsChange'), 10, 2);
    }

    /**
     * Add query variables
     * 
     * @param array $vars
     * @return array
     */
    public function addQueryVars($vars)
    {
        $vars[] = 'odse_oauth_callback';
        return $vars;
    }

    /**
     * Flush rewrite rules if plugin version changed
     */
    public function maybeFlushRewriteRules()
    {
        $current_version = ODSE_VERSION;
        $saved_version = get_option('odse_rewrite_version');

        if ($saved_version !== $current_version) {
            $this->registerOAuthEndpoint(); // Ensure rules are added before flushing
            flush_rewrite_rules();
            update_option('odse_rewrite_version', $current_version);
        }
    }

    /**
     * Register OAuth callback endpoint rewrite rule
     */
    public function registerOAuthEndpoint()
    {
        add_rewrite_rule(
            '^odse-oauth-callback/?$',
            'index.php?odse_oauth_callback=1',
            'top'
        );
        add_rewrite_tag('%odse_oauth_callback%', '1');
    }

    /**
     * Handle OAuth callback at custom endpoint
     */
    public function handleOAuthEndpoint()
    {
        if (get_query_var('odse_oauth_callback')) {
            $this->handleOAuthCallback();
            exit;
        }
    }

    /**
     * Flush rewrite rules on plugin activation
     */
    public static function activatePlugin()
    {
        // Register the endpoint first
        add_rewrite_rule(
            '^odse-oauth-callback/?$',
            'index.php?odse_oauth_callback=1',
            'top'
        );
        add_rewrite_tag('%odse_oauth_callback%', '1');

        // Flush to apply the new rule
        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules on plugin deactivation
     */
    public static function deactivatePlugin()
    {
        flush_rewrite_rules();
    }

    /**
     * Check if Client ID or Client Secret has changed and clear tokens if so
     * 
     * @param array $new_value New settings value
     * @param array $old_value Old settings value
     * @return array
     */
    public function checkCredentialsChange($new_value, $old_value)
    {
        $client_id_field = ODSE_OneDrive_Config::KEY_CLIENT_ID;
        $client_secret_field = ODSE_OneDrive_Config::KEY_CLIENT_SECRET;

        $old_id = isset($old_value[$client_id_field]) ? $old_value[$client_id_field] : '';
        $new_id = isset($new_value[$client_id_field]) ? $new_value[$client_id_field] : '';

        $old_secret = isset($old_value[$client_secret_field]) ? $old_value[$client_secret_field] : '';
        $new_secret = isset($new_value[$client_secret_field]) ? $new_value[$client_secret_field] : '';

        // If Client ID or Client Secret changed, clear tokens
        if ($old_id !== $new_id || $old_secret !== $new_secret) {
            $this->config->clearTokens();
        }

        return $new_value;
    }

    /**
     * Add settings to EDD Extensions tab
     * 
     * @param array $settings
     * @return array
     */
    public function addSettings($settings)
    {
        $is_connected = $this->config->isConnected();

        // Check if we are on the EDD extensions settings page
        // This prevents API calls on every admin page load
        // Load folders if: tab=extensions AND (no section OR section is ours)
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only comparison against hardcoded strings for page detection, no data processing.
        $is_settings_page = is_admin() &&
            isset($_GET['page']) && $_GET['page'] === 'edd-settings' &&
            isset($_GET['tab']) && $_GET['tab'] === 'extensions' &&
            (!isset($_GET['section']) || $_GET['section'] === 'odse-settings');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Build folder options first to check permissions
        $folder_options = array('' => __('Root folder', 'storage-for-edd-via-onedrive'));
        $has_permission_error = false;
        if ($is_connected && $is_settings_page) {
            try {
                $folders = $this->client->listFolders('');
                if (empty($folders)) {
                    // Empty folders is OK - might just be empty OneDrive
                } else {
                    $folder_options = array_merge($folder_options, $folders);
                }
            } catch (Exception $e) {
                $has_permission_error = true;
                $this->config->debug('Error loading folders: ' . $e->getMessage());
            }
        } elseif ($is_connected && !$is_settings_page) {
            // If connected but not on settings page, preserve the currently saved value in the dropdown options
            // so it doesn't look empty if accessed via other means (though rare)
            $saved_folder = $this->config->getSelectedFolder();
            if (!empty($saved_folder)) {
                $folder_options[$saved_folder] = $saved_folder;
            }
        }

        // Build connect/disconnect button HTML
        $connect_button = '';
        if ($is_connected) {
            $disconnect_url = wp_nonce_url(
                admin_url('admin-post.php?action=odse_disconnect'),
                'odse_disconnect'
            );

            // Different status display based on permissions
            if ($has_permission_error) {
                // Yellow/warning status when connected but permissions missing
                $connect_button = sprintf(
                    '<span class="odse-warning-status">%s</span><br><br><span class="odse-permission-warning">%s</span><br><br><a href="%s" class="button button-secondary">%s</a>',
                    esc_html__('Permissions Not Active', 'storage-for-edd-via-onedrive'),
                    esc_html__('Required permissions are not enabled. Please disconnect, enable Files.Read and Files.ReadWrite in your Azure app, then reconnect.', 'storage-for-edd-via-onedrive'),
                    esc_url($disconnect_url),
                    esc_html__('Disconnect from OneDrive', 'storage-for-edd-via-onedrive')
                );
            } else {
                // Green status when fully connected
                $connect_button = sprintf(
                    '<span class="odse-connected-status">%s</span><br><br><a href="%s" class="button button-secondary">%s</a>',
                    esc_html__('Connected', 'storage-for-edd-via-onedrive'),
                    esc_url($disconnect_url),
                    esc_html__('Disconnect from OneDrive', 'storage-for-edd-via-onedrive')
                );
            }
        } elseif ($this->config->hasAppCredentials()) {
            $connect_url = wp_nonce_url(
                admin_url('admin-post.php?action=odse_oauth_start'),
                'odse_oauth_start'
            );
            $connect_button = sprintf(
                '<a href="%s" class="button button-primary">%s</a>',
                esc_url($connect_url),
                esc_html__('Connect to OneDrive', 'storage-for-edd-via-onedrive')
            );
        } else {
            $connect_button = '<span class="odse-notice">' . esc_html__('Please enter your Client ID and Client Secret first, then save settings.', 'storage-for-edd-via-onedrive') . '</span>';
        }

        $odse_settings = array(
            array(
                'id' => 'odse_settings',
                'name' => '<strong>' . __('OneDrive Storage Settings', 'storage-for-edd-via-onedrive') . '</strong>',
                'type' => 'header'
            ),
            array(
                'id' => ODSE_OneDrive_Config::KEY_CLIENT_ID,
                'name' => __('Client ID', 'storage-for-edd-via-onedrive'),
                'desc' => __('Enter your Azure AD Application (client) ID.', 'storage-for-edd-via-onedrive'),
                'type' => 'text',
                'size' => 'regular',
                'class' => 'odse-credential'
            ),
            array(
                'id' => ODSE_OneDrive_Config::KEY_CLIENT_SECRET,
                'name' => __('Client Secret', 'storage-for-edd-via-onedrive'),
                'desc' => __('Enter your Azure AD Client Secret.', 'storage-for-edd-via-onedrive'),
                'type' => 'password',
                'size' => 'regular',
                'class' => 'odse-credential'
            ),
            array(
                'id' => 'odse_connection',
                'name' => __('Connection Status', 'storage-for-edd-via-onedrive'),
                'desc' => $connect_button,
                'type' => 'descriptive_text'
            ),
            array(
                'id' => ODSE_OneDrive_Config::KEY_FOLDER,
                'name' => __('Default Folder', 'storage-for-edd-via-onedrive'),
                'desc' => $is_connected
                    ? __('Select the default folder for uploads.', 'storage-for-edd-via-onedrive')
                    : __('Connect to OneDrive first to select a folder.', 'storage-for-edd-via-onedrive'),
                'type' => 'select',
                'options' => $folder_options,
                'std' => '',
                'class' => $is_connected ? '' : 'odse-disabled'
            ),
            array(
                'id' => 'odse_help',
                'name' => __('Setup Instructions', 'storage-for-edd-via-onedrive'),
                'desc' => sprintf(
                    '<ol>
                        <li>%s <a href="https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank">%s</a></li>
                        <li>%s</li>
                        <li><strong>%s</strong>
                            <ul style="margin-top:5px;margin-left:20px;">
                                <li><code>Files.Read</code></li>
                                <li><code>Files.ReadWrite</code></li>
                                <li><code>Files.Read.All</code></li>
                                <li><code>Files.ReadWrite.All</code></li>
                                <li><code>User.Read</code></li>
                                <li><code>offline_access</code></li>
                            </ul>
                        </li>
                        <li>%s <code>%s</code></li>
                        <li>%s</li>
                        <li>%s</li>
                    </ol>',
                    __('Go to', 'storage-for-edd-via-onedrive'),
                    __('Azure App Registrations', 'storage-for-edd-via-onedrive'),
                    __('Create a new registration with "Accounts in any organizational directory and personal Microsoft accounts".', 'storage-for-edd-via-onedrive'),
                    __('Add these API permissions (Microsoft Graph - Delegated):', 'storage-for-edd-via-onedrive'),
                    __('Add this Redirect URI (Web platform):', 'storage-for-edd-via-onedrive'),
                    esc_html($this->getRedirectUri()),
                    __('Create a Client Secret in "Certificates & secrets" section.', 'storage-for-edd-via-onedrive'),
                    __('Copy the Application (client) ID and Client Secret value, then paste them above.', 'storage-for-edd-via-onedrive')
                ),
                'type' => 'descriptive_text'
            ),
        );

        return array_merge($settings, array('odse-settings' => $odse_settings));
    }

    /**
     * Register settings section
     * 
     * @param array $sections
     * @return array
     */
    public function registerSection($sections)
    {
        $sections['odse-settings'] = __('OneDrive Storage', 'storage-for-edd-via-onedrive');
        return $sections;
    }

    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook
     */
    public function enqueueAdminScripts($hook)
    {
        if ($hook !== 'download_page_edd-settings') {
            return;
        }

        wp_enqueue_script('jquery');

        wp_register_style('odse-admin-settings', ODSE_PLUGIN_URL . 'assets/css/admin-settings.css', array(), ODSE_VERSION);
        wp_enqueue_style('odse-admin-settings');

        wp_register_script('odse-admin-settings', ODSE_PLUGIN_URL . 'assets/js/admin-settings.js', array('jquery'), ODSE_VERSION, true);
        wp_enqueue_script('odse-admin-settings');
    }

    /**
     * Get OAuth redirect URI
     * 
     * @return string
     */
    private function getRedirectUri()
    {
        // Use clean endpoint without query strings for Azure compatibility
        return home_url('/odse-oauth-callback/');
    }

    /**
     * Start OAuth authorization flow
     */
    public function startOAuthFlow()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'storage-for-edd-via-onedrive'));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'odse_oauth_start')) {
            wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-onedrive'));
        }

        if (!$this->config->hasAppCredentials()) {
            wp_safe_redirect(add_query_arg('odse_error', 'no_credentials', wp_get_referer()));
            exit;
        }

        // Store state for security
        $state = wp_create_nonce('odse_oauth_state');
        set_transient('odse_oauth_state_' . get_current_user_id(), $state, 600);

        $auth_url = $this->client->getAuthorizationUrl($this->getRedirectUri());
        $auth_url .= '&state=' . $state;

        add_filter('allowed_redirect_hosts', function ($hosts) {
            $hosts[] = 'login.microsoftonline.com';
            return $hosts;
        });

        wp_safe_redirect($auth_url);
        exit;
    }

    /**
     * Handle OAuth callback from Microsoft
     */
    public function handleOAuthCallback()
    {
        // Debug logging
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback uses state parameter verification for CSRF protection instead of nonces.
        $this->config->debug('OAuth callback triggered');

        // Check if user is logged in (frontend callback)
        if (!is_user_logged_in()) {
            $this->config->debug('User not logged in');
            $this->redirectWithError('not_logged_in');
            return;
        }

        if (!current_user_can('manage_options')) {
            $this->config->debug('User cannot manage options');
            wp_die(esc_html__('Unauthorized', 'storage-for-edd-via-onedrive'));
        }

        // Verify state
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth uses state parameter as CSRF protection; properly sanitized.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $stored_state = get_transient('odse_oauth_state_' . get_current_user_id());

        $this->config->debug('State from URL: ' . $state);
        $this->config->debug('Stored state: ' . ($stored_state ? $stored_state : 'null'));

        delete_transient('odse_oauth_state_' . get_current_user_id());

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- State is compared with stored transient value, this is OAuth CSRF protection.
        if (empty($state) || $state !== $stored_state) {
            $this->config->debug('State mismatch - invalid_state error');
            $this->redirectWithError('invalid_state');
            return;
        }

        // Check for error from Microsoft
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback uses state parameter verification above instead of nonces.
        if (isset($_GET['error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth callback uses state parameter verification instead of nonces.
            $error = sanitize_text_field(wp_unslash($_GET['error']));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth callback uses state parameter verification instead of nonces.
            $error_desc = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : '';
            $this->config->debug('Microsoft error: ' . $error . ' - ' . $error_desc);
            $this->redirectWithError($error);
            return;
        }

        // Get authorization code
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth callback uses state parameter verification instead of nonces.
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            $this->config->debug('No authorization code received');
            $this->redirectWithError('no_code');
            return;
        }

        $this->config->debug('Got authorization code, exchanging for token...');

        // Exchange code for token
        $tokens = $this->client->exchangeCodeForToken($code, $this->getRedirectUri());
        if (!$tokens) {
            $this->config->debug('Token exchange failed');
            $this->redirectWithError('token_exchange_failed');
            return;
        }

        $this->config->debug('Token exchange successful');

        // Save tokens
        $this->config->saveTokens(
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['expires_in']
        );

        $this->config->debug('Tokens saved, redirecting to settings...');

        // Redirect back to settings with success message
        $redirect = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings&odse_connected=1');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle disconnect request
     */
    public function handleDisconnect()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'storage-for-edd-via-onedrive'));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'odse_disconnect')) {
            wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-onedrive'));
        }

        $this->config->clearTokens();

        $redirect = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings&odse_disconnected=1');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Redirect to settings with error
     * 
     * @param string $error
     */
    private function redirectWithError($error)
    {
        $redirect = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings&odse_error=' . urlencode($error));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices()
    {
        // Success: Connected
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check for admin notice, set by OAuth redirect with proper nonce verification in handleOAuthCallback().
        if (isset($_GET['odse_connected'])) {
?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Successfully connected to OneDrive!', 'storage-for-edd-via-onedrive'); ?></p>
            </div>
        <?php
        }

        // Success: Disconnected
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check for admin notice, set by handleDisconnect() with proper nonce verification.
        if (isset($_GET['odse_disconnected'])) {
        ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Disconnected from OneDrive.', 'storage-for-edd-via-onedrive'); ?></p>
            </div>
        <?php
        }

        // Error messages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check, error codes are validated against hardcoded array and never echoed directly.
        if (isset($_GET['odse_error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized with sanitize_text_field, used as lookup key only.
            $error = sanitize_text_field(wp_unslash($_GET['odse_error']));
            $messages = array(
                'no_credentials' => __('Please enter your Client ID and Client Secret first.', 'storage-for-edd-via-onedrive'),
                'invalid_state' => __('OAuth security check failed. Please try again.', 'storage-for-edd-via-onedrive'),
                'no_code' => __('No authorization code received from Microsoft.', 'storage-for-edd-via-onedrive'),
                'token_exchange_failed' => __('Failed to exchange authorization code for access token.', 'storage-for-edd-via-onedrive'),
                'access_denied' => __('Access was denied by the user.', 'storage-for-edd-via-onedrive'),
                'not_logged_in' => __('You must be logged in to WordPress to complete OAuth authorization.', 'storage-for-edd-via-onedrive'),
            );
            $message = isset($messages[$error]) ? $messages[$error] : __('An error occurred during authorization.', 'storage-for-edd-via-onedrive');
        ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
<?php
        }
    }
}
