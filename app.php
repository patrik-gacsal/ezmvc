<?php
if (! defined('EZMVC')) {
    http_response_code(404);
    die();
}

use EzMvc\ApiController;
use EzMvc\Core;
use EzMvc\Entity;
use EzMvc\PageController;
use EzMvc\StaticDatabase;

require_once 'core/orm/StaticDatabase.php';
require_once 'views/DefaultPageView.php';

// Logging goes to a FileLogger at Config::LOGFILE_PREFIX unless replaced here,
// first thing, so every line of the request reaches the chosen logger.
// Core::setLogger(new \EzMvc\StreamLogger('php://stderr'));
// Core::setMinimumLevel(\EzMvc\LogLevel::WARNING);

StaticDatabase::init(Config::DB_HOST, Config::DB_USER, Config::DB_PASS, Config::DB_NAME);
Entity::bindDefaultConnection(StaticDatabase::getConnection());

//include_once 'entities/User.php';

// Mail goes through PHP's mail() with php.ini's sender address unless replaced here.
// \EzMvc\Mail::setSender(new \EzMvc\PhpMail(Config::MAIL_FROM));
// \EzMvc\Mail::setSender(new \EzMvc\Smtp(Config::MAIL_FROM, Config::MAIL_FROM_NAME, Config::MAIL_BCC, Config::SMTP_HOST));

Core::setDebug();
ApiController::setDefaultSecret(Config::API_KEY);

PageController::setDefaultPageView('DefaultPageView');
ApiController::setDefaultAuthType(ApiController::AUTH_APIKEY);

//$db = new \EzMvc\Database(Config::DB_HOST, Config::DB_USER, Config::DB_PASS, Config::DB_NAME);
//$db->getConnection()->query('SET time_zone = "+00:00"');
//Entity::bindDefaultConnection($db->getConnection());

// Dependency injection (optional). Every injectable service is listed here;
// a controller then type-hints it in its constructor. Nothing registered
// means nothing injected — controllers themselves never need a line.
// Logger and MailSender need no line: they inject what Core::setLogger() and
// Mail::setSender() above installed.
// $container = \EzMvc\Container::root();
// $container->addSingleton(\EzMvc\Database::class, fn() => new \EzMvc\Database(Config::DB_HOST, Config::DB_USER, Config::DB_PASS, Config::DB_NAME));
// $container->addScoped(UserService::class);
