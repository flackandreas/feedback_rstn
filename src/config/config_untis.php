<?php
/**
 * src/config/config_untis.php
 * Configuration for WebUntis API access.
 */

// [IMPORTANT] Replace these with your real WebUntis credentials
// It is recommended to use environment variables for sensitive data.

return [
    'server'   => 'https://server.webuntis.com', // e.g., https://triton.webuntis.com
    'school'   => 'YOUR_SCHOOL_NAME',            // Your school name in WebUntis
    'username' => 'YOUR_API_USERNAME',           // Username for API access
    'password' => 'YOUR_API_PASSWORD',           // Password for API access
    
    // Optional: Enable/Disable WebUntis integration
    'enabled'  => false, 
];
