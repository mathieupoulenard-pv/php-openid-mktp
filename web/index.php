<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpClient\HttpClient;

$app = new Silex\Application();
$app['debug'] = true;

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));


$client = HttpClient::create();
$openidConf = $client->request('GET', getenv('SF_LOGIN_URL').'/.well-known/openid-configuration');

echo $openidConf->getContent();
// Our web handlers
$app->get('/', function() use($app) {
  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('index.twig', [
  		'test' => getenv('TEST'),
  		'openidConf' => $openidConf->toArray(),
  		'conf' => $openidConf->getContent()
  ]);
});

$app->run();
