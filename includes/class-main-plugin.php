<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin Class
 * 
 * Initializes all plugin components and sets up hooks.
 */
class ODSE_OneDriveStorage
{
    private $settings;
    private $media_library;
    private $downloader;
    private $uploader;

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize plugin components
     */
    private function init()
    {
        add_action('admin_notices', array($this, 'showConfigurationNotice'));

        // Initialize components
        $this->settings = new ODSE_Admin_Settings();
        $this->media_library = new ODSE_Media_Library();
        $this->downloader = new ODSE_OneDrive_Downloader();
        $this->uploader = new ODSE_OneDrive_Uploader();

        // Register EDD download filter
        add_filter('edd_requested_file', array($this->downloader, 'generateUrl'), 11, 3);
    }

    /**
     * Show admin notice if OneDrive is not configured
     */
    public function showConfigurationNotice()
    {
        // Only show on admin pages
        if (!is_admin()) {
            return;
        }

        // Don't show on OneDrive settings page itself
        $current_screen = get_current_screen();
        if ($current_screen && strpos($current_screen->id, 'edd-settings') !== false) {
            return;
        }

        $config = new ODSE_OneDrive_Config();

        // Show notice if not connected
        if (!$config->isConnected()) {
            $settings_url = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings');
?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Storage for EDD via OneDrive:', 'storage-for-edd-via-onedrive'); ?></strong>
                    <?php esc_html_e('Please connect to OneDrive to start using cloud storage for your digital products.', 'storage-for-edd-via-onedrive'); ?>
                    <a href="<?php echo esc_url($settings_url); ?>" class="button button-secondary" style="margin-left: 10px;">
                        <?php esc_html_e('Configure OneDrive', 'storage-for-edd-via-onedrive'); ?>
                    </a>
                </p>
            </div>
<?php
        }
    }

    /**
     * Get plugin version
     * @return string
     */
    public function getVersion()
    {
        return ODSE_VERSION;
    }
}
