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

        // Media library integration
        add_filter('media_upload_tabs', array($this, 'addOneDriveTabs'));
        add_action('media_upload_odse_lib', array($this, 'registerLibraryTab'));
        add_action('admin_head', array($this, 'setupAdminJS'));

        // Enqueue styles
        add_action('admin_enqueue_scripts', array($this, 'enqueueStyles'));
    }

    /**
     * Add OneDrive tabs to media uploader
     * 
     * @param array $default_tabs
     * @return array
     */
    public function addOneDriveTabs($default_tabs)
    {
        if ($this->config->isConnected()) {
            $default_tabs['odse_lib'] = esc_html__('OneDrive Library', 'storage-for-edd-via-onedrive');
        }
        return $default_tabs;
    }

    /**
     * Register OneDrive Library tab
     */
    public function registerLibraryTab()
    {
        $mediaCapability = apply_filters('odse_media_access_cap', 'edit_products');
        if (!current_user_can($mediaCapability)) {
            wp_die(esc_html__('You do not have permission to access OneDrive library.', 'storage-for-edd-via-onedrive'));
        }

        // Check nonce for GET requests with parameters
        if (!empty($_GET) && (isset($_GET['folder']) || isset($_GET['_wpnonce']))) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'media-form')) {
                wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-onedrive'));
            }
        }

        if (!empty($_POST)) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'media-form')) {
                wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-onedrive'));
            }

            $error = media_upload_form_handler();
            if (is_string($error)) {
                return $error;
            }
        }
        wp_iframe(array($this, 'renderLibraryTab'));
    }

    /**
     * Render OneDrive Library tab content
     */
    public function renderLibraryTab()
    {
        media_upload_header();
        wp_enqueue_style('media');
        wp_enqueue_style('odse-media-library');
        wp_enqueue_style('odse-media-container');
        wp_enqueue_style('odse-upload');
        wp_enqueue_script('odse-media-library');
        wp_enqueue_script('odse-upload');

        $folderId = $this->getFolderId();

        // Check if OneDrive is connected
        if (!$this->config->isConnected()) {
?>
            <div id="media-items" class="odse-media-container">
                <h3 class="media-title"><?php esc_html_e('OneDrive File Browser', 'storage-for-edd-via-onedrive'); ?></h3>

                <div class="odse-notice warning">
                    <h4><?php esc_html_e('OneDrive not connected', 'storage-for-edd-via-onedrive'); ?></h4>
                    <p><?php esc_html_e('Please connect to OneDrive in the plugin settings before browsing files.', 'storage-for-edd-via-onedrive'); ?></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=odse-settings')); ?>" class="button-primary">
                            <?php esc_html_e('Configure OneDrive Settings', 'storage-for-edd-via-onedrive'); ?>
                        </a>
                    </p>
                </div>
            </div>
        <?php
            return;
        }

        // Use default folder from settings if no folder specified in URL
        if (empty($folderId)) {
            $folderId = $this->config->getSelectedFolder();
        }

        // Try to get files
        try {
            $files = $this->client->listFiles($folderId);
            $connection_error = false;
        } catch (Exception $e) {
            $files = [];
            $connection_error = true;
            $this->config->debug('OneDrive connection error: ' . $e->getMessage());
        }
        ?>

        <?php
        // Calculate back URL for header if in subfolder
        $back_url = '';
        if (!empty($folderId) && $folderId !== 'root') {
            $parentFolderId = $this->client->getParentFolderId($folderId);
            if ($parentFolderId === false) {
                $parentFolderId = 'root';
            }
            // Remove success parameters to prevent notice from showing after back navigation
            $back_url = remove_query_arg(array('odse_success', 'odse_filename', 'error'));
            $back_url = add_query_arg(array(
                'folder' => $parentFolderId,
                '_wpnonce' => wp_create_nonce('media-form')
            ), $back_url);
        }
        ?>
        <div style="width: inherit;" id="media-items">
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
            <?php } elseif (!$connection_error) { ?>

                <div class="odse-breadcrumb-nav">
                    <div class="odse-nav-group">
                        <?php if (!empty($back_url)) { ?>
                            <a href="<?php echo esc_url($back_url); ?>" class="odse-nav-back" title="<?php esc_attr_e('Go Back', 'storage-for-edd-via-onedrive'); ?>">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </a>
                        <?php } else { ?>
                            <span class="odse-nav-back disabled">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </span>
                        <?php } ?>

                        <div class="odse-breadcrumbs">
                            <?php
                            if (!empty($folderId) && $folderId !== 'root') {
                                $folderDetails = $this->client->getFolderDetails($folderId);
                                $currentPath = ($folderDetails && isset($folderDetails['path'])) ? $folderDetails['path'] : '';

                                if (!empty($currentPath)) {
                                    // Build breadcrumb navigation from path
                                    $path_parts = explode('/', trim($currentPath, '/'));
                                    $breadcrumb_links = array();

                                    // Root link
                                    $root_url = remove_query_arg(array('folder', 'odse_success', 'odse_filename', 'error'));
                                    $root_url = add_query_arg(array('_wpnonce' => wp_create_nonce('media-form')), $root_url);
                                    $breadcrumb_links[] = '<a href="' . esc_url($root_url) . '">' . esc_html__('Root', 'storage-for-edd-via-onedrive') . '</a>';

                                    // Build path links - for OneDrive we need to get folder IDs for each path segment
                                    // Since we don't have a reliable way to get intermediate folder IDs, 
                                    // we'll just show current folder as bold (non-clickable) and root as clickable
                                    foreach ($path_parts as $index => $part) {
                                        if ($index === count($path_parts) - 1) {
                                            // Current folder - not a link
                                            $breadcrumb_links[] = '<span class="current">' . esc_html($part) . '</span>';
                                        } else {
                                            // Parent folders - show as text (we don't have folder IDs)
                                            $breadcrumb_links[] = '<span class="text">' . esc_html($part) . '</span>';
                                        }
                                    }

                                    echo wp_kses(implode(' <span class="sep">/</span> ', $breadcrumb_links), array(
                                        'a' => array('href' => array()),
                                        'span' => array('class' => array())
                                    ));
                                } else {
                                    echo '<span class="current">' . esc_html__('Root folder', 'storage-for-edd-via-onedrive') . '</span>';
                                }
                            } else {
                                echo '<span class="current">' . esc_html__('Root folder', 'storage-for-edd-via-onedrive') . '</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- Moved Search Input -->
                    <?php if (!empty($files)) { ?>
                        <div class="odse-search-inline">
                            <input type="text"
                                id="odse-file-search"
                                class="odse-search-input"
                                placeholder="<?php esc_attr_e('Search files...', 'storage-for-edd-via-onedrive'); ?>">
                            <button type="button"
                                id="odse-clear-search"
                                class="button">
                                <?php esc_html_e('Clear', 'storage-for-edd-via-onedrive'); ?>
                            </button>
                        </div>
                    <?php } ?>
                </div>


                <?php
                // Upload form integrated into Library
                $successFlag = filter_input(INPUT_GET, 'odse_success', FILTER_SANITIZE_NUMBER_INT);
                $errorMsg = filter_input(INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

                if ($errorMsg) {
                    $this->config->debug('Upload error: ' . $errorMsg);
                ?>
                    <div class="edd_errors odse-notice warning">
                        <h4><?php esc_html_e('Error', 'storage-for-edd-via-onedrive'); ?></h4>
                        <p class="edd_error"><?php esc_html_e('An error occurred during the upload process. Please try again.', 'storage-for-edd-via-onedrive'); ?></p>
                    </div>
                <?php
                }

                if (!empty($successFlag) && '1' == $successFlag) {
                    $savedPathAndFilename = filter_input(INPUT_GET, 'odse_filename', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                    $savedPathAndFilename = sanitize_text_field($savedPathAndFilename);
                    $lastSlashPos = strrpos($savedPathAndFilename, '/');
                    $savedFilename = $lastSlashPos !== false ? substr($savedPathAndFilename, $lastSlashPos + 1) : $savedPathAndFilename;
                ?>
                    <div class="edd_errors odse-notice success">
                        <h4><?php esc_html_e('Upload Successful', 'storage-for-edd-via-onedrive'); ?></h4>
                        <p class="edd_success">
                            <?php
                            // translators: %s: File name.
                            printf(esc_html__('File %s uploaded successfully!', 'storage-for-edd-via-onedrive'), '<strong>' . esc_html($savedFilename) . '</strong>');
                            ?>
                        </p>
                        <p>
                            <a href="javascript:void(0)"
                                id="odse_save_link"
                                class="button-primary"
                                data-odse-fn="<?php echo esc_attr($savedFilename); ?>"
                                data-odse-path="<?php echo esc_attr(ltrim($savedPathAndFilename, '/')); ?>">
                                <?php esc_html_e('Use this file in your Download', 'storage-for-edd-via-onedrive'); ?>
                            </a>
                        </p>
                    </div>
                <?php
                }
                ?>
                <!-- Upload Form (Hidden by default) -->
                <form enctype="multipart/form-data" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="odse-upload-form" id="odse-upload-section" style="display: none;">
                    <?php wp_nonce_field('odse_upload', 'odse_nonce'); ?>
                    <input type="hidden" name="action" value="odse_upload" />
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
                    <input type="hidden" name="odse_folder" value="<?php echo esc_attr($folderId); ?>" />
                </form>

                <?php if (!empty($files)) { ?>


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
                                    $folder_url = add_query_arg(array(
                                        'folder' => $file['id'],
                                        '_wpnonce' => wp_create_nonce('media-form')
                                    ));
                            ?>
                                    <tr class="odse-folder-row">
                                        <td class="column-primary" data-label="<?php esc_attr_e('Folder Name', 'storage-for-edd-via-onedrive'); ?>">
                                            <a href="<?php echo esc_url($folder_url); ?>" class="folder-link">
                                                <span class="dashicons dashicons-category"></span>
                                                <span class="file-name"><?php echo esc_html($file['name']); ?></span>
                                            </a>
                                        </td>
                                        <td class="column-size">—</td>
                                        <td class="column-date">—</td>
                                        <td class="column-actions">
                                            <a href="<?php echo esc_url($folder_url); ?>" class="button-secondary button-small">
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
                                            <?php if (!empty($folderId)) {
                                                // Get folder path from file path
                                                $folder_path = dirname($file['path']);
                                                if ($folder_path === '.' || $folder_path === '\\') $folder_path = '';
                                                // Ensure forward slashes
                                                $folder_path = str_replace('\\', '/', $folder_path);
                                            ?>
                                                <span class="file-path"><?php echo esc_html(ltrim($folder_path, '/')); ?></span>
                                            <?php } ?>
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
        </div>
<?php
    }


    /**
     * Setup admin JavaScript
     */
    public function setupAdminJS()
    {
        wp_enqueue_script('odse-admin-upload-buttons');
    }

    /**
     * Get current folder ID from GET param
     * @return string
     */
    private function getFolderId()
    {
        $mediaCapability = apply_filters('odse_media_access_cap', 'edit_products');
        if (!current_user_can($mediaCapability)) {
            return '';
        }

        if (!empty($_GET['folder'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'media-form')) {
                wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-onedrive'));
            }
        }
        return !empty($_GET['folder']) ? sanitize_text_field(wp_unslash($_GET['folder'])) : '';
    }

    /**
     * Format file size in human readable format
     * @param int $size
     * @return string
     */
    private function formatFileSize($size)
    {
        if ($size == 0) return '0 B';

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = floor(log($size, 1024));

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

        // Register scripts
        wp_register_script('odse-media-library', ODSE_PLUGIN_URL . 'assets/js/onedrive-media-library.js', array('jquery'), ODSE_VERSION, true);
        wp_register_script('odse-upload', ODSE_PLUGIN_URL . 'assets/js/onedrive-upload.js', array('jquery'), ODSE_VERSION, true);
        wp_register_script('odse-admin-upload-buttons', ODSE_PLUGIN_URL . 'assets/js/admin-upload-buttons.js', array('jquery'), ODSE_VERSION, true);

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
        wp_add_inline_script('odse-upload', 'var odse_max_upload_size = ' . json_encode(wp_max_upload_size()) . ';', 'before');

        wp_add_inline_script('odse-admin-upload-buttons', 'var odse_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');
    }
}
