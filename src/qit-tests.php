<?php

use lucatume\DI52\Container;
use QitTests\App;
use Symfony\Component\Console\Application;
use QitTests\Commands\DownloadWooNightlyCommand;
use QitTests\Commands\SlackNotificationCommand;
use QitTests\Commands\GenerateConfigCommand;

try {

    require_once __DIR__ . '/../vendor/autoload.php';


    $container = new Container();
    App::setContainer( $container );

    $app = new Application( 'QIT Tests', '1.0.0' );

    $app->add( new DownloadWooNightlyCommand() );
    $app->add( new SlackNotificationCommand() );
    $app->add( new GenerateConfigCommand() );
    $app->run();
    
} catch ( Exception $e ) {
    echo $e->getMessage();
    exit( 1 );
}