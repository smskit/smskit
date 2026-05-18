<?php
/**
 * TextFlow SMS Gateway - Router
 * Routes to setup or dashboard based on installation status.
 */

if (file_exists(__DIR__ . '/config/admin.json')) {
    header('Location: dashboard/');
    exit;
} else {
    header('Location: setup.php');
    exit;
}
