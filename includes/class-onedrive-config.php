<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OneDrive Configuration Manager
 * 
 * Handles all OneDrive API configuration including OAuth2 tokens,
 * app credentials, and folder settings.
 */
class ODSE_OneDrive_Config
{
    // Option keys for storing configuration in WordPress database
    const KEY_CLIENT_ID = 'odse_client_id';
    const KEY_CLIENT_SECRET = 'odse_client_secret';
    const KEY_ACCESS_TOKEN = 'odse_access_token';
    const KEY_REFRESH_TOKEN = 'odse_refresh_token';
    const KEY_TOKEN_EXPIRY = 'odse_token_expiry';
    const KEY_FOLDER = 'odse_folder';

    // URL prefix for OneDrive file URLs in EDD
    const URL_PREFIX = 'edd-onedrive://';

    /**
     * Get the URL prefix for OneDrive file URLs
     * This method allows developers to customize the URL prefix using filter
     * 
     * @return string The URL prefix (default: 'edd-onedrive://')
     */
    public function getUrlPrefix()
    {
        /**
         * Filter the URL prefix for OneDrive file URLs
         * 
         * @param string $prefix The default URL prefix
         * @return string The filtered URL prefix
         */
        return apply_filters('odse_url_prefix', self::URL_PREFIX);
    }

    /**
     * Get Azure AD Client ID
     * @return string
     */
    public function getClientId()
    {
        return edd_get_option(self::KEY_CLIENT_ID, '');
    }

    /**
     * Get Azure AD Client Secret
     * @return string
     */
    public function getClientSecret()
    {
        return edd_get_option(self::KEY_CLIENT_SECRET, '');
    }

    /**
     * Get OAuth2 Access Token
     * @return string
     */
    public function getAccessToken()
    {
        // Try to get from transient first (fast validation)
        $token = get_transient(self::KEY_ACCESS_TOKEN);
        if ($token !== false) {
            return $token;
        }

        // Fallback to option if transient expired but option exists (though likely needs refresh)
        return get_option(self::KEY_ACCESS_TOKEN, '');
    }

    /**
     * Get OAuth2 Refresh Token
     * @return string
     */
    public function getRefreshToken()
    {
        return get_option(self::KEY_REFRESH_TOKEN, '');
    }

    /**
     * Get token expiry timestamp
     * @return int
     */
    public function getTokenExpiry()
    {
        return (int) get_option(self::KEY_TOKEN_EXPIRY, 0);
    }

    /**
     * Get selected OneDrive folder ID
     * @return string
     */
    public function getSelectedFolder()
    {
        return edd_get_option(self::KEY_FOLDER, '');
    }

    /**
     * Check if access token is expired
     * @return bool
     */
    public function isTokenExpired()
    {
        // If transient exists, token is valid
        if (get_transient(self::KEY_ACCESS_TOKEN) !== false) {
            return false;
        }

        // If transient is gone, check if we even have a token stored
        $access_token = get_option(self::KEY_ACCESS_TOKEN);
        if (empty($access_token)) {
            return true;
        }

        // If we have a token but no transient, force refresh
        return true;
    }

    /**
     * Save OAuth2 tokens to database and transient
     * 
     * @param string $access_token
     * @param string $refresh_token
     * @param int $expires_in Seconds until token expires
     * @return bool
     */
    public function saveTokens($access_token, $refresh_token = '', $expires_in = 3600)
    {
        // Save access token to transient with buffer (5 mins less than actual expiry)
        // This handles auto-invalidation without checking timestamps manually
        set_transient(self::KEY_ACCESS_TOKEN, $access_token, $expires_in - 300);

        // Still save to option for persistence/backup
        $saved = update_option(self::KEY_ACCESS_TOKEN, $access_token);

        if (!empty($refresh_token)) {
            update_option(self::KEY_REFRESH_TOKEN, $refresh_token);
        }

        // Calculate and save expiry timestamp
        $expiry = time() + $expires_in;
        update_option(self::KEY_TOKEN_EXPIRY, $expiry);

        return $saved;
    }

    /**
     * Clear all OAuth2 tokens (disconnect)
     * @return void
     */
    public function clearTokens()
    {
        delete_transient(self::KEY_ACCESS_TOKEN);
        delete_option(self::KEY_ACCESS_TOKEN);
        delete_option(self::KEY_REFRESH_TOKEN);
        delete_option(self::KEY_TOKEN_EXPIRY);
    }

    /**
     * Check if app credentials are configured
     * @return bool
     */
    public function hasAppCredentials()
    {
        return !empty($this->getClientId()) && !empty($this->getClientSecret());
    }

    /**
     * Check if OAuth2 is connected
     * @return bool
     */
    public function isConnected()
    {
        return !empty($this->getAccessToken()) && $this->hasAppCredentials();
    }

    /**
     * Check if fully configured (connected + folder selected)
     * @return bool
     */
    public function isConfigured()
    {
        return $this->isConnected();
    }

    /**
     * Debug logging helper
     * @param mixed $log
     */
    public function debug($log)
    {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            if (is_array($log) || is_object($log)) {
                $message = wp_json_encode($log, JSON_UNESCAPED_UNICODE);
                if ($message !== false) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('[ODSE] ' . $message);
                }
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[ODSE] ' . sanitize_text_field($log));
            }
        }
    }
}
