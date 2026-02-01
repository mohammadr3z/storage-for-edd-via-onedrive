=== Storage for EDD via OneDrive ===
author: mohammadr3z
Contributors: mohammadr3z
Tags: easy-digital-downloads, onedrive, storage, cloud, edd
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.0.4
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable secure cloud storage and delivery of your digital products through Microsoft OneDrive for Easy Digital Downloads.

== Description ==

Storage for EDD via OneDrive is a powerful extension for Easy Digital Downloads that allows you to store and deliver your digital products using Microsoft OneDrive cloud storage. This plugin provides seamless integration with Microsoft Graph API, featuring OAuth2 authentication and secure download links.


= Key Features =

* **OneDrive Integration**: Store your digital products securely in Microsoft OneDrive
* **OAuth2 Authentication**: Secure and easy connection to your Microsoft account
* **Temporary Download Links**: Generates secure temporary download URLs via @microsoft.graph.downloadUrl
* **Easy File Management**: Upload files directly to OneDrive through WordPress admin
* **Media Library Integration**: Browse and select files from your OneDrive within WordPress
* **Folder Support**: Navigate and organize files in folders
* **Security First**: Built with WordPress security best practices
* **Developer Friendly**: Clean, well-documented code with hooks and filters

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/storage-for-edd-via-onedrive` directory, or install the plugin through the WordPress plugins screen directly.
2. Make sure you have Easy Digital Downloads plugin installed and activated.
3. Run `composer install` in the plugin directory if installing from source (not needed for release versions).
4. Activate the plugin through the 'Plugins' screen in WordPress.
5. Navigate to Downloads > Settings > Extensions > OneDrive Storage to configure the plugin.

== Configuration ==

1. Register an Azure AD Application at [Azure App Registrations](https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade)
2. Add API permissions for Microsoft Graph (Files.Read, Files.ReadWrite, User.Read, offline_access)
3. Set Redirect URI to: `https://your-site.com/odse-oauth-callback/`
4. Create a Client Secret and copy the value
5. Go to Downloads > Settings > Extensions > OneDrive Storage
6. Enter your Application (client) ID and Client Secret
7. Save settings and click "Connect to OneDrive"

== Usage ==

= Browsing and Selecting Files =

1. When creating or editing a download in Easy Digital Downloads
2. Click the "Browse OneDrive" button next to the file URL field
3. Browse your OneDrive storage using the folder navigation
4. Use the breadcrumb navigation bar to quickly jump to parent folders
5. Use the search box in the header to filter files by name
6. Click "Select" to use an existing file for your download

= Uploading New Files =

1. In the OneDrive browser, click the "Upload" button in the header row
2. The upload form will appear above the file list
3. Choose your file and click "Upload"
4. After a successful upload, the file URL will be automatically set with the OneDrive prefix
5. Click the button again to hide the upload form

== Frequently Asked Questions ==

= How secure are the download links? =

The plugin uses Microsoft Graph API's `@microsoft.graph.downloadUrl` which provides temporary, pre-authenticated download links. These links are generated on-demand when a customer purchases your product.

= What file types are supported for upload? =

The plugin supports safe file types including:
* Archives: ZIP, RAR, 7Z, TAR, GZ
* Documents: PDF, DOC, DOCX, TXT, RTF, XLS, XLSX, CSV, PPT, PPTX
* Images: JPG, JPEG, PNG, GIF, WEBP
* Audio: MP3, WAV, OGG, FLAC, M4A
* Video: MP4, AVI, MOV, WMV, FLV, WEBM
* E-books: EPUB, MOBI, AZW, AZW3
* Web files: CSS, JS, JSON, XML

Dangerous file types (executables, scripts) are automatically blocked for security.

= Can I customize the URL prefix for OneDrive files? =

Yes, developers can customize the URL prefix using the `odse_url_prefix` filter. Add this code to your theme's functions.php:

`
function customize_onedrive_url_prefix($prefix) {
    return 'edd-myprefix://'; // Change to your preferred prefix
}
add_filter('odse_url_prefix', 'customize_onedrive_url_prefix');
`

= Can I customize the allowed file types (MIME types)? =

