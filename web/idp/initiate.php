<?php

require_once __DIR__.'/_config.php';

use LightSaml\Criteria\CriteriaSet;
use LightSaml\SamlConstants;
use LightSaml\Resolver\Endpoint\Criteria\BindingCriteria;
use LightSaml\Resolver\Endpoint\Criteria\DescriptorTypeCriteria;
use LightSaml\Model\Metadata\SpSsoDescriptor;
use LightSaml\Resolver\Endpoint\Criteria\ServiceTypeCriteria;
use LightSaml\Model\Metadata\AssertionConsumerService;
use LightSaml\Idp\Builder\Profile\WebBrowserSso\Idp\SsoIdpSendResponseProfileBuilder;
use LightSaml\Idp\Builder\Action\Profile\SingleSignOn\Idp\SsoIdpAssertionActionBuilder;

$spEntityId = @$_GET['sp'];
if (null == $spEntityId) {
    header('Location: discovery.php');
    exit;
}
$spEntityDescriptor = IdpConfig::current()->getBuildContainer()->getPartyContainer()->getSpEntityDescriptorStore()->get($spEntityId);
if (null == $spEntityDescriptor) {
    header('Location: discovery.php');
    exit;
}

$buildContainer = IdpConfig::current()->getBuildContainer();

$criteriaSet = new CriteriaSet([
    new BindingCriteria([SamlConstants::BINDING_SAML2_HTTP_POST]),
    new DescriptorTypeCriteria(SpSsoDescriptor::class),
    new ServiceTypeCriteria(AssertionConsumerService::class)
]);
$arrEndpoints = IdpConfig::current()->getBuildContainer()->getServiceContainer()->getEndpointResolver()->resolve($criteriaSet, $spEntityDescriptor->getAllEndpoints());
if (empty($arrEndpoints)) {
    throw new \RuntimeException(sprintf('SP party "%s" does not have any SP ACS endpoint defined', $spEntityId));
}

$endpoint = $arrEndpoints[0]->getEndpoint();
$trustOptions = IdpConfig::current()->getBuildContainer()->getPartyContainer()->getTrustOptionsStore()->get($spEntityId);

$sendBuilder = new SsoIdpSendResponseProfileBuilder(
    $buildContainer,
    array(new SsoIdpAssertionActionBuilder($buildContainer)),
    $spEntityId
);
$sendBuilder->setPartyEntityDescriptor($spEntityDescriptor);
$sendBuilder->setPartyTrustOptions($trustOptions);
$sendBuilder->setEndpoint($endpoint);

$context = $sendBuilder->buildContext();
$action = $sendBuilder->buildAction();

$action->execute($context);

$context->getHttpResponseContext()->getResponse()->send();
