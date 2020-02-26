<?php

require('../vendor/autoload.php');

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

const API_VERSION = "48.0";

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

  $app['session']->set('token', $userInfoResponse->toArray());
  $app['session']->set('accessToken', $tokenResponse->toArray()['access_token']);
  $app['session']->set('user', $userInfo);

  $app['monolog']->addDebug('user connected');

    if ((null !== $show = $app['session']->get('prepareShow')) && (false !== $show = $app['session']->get('prepareShow'))) {
      $app['monolog']->addDebug('no user');
        return $app->redirect('/prepare?show='.$app['session']->get('prepareShow'));
  }


  if($userInfo["custom_attributes"]["marketPlaceAccess"] === "stm-ref") {
  	return new Response('Access denied to marketplace', 403);
  }

  //first access create membre de campagne programme et consentements
  $client = HttpClient::create();
  $userInfo = $app['session']->get('user');
  $accessToken = $app['session']->get('accessToken');

  // Get User content
  $userResponse = $client->request('GET', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."user/" . $userInfo["user_id"], [
    'headers' => [
      'Authorization' => "Bearer " . $accessToken
    ]
  ]);

  dump($userResponse->toArray());

  // Create campaign member
  $patchCampaignMemberResponse = $client->request('PATCH', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."CampaignMember/", [
      'headers' => [
        'Authorization' => "Bearer " . $accessToken,
        'Content-Type' => 'application/json'
      ],
      'json' => ['Code' => 'MKP', 'ContactId' => $userResponse->toArray()["ContactId"]]
    ]);

  dump($patchCampaignMemberResponse->getContent(false));

  // Create campaign member
  $patchConsentementResponse = $client->request('PATCH', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."Consentement__c/", [
      'headers' => [
        'Authorization' => "Bearer " . $accessToken,
        'Content-Type' => 'application/json'
      ],
      'json' => [
      	'Code_campagne__c' => 'MKP', 
      	'Contact__c' => $userResponse->toArray()["ContactId"],
      	'Optin_Email__c' => true,
      	'Optin_Email_Date_Creation__c' => $userResponse->toArray()["ContactId"],
      	'Optin_Email_Date_Modification__c' => $userResponse->toArray()["ContactId"],
      ]
    ]);

/*
Contact__c
BU__c
Campagne__c
Code_campagne__c
Date_et_heure_de_modification_custom__c
Optin_Courrier_Date_Creation__c
Optin_Courrier_Date_Modification__c
Optin_Courrier__c
Optin_Email_Date_Creation__c
Optin_Email_Date_Modification__c
Optin_Email__c
Optin_Partenaire_Date_Creation__c
Optin_Partenaire_Date_Modification__c
Optin_Partenaire__c
Optin_SMS_Date_Creation__c
Optin_SMS_Date_Modification__c
Optin_SMS__c
Optin_Tel_Date_Creation__c
Optin_Tel_Date_Modification__c
Optin_Tel__c
*/
  dump($patchConsentementResponse->getContent(false));
die;
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


$app->get('/order', function(Request $request) use($app, $openidParams, $openidConf) {
  if (null === $user = $app['session']->get('user')) {
      $app['monolog']->addDebug('no user');
        return $app->redirect('/');
  }



  $client = HttpClient::create();
  $userInfo = $app['session']->get('user');
  $accessToken = $app['session']->get('accessToken');

  // Get User content
  $userResponse = $client->request('GET', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."user/" . $userInfo["user_id"], [
    'headers' => [
      'Authorization' => "Bearer " . $accessToken
    ]
  ]);

  //dump($userResponse->toArray());

  // Get Contact content
  $contactResponse = $client->request('GET', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."contact/" . $userResponse->toArray()["ContactId"], [
    'headers' => [
      'Authorization' => "Bearer " . $accessToken
    ]
  ]);

  //dump($contactResponse->toArray());

  // Get Compte content
  $accountResponse = $client->request('GET', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."account/".$userResponse->toArray()["AccountId"], [
    'headers' => [
      'Authorization' => "Bearer " . $accessToken
    ]
  ]);

  //($accountResponse->toArray());

  // Post address de facturation
  $patchResponse = $client->request('PATCH', preg_replace("/{version}/", API_VERSION, $userInfo["urls"]["sobjects"])."contact/".$userResponse->toArray()["ContactId"], [
      'headers' => [
        'Authorization' => "Bearer " . $accessToken,
        'Content-Type' => 'application/json'
      ],
      'json' => ['OtherStreet' => 'Ma rue Marketplace', 'OtherCity' => 'Lyon 7è', 'OtherPostalCode' => '69007']
    ]);

  dump($patchResponse->getContent(false));

  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('order.twig', [
      'openidParams' => $openidParams,
      'openidConf' => $openidConf->getContent(),
      'openidConfArray' => $openidConf->toArray()
  ]);
});

$app->run();
