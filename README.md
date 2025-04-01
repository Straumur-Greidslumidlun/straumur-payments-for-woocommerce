# Straumur WooCommerce Plugin

Source control for Straumur's WooCommerce plugin.

More information about WordPress plugin development can be found in the [Plugin Handbook](https://developer.wordpress.org/plugins/).  
WooCommerce plugin guide can be found [here](https://woocommerce.com/documentation/).

---

## Guide for New Developers Working on the WooCommerce Plugin

Welcome to the WooCommerce development team! This guide will walk you through the essential steps to start working on the WooCommerce plugin, including cloning the GitHub repository, installing the plugin locally, and updating the WordPress.org SVN repository.

---

### 1. Cloning the GitHub Repository

#### Prerequisites:
- Ensure you have Git installed on your system.
- Obtain access to the GitHub repository.

#### Steps:
1. Open your terminal or Git client.
2. Navigate to the directory where you want to store the repository:
   ```bash
   cd /path/to/your/projects
   ```
3. Clone the repository using the provided URL:
   ```bash
   git clone https://github.com/kvika/straumur-payments-for-woocommerce.git
   ```
4. Navigate into the cloned repository:
   ```bash
   cd woocommerce-plugin
   ```
5. *(Optional)* Create a new branch for your feature or fix:
   ```bash
   git checkout -b feature/your-feature-name
   ```

---

### 2. Installing the Plugin in WooCommerce

#### Prerequisites:
- A local WordPress environment (e.g., using Local by Flywheel, XAMPP, or Docker).
- WooCommerce installed and activated.

#### Steps:
1. Locate the WooCommerce plugin directory in your local WordPress installation:
   ```
   /path/to/wordpress/wp-content/plugins/
   ```
2. Copy the cloned repository into the plugins directory:
   ```bash
   cp -R /path/to/woocommerce-plugin /path/to/wordpress/wp-content/plugins/
   ```
3. Navigate to your WordPress admin dashboard:  
   [http://localhost/wp-admin](http://localhost/wp-admin)
4. Go to **Plugins > Installed Plugins**.
5. Locate your plugin in the list and click **Activate**.
6. *(Optional)* To enable debugging, add the following to your `wp-config.php` file:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
   Debug logs will appear in the `wp-content/debug.log` file.

---

### 3. Updating the SVN Repository on WordPress.org

#### Prerequisites:
- Access to the Straumur WooCommerce plugin repository.
- Ensure your plugin is production-ready and tested.

#### Steps:
1. **Create a Pull Request into `main`:**  
   When all the changes that should go into the next version are merged into the `dev` branch, create a pull request to `main`.

2. **Create a New Release:**  
   Once the pull request has been approved and merged into `main`, create a new release with a tag that matches the version of the release.  
   The version should be the same as the `Stable Tag` in the `release.txt` file.

3. **Trigger Deployment:**  
   This will trigger a GitHub Action deployment that deploys to the WordPress.org SVN repository.

4. **Verify the Update:**  
   Check the WordPress.org plugin page to ensure your changes are live.

---

### 4. Tips for Success

- **Version Control:** Always increment the plugin version in the main file and `readme.txt`.
- **Testing:** Test your plugin thoroughly in different environments.
- **Documentation:** Keep the `readme.txt` file updated with accurate changelogs.
- **Collaboration:** Use pull requests to review changes with the team before committing.

---

Feel free to reach out to the team if you have any questions or run into issues.  
**Happy coding!**
