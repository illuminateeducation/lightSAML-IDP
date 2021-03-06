<?php

require_once __DIR__.'/_config.php';

use LightSaml\Builder\Profile\Metadata\MetadataProfileBuilder;

$builder = new MetadataProfileBuilder(
    IdpConfig::current()->getBuildContainer()
);

$context = $builder->buildContext();
$action = $builder->buildAction();

$action->execute($context);

$context->getHttpResponseContext()->getResponse()->send();
