<?php
// Entry point for the information-request flow: resume wherever the visitor
// left off. Mirrors register/index.php.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();

$state = inquiry_state();
header('Location: ' . inquiry_step_url((int)$state['max_step'] + 1));
exit;
