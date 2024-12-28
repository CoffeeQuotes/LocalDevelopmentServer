# PHP Project Directory Manager

A modern, feature-rich web interface for managing local PHP projects and directories. Built with PHP, Alpine.js, and Tailwind CSS, this application provides an intuitive way to manage, monitor, and analyze your development projects.

## Features

### 🚀 Core Functionality
- **Directory Management**
  - Create new directories using a user-friendly modal.
  - Rename existing directories easily.
  - Delete directories with confirmation prompts to prevent accidental removal.
  - Download directories as ZIP archives for easy sharing or backups.
  - Open directories in VS Code directly (requires VS Code and proper configuration).

### 📊 Analytics & Monitoring
- **Directory Analytics**
  - Display detailed statistics including the number of files and subdirectories.
  - Analyze directory size and file type distribution.
  - Visualize data with interactive charts and graphs.
  - Real-time updates for dynamic project tracking.

### 🎯 User Experience
- **Modern Interface**
  - Clean, responsive design optimized for all devices.
  - Dark/Light mode toggle for better accessibility.
  - Icon-based actions for intuitive navigation.
  - Tooltip guidance for easier feature discovery.
  - Search and sort functionality for directories and files.

### 📝 Project Organization
- **Recent Access Tracking**
  - Track and display recently accessed directories for quick navigation.
  - Highlight frequently used projects.
  - Clear history option to manage privacy and clutter.
  - New access indicators to easily spot recently modified directories.

### 🛠 System Features
- **Health Monitoring**
  - Perform PHP version checks to ensure compatibility.
  - Verify required extensions like ZipArchive for full functionality.
  - Check directory permissions and provide recommendations.
  - View system status through a detailed dashboard.

### 🌟 Optional Enhancements
- **File Upload and Management**
  - Drag-and-drop file uploads.
  - Inline editing for text files.
  - File preview support for common formats like images, PDFs, and text files.

- **Context Menu Support**
  - Right-click actions for file and directory operations.
  - Multi-select options for bulk actions.

- **Directory Visualization**
  - Tree structure view for nested directories.
  - Breadcrumb navigation for deeper directories.

## Requirements

- **Server Requirements**
  - PHP 7.4 or higher
  - Web server (Apache/Nginx)
  - ZipArchive PHP extension
  - Write permissions for the application directory

- **Client Requirements**
  - Modern web browser (Chrome, Firefox, Edge, Safari)

## Installation

1. Clone the repository to your local server:
   ```bash
   git clone https://github.com/your-repo/php-directory-manager.git
   cd php-directory-manager
   ```
2. Ensure the server meets the requirements listed above.
3. Grant write permissions to the application directory:
   ```bash
   chmod -R 775 /path/to/your/project
   ```
4. Start your web server and open the project in your browser.

## Usage

1. Access the application through your browser (e.g., `http://localhost/php-directory-manager`).
2. Use the intuitive interface to create, rename, or delete directories.
3. Explore analytics for insights into your project structure.
4. Monitor system health through the dashboard.
5. Utilize optional enhancements for a seamless experience.

## Contribution

We welcome contributions! To contribute:
1. Fork the repository.
2. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature
   ```
3. Commit your changes and push:
   ```bash
   git commit -m "Add your feature"
   git push origin feature/your-feature
   ```
4. Open a pull request for review.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details.

## Acknowledgments

- Inspired by modern web management tools like WordPress and cPanel.
- Built with the power of open-source technologies: PHP, Alpine.js, and Tailwind CSS.

---

For any questions or feedback, feel free to open an issue or contact us directly through our GitHub repository.

