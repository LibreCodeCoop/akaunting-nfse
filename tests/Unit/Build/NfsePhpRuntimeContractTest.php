<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Build;

use Modules\Nfse\Tests\TestCase;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\AdnClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\MunicipalParametersClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NativeStreamTransport;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NfseClient;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Support\GzipBase64;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Xml\XmlSignatureVerifier;

/**
 * Defines the nfse-php runtime surface that the Akaunting module depends on.
 *
 * This intentionally fails early in CI when the scoped runtime drifts behind
 * module features instead of letting missing capabilities fail in production.
 */
final class NfsePhpRuntimeContractTest extends TestCase
{
    public function testDpsDataExposesAllFieldsRequiredByCurrentIssuanceFeatures(): void
    {
        $constructor = new \ReflectionMethod(DpsData::class, '__construct');
        $parameters = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        foreach ([
            'tributacaoIssqn',
            'issqnPaisResultado',
            'issqnTipoImunidade',
            'issqnTipoSuspensao',
            'issqnNumeroProcessoSuspensao',
            'tomadorNif',
            'tomadorCodigoNaoNif',
            'tomadorPaisCodigo',
            'tomadorCodigoPostalExterior',
            'tomadorCidadeExterior',
            'tomadorEstadoExterior',
            'ibsCbsFinalidade',
            'ibsCbsIndFinal',
            'ibsCbsCodigoIndicadorOperacao',
            'ibsCbsIndDest',
            'ibsCbsCst',
            'ibsCbsClassificacaoTributaria',
            'codigoTributacaoMunicipal',
        ] as $requiredField) {
            self::assertContains($requiredField, $parameters, 'Missing nfse-php DpsData field: ' . $requiredField);
        }
    }

    public function testSefinClientExposesRecoveryAndEventCapabilities(): void
    {
        self::assertTrue(method_exists(NfseClient::class, 'emit'));
        self::assertTrue(method_exists(NfseClient::class, 'query'));
        self::assertTrue(method_exists(NfseClient::class, 'queryDps'));
        self::assertTrue(method_exists(NfseClient::class, 'existsDps'));
        self::assertTrue(method_exists(NfseClient::class, 'queryEvent'));
        self::assertTrue(method_exists(NfseClient::class, 'cancel'));
        self::assertTrue(method_exists(NfseClient::class, 'cancelWithReason'));
    }

    public function testAdnClientExposesDistributionCapabilities(): void
    {
        self::assertTrue(method_exists(AdnClient::class, 'getDfe'));
        self::assertTrue(method_exists(AdnClient::class, 'listEvents'));
    }

    public function testMunicipalParametersClientExposesDiagnosticCapabilities(): void
    {
        foreach ([
            'convenio',
            'aliquota',
            'regimesEspeciais',
            'retencoes',
            'beneficio',
        ] as $method) {
            self::assertTrue(
                method_exists(MunicipalParametersClient::class, $method),
                'Missing nfse-php municipal parameter method: ' . $method,
            );
        }
    }

    public function testInjectableFiscalTransportCapabilityIsAvailable(): void
    {
        self::assertTrue(interface_exists(HttpTransportInterface::class));
        self::assertTrue(class_exists(HttpRequestData::class));
        self::assertTrue(class_exists(HttpResponseData::class));
        self::assertTrue(class_exists(NativeStreamTransport::class));
        self::assertTrue(is_a(NativeStreamTransport::class, HttpTransportInterface::class, true));

        foreach ([NfseClient::class, AdnClient::class, MunicipalParametersClient::class] as $clientClass) {
            $constructor = new \ReflectionMethod($clientClass, '__construct');
            $parameterNames = array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            );

            self::assertContains('transport', $parameterNames, 'Missing injectable transport on ' . $clientClass);
        }
    }

    public function testSecurityAndPayloadHelpersRequiredByTheModuleAreAvailable(): void
    {
        self::assertTrue(method_exists(XmlSignatureVerifier::class, 'verify'));
        self::assertTrue(method_exists(GzipBase64::class, 'decode'));
    }
}
