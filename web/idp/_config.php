<?php

require_once __DIR__.'/../../vendor/autoload.php';

use LightSaml\Bridge\Pimple\Container\BuildContainer;
use LightSaml\Bridge\Pimple\Container\SystemContainer;
use LightSaml\Bridge\Pimple\Container\PartyContainer;
use LightSaml\Bridge\Pimple\Container\ProviderContainer;
use LightSaml\Bridge\Pimple\Container\Factory\OwnContainerProvider;
use LightSaml\Bridge\Pimple\Container\Factory\SystemContainerProvider;
use LightSaml\Bridge\Pimple\Container\Factory\PartyContainerProvider;
use LightSaml\Bridge\Pimple\Container\Factory\ProviderContainerProvider;
use LightSaml\Bridge\Pimple\Container\Factory\ServiceContainerProvider;
use LightSaml\Bridge\Pimple\Container\Factory\StoreContainerProvider;
use LightSaml\Bridge\Pimple\Container\Factory\CredentialContainerProvider;
use LightSaml\Builder\EntityDescriptor\SimpleEntityDescriptorBuilder;
use LightSaml\Meta\TrustOptions\TrustOptions;
use LightSaml\Model\Metadata\EntityDescriptor;
use LightSaml\Model\Assertion\NameID;
use LightSaml\Model\Assertion\Attribute;
use LightSaml\ClaimTypes;
use LightSaml\Credential\X509Credential;
use LightSaml\Credential\X509Certificate;
use LightSaml\Credential\KeyHelper;
use LightSaml\Provider\Attribute\FixedAttributeValueProvider;
use LightSaml\Provider\NameID\FixedNameIdProvider;
use LightSaml\Provider\Session\FixedSessionInfoProvider;
use LightSaml\SamlConstants;
use LightSaml\Store\EntityDescriptor\FixedEntityDescriptorStore;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Pimple\Container;
use Symfony\Component\HttpFoundation\Session\Session;
use LightSaml\Store\TrustOptions\FixedTrustOptionsStore;

class IdpConfig
{
    private $config = [];

    /** @var  \SpConfig */
    private static $instance;

    public $debug = true;

    public function __construct()
    {
        $this->config = yaml_parse_file(__DIR__ .'/../../config/config.yml');
    }

