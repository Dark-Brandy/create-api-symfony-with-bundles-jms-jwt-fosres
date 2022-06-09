<?php

// This file has been auto-generated by the Symfony Routing Component.

return [
    '_preview_error' => [['code', '_format'], ['_controller' => 'error_controller::preview', '_format' => 'html'], ['code' => '\\d+'], [['variable', '.', '[^/]++', '_format'], ['variable', '/', '\\d+', 'code'], ['text', '/_error']], [], []],
    'home_api' => [[], ['_controller' => 'App\\Controller\\MachineOutilController::index'], [], [['text', '/api/']], [], []],
    'list_machines_collections_get' => [[], ['_controller' => 'App\\Controller\\MachineOutilController::getAllMachines'], [], [['text', '/api/machines']], [], []],
    'item_machine_get' => [['id'], ['_controller' => 'App\\Controller\\MachineOutilController::getMachineItem'], ['id' => '\\d+'], [['variable', '/', '\\d+', 'id'], ['text', '/api/machines']], [], []],
    'item_machine_create' => [[], ['_controller' => 'App\\Controller\\MachineOutilController::createMachine'], [], [['text', '/api/machines']], [], []],
    'item_machine_update' => [['id'], ['_controller' => 'App\\Controller\\MachineOutilController::updateMachine'], ['id' => '\\d+'], [['variable', '/', '\\d+', 'id'], ['text', '/api/machines']], [], []],
    'item_machine_delete' => [['id'], ['_controller' => 'App\\Controller\\MachineOutilController::deleteMachine'], ['id' => '\\d+'], [['variable', '/', '\\d+', 'id'], ['text', '/api/machines']], [], []],
    'api_login_check' => [[], ['_controller' => 'App\\Controller\\AuthController::login'], [], [['text', '/api/login']], [], []],
];
