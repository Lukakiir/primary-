<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/crud.php';
require __DIR__ . '/includes/modules.php';
crud_run(module('fees'));
