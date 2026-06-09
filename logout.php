<?php
// 1. Simulan ang session para ma-access ang kasalukuyang active session data
session_start();

// 2. Alisin o i-clear ang lahat ng session variables na naka-save sa server
session_unset();

// 3. I-destroy o permanenteng burahin ang session record
session_destroy();

// 4. I-redirect ang user pabalik sa login page para hindi na nila ma-access ang dashboard
header("Location: login.php");
exit;
?>