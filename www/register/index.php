<?php
// Wizard entry: send the visitor to their first incomplete step.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();

$state = registration_state();
header('Location: ' . registration_step_url((int)$state['max_step'] + 1));
exit;
