<?php

require('../vendor/autoload.php');

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
$response = $client->request('GET', getenv('SF_LOGIN_URL').'/.well-known/openid-configuration');
$statusCode = $response->getStatusCode();
echo $statusCode;
echo $response->getContent();

$openidConf = file_get_contents(getenv('SF_LOGIN_URL').'/.well-known/openid-configuration');
// Our web handlers
$app->get('/', function() use($app) {
  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('index.twig', [
  		'test' => getenv('TEST'),
  		'openid-conf' => $openidConf
  ]);
});

$app->run();
