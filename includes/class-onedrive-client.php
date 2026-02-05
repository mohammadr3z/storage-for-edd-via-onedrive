<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OneDrive API Client
 * 
 * Handles all Microsoft Graph API communications including OAuth2 authentication,
 * file listing, uploads, and download URL generation.
 */
class ODSE_OneDrive_Client
{
    private $httpClient = null;
    private $config;

    // Microsoft OAuth2 endpoints (using /consumers/ for personal Microsoft accounts)
    const AUTH_URL = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize';
    const TOKEN_URL = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token';
    const API_URL = 'https://graph.microsoft.com/v1.0';

    // Required scopes
    const SCOPES = 'Files.Read Files.ReadWrite Files.Read.All Files.ReadWrite.All offline_access User.Read';

    public function __construct()
    {
        $this->config = new ODSE_OneDrive_Config();
    }

    /**
     * Get HTTP client instance (Guzzle)
     * @return GuzzleHttp\Client|null
     */
    public function getClient()
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        try {
            $clientOptions = [
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => true,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => 'storage-for-edd-via-onedrive/1.0'
                ]
            ];

            $this->httpClient = new \GuzzleHttp\Client($clientOptions);
            return $this->httpClient;
        } catch (Exception $e) {
            $this->config->debug('Error creating HTTP client: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate OAuth2 authorization URL
     * 
     * @param string $redirect_uri The callback URL
     * @return string Authorization URL
     */
    public function getAuthorizationUrl($redirect_uri)
    {
        $params = [
            'client_id' => $this->config->getClientId(),
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'response_mode' => 'query',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     * 
     * @param string $code Authorization code from OAuth callback
     * @param string $redirect_uri The callback URL (must match the one used for authorization)
     * @return array|false Token data or false on failure
     */
    public function exchangeCodeForToken($code, $redirect_uri)
    {
        $client = $this->getClient();
        if (!$client) {
            return false;
        }

        try {
            $response = $client->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirect_uri,
                    'client_id' => $this->config->getClientId(),
                    'client_secret' => $this->config->getClientSecret(),
                    'scope' => self::SCOPES,
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->config->debug('Token exchange failed with status: ' . $statusCode);
                return false;
            }

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['access_token'])) {
                return [
                    'access_token' => $body['access_token'],
                    'refresh_token' => isset($body['refresh_token']) ? $body['refresh_token'] : '',
                    'expires_in' => isset($body['expires_in']) ? $body['expires_in'] : 3600,
                ];
            }

            return false;
        } catch (Exception $e) {
            $this->config->debug('Token exchange error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Refresh the access token using refresh token
     * 
     * @return bool Success status
     */
    public function refreshAccessToken()
    {
        $client = $this->getClient();
        $refresh_token = $this->config->getRefreshToken();

        if (!$client || empty($refresh_token)) {
            return false;
        }

        try {
            $response = $client->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                    'client_id' => $this->config->getClientId(),
                    'client_secret' => $this->config->getClientSecret(),
                    'scope' => self::SCOPES,
                ]
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->config->debug('Token refresh failed with status: ' . $statusCode);
                return false;
            }

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['access_token'])) {
                $this->config->saveTokens(
                    $body['access_token'],
                    isset($body['refresh_token']) ? $body['refresh_token'] : '',
                    isset($body['expires_in']) ? $body['expires_in'] : 3600
                );
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->config->debug('Token refresh error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get valid access token (refreshing if needed)
     * 
     * @return string|false Access token or false on failure
     */
    public function getValidAccessToken()
    {
        if ($this->config->isTokenExpired()) {
            if (!$this->refreshAccessToken()) {
                return false;
            }
        }

        return $this->config->getAccessToken();
    }

    /**
     * Make an authenticated API request to Microsoft Graph
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $data Request data
     * @param bool $isJson Whether to send data as JSON
     * @param bool $retry Whether this is a retry after token refresh (prevents infinite recursion)
     * @return array|false Response data or false on failure
     */
    private function apiRequest($endpoint, $method = 'GET', $data = [], $isJson = true, $retry = false)
    {
        $client = $this->getClient();
        $access_token = $this->getValidAccessToken();

        if (!$client || !$access_token) {
            return false;
        }

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'timeout' => 30,
                'connect_timeout' => 10
            ];

            if (!empty($data)) {
                if ($isJson) {
                    $options['headers']['Content-Type'] = 'application/json';
                    $options['body'] = json_encode($data);
                } else {
                    $options['form_params'] = $data;
                }
            }

            $response = $client->request($method, self::API_URL . $endpoint, $options);

            $statusCode = $response->getStatusCode();

            // Handle token expiration (only retry once to prevent infinite recursion)
            if ($statusCode === 401 && !$retry) {
                if ($this->refreshAccessToken()) {
                    // Retry request with new token
                    return $this->apiRequest($endpoint, $method, $data, $isJson, true);
                }
                return false;
            } elseif ($statusCode === 401) {
                $this->config->debug('API request failed after token refresh: ' . $endpoint);
                return false;
            }

            // Handle permission denied
            if ($statusCode === 403) {
                $this->config->debug('API permission denied: ' . $endpoint . ' - Check Azure app permissions');
                return false;
            }

            if ($statusCode !== 200 && $statusCode !== 201) {
                $this->config->debug('API request failed: ' . $endpoint . ' - Status: ' . $statusCode);
                return false;
            }

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            $this->config->debug('API request error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List files in a OneDrive folder
     * 
     * @param string $folderId Folder ID (empty string or 'root' for root folder)
     * @return array List of files
     */
    public function listFiles($folderId = '')
    {
        $files = [];

        if (!$this->config->isConnected()) {
            return $files;
        }

        // Build endpoint - use 'root' for root folder
        if (empty($folderId) || $folderId === 'root') {
            $endpoint = '/me/drive/root/children';
        } else {
            $endpoint = '/me/drive/items/' . $folderId . '/children';
        }

        $response = $this->apiRequest($endpoint);

        // Debug: log the API response - Removed for performance
        // $this->config->debug('listFiles response for folder "' . $folderId . '": ' . print_r($response, true));

        if (!$response || !isset($response['value'])) {
            $this->config->debug('No value in response or response is false');
            return $files;
        }

        $allEntries = $response['value'];

        // Handle pagination if there are more files
        while (isset($response['@odata.nextLink'])) {
            $nextUrl = str_replace(self::API_URL, '', $response['@odata.nextLink']);
            $response = $this->apiRequest($nextUrl);

            if ($response && isset($response['value'])) {
                $allEntries = array_merge($allEntries, $response['value']);
            }
        }

        foreach ($allEntries as $item) {
            $isFolder = isset($item['folder']);

            $path = isset($item['parentReference']['path']) ? $item['parentReference']['path'] . '/' . $item['name'] : '/' . $item['name'];

            // Clean up path - remove /drive/root: prefix
            if (strpos($path, '/drive/root:') === 0) {
                $path = substr($path, 12);
            }

            $files[] = [
                'name' => $item['name'],
                'id' => $item['id'],
                'path' => $path,
                'size' => isset($item['size']) ? $item['size'] : 0,
                'modified' => isset($item['lastModifiedDateTime']) ? $item['lastModifiedDateTime'] : '',
                'is_folder' => $isFolder,
                'download_url' => isset($item['@microsoft.graph.downloadUrl']) ? $item['@microsoft.graph.downloadUrl'] : ''
            ];
        }

        return $files;
    }

    /**
     * Get list of folders for folder selection dropdown
     * 
     * @param string $parentId Parent folder ID
     * @return array List of folders (max 50)
     */
    public function listFolders($parentId = '')
    {
        $folders = [];
        $maxFolders = 50;

        if (!$this->config->isConnected()) {
            return $folders;
        }

        // Build endpoint with filter for folders only
        if (empty($parentId) || $parentId === 'root') {
            $endpoint = '/me/drive/root/children?$filter=folder ne null&$top=' . $maxFolders;
        } else {
            $endpoint = '/me/drive/items/' . $parentId . '/children?$filter=folder ne null&$top=' . $maxFolders;
        }

        $response = $this->apiRequest($endpoint);

        if (!$response || !isset($response['value'])) {
            $this->config->debug('listFolders: No response or value');
            return $folders;
        }

        foreach ($response['value'] as $item) {
            if (isset($item['folder'])) {
                $folders[$item['id']] = $item['name'];
                if (count($folders) >= $maxFolders) {
                    return $folders;
                }
            }
        }

        return $folders;
    }

    /**
     * Get a download URL for a file
     * 
     * @param string $itemId File ID in OneDrive
     * @return string|false Download URL or false on failure
     */
    public function getDownloadUrl($itemId)
    {
        // Check if $itemId looks like a path (contains / or starts with /)
        if (strpos($itemId, '/') !== false || substr($itemId, 0, 1) === '/') {
            // It's a path. Ensure it is encoded correctly.
            // Normalize path: ensure leading slash, remove trailing
            $path = '/' . ltrim(rtrim($itemId, '/'), '/');

            // Encode segments
            $segments = explode('/', trim($path, '/'));
            $encodedPath = implode('/', array_map('rawurlencode', $segments));

            $endpoint = '/me/drive/root:/' . $encodedPath . '?select=id,name,@microsoft.graph.downloadUrl';
            $response = $this->apiRequest($endpoint);
        } else {
            // Assume ID first
            $response = $this->apiRequest('/me/drive/items/' . $itemId . '?select=id,name,@microsoft.graph.downloadUrl');

            // Fallback: If ID lookup fails, it might be a root file path "file.txt"
            if ((!$response || !isset($response['@microsoft.graph.downloadUrl'])) && strpos($itemId, '.') !== false) {
                // Try as root path
                $encodedName = rawurlencode($itemId);
                $endpoint = '/me/drive/root:/' . $encodedName . '?select=id,name,@microsoft.graph.downloadUrl';
                $response = $this->apiRequest($endpoint);
            }
        }

        if ($response && isset($response['@microsoft.graph.downloadUrl'])) {
            return $response['@microsoft.graph.downloadUrl'];
        }

        return false;
    }

    /**
     * Upload a file to OneDrive
     * 
     * @param string $filename Filename
     * @param string $content File content
     * @param string $folderId Parent folder ID
     * @return array|false File metadata or false on failure
     */
    public function uploadFile($filename, $content, $folderId = '')
    {
        $client = $this->getClient();
        $access_token = $this->getValidAccessToken();

        if (!$client || !$access_token) {
            return false;
        }

        // Build endpoint
        if (empty($folderId) || $folderId === 'root') {
            $endpoint = '/me/drive/root:/' . rawurlencode($filename) . ':/content';
        } else {
            $endpoint = '/me/drive/items/' . $folderId . ':/' . rawurlencode($filename) . ':/content';
        }

        try {
            $response = $client->request('PUT', self::API_URL . $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/octet-stream',
                ],
                'body' => $content
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200 && $statusCode !== 201) {
                $this->config->debug('Upload failed with status: ' . $statusCode);
                return false;
            }

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            $this->config->debug('Upload error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get folder info (details + parent ID) in single API call
     * Performance optimization: replaces separate getFolderDetails + getParentFolderId calls
     * 
     * @param string $folderId Folder ID
     * @return array ['name' => string, 'path' => string, 'parentId' => string|false]
     */
    public function getFolderInfo($folderId)
    {
        if (empty($folderId) || $folderId === 'root') {
            return [
                'name' => 'root',
                'path' => '',
                'parentId' => false
            ];
        }

        // Single API call to get all needed info
        $response = $this->apiRequest('/me/drive/items/' . $folderId . '?select=id,name,parentReference');

        if ($response && isset($response['name'])) {
            // Build path
            $path = isset($response['parentReference']['path'])
                ? $response['parentReference']['path'] . '/' . $response['name']
                : '/' . $response['name'];

            // Clean up path - remove /drive/root: prefix
            if (strpos($path, '/drive/root:') === 0) {
                $path = substr($path, 12);
            }

            // Get parent ID
            $parentId = 'root';
            if (isset($response['parentReference']['id'])) {
                // Check if parent is root
                if (isset($response['parentReference']['path']) && $response['parentReference']['path'] === '/drive/root:') {
                    $parentId = 'root';
                } else {
                    $parentId = $response['parentReference']['id'];
                }
            }

            return [
                'name' => $response['name'],
                'path' => $path,
                'parentId' => $parentId
            ];
        }

        return [
            'name' => '',
            'path' => '',
            'parentId' => false
        ];
    }

    /**
     * Get current user info
     * 
     * @return array|false Account info or false on failure
     */
    public function getAccountInfo()
    {
        $response = $this->apiRequest('/me');
        return $response;
    }
    /**
     * Get folder ID by path
     * 
     * @param string $path Folder path
     * @return string|false Folder ID or false on failure
     */
    public function getFolderIdByPath($path)
    {
        // Clean path of potential protocol prefix and leading slashes
        $path = str_replace('onedrive://', '', $path);
        $path = trim($path, '/');

        if (empty($path)) {
            return 'root';
        }

        // Encode path segments
        $segments = explode('/', $path);
        $encodedPath = implode('/', array_map('rawurlencode', $segments));

        // Request id, folder (to check if it is a folder), and parentReference (to get parent if it is a file)
        $endpoint = '/me/drive/root:/' . $encodedPath . '?select=id,folder,parentReference';
        $response = $this->apiRequest($endpoint);

        if ($response && isset($response['id'])) {
            // Verify it is actually a folder
            if (isset($response['folder'])) {
                return $response['id'];
            }
            // If it's a file, get its parent from parentReference
            if (isset($response['parentReference']['id'])) {
                return $response['parentReference']['id'];
            }
            // Fallback for root parent or missing parent info
            return 'root';
        }

        return false;
    }
}
