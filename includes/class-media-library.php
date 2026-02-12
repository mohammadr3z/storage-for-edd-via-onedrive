<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OneDrive Media Library Integration
 * 
 * Adds custom tabs to WordPress media uploader for browsing
 * and uploading files to OneDrive.
 */
class ODSE_Media_Library
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new ODSE_OneDrive_Config();
        $this->client = new ODSE_OneDrive_Client();

        // Enqueue styles
        add_action('admin_enqueue_scripts', array($this, 'enqueueStyles'));

        // Add OneDrive button to EDD downloadable files (Server Side)
        add_action('edd_download_file_table_row', array($this, 'renderBrowseButton'), 10, 3);

        // Print scripts for button functionality
        add_action('admin_footer', array($this, 'printAdminScripts'));

        // AJAX Handler for fetching library
        add_action('wp_ajax_odse_get_library', array($this, 'ajaxGetLibrary'));
    }

    /**
     * AJAX Handler to get library content
     */
    public function ajaxGetLibrary()
    {
        check_ajax_referer('media-form', '_wpnonce');

        $mediaCapability = apply_filters('odse_media_access_cap', 'edit_products');
        if (!current_user_can($mediaCapability)) {
            wp_send_json_error(esc_html__('You do not have permission to access OneDrive library.', 'storage-for-edd-via-onedrive'));
        }

        // 'path' param used in JS
        // If 'is_path' is true, it's a file path string (e.g. "Attachments") that needs resolving to an ID
        // Otherwise, it's already a Folder ID
        $pathInput = isset($_REQUEST['path']) ? sanitize_text_field(wp_unslash($_REQUEST['path'])) : '';
        $isPath = isset($_REQUEST['is_path']) && $_REQUEST['is_path'] === '1';

        $folderId = '';

        if ($isPath && !empty($pathInput)) {
            // Resolve Path -> ID
            $resolvedId = $this->client->getFolderIdByPath($pathInput);
            if ($resolvedId) {
                $folderId = $resolvedId;
            } else {
                // If path resolution fails (e.g. folder deleted), fallback to root
                $folderId = 'root';
            }
        } else {
            // It's already an ID
            $folderId = $pathInput;
        }

        // Prevent directory traversal (not strictly applicable to IDs but good practice)
        if (strpos($folderId, '..') !== false) {
            wp_send_json_error(esc_html__('Invalid path.', 'storage-for-edd-via-onedrive'));
        }

        if (empty($folderId)) {
            $folderId = $this->config->getSelectedFolder();
        }

        ob_start();
        $this->renderLibraryContent($folderId);
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Render the inner content of the library
     * 
     * @param string $folderId
     */
    private function renderLibraryContent($folderId)
    {
        $folderInfo = null;
        $connection_error = false;
        $files = [];

        // Check if OneDrive is connected
        if (!$this->config->isConnected()) {
            $connection_error = true;
?>
            <div class="odse-notice warning">
                <h4><?php esc_html_e('OneDrive not connected', 'storage-for-edd-via-onedrive'); ?></h4>
                <p><?php esc_html_e('Please connect to OneDrive in the plugin settings before browsing files.', 'storage-for-edd-via-onedrive'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings')); ?>" class="button-primary">
                        <?php esc_html_e('Configure OneDrive Settings', 'storage-for-edd-via-onedrive'); ?>
                    </a>
                </p>
            </div>
        <?php
            // We return here, similar to how valid connection flow works but ensuring structure is closed if needed.
            // Actually, for AJAX response, returning just this HTML div is fine.
            return;
        }

        // Try to get files
        try {
            // Resolve 'root' logic
            if (empty($folderId)) {
                $folderId = 'root';
            }

            $files = $this->client->listFiles($folderId);

            // Get folder info for breadcrumbs
            if ($folderId !== 'root') {
                $folderInfo = $this->client->getFolderInfo($folderId);
            }
        } catch (Exception $e) {
            $files = [];
            $connection_error = true;
            $this->config->debug('OneDrive connection error: ' . $e->getMessage());
        }
        ?>

        <?php
        $back_url = '';
        $currentPath = '';
        $parentFolderId = '';

        if (!$connection_error) {
            if (!empty($folderInfo)) {
                $parentFolderId = isset($folderInfo['parentId']) ? $folderInfo['parentId'] : 'root';
                if (!$parentFolderId) $parentFolderId = 'root'; // fallback

                $currentPath = isset($folderInfo['path']) ? $folderInfo['path'] : '';
                $back_url = $parentFolderId;
            } elseif ($folderId !== 'root') {
                // Fallback if no folder info but we are not at root (shouldn't happen usually)
                $back_url = 'root';
            }
        }
        ?>
        <div class="odse-header-row">
            <h3 class="media-title"><?php esc_html_e('Select a file from OneDrive', 'storage-for-edd-via-onedrive'); ?></h3>
            <div class="odse-header-buttons">
                <button type="button" class="button button-primary" id="odse-toggle-upload">
                    <?php esc_html_e('Upload File', 'storage-for-edd-via-onedrive'); ?>
                </button>
            </div>
        </div>

        <?php if ($connection_error) { ?>
            <div class="odse-notice warning">
                <h4><?php esc_html_e('Connection Error', 'storage-for-edd-via-onedrive'); ?></h4>
                <p><?php esc_html_e('Unable to connect to OneDrive.', 'storage-for-edd-via-onedrive'); ?></p>
                <p><?php esc_html_e('Please check your OneDrive configuration settings and try again.', 'storage-for-edd-via-onedrive'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings')); ?>" class="button-primary">
                        <?php esc_html_e('Check Settings', 'storage-for-edd-via-onedrive'); ?>
                    </a>
                </p>
            </div>
        <?php } else { ?>

            <div class="odse-breadcrumb-nav">
                <div class="odse-nav-group">
                    <?php if (!empty($folderInfo) && $folderId !== 'root') { ?>
                        <a href="#" class="odse-nav-back" title="<?php esc_attr_e('Go Back', 'storage-for-edd-via-onedrive'); ?>" data-path="<?php echo esc_attr($parentFolderId); ?>">
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                        </a>
                    <?php } else { ?>
                        <span class="odse-nav-back disabled">
                            <span class="dashicons dashicons-arrow-left-alt2"></span>
                        </span>
                    <?php } ?>

                    <div class="odse-breadcrumbs">
                        <?php
                        // Breadcrumbs
                        $root_link = '<a href="#" data-path="">' . esc_html__('Home', 'storage-for-edd-via-onedrive') . '</a>';

                        if (!empty($currentPath)) {
                            $path_parts = explode('/', trim($currentPath, '/'));

                            echo $root_link;
                            foreach ($path_parts as $part) {
                                echo ' <span class="sep">/</span> <span class="text">' . esc_html($part) . '</span>';
                            }
                        } else {
                            echo '<span class="current">' . esc_html__('Home', 'storage-for-edd-via-onedrive') . '</span>';
                        }
                        ?>
                    </div>
                </div>

                <?php if (!empty($files)) { ?>
                    <div class="odse-search-inline">
                        <input type="search"
                            id="odse-file-search"
                            class="odse-search-input"
                            placeholder="<?php esc_attr_e('Search files...', 'storage-for-edd-via-onedrive'); ?>">
                    </div>
                <?php } ?>
            </div>

            <?php
            // Upload form integrated into Library
            ?>
            <!-- Upload Form (Hidden by default) -->
            <form enctype="multipart/form-data" method="post" action="#" class="odse-upload-form" id="odse-upload-section" style="display: none;">
                <?php wp_nonce_field('odse_upload', 'odse_nonce'); ?>
                <input type="hidden" name="action" value="odse_ajax_upload" />
                <div class="upload-field">
                    <input type="file"
                        name="odse_file"
                        accept=".zip,.rar,.7z,.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif" />
                </div>
                <p class="description">
                    <?php
                    // translators: %s: Maximum upload file size.
                    printf(esc_html__('Maximum upload file size: %s', 'storage-for-edd-via-onedrive'), esc_html(size_format(wp_max_upload_size())));
                    ?>
                </p>
                <input type="submit"
                    class="button-primary"
                    value="<?php esc_attr_e('Upload', 'storage-for-edd-via-onedrive'); ?>" />
                <input type="hidden" name="odse_path" value="<?php echo esc_attr($folderId); ?>" />
            </form>

            <?php if (is_array($files) && !empty($files)) { ?>

                <!-- File Display Table -->
                <table class="wp-list-table widefat fixed odse-files-table">
                    <thead>
                        <tr>
                            <th class="column-primary" style="width: 40%;"><?php esc_html_e('File Name', 'storage-for-edd-via-onedrive'); ?></th>
                            <th class="column-size" style="width: 20%;"><?php esc_html_e('File Size', 'storage-for-edd-via-onedrive'); ?></th>
                            <th class="column-date" style="width: 25%;"><?php esc_html_e('Last Modified', 'storage-for-edd-via-onedrive'); ?></th>
                            <th class="column-actions" style="width: 15%;"><?php esc_html_e('Actions', 'storage-for-edd-via-onedrive'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Sort: folders first, then files
                        usort($files, function ($a, $b) {
                            if ($a['is_folder'] && !$b['is_folder']) return -1;
                            if (!$a['is_folder'] && $b['is_folder']) return 1;
                            return strcasecmp($a['name'], $b['name']);
                        });

                        foreach ($files as $file) {
                            // Handle folders
                            if ($file['is_folder']) {
                        ?>
                                <tr class="odse-folder-row">
                                    <td class="column-primary" data-label="<?php esc_attr_e('Folder Name', 'storage-for-edd-via-onedrive'); ?>">
                                        <a href="#" class="folder-link" data-path="<?php echo esc_attr($file['id']); ?>">
                                            <span class="dashicons dashicons-category"></span>
                                            <span class="file-name"><?php echo esc_html($file['name']); ?></span>
                                        </a>
                                    </td>
                                    <td class="column-size">—</td>
                                    <td class="column-date">—</td>
                                    <td class="column-actions">
                                        <a href="#" class="button-secondary button-small folder-link" data-path="<?php echo esc_attr($file['id']); ?>">
                                            <?php esc_html_e('Open', 'storage-for-edd-via-onedrive'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php
                                continue;
                            }

                            // Handle files
                            $file_size = $this->formatFileSize($file['size']);
                            $last_modified = !empty($file['modified']) ? $this->formatHumanDate($file['modified']) : '—';
                            ?>
                            <tr>
                                <td class="column-primary" data-label="<?php esc_attr_e('File Name', 'storage-for-edd-via-onedrive'); ?>">
                                    <div class="odse-file-display">
                                        <span class="file-name"><?php echo esc_html($file['name']); ?></span>
                                    </div>
                                </td>
                                <td class="column-size" data-label="<?php esc_attr_e('File Size', 'storage-for-edd-via-onedrive'); ?>">
                                    <span class="file-size"><?php echo esc_html($file_size); ?></span>
                                </td>
                                <td class="column-date" data-label="<?php esc_attr_e('Last Modified', 'storage-for-edd-via-onedrive'); ?>">
                                    <span class="file-date"><?php echo esc_html($last_modified); ?></span>
                                </td>
                                <td class="column-actions" data-label="<?php esc_attr_e('Actions', 'storage-for-edd-via-onedrive'); ?>">
                                    <a class="save-odse-file button-secondary button-small"
                                        href="javascript:void(0)"
                                        data-odse-filename="<?php echo esc_attr($file['name']); ?>"
                                        data-odse-link="<?php echo esc_attr(ltrim($file['path'], '/')); ?>">
                                        <?php esc_html_e('Select File', 'storage-for-edd-via-onedrive'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <div class="odse-notice info" style="margin-top: 15px;">
                    <p><?php esc_html_e('This folder is empty. Use the upload form above to add files.', 'storage-for-edd-via-onedrive'); ?></p>
                </div>
            <?php } ?>
        <?php } ?>
    <?php
    }

    /**
     * Format file size in human readable format
     * @param int $size
     * @return string
     */
    private function formatFileSize($size)
    {
        if ($size === 0) return '0 B';

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = floor(log($size, 1024));
        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($size / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Format date in human readable format
     * @param string $date
     * @return string
     */
    private function formatHumanDate($date)
    {
        try {
            $timestamp = strtotime($date);
            if ($timestamp) {
                return date_i18n('j F Y', $timestamp);
            }
        } catch (Exception $e) {
            // Ignore date formatting errors
        }
        return $date;
    }

    /**
     * Enqueue CSS styles and JS scripts
     */
    public function enqueueStyles()
    {
        // Register styles
        wp_register_style('odse-media-library', ODSE_PLUGIN_URL . 'assets/css/onedrive-media-library.css', array(), ODSE_VERSION);
        wp_register_style('odse-upload', ODSE_PLUGIN_URL . 'assets/css/onedrive-upload.css', array(), ODSE_VERSION);
        wp_register_style('odse-media-container', ODSE_PLUGIN_URL . 'assets/css/onedrive-media-container.css', array(), ODSE_VERSION);
        wp_register_style('odse-modal', ODSE_PLUGIN_URL . 'assets/css/onedrive-modal.css', array('dashicons'), ODSE_VERSION);
        wp_register_style('odse-browse-button', ODSE_PLUGIN_URL . 'assets/css/onedrive-browse-button.css', array(), ODSE_VERSION);

        // Register scripts
        wp_register_script('odse-media-library', ODSE_PLUGIN_URL . 'assets/js/onedrive-media-library.js', array('jquery'), ODSE_VERSION, true);
        wp_register_script('odse-upload', ODSE_PLUGIN_URL . 'assets/js/onedrive-upload.js', array('jquery'), ODSE_VERSION, true);
        wp_register_script('odse-modal', ODSE_PLUGIN_URL . 'assets/js/onedrive-modal.js', array('jquery'), ODSE_VERSION, true);
        wp_register_script('odse-browse-button', ODSE_PLUGIN_URL . 'assets/js/onedrive-browse-button.js', array('jquery', 'odse-modal'), ODSE_VERSION, true);

        // Localize scripts
        wp_localize_script('odse-media-library', 'odse_i18n', array(
            'file_selected_success' => esc_html__('File selected successfully!', 'storage-for-edd-via-onedrive'),
            'file_selected_error' => esc_html__('Error selecting file. Please try again.', 'storage-for-edd-via-onedrive')
        ));

        wp_add_inline_script('odse-media-library', 'var odse_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');

        wp_localize_script('odse-upload', 'odse_i18n', array(
            'file_size_too_large' => esc_html__('File size too large. Maximum allowed size is', 'storage-for-edd-via-onedrive')
        ));

        wp_add_inline_script('odse-upload', 'var odse_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');
        wp_add_inline_script('odse-upload', 'var odse_max_upload_size = ' . wp_json_encode(wp_max_upload_size()) . ';', 'before');
    }

    /**
     * Render Browse OneDrive button in EDD file row (Server Side)
     */
    public function renderBrowseButton($key, $file, $post_id)
    {
        if (!$this->config->isConnected()) {
            return;
        }

        // Add hidden input to store connection status/check if needed by JS (optional)
    ?>
        <div class="edd-form-group edd-file-odse-browse">
            <label class="edd-form-group__label edd-repeatable-row-setting-label">&nbsp;</label>
            <div class="edd-form-group__control">
                <button type="button" class="button odse_browse_button">
                    <?php esc_html_e('Browse OneDrive', 'storage-for-edd-via-onedrive'); ?>
                </button>
            </div>
        </div>
<?php
    }

    /**
     * Add OneDrive browse button scripts
     */
    public function printAdminScripts()
    {
        global $pagenow, $typenow;

        // Only on EDD download edit pages
        if (!($pagenow === 'post.php' || $pagenow === 'post-new.php') || $typenow !== 'download') {
            return;
        }

        // Only if connected
        if (!$this->config->isConnected()) {
            return;
        }

        // Enqueue modal assets
        wp_enqueue_style('odse-modal');
        wp_enqueue_script('odse-modal');

        // Enqueue media library assets (AJAX)
        wp_enqueue_style('odse-media-library');
        wp_enqueue_script('odse-media-library');
        wp_enqueue_style('odse-upload');
        wp_enqueue_script('odse-upload');

        // Enqueue browse button assets
        wp_enqueue_style('odse-browse-button');
        wp_enqueue_script('odse-browse-button');

        // Localize script with dynamic data
        wp_localize_script('odse-browse-button', 'odse_browse_button', array(
            'modal_title'        => __('OneDrive Library', 'storage-for-edd-via-onedrive'),
            'nonce'              => wp_create_nonce('media-form'),
            'url_prefix'         => $this->config->getUrlPrefix(),
            'i18n_select_file'   => __('Select a file from OneDrive', 'storage-for-edd-via-onedrive'),
            'i18n_upload'        => __('Upload File', 'storage-for-edd-via-onedrive'),
            'i18n_file_name'     => __('File Name', 'storage-for-edd-via-onedrive'),
            'i18n_file_size'     => __('File Size', 'storage-for-edd-via-onedrive'),
            'i18n_last_modified' => __('Last Modified', 'storage-for-edd-via-onedrive'),
            'i18n_actions'       => __('Actions', 'storage-for-edd-via-onedrive'),
            'i18n_search'        => __('Search files...', 'storage-for-edd-via-onedrive')
        ));
    }
}
