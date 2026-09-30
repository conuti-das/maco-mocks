<?php

declare(strict_types=1);

use MacoMocks\App;
use MacoMocks\Config;

require __DIR__ . '/src/bootstrap.php';

$app = new App(Config::load(__DIR__));
App::emit($app->handle(App::requestFromGlobals()));
