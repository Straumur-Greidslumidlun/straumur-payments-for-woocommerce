Straumur WooCommerce Plugin

Source control for Straumur's WooCommerce plugin.

More information about WordPress plugin development can be found in the Plugin Handbook. WooCommerce plugin guide can be found here.
Guide for New Developers Working on the WooCommerce Plugin

Welcome to the WooCommerce development team! This guide will walk you through the essential steps to start working on the WooCommerce plugin, including cloning the GitHub repository, installing the plugin locally, and updating the WordPress.org SVN repository.

1. Cloning the GitHub Repository

Prerequisites:

Ensure you have Git installed on your system.
Obtain access to the GitHub repository.
Steps:

Open your terminal or Git client.
Navigate to the directory where you want to store the repository:
cd /path/to/your/projects
Clone the repository using the provided URL:
git clone https://github.com/kvika/straumur-payments-for-woocommerce.git
Navigate into the cloned repository:
cd woocommerce-plugin
(Optional) Create a new branch for your feature or fix:
git checkout -b feature/your-feature-name 2. Installing the Plugin in WooCommerce

Prerequisites:

A local WordPress environment (e.g., using Local by Flywheel, XAMPP, or Docker).
WooCommerce installed and activated.
Steps:

Locate the WooCommerce plugin directory in your local WordPress installation:
/path/to/wordpress/wp-content/plugins/
Copy the cloned repository into the plugins directory:
cp -R /path/to/woocommerce-plugin /path/to/wordpress/wp-content/plugins/
Navigate to your WordPress admin dashboard (http://localhost/wp-admin).
Go to Plugins > Installed Plugins.
Locate your plugin in the list and click Activate.
(Optional) To enable debugging, add the following to your wp-config.php file:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
Debug logs will appear in the wp-content/debug.log file. 3. Updating the SVN Repository on WordPress.org

Prerequisites:

Access to the Straumur Woocommerce plugin repository
Ensure your plugin is production-ready and tested.
Steps:

Create a Pull Request into main When all the changes that should go into next version are merged into dev branch, create a pull request to main.

Create a new Release Once pull request has been approved and merged into main you should create a new Release with a tag that is the version of the release. The version should be the same as the Stable Tag in release.txt file.

This will trigger a Github action deployment trigger that deploys to Wordpress.org SVN repository.

Verify the Update: Check the WordPress.org plugin page to ensure your changes are live.

4. Tips for Success

Version Control: Always increment the plugin version in the main file and readme.txt.
Testing: Test your plugin thoroughly in different environments.
Documentation: Keep the readme.txt file updated with accurate changelogs.
Collaboration: Use pull requests to review changes with the team before committing.
Feel free to reach out to the team if you have any questions or run into issues. Happy coding!
