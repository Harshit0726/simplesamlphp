<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\saml\Auth\Source;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SAML2\{AuthnRequest, LogoutRequest};
use SimpleSAML\Assert\AssertionFailedException;
use SimpleSAML\Configuration;
use SimpleSAML\Error\Exception;
use SimpleSAML\Module\saml\Auth\Source\SP;
use SimpleSAML\SAML2\Constants as C;
use SimpleSAML\SAML2\Exception\Protocol\{NoAvailableIDPException, NoSupportedIDPException};
use SimpleSAML\SAML2\Utils\XPath;
use SimpleSAML\SAML2\XML\saml\NameID;
use SimpleSAML\SAML2\XML\samlp\{IDPEntry, IDPList};
use SimpleSAML\Test\Metadata\MetaDataStorageSourceTest;
use SimpleSAML\TestUtils\ClearStateTestCase;
use SimpleSAML\Test\Utils\{ExitTestException, SpTester};
use SimpleSAML\XML\{Chunk, DOMDocumentFactory};
use Symfony\Component\HttpFoundation\Request;

/**
 * Set of test cases for \SimpleSAML\Module\saml\Auth\Source\SP.
 */
#[CoversClass(SP::class)]
class SPTest extends ClearStateTestCase
{
    /** @var string */
    private const SECURITY = 'vendor/simplesamlphp/xml-security/resources';

    /** @var string */
    public const CERT_KEY = '../' . self::SECURITY . '/certificates/selfsigned.simplesamlphp.org.key';

    /** @var string */
    public const CERT_PUBLIC = '../' . self::SECURITY . '/certificates/selfsigned.simplesamlphp.org.crt';

    /** @var string */
    public const CERT_OTHER_KEY = '../' . self::SECURITY . '/certificates/other.simplesamlphp.org.key';

    /** @var string */
    public const CERT_OTHER_PUBLIC = '../' . self::SECURITY . '/certificates/other.simplesamlphp.org.crt';

    /** @var \SimpleSAML\Configuration|null $idpMetadata */
    private ?Configuration $idpMetadata = null;

    /** @var array $idpConfigArray */
    private array $idpConfigArray;

    /** @var \SimpleSAML\Configuration */
    private Configuration $config;


    /**
     * @return \SimpleSAML\Configuration
     */
    private function getIdpMetadata(): Configuration
    {
        if (!$this->idpMetadata) {
            $this->idpMetadata = new Configuration(
                $this->idpConfigArray,
                'Auth_Source_SP_Test::getIdpMetadata()',
            );
        }

        return $this->idpMetadata;
    }


    /**
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->idpConfigArray = [
            'metadata-set'        => 'saml20-idp-remote',
            'entityid'            => 'https://engine.surfconext.nl/authentication/idp/metadata',
            'SingleSignOnService' => [
                [
                    'Binding'  => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                    'Location' => 'https://engine.surfconext.nl/authentication/idp/single-sign-on',
                ],
            ],
            'keys'                => [
                [
                    'encryption'      => false,
                    'signing'         => true,
                    'type'            => 'X509Certificate',
                    'X509Certificate' =>
                        'MIID3zCCAsegAwIBAgIJAMVC9xn1ZfsuMA0GCSqGSIb3DQEBCwUAMIGFMQswCQYDVQQGEwJOTDEQMA4GA1UECAwHVXR' .
                        'yZWNodDEQMA4GA1UEBwwHVXRyZWNodDEVMBMGA1UECgwMU1VSRm5ldCBCLlYuMRMwEQYDVQQLDApTVVJGY29uZXh0MS' .
                        'YwJAYDVQQDDB1lbmdpbmUuc3VyZmNvbmV4dC5ubCAyMDE0MDUwNTAeFw0xNDA1MDUxNDIyMzVaFw0xOTA1MDUxNDIyM' .
                        'zVaMIGFMQswCQYDVQQGEwJOTDEQMA4GA1UECAwHVXRyZWNodDEQMA4GA1UEBwwHVXRyZWNodDEVMBMGA1UECgwMU1VS' .
                        'Rm5ldCBCLlYuMRMwEQYDVQQLDApTVVJGY29uZXh0MSYwJAYDVQQDDB1lbmdpbmUuc3VyZmNvbmV4dC5ubCAyMDE0MDU' .
                        'wNTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKthMDbB0jKHefPzmRu9t2h7iLP4wAXr42bHpjzTEk6gtt' .
                        'HFb4l/hFiz1YBI88TjiH6hVjnozo/YHA2c51us+Y7g0XoS7653lbUN/EHzvDMuyis4Xi2Ijf1A/OUQfH1iFUWttIgtW' .
                        'K9+fatXoGUS6tirQvrzVh6ZstEp1xbpo1SF6UoVl+fh7tM81qz+Crr/Kroan0UjpZOFTwxPoK6fdLgMAieKSCRmBGpb' .
                        'JHbQ2xxbdykBBrBbdfzIX4CDepfjE9h/40ldw5jRn3e392jrS6htk23N9BWWrpBT5QCk0kH3h/6F1Dm6TkyG9CDtt73' .
                        '/anuRkvXbeygI4wml9bL3rE8CAwEAAaNQME4wHQYDVR0OBBYEFD+Ac7akFxaMhBQAjVfvgGfY8hNKMB8GA1UdIwQYMB' .
                        'aAFD+Ac7akFxaMhBQAjVfvgGfY8hNKMAwGA1UdEwQFMAMBAf8wDQYJKoZIhvcNAQELBQADggEBAC8L9D67CxIhGo5aG' .
                        'Vu63WqRHBNOdo/FAGI7LURDFeRmG5nRw/VXzJLGJksh4FSkx7aPrxNWF1uFiDZ80EuYQuIv7bDLblK31ZEbdg1R9Lgi' .
                        'ZCdYSr464I7yXQY9o6FiNtSKZkQO8EsscJPPy/Zp4uHAnADWACkOUHiCbcKiUUFu66dX0Wr/v53Gekz487GgVRs8HEe' .
                        'T9MU1reBKRgdENR8PNg4rbQfLc3YQKLWK7yWnn/RenjDpuCiePj8N8/80tGgrNgK/6fzM3zI18sSywnXLswxqDb/J+j' .
                        'gVxnQ6MrsTf1urM8MnfcxG/82oHIwfMh/sXPCZpo+DTLkhQxctJ3M=',
                ],
            ],
        ];

        $this->config = Configuration::loadFromArray([], '[ARRAY]', 'simplesaml');
    }


    /**
     * Create a SAML AuthnRequest using \SimpleSAML\Module\saml\Auth\Source\SP
     *
     * @param array $state The state array to use in the test. This is an array of the parameters described in section
     * 2 of https://simplesamlphp.org/docs/development/saml:sp
     *
     * @return \SimpleSAML\SAML2\AuthnRequest The AuthnRequest generated.
     */
    private function createAuthnRequest(array $state = []): AuthnRequest
    {
        $info = ['AuthId' => 'default-sp'];
        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];
        $as = new SpTester($info, $config);