    /**
     * @return \IdpConfig
     */
    public static function current()
    {
        if (null == self::$instance) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * @return \LightSaml\Build\Container\BuildContainerInterface
     */
    public function getBuildContainer()
    {
        $pimple = new Container();
        $result = new BuildContainer($pimple);
        $this->buildOwnContext($result);
        $this->buildSystemContext($result);
        $this->buildPartyContext($result);
        $this->buildStoreContext($result);
        $this->buildProviderContext($result);
        $this->buildCredentialContext($result);
        $this->buildServiceContext($result);

        return $result;
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildOwnContext(BuildContainer $buildContainer)
    {
        $ownCredential = $this->buildOwnCredential();
        $ownEntityDescriptorProvider = $this->buildOwnEntityDescriptorProvider($ownCredential->getCertificate());

        $buildContainer->getPimple()->register(
            new OwnContainerProvider(
                $ownEntityDescriptorProvider,
                [$ownCredential]
            )
        );
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildSystemContext(BuildContainer $buildContainer)
    {
        $buildContainer->getPimple()->register(new SystemContainerProvider());

        $pimple = $buildContainer->getPimple();
        $pimple[SystemContainer::LOGGER] = function () {
            return $this->buildLogger();

        };
        $pimple[SystemContainer::SESSION] = function () {
            return $this->buildSession();

        };
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildPartyContext(BuildContainer $buildContainer)
    {
        $buildContainer->getPimple()->register(new PartyContainerProvider());

        $pimple = $buildContainer->getPimple();
        $pimple[PartyContainer::SP_ENTITY_DESCRIPTOR] = function () {
            return $this->buildSpEntityStore();
        };
        $pimple[PartyContainer::TRUST_OPTIONS_STORE] = function () {
            $trustOptions = new TrustOptions();

            return new FixedTrustOptionsStore($trustOptions);
        };
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildStoreContext(BuildContainer $buildContainer)
    {
        $buildContainer->getPimple()->register(
            new StoreContainerProvider(
                $buildContainer->getSystemContainer()
            )
        );
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildProviderContext(BuildContainer $buildContainer)
    {
        $buildContainer->getPimple()->register(
            new ProviderContainerProvider()
        );

        $pimple = $buildContainer->getPimple();
        // Look up user info here
        $pimple[ProviderContainer::ATTRIBUTE_VALUE_PROVIDER] = function () {
            return (new FixedAttributeValueProvider())
                ->add(new Attribute(
                    ClaimTypes::COMMON_NAME,
                    'common-name'
                ))
                ->add(new Attribute(
                    ClaimTypes::GIVEN_NAME,
                    'first'
                ))
                ->add(new Attribute(
                    ClaimTypes::SURNAME,
                    'last'
                ))
                ->add(new Attribute(
                    ClaimTypes::EMAIL_ADDRESS,
                    'somebody@example.com'
                ));

        };

        $pimple[ProviderContainer::SESSION_INFO_PROVIDER] = function () {
            return new FixedSessionInfoProvider(
                time() - $this->config['session_ttl'],
                'session-index',
                SamlConstants::AUTHN_CONTEXT_PASSWORD_PROTECTED_TRANSPORT
            );
        };

        $pimple[ProviderContainer::NAME_ID_PROVIDER] = function () use ($buildContainer) {
            $nameId = new NameId($this->config['id_provider_name']);
            $nameId
                ->setFormat(SamlConstants::NAME_ID_FORMAT_EMAIL)
                ->setNameQualifier($buildContainer->getOwnContainer()->getOwnEntityDescriptorProvider()->get()->getEntityID())
            ;

            return new FixedNameIdProvider($nameId);
        };
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildCredentialContext(BuildContainer $buildContainer)
    {
        $buildContainer->getPimple()->register(
            new CredentialContainerProvider(
                $buildContainer->getPartyContainer(),
                $buildContainer->getOwnContainer()
            )
        );
    }

    /**
     * @param BuildContainer $buildContainer
     */
    private function buildServiceContext(BuildContainer $buildContainer)
    {
        $buildContainer->getPimple()->register(
            new ServiceContainerProvider(
                $buildContainer->getCredentialContainer(),
                $buildContainer->getStoreContainer(),
                $buildContainer->getSystemContainer()
            )
        );
    }

    /**
     * @return Session
     */
    private function buildSession()
    {
        $session = new Session();
        $session->setName($this->config['session_name']);
        $session->start();

        return $session;
    }

    /**
     * @return X509Credential
     */
    private function buildOwnCredential()
    {
        $ownCredential = new X509Credential(
            (new X509Certificate())
                ->loadPem(file_get_contents(__DIR__ .'/../../config/'.$this->config['cert'])),
            KeyHelper::createPrivateKey(__DIR__ .'/../../config/'.$this->config['cert_key'], null, true)
        );
        $ownCredential
            ->setEntityId($this->config['own_entity_id'])
        ;

        return $ownCredential;
    }

    /**
     * @param X509Certificate $certificate
     *
     * @return \LightSaml\Provider\EntityDescriptor\EntityDescriptorProviderInterface
     */
    private function buildOwnEntityDescriptorProvider(X509Certificate $certificate)
    {
        return new SimpleEntityDescriptorBuilder(
            $this->config['own_entity_id'],
            null,
            $this->config['id_provider'],
            $certificate
        );
    }

    /**
     * @return FixedEntityDescriptorStore
     */
    private function buildSpEntityStore()
    {
        $idpProvider = new FixedEntityDescriptorStore();

        $idpProvider->add(
            EntityDescriptor::load(__DIR__.$this->config['entity_store'])
        );

        return $idpProvider;
    }

    /**
     * @return Logger
     */
    private function buildLogger()
    {
        $logger = new Logger($this->config['log_id'], array(new StreamHandler(__DIR__ .'/../../logs/'.$this->config['log'])));

        return $logger;
    }
}