Yes, developers can customize the allowed MIME types using the `odse_allowed_mime_types` filter.

== Screenshots ==

1. Admin panel user interface
2. File selection from OneDrive storage section
3. File upload to OneDrive storage interface

== Changelog ==

= 1.0.4 =
* Use wp_enqueue commands: Replaced inline <style> and <script> in includes/class-media-library.php (admin media library)

= 1.0.3 =
* Added: New "Browse" button next to file inputs for easier file selection.
* Improved: Modernized file browser UI with a dedicated modal window.
* Improved: File browser is now context-aware, opening directly to the selected file's folder.
* Improved: Browse button is automatically hidden if the plugin is not configured.
* Improved: Removed legacy "OneDrive Library" tab from the standard WordPress media uploader for a cleaner interface.

= 1.0.2 =
* Added: Native search input type with clear ("X") icon support for a cleaner UI.
* Improved: Mobile breadcrumb navigation with path wrapping for long directory names.
* Improved: Reduced separator spacing in breadcrumbs on mobile devices.
* Improved: Media library table styling for more consistent file and folder display.
* Improved: Redesigned folder rows with better icons and refined hover effects.
* Improved: Enhanced mobile responsiveness for the file browser table.
* Fixed: Corrected file name and path display order in the media library.
* Improved: Standardized header row spacing and title font sizes for UI consistency.
* Improved: Enhanced notice detail styling for better error/success message readability.
* Improved: More robust handling of file lists with additional data validation.
* Security: Standardized use of wp_json_encode() for client-side data.
* Improved: Unified root folder label as "Home" across all breadcrumb states for consistent navigation.

= 1.0.1 =
* Added: Breadcrumb navigation in file browser - click any folder in the path to navigate directly.
* Improved: Integrated search functionality directly into the breadcrumb navigation bar for a cleaner UI.
* Improved: Better navigation experience without needing the Back button.
* Improved: Enhanced styling for search inputs and buttons, including compact padding.
* Fixed: RTL layout issues for breadcrumbs and navigation buttons.
* Cleaned: Removed legacy CSS and unused search container elements.

= 1.0.0 =
* Initial release
* Microsoft Graph API OAuth2 integration
* Temporary download link generation via @microsoft.graph.downloadUrl
* Media library integration
* File upload functionality
* Admin settings interface
* Security enhancements and validation
* Internationalization support



== External services ==

This plugin connects to Microsoft Graph API (OneDrive) to manage files, create download links, and handle authentication.

It sends the necessary authentication tokens and file requests to Microsoft servers. This happens when you browse your OneDrive files in the dashboard, upload files, or when a customer downloads a file.

* **Service**: Microsoft Graph API (OneDrive)
* **Used for**: Authentication, file browsing, uploading, and generating download links.
* **Data sent**: OAuth tokens, file metadata, file content (during upload).
* **URLs**:
    * `https://graph.microsoft.com` (API calls)
    * `https://login.microsoftonline.com` (Authentication)
* **Legal**: [Terms of Service](https://www.microsoft.com/en-us/legal/terms-of-use), [Privacy Policy](https://privacy.microsoft.com/en-us/privacystatement)

== Support ==

For support and bug reports, please use the WordPress.org plugin support forum.

If you find this plugin helpful, please consider leaving a review on WordPress.org.

== Other Storage Providers ==

Looking for a different storage provider? Check out our other plugins:

* [Storage for EDD via Box](https://wordpress.org/plugins/storage-for-edd-via-box/) - Use Box for your digital product storage
* [Storage for EDD via Dropbox](https://wordpress.org/plugins/storage-for-edd-via-dropbox/) - Use Dropbox for your digital product storage
* [Storage for EDD via S3-Compatible](https://wordpress.org/plugins/storage-for-edd-via-s3-compatible/) - Use S3-compatible services like MinIO, DigitalOcean Spaces, Linode, Wasabi, and more

== Privacy Policy ==

This plugin requires authorization to access your Microsoft OneDrive account for file storage and retrieval. It does not collect or store any personal data beyond the OAuth tokens needed to maintain the connection. All file storage and delivery is handled through Microsoft's secure infrastructure.
