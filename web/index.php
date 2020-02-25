<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

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

$session = new Session();
$session->start();
$app['session'] = $session;

$client = HttpClient::create();
$openidConf = $client->request('GET', $openidParams['login_url'].'/.well-known/openid-configuration');

// Our web handlers
$app->get('/', function(Request $request) use($app, $openidParams, $openidConf) {
  if (null !== $autoLogin = $request->query->get('autologin')) {
  		$app['monolog']->addDebug('autologin');
        return $app->redirect($openidConf->toArray()['authorization_endpoint'].'?response_type=code&client_id='.$openidParams['client_id'].'&redirect_uri='.$openidParams['client_redirect_url'].'&state=hp');
  }

  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('index.twig', [
  		'openidParams' => $openidParams,
  		'openidConf' => $openidConf->getContent(),
  		'openidConfArray' => $openidConf->toArray()
  ]);
});


$app->get('/callback', function(Request $request) use($app, $openidParams, $openidConf) {
  
  $app['monolog']->addDebug('callback output.');

  if (null === $code = $request->query->get('code')) {
  		$app['monolog']->addDebug('no code');
        return $app->redirect('/');
  }

  $client = HttpClient::create();
  $tokenResponse = $client->request('POST', $openidConf->toArray()['token_endpoint'], [
    'body' => [
    	'grant_type' => 'authorization_code',
    	'code' => $code,
    	'client_id' => $openidParams['client_id'],
    	'client_secret' => $openidParams['client_secret'],
    	'redirect_uri' => $openidParams['client_redirect_url']
    ],
  ]);

  if (null === $accessToken = $tokenResponse->toArray()['access_token']) {
  		$app['monolog']->addDebug('no access token');
        return $app->redirect('/');
  }

  $userInfoResponse = $client->request('POST', $openidConf->toArray()['userinfo_endpoint'], [
    'headers' => [
    	'Authorization' => $tokenResponse->toArray()['token_type'] . ' ' . $tokenResponse->toArray()['access_token']
    ],
  ]);

  if (null === $userInfo = $userInfoResponse->toArray()) {
  		$app['monolog']->addDebug('no access token');
        return $app->redirect('/');
  }

  $app['session']->set('user', $userInfo);

  $app['monolog']->addDebug('user connected');

    if ((null !== $show = $app['session']->get('prepareShow')) && (false !== $show = $app['session']->get('prepareShow'))) {
      $app['monolog']->addDebug('no user');
        return $app->redirect('/prepare?show='.$app['session']->get('prepareShow'));
  }

  if($userInfo["custom_attributes"]["marketPlaceAccess"] === "stm-ref") {
  	return new Response('Access denied to marketplace', 403);

  }
	
  return $app->redirect('/');

});

$app->get('/logout', function(Request $request) use($app, $openidParams, $openidConf) {

  $app['monolog']->addDebug('logout');
  $app['session']->invalidate();
  return $app->redirect($openidConf->toArray()['end_session_endpoint']);
});

$app->get('/prepare', function(Request $request) use($app, $openidParams, $openidConf) {
  if ((null !== $autoLogin = $request->query->get('autologin')) && (null === $user = $app['session']->get('user'))) {
  		$app['monolog']->addDebug('autologin');
  		$app['session']->set('prepareShow', $request->query->get('show'));
        return $app->redirect($openidConf->toArray()['authorization_endpoint'].'?response_type=code&client_id='.$openidParams['client_id'].'&redirect_uri='.$openidParams['client_redirect_url'].'&state=hp');
  }

  $app['session']->set('prepareShow', false);

  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('prepare.twig', [
  		'openidParams' => $openidParams,
  		'openidConf' => $openidConf->getContent(),
  		'openidConfArray' => $openidConf->toArray(),
  		'show' => $request->query->get('show'),

  ]);
});


$app->run();