        /** @var \SimpleSAML\SAML2\AuthnRequest $ar */
        $ar = null;
        try {
            $as->startSSO2Test($this->getIdpMetadata(), $state);
            $this->assertTrue(false, 'Expected ExitTestException');
        } catch (ExitTestException $e) {
            $r = $e->getTestResult();
            $ar = $r['ar'];
        }
        return $ar;
    }


    /**
     * Create a SAML LogoutRequest using \SimpleSAML\Module\saml\Auth\Source\SP
     *
     * @param array $state The state array to use in the test. This is an array of the parameters described in section
     * 2 of https://simplesamlphp.org/docs/development/saml:sp
     *
     * @return \SimpleSAML\SAML2\LogoutRequest The LogoutRequest generated.
     */
    private function createLogoutRequest(array $state = []): LogoutRequest
    {
        $info = ['AuthId' => 'default-sp'];
        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];
        $as = new SpTester($info, $config);

        /** @var \SimpleSAML\SAML2\LogoutRequest $lr */
        $lr = null;
        try {
            $as->startSLO2($this->config, $state);
            $this->assertTrue(false, 'Expected ExitTestException');
        } catch (ExitTestException $e) {
            $r = $e->getTestResult();
            $lr = $r['lr'];
        }
        return $lr;
    }


    /**
     * Test generating an AuthnRequest
     */
    public function testAuthnRequest(): void
    {
        $ar = $this->createAuthnRequest();

        $xml = $ar->toSignedXML();

        $xpCache = XPath::getXPath($xml);
        /** @var \DOMAttr[] $q */
        $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/@Destination', $xpCache);
        $this->assertEquals(
            $this->idpConfigArray['SingleSignOnService'][0]['Location'],
            $q[0]->value,
        );

        $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/saml:Issuer', $xpCache);
        $this->assertEquals(
            'urn:x-simplesamlphp:example-sp',
            $q[0]->textContent,
        );
    }


    /**
     * Test setting a Subject
     */
    public function testNameID(): void
    {
        $state = [
            'saml:NameID' => ['value' => 'user@example.org', 'Format' => C::NAMEID_UNSPECIFIED],
        ];

        $ar = $this->createAuthnRequest($state);

        /** @var \SAML2\XML\saml\NameID $nameID */
        $nameID = $ar->getNameId();
        $this->assertEquals($state['saml:NameID']['value'], $nameID->getValue());
        $this->assertEquals($state['saml:NameID']['Format'], $nameID->getFormat());

        $xml = $ar->toSignedXML();

        $xpCache = XPath::getXPath($xml);
        /** @var \DOMAttr[] $q */
        $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/saml:Subject/saml:NameID/@Format', $xpCache);
        $this->assertEquals(
            $state['saml:NameID']['Format'],
            $q[0]->value,
        );

        $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/saml:Subject/saml:NameID', $xpCache);
        $this->assertEquals(
            $state['saml:NameID']['value'],
            $q[0]->textContent,
        );
    }


    /**
     * Test setting an AuthnConextClassRef
    public function testAuthnContextClassRef(): void
    {
        $state = [
            'saml:AuthnContextClassRef' => new AuthnContextClassRef('http://example.com/myAuthnContextClassRef'),
        ];

        $ar = $this->createAuthnRequest($state);
        $this->assertEquals(
            ($state['saml:AuthnContextClassRef'])->getContent(),
            $ar->getRequestedAuthnContext()[0]->getContent(),
        );

        $xml = $ar->toSignedXML();

        $xpCache = XPath::getXPath($xml);
        $q = XPath::xpQuery(
            $xml,
            '/samlp:AuthnRequest/samlp:RequestedAuthnContext/saml:AuthnContextClassRef',
            $xpCache,
        );

        $this->assertEquals(
            ($state['saml:AuthnContextClassRef'])->getContent(),
            $q->textContent,
        );
    }
     */


    /**
     * Test setting ForcedAuthn
     */
    public function testForcedAuthn(): void
    {
        /** @var array{ForceAuthn:bool} $state */
        $state = [
            'ForceAuthn' => true,
        ];

        $ar = $this->createAuthnRequest($state);

        $this->assertEquals(
            $state['ForceAuthn'],
            $ar->getForceAuthn(),
        );

        $xml = $ar->toSignedXML();

        $xpCache = XPath::getXPath($xml);
        /** @var \DOMAttr[] $q */
        $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/@ForceAuthn', $xpCache);
        $this->assertEquals(
            $state['ForceAuthn'] ? 'true' : 'false',
            $q[0]->value,
        );
    }


    /**
     * Test specifying an IDPList where no metadata found for those idps is an error
     */
    public function testIdpListWithNoMatchingMetadata(): void
    {
        $this->expectException(NoSupportedIDPException::class);
        $state = [
            'saml:IDPList' => ['noSuchIdp'],
        ];

        $info = ['AuthId' => 'default-sp'];
        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];

        $request = Request::createFromGlobals();
        $as = new SpTester($info, $config);
        $as->authenticate($request, $state);
    }


    /**
     * Test specifying an IDPList where the list does not overlap with the Idp specified in SP config is an error
     */
    public function testIdpListWithExplicitIdpNotMatch(): void
    {
        $this->expectException(NoAvailableIDPException::class);
        $entityId = "https://example.com";
        $xml = MetaDataStorageSourceTest::generateIdpMetadataXml($entityId);
        $c = [
            'metadata.sources' => [
                ["type" => "xml", "xml" => $xml],
            ],
        ];
        Configuration::loadFromArray($c, '', 'simplesaml');
        $state = [
            'saml:IDPList' => ['noSuchIdp', $entityId],
        ];

        $info = ['AuthId' => 'default-sp'];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'idp' => 'https://engine.surfconext.nl/authentication/idp/metadata',
        ];

        $request = Request::createFromGlobals();
        $as = new SpTester($info, $config);
        $as->authenticate($request, $state);
    }


    /**
     * Test that IDPList overlaps with the IDP specified in SP config results in AuthnRequest
     */
    public function testIdpListWithExplicitIdpMatch(): void
    {
        $entityId = "https://example.com";
        $xml = MetaDataStorageSourceTest::generateIdpMetadataXml($entityId);
        $c = [
            'metadata.sources' => [
                ["type" => "xml", "xml" => $xml],
            ],
        ];
        Configuration::loadFromArray($c, '', 'simplesaml');
        $state = [
            'saml:IDPList' => ['noSuchIdp', $entityId],
        ];

        $info = ['AuthId' => 'default-sp'];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'idp' => $entityId,
        ];

        $as = new SpTester($info, $config);
        $request = Request::createFromGlobals();
        try {
            $as->authenticate($request, $state);
            $this->fail('Expected ExitTestException');
        } catch (ExitTestException $e) {
            $r = $e->getTestResult();
            /** @var AuthnRequest $ar */
            $ar = $r['ar'];
            $xml = $ar->toSignedXML();

            $xpCache = XPath::getXPath($xml);
            /** @var \DOMAttr[] $q */
            $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/@Destination', $xpCache);
            $this->assertEquals(
                'https://saml.idp/sso/',
                $q[0]->value,
            );
        }
    }


    /**
     * Test that IDPList with a single valid idp and no SP config idp results in AuthnRequest to that idp
     */
    public function testIdpListWithSingleMatch(): void
    {
        $entityId = "https://example.com";
        $xml = MetaDataStorageSourceTest::generateIdpMetadataXml($entityId);
        $c = [
            'metadata.sources' => [
                ["type" => "xml", "xml" => $xml],
            ],
        ];
        Configuration::loadFromArray($c, '', 'simplesaml');
        $state = [
            'saml:IDPList' => ['noSuchIdp', $entityId],
        ];

        $info = ['AuthId' => 'default-sp'];
        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];
        $as = new SpTester($info, $config);
        $request = Request::createFromGlobals();
        try {
            $as->authenticate($request, $state);
            $this->fail('Expected ExitTestException');
        } catch (ExitTestException $e) {
            $r = $e->getTestResult();
            /** @var AuthnRequest $ar */
            $ar = $r['ar'];
            $xml = $ar->toSignedXML();

            $xpCache = XPath::getXPath($xml);
            /** @var \DOMAttr[] $q */
            $q = XPath::xpQuery($xml, '/samlp:AuthnRequest/@Destination', $xpCache);
            $this->assertEquals(
                'https://saml.idp/sso/',
                $q[0]->value,
            );
        }
    }


    /**
     * Test that IDPList with multiple valid idp and no SP config idp results in discovery redirect
     */
    public function testIdpListWithMultipleMatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL: smtp://invalidurl');
        $entityId = "https://example.com";
        $xml = MetaDataStorageSourceTest::generateIdpMetadataXml($entityId);
        $entityId1 = "https://example1.com";
        $xml1 = MetaDataStorageSourceTest::generateIdpMetadataXml($entityId1);
        $c = [
            'metadata.sources' => [
                ["type" => "xml", "xml" => $xml],
                ["type" => "xml", "xml" => $xml1],
            ],
        ];
        Configuration::loadFromArray($c, '', 'simplesaml');
        $state = [
            'saml:IDPList' => ['noSuchIdp', $entityId, $entityId1],
        ];

        $info = ['AuthId' => 'default-sp'];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            // Use a url that is invalid for http redirects so redirect code throws an error
            // otherwise it will call exit
            'discoURL' => 'smtp://invalidurl',
        ];

        // Http redirect util library requires a request_uri to be set.
        $request = Request::createFromGlobals();
        $_SERVER['REQUEST_URI'] = 'https://l.example.com/';
        $as = new SpTester($info, $config);
        $as->authenticate($request, $state);
    }

    /**
     * Basic test for the hosted metadata generation in a default config
     */
    public function testMetadataHostedBasicConfig(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertEquals('saml20-sp-remote', $md['metadata-set']);
        $this->assertEquals('urn:x-simplesamlphp:example-sp', $md['entityid']);
        $this->assertArrayHasKey('SingleLogoutService', $md);
        $this->assertIsArray($md['SingleLogoutService']);
        $this->assertArrayHasKey('AssertionConsumerService', $md);
        $this->assertIsArray($md['AssertionConsumerService']);
        foreach ($md['AssertionConsumerService'] as $acs) {
            $this->assertEquals(
                'http://localhost/simplesaml/module.php/saml/sp/saml2-acs.php/' . $spId,
                $acs['Location'],
            );
            $this->assertStringStartsWith('urn:oasis:names:tc:SAML:2.0:bindings', $acs['Binding']);
            $this->assertIsInt($acs['index']);
        }
    }

    /**
     * Test that adding IDPList to the state (of an AuthRequest)
     * will result in that IDP being added to the scope
     */
    public function testSPIdpListScoping(): void
    {
        $ar = $this->createAuthnRequest([
            'IDPList' => new IDPList([new IDPEntry('https://scope.example.com')]),
        ]);

        $this->assertContains(
            'https://scope.example.com',
            [$ar->getIDPList()['IDPEntry'][0]['ProviderID']],
        );
    }

    /**
     * Test that adding IDPList to the idp metadata
     * will result in that IDP being added to the scope
     */
    public function testIdpMetadataScoping(): void
    {
        $this->idpConfigArray['IDPList'] = ['https://scope.example.com'];
        $ar = $this->createAuthnRequest([]);

        $this->assertContains(
            'https://scope.example.com',
            [$ar->getIDPList()['IDPEntry'][0]['ProviderID']],
        );
    }

    /**
     * Test that adding IDPList to the config
     * will result in that IDP being added to the scope
     */
    public function testRemoteMetadataScoping(): void
    {
        $info = ['AuthId' => 'default-sp'];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'IDPList' => ['https://scope.example.com'],
        ];
        $as = new SpTester($info, $config);

        /** @var \SimpleSAML\SAML2\AuthnRequest $ar */
        try {
            $as->startSSO2Test($this->getIdpMetadata(), []);
            $this->assertTrue(false, 'Expected ExitTestException');
        } catch (ExitTestException $e) {
            ['ar' => $ar] = $e->getTestResult();
            $this->assertContains(
                'https://scope.example.com',
                [$ar->getIDPList()['IDPEntry'][0]['ProviderID']],
            );
        }
    }


    /**
     * Test that makes sure that the order in which the IDPList config is applied
     * is correct. Ie: The state IDPList has the highest priority, then the remote metadata,
     * then the idp config array.
     */
    #[DataProvider('getScopingOrders')]
    public function testSPIdpListScopingOrder(
        ?IDPList $stateIdpList,
        ?array $idpConfigArray,
        ?array $remoteMetadata,
        string $expectedScope,
    ): void {
        $info = ['AuthId' => 'default-sp'];
        $state = [];
        if (isset($stateIdpList)) {
            $state['IDPList'] = $stateIdpList;
        }

        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];
        if (isset($remoteMetadata)) {
            $config['IDPList'] = $remoteMetadata;
        }

        if (isset($idpConfigArray)) {
            $this->idpConfigArray['IDPList'] = $idpConfigArray;
        }

        $as = new SpTester($info, $config);

        /** @var \SimpleSAML\SAML2\AuthnRequest $ar */
        try {
            $as->startSSO2Test($this->getIdpMetadata(), $state);
            $this->assertTrue(false, 'Expected ExitTestException');
        } catch (ExitTestException $e) {
            ['ar' => $ar] = $e->getTestResult();

            $this->assertContains(
                $expectedScope,
                [$ar->getIDPList()['IDPEntry'][0]['ProviderID']],
            );
        }
    }

    public static function getScopingOrders(): array
    {
        return [
            [
                'stateIdpList' => new IDPList([new IDPEntry('https://scope1.example.com')]),
                'idpConfigArray' => ['https://scope2.example.com'],
                'remoteMetadata' => ['https://scope3.example.com'],
                'expectedScope' => 'https://scope1.example.com',
            ],
            [
                'stateIdpList' => null,
                'idpConfigArray' => ['https://scope2.example.com'],
                'remoteMetadata' => ['https://scope3.example.com'],
                'expectedScope' => 'https://scope3.example.com',
            ],
            [
                'stateIdpList' => null,
                'idpConfigArray' => null,
                'remoteMetadata' => ['https://scope3.example.com'],
                'expectedScope' => 'https://scope3.example.com',
            ],
            [
                'stateIdpList' => new IDPList([new IDPEntry('https://scope1.example.com')]),
                'idpConfigArray' => null,
                'remoteMetadata' => ['https://scope3.example.com'],
                'expectedScope' => 'https://scope1.example.com',
            ],
            [
                'stateIdpList' => new IDPList([new IDPEntry('https://scope1.example.com')]),
                'idpConfigArray' => ['https://scope2.example.com'],
                'remoteMetadata' => null,
                'expectedScope' => 'https://scope1.example.com',
            ],
        ];
    }

    /**
     * Test for the hosted metadata generation with a custom entityID
     */
    public function testMetadataHostedSetEntityId(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = ['entityID' => 'urn:example:mysp:001'];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertEquals('urn:example:mysp:001', $md['entityid']);
    }

    /**
     * Contacts in SP hosted config appear in metadata
     */
    public function testMetadataHostedContacts(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'contacts' => [
                [
                    'ContactType'       => 'other',
                    'EmailAddress'      => ['csirt@example.com'],
                    'SurName'           => 'CSIRT',
                    'TelephoneNumber'   => ['+31SECOPS'],
                    'Company'           => 'Acme Inc',
                    'attributes' => [
                        [
                            'namespaceURI' => 'http://refeds.org/metadata',
                            'namespacePrefix' => 'remd',
                            'attrName' => 'contactType',
                            'attrValue' => 'http://refeds.org/metadata/contactType/security',
                        ],
                    ],
                ],
                [
                    'ContactType'       => 'administrative',
                    'EmailAddress'      => ['j.doe@example.edu'],
                    'GivenName'         => 'Jane',
                    'SurName'           => 'Doe',
                ],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('contacts', $md);
        $this->assertIsArray($md['contacts']);
        $this->assertCount(2, $md['contacts']);

        $contacts = $md['contacts'];
        $contact = $md['contacts'][0];

        $this->assertIsArray($contact);
        $this->assertEquals('other', $contact['ContactType']);
        $this->assertEquals('CSIRT', $contact['SurName']);
        $this->assertArrayNotHasKey('GivenName', $contact);
        $this->assertEquals(['+31SECOPS'], $contact['TelephoneNumber']);
        $this->assertEquals('Acme Inc', $contact['Company']);
        $this->assertIsArray($contact['attributes']);
        $attrs = [
            [
                'namespaceURI' => 'http://refeds.org/metadata',
                'namespacePrefix' => 'remd',
                'attrName' => 'contactType',
                'attrValue' => 'http://refeds.org/metadata/contactType/security',
            ],
        ];
        $this->assertEquals($attrs, $contact['attributes']);

        $contact = $md['contacts'][1];
        $this->assertIsArray($contact);
        $this->assertEquals('administrative', $contact['ContactType']);
        $this->assertEquals(['mailto:j.doe@example.edu'], $contact['EmailAddress']);
        $this->assertArrayNotHasKey('attributes', $contact);
    }

    /**
     * A globally set tech contact also appears in SP hosted metadata
     */
    public function testMetadataHostedContactsIncludesGlobalTechContact(): void
    {
        Configuration::loadFromArray([
            'technicalcontact_email' => 'someone.somewhere@example.org',
            'technicalcontact_name' => 'Someone von Somewhere',
        ], '[ARRAY]', 'simplesaml');

        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'contacts' => [
                [
                    'ContactType'       => 'technical',
                    'EmailAddress'      => ['j.doe@example.edu'],
                    'GivenName'         => 'Jane',
                    'SurName'           => 'Doe',
                ],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('contacts', $md);
        $this->assertIsArray($md['contacts']);
        $this->assertCount(2, $md['contacts']);

        $contacts = $md['contacts'];
        $contact = $md['contacts'][0];

        $this->assertIsArray($contact);
        $this->assertEquals('technical', $contact['ContactType']);
        $this->assertEquals('Doe', $contact['SurName']);

        $contact = $md['contacts'][1];
        $this->assertIsArray($contact);
        $this->assertEquals('technical', $contact['ContactType']);
        $this->assertEquals(['mailto:someone.somewhere@example.org'], $contact['EmailAddress']);
        $this->assertEquals('Someone von Somewhere', $contact['GivenName']);
        $this->assertArrayNotHasKey('SurName', $contact);
    }

    /**
     * The special value na@example.org global tech contact is not included in SP metadata
     */
    public function testMetadataHostedContactsSkipsNAGlobalTechContact(): void
    {
        Configuration::loadFromArray([
            'technicalcontact_email' => 'na@example.org',
            'technicalcontact_name' => 'Someone von Somewhere',
        ], '[ARRAY]', 'simplesaml');

        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'contacts' => [
                [
                    'ContactType'       => 'technical',
                    'EmailAddress'      => ['j.doe@example.edu'],
                    'SurName'           => 'Doe',
                ],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(1, $md['contacts']);
        $this->assertEquals(['mailto:j.doe@example.edu'], $md['contacts'][0]['EmailAddress']);
    }

    /**
     * Contacts in SP hosted of unknown type throws Exceptiona
     */
    public function testMetadataHostedContactsUnknownTypeThrowsException(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'contacts' => [
                [
                   'ContactType'       => 'anything',
                   'EmailAddress'      => ['j.doe@example.edu'],
                   'GivenName'         => 'Jane',
                   'SurName'           => 'Doe',
                ],
            ],
        ];
        $as = new SpTester($info, $config);

        $this->expectException(AssertionFailedException::class);
        $this->expectExceptionMessage(
            'Expected one of: "technical", "support", "administrative", "billing", "other". Got: "anything"',
        );

        $md = $as->getHostedMetadata();
    }

    /**
     * SP acs.Bindings option overrides default bindigs
     */
    public function testMetadataHostedAcsBindingsOption(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'contacts' => [
                [
                    'ContactType'       => 'administrative',
                    'EmailAddress'      => ['j.doe@example.edu'],
                    'GivenName'         => 'Jane',
                    'SurName'           => 'Doe',
                ],
            ],
            'acs.Bindings' => ['urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(1, $md['AssertionConsumerService']);
        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            $md['AssertionConsumerService'][0]['Binding'],
        );
    }

    /**
     * SP acs.Bindings option with unsupported value should be skipped
     */
    public function testMetadataHostedAcsBindingsUnknownValueIsSkipped(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'contacts' => [
                [
                    'ContactType'       => 'administrative',
                    'EmailAddress'      => ['j.doe@example.edu'],
                    'GivenName'         => 'Jane',
                    'SurName'           => 'Doe',
                ],
            ],
            'acs.Bindings' => ['urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST', 'urn:this:doesnotexist'],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(1, $md['AssertionConsumerService']);
        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            $md['AssertionConsumerService'][0]['Binding'],
        );
    }

    /**
     * SP SLO Bindings option overrides default bindigs
     */
    public function testMetadataHostedSloBindingsOption(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'SingleLogoutServiceBinding' => ['urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(1, $md['SingleLogoutService']);
        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            $md['SingleLogoutService'][0]['Binding'],
        );
    }

    /**
     * SP empty SLO Bindings option omits SLO in metadata
     */
    public function testMetadataHostedSloBindingsEmptyNotInMetadata(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'SingleLogoutServiceBinding' => [],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(0, $md['SingleLogoutService']);
    }

    /**
     * SP SLO Bindings option with unknown value is accepted as-is
     */
    public function testMetadataHostedSloBindingsUnknownValueIsAccepted(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'SingleLogoutServiceBinding' => [
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                'urn:this:doesnotexist',
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(2, $md['SingleLogoutService']);
        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            $md['SingleLogoutService'][0]['Binding'],
        );
        $this->assertEquals('urn:this:doesnotexist', $md['SingleLogoutService'][1]['Binding']);
    }

    /**
     * SP SLO Location option is used as URL for all SLO Bindings
     */
    public function testMetadataHostedSloURLIsUsedForAllSLOBindings(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'SingleLogoutServiceBinding' => [
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                'urn:this:doesnotexist',
            ],
            'SingleLogoutServiceLocation' => 'https://sp.example.org/logout',
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(2, $md['SingleLogoutService']);
        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            $md['SingleLogoutService'][0]['Binding'],
        );
        $this->assertEquals('urn:this:doesnotexist', $md['SingleLogoutService'][1]['Binding']);
        $this->assertEquals('https://sp.example.org/logout', $md['SingleLogoutService'][0]['Location']);
        $this->assertEquals('https://sp.example.org/logout', $md['SingleLogoutService'][1]['Location']);
    }

    /**
     * SP AssertionConsumerService option overrides default bindigs
     */
    public function testMetadataHostedAssertionConsumerServiceOption(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'AssertionConsumerService' => [
                [
                    'index' => 1,
                    'isDefault' => true,
                    'Location' => 'https://sp.example.org/ACS',
                    'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                [
                    'index' => 17,
                    'Location' => 'https://sp.example.org/ACSeventeen',
                    'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Artifact',
                ],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(2, $md['AssertionConsumerService']);
        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            $md['AssertionConsumerService'][0]['Binding'],
        );
        $this->assertEquals('https://sp.example.org/ACS', $md['AssertionConsumerService'][0]['Location']);
        $this->assertEquals(1, $md['AssertionConsumerService'][0]['index']);
        $this->assertTrue($md['AssertionConsumerService'][0]['isDefault']);

        $this->assertEquals(
            'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Artifact',
            $md['AssertionConsumerService'][1]['Binding'],
        );
        $this->assertEquals('https://sp.example.org/ACSeventeen', $md['AssertionConsumerService'][1]['Location']);
        $this->assertEquals(17, $md['AssertionConsumerService'][1]['index']);
        $this->assertArrayNotHasKey('isDefault', $md['AssertionConsumerService'][1]);
    }


    /**
     * SP config options WantAssertionsSigned, redirect.sign is reflected in metadata
     */
    public function testMetadataHostedSigning(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'WantAssertionsSigned' => true,
            'redirect.sign' => true,
            'sign.authnrequest' => true,
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('saml20.sign.assertion', $md);
        $this->assertArrayHasKey('redirect.validate', $md);
        $this->assertTrue($md['saml20.sign.assertion']);
        $this->assertTrue($md['redirect.validate']);
        $this->assertArrayNotHasKey('validate.authnrequest', $md);

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'WantAssertionsSigned' => false,
            'redirect.sign' => false,
            'sign.authnrequest' => false,
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('saml20.sign.assertion', $md);
        $this->assertArrayHasKey('redirect.validate', $md);
        $this->assertFalse($md['saml20.sign.assertion']);
        $this->assertFalse($md['redirect.validate']);
        $this->assertArrayNotHasKey('validate.authnrequest', $md);

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'sign.authnrequest' => true,
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayNotHasKey('redirect.validate', $md);
        $this->assertArrayHasKey('validate.authnrequest', $md);
        $this->assertTrue($md['validate.authnrequest']);
    }

    /**
     * SP config option RegistrationInfo is reflected in metadata
     */
    public function testMetadataHostedContainsRegistrationInfo(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'RegistrationInfo' => [
                'authority' => 'urn:mace:sp.example.org',
                'instant' => '2008-01-17T11:28:03.577Z',
                'policies' => ['en' => 'http://sp.example.org/policy', 'es' => 'http://sp.example.org/politica'],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('RegistrationInfo', $md);
        $reginfo = $md['RegistrationInfo'];
        $this->assertIsArray($reginfo);
        $this->assertEquals('urn:mace:sp.example.org', $reginfo['authority']);
        $this->assertEquals('2008-01-17T11:28:03.577Z', $reginfo['instant']);
        $this->assertIsArray($reginfo['policies']);
        $this->assertCount(2, $reginfo['policies']);
        $this->assertEquals('http://sp.example.org/politica', $reginfo['policies']['es']);
    }

    /**
     * SP config option NameIDPolicy is reflected in metadata
     */
    public function testMetadataHostedNameIDPolicy(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'NameIDPolicy' => [
                'Format' => 'urn:mace:shibboleth:1.0:nameIdentifier',
                'AllowCreate' => true,
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('NameIDFormat', $md);
        $this->assertEquals('urn:mace:shibboleth:1.0:nameIdentifier', $md['NameIDFormat']);
    }

    /**
     * SP config option NameIDPolicy specified without Format is reflected in metadata
     */
    public function testMetadataHostedNameIDPolicyNullFormat(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'NameIDPolicy' => ['AllowCreate' => true],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('NameIDFormat', $md);
        $this->assertEquals('urn:oasis:names:tc:SAML:2.0:nameid-format:transient', $md['NameIDFormat']);
    }

    /**
     * SP config option Organization* are reflected in metadata
     */
    public function testMetadataHostedOrganizationData(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'OrganizationName' => [
                'en' => 'Voorbeeld Organisatie Foundation b.a.',
                'nl' => 'Stichting Voorbeeld Organisatie b.a.',
            ],
            'OrganizationDisplayName' => [
                'en' => 'Example organization',
                'nl' => 'Voorbeeldorganisatie',
            ],
            'OrganizationURL' => [
                'en' => 'https://example.com',
                'nl' => 'https://example.com/nl',
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertEquals('Voorbeeld Organisatie Foundation b.a.', $md['OrganizationName']['en']);
        $this->assertEquals('Voorbeeldorganisatie', $md['OrganizationDisplayName']['nl']);
        $this->assertEquals('https://example.com/nl', $md['OrganizationURL']['nl']);
    }

    /**
     * SP config option Organization* without explicit DisplayName are reflected in metadata
     */
    public function testMetadataHostedOrganizationDataDefaultForDisplayNameIsName(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'OrganizationName' => [
                'nl' => 'Stichting Voorbeeld Organisatie b.a.',
            ],
            'OrganizationURL' => [
                'nl' => 'https://example.com/nl',
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertEquals('Stichting Voorbeeld Organisatie b.a.', $md['OrganizationName']['nl']);
        $this->assertEquals('Stichting Voorbeeld Organisatie b.a.', $md['OrganizationDisplayName']['nl']);
        $this->assertEquals('https://example.com/nl', $md['OrganizationURL']['nl']);
    }

    /**
     * SP config option Organization* without URL is rejected with an Exception
     */
    public function testMetadataHostedOrganizationURLMissingRaisesException(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'OrganizationName' => [
                'nl' => 'Stichting Voorbeeld Organisatie b.a.',
            ],
            'OrganizationDisplayName' => [
                'nl' => 'Voorbeeldorganisatie',
            ],
        ];
        $as = new SpTester($info, $config);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('If OrganizationName is set, OrganizationURL must also be set.');
        $md = $as->getHostedMetadata();
    }

    /**
     * SP config option for UIInfo is reflected in metadata
     */
    public function testMetadataHostedUIInfo(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'UIInfo' => [
                'DisplayName' => [
                    'en' => 'English name',
                    'es' => 'Nombre en Español',
                 ],
                 'Description' => [
                    'en' => 'English description',
                    'es' => 'Descripción en Español',
                 ],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('UIInfo', $md);
        $this->assertIsArray($md['UIInfo']);
        $this->assertEquals('Descripción en Español', $md['UIInfo']['Description']['es']);
    }

    /**
     * SP config option for entity attribute extensions is reflected in metadata
     */
    public function testMetadataHostedEntityExtensions(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $ea = ['{urn:simplesamlphp:v1}foo' => ['bar']];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'EntityAttributes' => $ea,
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('EntityAttributes', $md);
        $this->assertEquals($ea, $md['EntityAttributes']);
    }

    /**
     * SP config option for Name, Description, Attributes is in metadata
     */
    public function testMetadataHostedNameDescriptionAttributes(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'name' => [
                'en' => 'My First SP',
            ],
            'description' => [
                'en' => 'This SP is my first one',
            ],
            'attributes' => [
                'mail' => 'urn:oid:0.9.2342.19200300.100.1.3',
                'schacHomeOrganization' => 'urn:oid:1.3.6.1.4.1.25178.1.2.9',
            ],
            'attributes.required' => [
                'eduPersonPrincipalName' => 'urn:oid:1.3.6.1.4.1.5923.1.1.1.6',
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('name', $md);
        $this->assertEquals('My First SP', $md['name']['en']);
        $this->assertArrayHasKey('description', $md);
        $this->assertEquals('This SP is my first one', $md['description']['en']);
        $this->assertArrayHasKey('attributes', $md);
        $this->assertEquals(
            [
                'mail' => 'urn:oid:0.9.2342.19200300.100.1.3',
                'schacHomeOrganization' => 'urn:oid:1.3.6.1.4.1.25178.1.2.9',
            ],
            $md['attributes'],
        );
        $this->assertArrayHasKey('attributes.required', $md);
        $this->assertEquals(
            [
                'eduPersonPrincipalName' => 'urn:oid:1.3.6.1.4.1.5923.1.1.1.6',
            ],
            $md['attributes.required'],
        );
    }

    /**
     * SP config option for Name, Description require attributes to be specified
     */
    public function testMetadataHostedNameDescriptionAbsentWhenNoAttributes(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'name' => [
                'en' => 'My First SP',
            ],
            'description' => [
                'en' => 'This SP is my first one',
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayNotHasKey('name', $md);
        $this->assertArrayNotHasKey('description', $md);
    }

    /**
     * SP config for attributes also requires name in metadata
     */
    public function testMetadataHostedAttributesRequiresName(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'attributes' => [
                'mail' => 'urn:oid:0.9.2342.19200300.100.1.3',
                'schacHomeOrganization' => 'urn:oid:1.3.6.1.4.1.25178.1.2.9',
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayNotHasKey('attributes', $md);
    }

    /**
     * SP config for attributes with extra options
     */
    public function testMetadataHostedAttributesExtraOptions(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'name' => [
                'en' => 'My First SP',
            ],
            'attributes' => [
                'mail' => 'urn:oid:0.9.2342.19200300.100.1.3',
                'schacHomeOrganization' => 'urn:oid:1.3.6.1.4.1.25178.1.2.9',
            ],
            'attributes.NameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri',
            'attributes.index' => 5,
            'attributes.isDefault' => true,
            ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertEquals('urn:oasis:names:tc:SAML:2.0:attrname-format:uri', $md['attributes.NameFormat']);
        $this->assertEquals(5, $md['attributes.index']);
        $this->assertEquals(true, $md['attributes.isDefault']);
    }

    /**
     * SP config for holder-of-key profile via ProtocolBinding is reflected in metadata
     */
    public function testMetadataHolderOfKeyViaProtocolBindingIsInMetadata(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'ProtocolBinding' => 'urn:oasis:names:tc:SAML:2.0:profiles:holder-of-key:SSO:browser',
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertCount(3, $md['AssertionConsumerService']);
        $hok = $md['AssertionConsumerService'][2];
        $this->assertIsArray($hok);
        $this->assertEquals('urn:oasis:names:tc:SAML:2.0:profiles:holder-of-key:SSO:browser', $hok['Binding']);
        $this->assertEquals(
            'http://localhost/simplesaml/module.php/saml/sp/saml2-acs.php/' . $spId,
            $hok['Location'],
        );
        $this->assertEquals(2, $hok['index']);
        $this->assertEquals('urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect', $hok['hoksso:ProtocolBinding']);
    }

    /**
     * SP config with certificate are reflected in metadata
     */
    public function testMetadatCertificateIsInMetadata(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'privatekey' => self::CERT_KEY,
            'certificate' => self::CERT_PUBLIC,
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('keys', $md);
        $this->assertIsArray($md['keys']);
        $this->assertCount(1, $md['keys']);
        $this->assertEquals('X509Certificate', $md['keys'][0]['type']);
        $this->assertStringStartsWith('MIICxDCCAi2gAwIBAgIUZ9QDx+', $md['keys'][0]['X509Certificate']);
        $this->assertTrue($md['keys'][0]['encryption']);
        $this->assertTrue($md['keys'][0]['signing']);
        $this->assertEquals('', $md['keys'][0]['prefix']);
    }

    /**
     * SP config with certificate in rollocer scenario are reflected in metadata
     */
    public function testMetadatCertificateInRolloverIsInMetadata(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];

        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'privatekey' => self::CERT_KEY,
            'certificate' => self::CERT_PUBLIC,
            'new_privatekey' => self::CERT_OTHER_KEY,
            'new_certificate' => self::CERT_OTHER_PUBLIC,
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $this->assertArrayHasKey('keys', $md);
        $this->assertIsArray($md['keys']);
        $this->assertCount(2, $md['keys']);
        $this->assertEquals('X509Certificate', $md['keys'][0]['type']);
        $this->assertEquals('X509Certificate', $md['keys'][1]['type']);
        $this->assertStringStartsWith('MIICeTCCAeICAQMwDQYJKoZIhv', $md['keys'][0]['X509Certificate']);
        $this->assertStringStartsWith('MIICxDCCAi2gAwIBAgIUZ9QDx+', $md['keys'][1]['X509Certificate']);
        $this->assertTrue($md['keys'][0]['encryption']);
        $this->assertTrue($md['keys'][0]['signing']);
        $this->assertFalse($md['keys'][1]['encryption']);
        $this->assertTrue($md['keys'][1]['signing']);
        $this->assertEquals('new_', $md['keys'][0]['prefix']);
        $this->assertEquals('', $md['keys'][1]['prefix']);
    }

    /**
     * We only support SAML 2.0 as a protocol with this auth source
     */
    public function testSupportedProtocolsReturnsSAML20Only(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = ['entityID' => 'urn:x-simplesamlphp:example-sp'];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();
        $protocols = $as->getSupportedProtocols();
        $this->assertCount(1, $protocols);
        $this->assertEquals('urn:oasis:names:tc:SAML:2.0:protocol', $protocols[0]);
    }

    /**
     * We only support SAML 2.0 as a protocol with this auth source
     */
    public function testSAML11BindingsDoesNotInfluenceProtocolsSupported(): void
    {
        $spId = 'myhosted-sp';
        $info = ['AuthId' => $spId];
        $config = [
            'entityID' => 'urn:x-simplesamlphp:example-sp',
            'AssertionConsumerService' => [
                [
                    'index' => 1,
                    'isDefault' => true,
                    'Location' => 'https://sp.example.org/ACS',
                    'Binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                [
                    'index' => 17,
                    'Location' => 'https://sp.example.org/ACS',
                    'Binding' => 'urn:oasis:names:tc:SAML:1.0:profiles:browser-post',
                ],
            ],
        ];
        $as = new SpTester($info, $config);

        $md = $as->getHostedMetadata();

        $protocols = $as->getSupportedProtocols();
        $this->assertCount(1, $protocols);
        $this->assertEquals('urn:oasis:names:tc:SAML:2.0:protocol', $protocols[0]);
    }

   /**
    * Test sending a LogoutRequest
    */
    public function testLogoutRequest(): void
    {
        $nameId = new NameID('someone@example.com');

        $dom = DOMDocumentFactory::create();
        $extension = $dom->createElementNS('urn:some:namespace', 'MyLogoutExtension');
        $extChunk = [new Chunk($extension)];

        $entityId = "https://engine.surfconext.nl/authentication/idp/metadata";
        $xml = MetaDataStorageSourceTest::generateIdpMetadataXml($entityId);
        $c = [
            'metadata.sources' => [
                ["type" => "xml", "xml" => $xml],
            ],
        ];
        $this->config = Configuration::loadFromArray($c, '', 'simplesaml');

        $state = [
            'saml:logout:IdP' => $entityId,
            'saml:logout:NameID' => $nameId,
            'saml:logout:SessionIndex' => 'abc123',
            'saml:logout:Extensions' => $extChunk,
        ];

        $lr = $this->createLogoutRequest($state);

        /** @var \SimpleSAML\SAML2\XML\samlp\Extensions $extensions */
        $extensions = $lr->getExtensions();
        $this->assertcount(1, $state['saml:logout:Extensions']);

        $xml = $lr->toSignedXML();

        $xpCache = XPath::getXPath($xml);
        $q = XPath::xpQuery($xml, '/samlp:LogoutRequest/saml:NameID', $xpCache);
        $this->assertCount(1, $q);
        $this->assertEquals('someone@example.com', $q[0]->nodeValue);

        $q = XPath::xpQuery($xml, '/samlp:LogoutRequest/samlp:Extensions/*', $xpCache);
        $this->assertCount(1, $q);
        $this->assertEquals('MyLogoutExtension', $q[0]->firstChild->localName);
        $this->assertEquals('urn:some:namespace', $q[0]->firstChild->namespaceURI);
    }

    /*
     * Test using the entityID from config/authsources.php.dist
     */
    public function testSampleEntityIdException(): void
    {
        $info = ['AuthId' => 'default-sp'];
        $config = ['entityID' => 'https://myapp.example.org/'];
        $this->expectException(AssertionFailedException::class);
        $this->expectExceptionMessageMatches('/entityID/');
        $as = new SpTester($info, $config);
    }
}
