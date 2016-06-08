<?php

require_once __DIR__.'/_config.php';

use LightSaml\Idp\Builder\Profile\WebBrowserSso\Idp\SsoIdpReceiveAuthnRequestProfileBuilder;
use LightSaml\Idp\Builder\Profile\WebBrowserSso\Idp\SsoIdpSendResponseProfileBuilder;
use LightSaml\Idp\Builder\Action\Profile\SingleSignOn\Idp\SsoIdpAssertionActionBuilder;

$buildContext = IdpConfig::current()->getBuildContainer();
$receiveBuilder = new SsoIdpReceiveAuthnRequestProfileBuilder($buildContext);

$context = $receiveBuilder->buildContext();
$action = $receiveBuilder->buildAction();

$action->execute($context);

$partyContext = $context->getPartyEntityContext();
$endpoint = $context->getEndpoint();
$message = $context->getInboundMessage();

$sendBuilder = new SsoIdpSendResponseProfileBuilder(
    $buildContext,
    array(new SsoIdpAssertionActionBuilder($buildContext)),
    $partyContext->getEntityDescriptor()->getEntityID()
);
$sendBuilder->setPartyEntityDescriptor($partyContext->getEntityDescriptor());
$sendBuilder->setPartyTrustOptions($partyContext->getTrustOptions());
$sendBuilder->setEndpoint($endpoint);
$sendBuilder->setMessage($message);

$context = $sendBuilder->buildContext();
$action = $sendBuilder->buildAction();

$action->execute($context);

$context->getHttpResponseContext()->getResponse()->send();
