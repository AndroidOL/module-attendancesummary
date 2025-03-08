# Gibbon Attendance Review Module

This repository contains code for the **Gibbon Attendance Module** – a part of the flexible, open school platform Gibbon. The module provides features for viewing and querying student attendance information, as well as transferring attendance records between classes.

> **Note:** Some features (such as the transfer of attendance records) are not fully developed yet.

## Features

- **View Attendance Information:**  
  Allows users (Admins, Teachers, and Parents) to query attendance records for a specified student.  
  - For teachers and admins: view complete attendance records.
  - **(Incomplete)** For parents: view their child's attendance information.

- **Attendance Summary:**  
  Display daily attendance summaries including the expected score and actual attendance score, calculated based on attendance type (e.g., Present, Sick Leave, Absent).

- **Date Range Validation:**  
  Ensures valid date ranges are used for querying attendance data with default values and limits to prevent excessive date ranges.

- **Role & Permission Handling:**  
  Built-in logic to restrict access based on user roles (Admin, Teacher, Student, Parent, Support).

- **Transfer Attendance Records (Under Development):**  
  A feature intended to transfer attendance records from one class period to another.  
  **Status:** *Not fully implemented.* Some parts of this functionality are still in development.

## Installation

1. **Clone the Repository:**
    ```bash
    git clone https://github.com/yourusername/gibbon-attendance-module.git
    cd gibbon-attendance-module
    ```

2. **Configure the Environment:**
    - Ensure you have PHP installed (version 7.4 or higher is recommended).
    - Configure your web server (e.g., Apache, Nginx) to serve the module.
    - Set up your database connection (using PDO) in your configuration files.

3. **Dependencies:**
    - This module is part of the Gibbon framework. Ensure that you have Gibbon installed and configured.
    - The module makes use of Composer-managed packages. If you are using Composer, run:
      ```bash
      composer install
      ```

## Usage

1. Go to "System Settings" > "Attendance Summary" Module Settings.
2. Configure the module's basic information, such as module name and description.
3. Set up permissions to define which roles can access the module's features.

## Code Structure

- **Module Functions:**  
  Contains helper functions for:
  - Attendance record retrieval (`getAttendanceRecords()`).
  - Date range validation (`getValidatedDates()`).
  - Daily attendance calculations (`calculateDailyAttendance()`).
  - Permission and role processing.
  - Transfer record generation (`generateTransferData()`, `getTransferData()`).

- **Action Rows:**  
  Defines actions for viewing attendance (for students, staff, parents) as well as for transferring attendance records.  
  Permissions for each action are configured based on role categories.

- **SQL Queries:**  
  Uses complex SQL queries with Common Table Expressions (CTEs) to fetch attendance and scheduling data from the database.

## Future Development

- **Transfer Attendance Records:**  
  Further development is required to fully implement the transfer attendance functionality.
- **User Interface Improvements:**  
  Improved UI/UX for better data visualization and interaction.

## Contributing

Contributions are welcome! If you would like to contribute to this module, please fork the repository and submit a pull request with your changes. For major changes, please open an issue first to discuss what you would like to change.

## License

This project is licensed under the GNU General Public License v3.0. See [LICENSE](http://www.gnu.org/licenses/) for more details.

## Acknowledgments

- **Gibbon Project:**  
  Built as part of the [Gibbon Flexible, Open School Platform](https://gibbonedu.org/).
- **Community Contributions:**  
  Special thanks to Ross Parker, Sandra Kuipers, and the Gibbon community for their continuous support.

