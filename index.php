<?php
require __DIR__ . '/vendor/autoload.php';
require_once 'Framework/Configuration.php';
// settings
ini_set('display_errors', 0); 
//error_reporting(E_ALL); 
error_reporting(0);

$sessionPath = dirname(__FILE__).'/tmp/';
session_save_path( $sessionPath );

// Cookie attributes must be set before anything starts a session, and after
// session_save_path above. Session does this for itself as well, which is what
// covers the command line entry points; here it makes the ordering explicit for
// the web entry point.
require_once 'Framework/Session.php';
Session::configureCookie();

require 'Framework/Router.php';

if(Configuration::get('sentry_dsn', '')) {
    \Sentry\init(['dsn' => Configuration::get('sentry_dsn')]);
}


$router = new Router();
$router->routerRequest();
