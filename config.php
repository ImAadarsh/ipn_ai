<?php
// Vimeo API Credentials
define('VIMEO_CLIENT_ID', 'X+jnAV4SBZw7FcWVadVNC+oiFz5UlyYbnmTEa9jJOWXoOTt2PH39VAKk55x4/IQ9SkLSjesujlV6RkPivrgQn6pCYImqiqlV0GIpDZJzAaBDhkLJCqbon6IbBfDsve6a');
define('VIMEO_PERSONAL_TOKEN', 'd400a5c1d100216c1288f50dbc0b6444');

// Gemini API Key
define('GEMINI_API_KEY', 'AIzaSyC0xbyaZnrJ-CUI-lgvg0PuoHn2VW6_gvI');

// Database Configuration
define('DB_HOST', '82.180.142.204');
define('DB_USER', 'u954141192_ipnacademy');
define('DB_PASS', 'x?OR+Q2/D');
define('DB_NAME', 'u954141192_ipnacademy');

// Database Connection
$connect = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$connect) {
    die("Connection failed: " . mysqli_connect_error());
}
?> 