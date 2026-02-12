<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OneDrive Uploader
 * 
 * Handles file uploads to OneDrive from WordPress admin.
 */
class ODSE_OneDrive_Uploader
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new ODSE_OneDrive_Config();
        $this->client = new ODSE_OneDrive_Client();

        // Register upload handler for admin-post.php
        add_action('admin_post_odse_upload', array($this, 'performFileUpload'));

        // Register AJAX upload handler
        add_action('wp_ajax_odse_ajax_upload', array($this, 'ajaxUpload'));
    }

    /**
     * Handle file upload to OneDrive.
     */
    public function performFileUpload()
    {
        if (!is_admin()) {
            return;
        }

        // Verify Nonce
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is happening right here
        if (!isset($_POST['odse_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['odse_nonce'])), 'odse_upload')) {
            wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-onedrive'), esc_html__('Error', 'storage-for-edd-via-onedrive'), array('back_link' => true));
        }

        $uploadCapability = apply_filters('odse_upload_cap', 'edit_products');
        if (!current_user_can($uploadCapability)) {
            wp_die(esc_html__('You do not have permission to upload files to OneDrive.', 'storage-for-edd-via-onedrive'));
        }

        if (!$this->validateUpload()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified at top of function
        $folderId = filter_input(INPUT_POST, 'odse_folder', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (empty($folderId)) {
            $folderId = $this->config->getSelectedFolder();
        }

        try {
            // Process upload
            $uploadedPath = $this->processUpload($_FILES['odse_file'], $folderId);

            // Create secure redirect URL
            $referer = wp_get_referer();
            if (!$referer) {
                $referer = admin_url('admin.php?page=edd-settings&tab=extensions&section=odse-settings');
            }

            $redirectURL = add_query_arg(
                array(
                    'odse_success'  => '1',
                    'odse_filename' => rawurlencode($uploadedPath),
                ),
                $referer
            );
            wp_safe_redirect(esc_url_raw($redirectURL));
            exit;
        } catch (Exception $e) {
            $this->config->debug('File upload error: ' . $e->getMessage());
            wp_die(esc_html__('An error occurred while attempting to upload your file.', 'storage-for-edd-via-onedrive'), esc_html__('Error', 'storage-for-edd-via-onedrive'), array('back_link' => true));
        }
    }

    /**
     * Handle AJAX file upload.
     */
    public function ajaxUpload()
    {
        check_ajax_referer('odse_upload', 'odse_nonce');

        $uploadCapability = apply_filters('odse_upload_cap', 'edit_products');
        if (!current_user_can($uploadCapability)) {
            wp_send_json_error(esc_html__('You do not have permission to upload files to OneDrive.', 'storage-for-edd-via-onedrive'));
        }

        // Use checkUploadValidation for better AJAX error handling
        $validation = $this->checkUploadValidation();
        if ($validation !== true) {
            wp_send_json_error($validation);
        }

        $folderId = isset($_POST['odse_path']) ? sanitize_text_field(wp_unslash($_POST['odse_path'])) : '';
        if (empty($folderId)) {
            $folderId = $this->config->getSelectedFolder();
        }

        if (!$this->config->isConnected()) {
            wp_send_json_error(esc_html__('OneDrive is not connected.', 'storage-for-edd-via-onedrive'));
        }

        try {
            $uploadedPath = $this->processUpload($_FILES['odse_file'], $folderId);

            // Return success with file info
            wp_send_json_success(array(
                'message' => esc_html__('File uploaded successfully!', 'storage-for-edd-via-onedrive'),
                'filename' => basename($uploadedPath),
                'path' => $uploadedPath,
                'odse_link' => ltrim($uploadedPath, '/')
            ));
        } catch (Exception $e) {
            $this->config->debug('AJAX upload error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Core upload processing logic
     * 
     * @param array $file_array $_FILES item
     * @param string $folderId Target folder ID
     * @return string Uploaded file path (display path)
     * @throws Exception
     */
    private function processUpload($file_array, $folderId)
    {
        // Check and sanitize file name
        $filename = '';
        if (isset($file_array['name']) && !empty($file_array['name'])) {
            $filename = sanitize_file_name($file_array['name']);
        } else {
            throw new Exception(esc_html__('No file selected.', 'storage-for-edd-via-onedrive'));
        }

        // Validate file path
        if (
            !isset($file_array['tmp_name']) ||
            !is_uploaded_file($file_array['tmp_name']) ||
            !is_readable($file_array['tmp_name'])
        ) {
            throw new Exception(esc_html__('Invalid file upload.', 'storage-for-edd-via-onedrive'));
        }

        $filePath = $file_array['tmp_name'];

        // Upload to OneDrive using stream (memory efficient)
        $result = $this->client->uploadFileStream($filename, $filePath, $folderId);

        if (!$result) {
            throw new Exception(esc_html__('Failed to upload file to OneDrive.', 'storage-for-edd-via-onedrive'));
        }

        // Get the file ID and Name from OneDrive response
        $uploadedName = isset($result['name']) ? $result['name'] : $filename;

        // Calculate full path
        $parentPath = isset($result['parentReference']['path']) ? $result['parentReference']['path'] : '';
        // Remove /drive/root: prefix
        if (strpos($parentPath, '/drive/root:') === 0) {
            $parentPath = substr($parentPath, 12);
        }
        // Construct path
        $uploadedPath = rtrim($parentPath, '/') . '/' . $uploadedName;

        return $uploadedPath;
    }

    /**
     * Helper to return validation result without dying (for AJAX)
     * @return bool|string Returns true on success, or error message string on failure
     */
    private function checkUploadValidation()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling methods before this is called.
        // Check for file existence and its components
        if (
            !isset($_FILES['odse_file']) ||
            !isset($_FILES['odse_file']['name']) ||
            !isset($_FILES['odse_file']['tmp_name']) ||
            !isset($_FILES['odse_file']['size']) ||
            empty($_FILES['odse_file']['name'])
        ) {
            return esc_html__('Please select a file to upload.', 'storage-for-edd-via-onedrive');
        }

        // Check uploaded file security
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- tmp_name is a system path
        if (!is_uploaded_file($_FILES['odse_file']['tmp_name'])) {
            return esc_html__('Invalid file upload.', 'storage-for-edd-via-onedrive');
        }

        // Validate file type
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Filename is sanitized using sanitize_file_name
        if (!$this->isAllowedFileType(sanitize_file_name($_FILES['odse_file']['name']))) {
            return esc_html__('File type not allowed. Only safe file types are permitted.', 'storage-for-edd-via-onedrive');
        }

        // Validate Content-Type (MIME type)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES array passed to validateFileContentType()
        if (!$this->validateFileContentType($_FILES['odse_file'])) {
            return esc_html__('File content type validation failed. The file may be corrupted or have an incorrect extension.', 'storage-for-edd-via-onedrive');
        }

        // Check and sanitize file size
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File size is validated/sanitized using absint
        $fileSize = absint($_FILES['odse_file']['size']);
        $maxSize = wp_max_upload_size();
        if ($fileSize > $maxSize || $fileSize <= 0) {
            return sprintf(
                // translators: %s: Maximum upload file size.
                esc_html__('File size too large. Maximum allowed size is %s', 'storage-for-edd-via-onedrive'),
                esc_html(size_format($maxSize))
            );
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return true;
    }

    /**
     * Validate file upload (legacy wrapper for non-AJAX calls).
     * @return bool
     */
    private function validateUpload()
    {
        $result = $this->checkUploadValidation();
        if ($result === true) {
            return true;
        }

        wp_die($result, esc_html__('Error', 'storage-for-edd-via-onedrive'), array('back_link' => true));
        return false;
    }

    /**
     * Check if file type is allowed (simple extension-based validation)
     * @param string $filename
     * @return bool
     */
    private function isAllowedFileType($filename)
    {
        // Get file extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Allowed safe extensions for digital products
        $allowedExtensions = array(
            'zip',
            'rar',
            '7z',
            'tar',
            'gz',
            'pdf',
            'doc',
            'docx',
            'txt',
            'rtf',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'mp3',
            'wav',
            'ogg',
            'flac',
            'm4a',
            'mp4',
            'avi',
            'mov',
            'wmv',
            'flv',
            'webm',
            'epub',
            'mobi',
            'azw',
            'azw3',
            'xls',
            'xlsx',
            'csv',
            'ppt',
            'pptx',
            'css',
            'js',
            'json',
            'xml'
        );

        // Check if extension is in allowed list
        if (!in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        // Block dangerous file patterns
        $dangerousPatterns = array(
            '.php',
            '.phtml',
            '.asp',
            '.aspx',
            '.jsp',
            '.cgi',
            '.pl',
            '.py',
            '.exe',
            '.com',
            '.bat',
            '.cmd',
            '.scr',
            '.vbs',
            '.jar',
            '.sh',
            '.bash',
            '.zsh',
            '.fish',
            '.htaccess',
            '.htpasswd'
        );

        $lowerFilename = strtolower($filename);
        foreach ($dangerousPatterns as $pattern) {
            if (strpos($lowerFilename, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate file content type (MIME type) matches the file extension
     * @param array $file The uploaded file array from $_FILES
     * @return bool
     */
    private function validateFileContentType($file)
    {
        // Ensure we have the required file information
        if (!isset($file['tmp_name']) || !isset($file['name'])) {
            return false;
        }

        // Use WordPress's built-in function to check file type and extension
        $filetype = wp_check_filetype_and_ext($file['tmp_name'], sanitize_file_name($file['name']));

        // Check if the file type was detected
        if (!$filetype || !isset($filetype['ext']) || !isset($filetype['type'])) {
            return false;
        }

        // If extension or type is false, the file failed validation
        if (false === $filetype['ext'] || false === $filetype['type']) {
            return false;
        }

        // Additional check: ensure the detected extension matches what we expect
        $actualExtension = strtolower(pathinfo(sanitize_file_name($file['name']), PATHINFO_EXTENSION));
        if ($filetype['ext'] !== $actualExtension) {
            return false;
        }

        // Validate against allowed MIME types
        $allowedMimeTypes = array(
            // Archives
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
            'application/x-gzip',
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/rtf',
            // Images
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            // Audio
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/ogg',
            'audio/flac',
            'audio/x-m4a',
            // Video
            'video/mp4',
            'video/mpeg',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-ms-wmv',
            'video/x-flv',
            'video/webm',
            // E-books
            'application/epub+zip',
            'application/x-mobipocket-ebook',
            // Spreadsheets
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            // Presentations
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            // Web files
            'text/css',
            'application/javascript',
            'text/javascript',
            'application/json',
            'application/xml',
            'text/xml',
        );

        // Apply filter to allow customization
        $allowedMimeTypes = apply_filters('odse_allowed_mime_types', $allowedMimeTypes);

        // Check if the detected MIME type is in our allowed list
        if (!in_array($filetype['type'], $allowedMimeTypes, true)) {
            return false;
        }

        return true;
    }
}
