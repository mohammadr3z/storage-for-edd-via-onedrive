<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OneDrive Downloader
 * 
 * Generates download links for EDD downloads stored in OneDrive.
 */
class ODSE_OneDrive_Downloader
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new ODSE_OneDrive_Config();
        $this->client = new ODSE_OneDrive_Client();
    }

    /**
     * Generate a OneDrive download URL.
     * 
     * This method is hooked to 'edd_requested_file' filter.
     * 
     * @param string $file The original file URL
     * @param array $downloadFiles Array of download files
     * @param string $fileKey The key of the current file
     * @return string The download URL or original file
     */
    public function generateUrl($file, $downloadFiles, $fileKey)
    {
        if (empty($downloadFiles[$fileKey])) {
            return $file;
        }

        $fileData = $downloadFiles[$fileKey];
        $filename = $fileData['file'];

        // Check if this is a OneDrive file
        $urlPrefix = $this->config->getUrlPrefix();
        if (strpos($filename, $urlPrefix) !== 0) {
            return $file;
        }

        // Extract the OneDrive item ID from the URL
        $itemId = substr($filename, strlen($urlPrefix));

        if (!$this->config->isConnected()) {
            $this->config->debug('OneDrive not connected for download: ' . $itemId);
            return $file;
        }

        try {
            // Get download URL from OneDrive (uses @microsoft.graph.downloadUrl)
            $downloadUrl = $this->client->getDownloadUrl($itemId);

            if ($downloadUrl) {
                return $downloadUrl;
            }

            $this->config->debug('Failed to get download URL for: ' . $itemId);
            return $file;
        } catch (Exception $e) {
            $this->config->debug('Error generating download URL: ' . $e->getMessage());
            return $file;
        }
    }
}
