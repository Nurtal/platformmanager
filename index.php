<?php
require __DIR__ . '/vendor/autoload.php';
require_once 'Framework/Configuration.php';
// settings
ini_set('display_errors', 0); 
//error_reporting(E_ALL); 
error_reporting(0);

$sessionPath = dirname(__FILE__).'/tmp/';
session_save_path( $sessionPath );

// Cookie attributes must be set before anything starts a session. Session does
// this for itself too, so command line entry points get the same treatment.
require_once 'Framework/Session.php';
Session::configureCookie();

require 'Framework/Router.php';

if(Configuration::get('sentry_dsn', '')) {
    \Sentry\init(['dsn' => Configuration::get('sentry_dsn')]);
}


$router = new Router();
$router->routerRequest();
debug_print_backtrace();
