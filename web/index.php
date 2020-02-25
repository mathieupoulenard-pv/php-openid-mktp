<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$app = new Silex\Application();
$app['debug'] = true;

$openidParams = [
	'login_url' => getenv('SF_LOGIN_URL'),
	'client_id' => getenv('CLIENT_ID'),
	'client_secret' => getenv('CLIENT_SECRET'),
	'client_redirect_url' => getenv('CLIENT_REDIRECT_URL')
];

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

$app->register(new Silex\Provider\SessionServiceProvider());

$client = HttpClient::create();
$openidConf = $client->request('GET', $openidParams['login_url'].'/.well-known/openid-configuration');

// Our web handlers
$app->get('/', function() use($app, $openidParams, $openidConf) {
  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('index.twig', [
  		'openidParams' => $openidParams,
  		'openidConf' => $openidConf->getContent(),
  		'openidConfArray' => $openidConf->toArray()
  ]);
});


$app->get('/callback', function(Request $request) use($app, $openidParams, $openidConf) {
  
  $app['monolog']->addDebug('callback output.');
  if (null === $user = $app['session']->get('user')) {
  		$app['monolog']->addDebug('no session');
        return $app->redirect('/');
  }

  dump($request);
  dump($request->query->get('code'));
  $username = 'toto';
  $app['session']->set('user', array('username' => $username));


  return $app['twig']->render('callback.twig', [
  		'openidParams' => $openidParams,
  		'openidConf' => $openidConf->getContent(),
  		'openidConfArray' => $openidConf->toArray()
  ]);
});

$app->run();
