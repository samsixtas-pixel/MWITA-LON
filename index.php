<?php
/**
 * index.php — entry point.
 */

require_once __DIR__ . '/includes/autoload.php';

redirect(User::isLoggedIn() ? User::homeUrl() : BASE_URL . 'login.php');
