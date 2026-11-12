<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

final class AuxiliaryApiParityTest extends FeatureTestCase
{
    public function testNativeAndBrowserRoutesShareControllersAndEnforcePermissions(): void
    {
        foreach ([
            ['dashboard.index', 'dashboard.data', 'GET', 'read-sales-invoices'],
            ['settings.email-footer.show', 'settings.email-footer.show', 'GET', 'read-nfse-settings'],
            ['settings.email-footer.update', 'settings.email-footer', 'PATCH', 'update-nfse-settings'],
            ['invoices.email-preferences.show', 'invoices.email-preferences.show', 'GET', 'read-sales-invoices'],
            ['invoices.email-preferences.store', 'invoices.email-preferences.store', 'POST', 'update-sales-invoices'],
            ['invoices.refresh-all', 'invoices.refresh-all', 'POST', 'update-sales-invoices'],
            ['invoices.emails.store', 'modals.invoices.emails.store', 'POST', 'update-sales-invoices'],
            ['ibge.ufs', 'ibge.ufs', 'GET', 'read-nfse-settings'],
            ['ibge.municipalities', 'ibge.municipalities', 'GET', 'read-nfse-settings'],
            ['lc116.services', 'lc116.services', 'GET', 'read-nfse-settings'],
            ['municipal-parameters.index', 'municipal-parameters', 'GET', 'read-nfse-settings'],
        ] as [$nativeName, $browserName, $method, $permission]) {
            $native = Route::getRoutes()->getByName('api.nfse.' . $nativeName);
            $browser = Route::getRoutes()->getByName('nfse.' . $browserName);

            self::assertNotNull($native, $nativeName);
            self::assertNotNull($browser, $browserName);
            self::assertContains('api', $native->gatherMiddleware());
            self::assertContains($method, $native->methods());
            self::assertContains('permission:' . $permission, $native->gatherMiddleware());
            self::assertSame($browser->getActionName(), $native->getActionName(), $nativeName);
        }
    }

    public function testMunicipalConsultationUsesExplicitPostWithExistingCoreAuthorization(): void
    {
        $consult = Route::getRoutes()->getByName('api.nfse.municipal-parameters.consult');
        $legacy = Route::getRoutes()->getByName('api.nfse.municipal-parameters.index');

        self::assertNotNull($consult);
        self::assertNotNull($legacy);
        self::assertContains('POST', $consult->methods());
        self::assertContains('GET', $legacy->methods());
        self::assertContains('api', $consult->gatherMiddleware());
        self::assertContains('permission:read-nfse-settings', $consult->gatherMiddleware());
        self::assertSame($legacy->getActionName(), $consult->getActionName());
    }

    public function testEmailFooterIsReadAndWrittenThroughJsonWithoutServerRenderedValues(): void
    {
        $this->loginAs();
        setting([
            'nfse.email_footer_message' => 'Synthetic footer from the saved API setting',
            'nfse.email_footer_url' => 'https://footer.example.test/old',
        ]);
        setting()->save();

        $this->getJson(route('nfse.settings.email-footer.show'))
            ->assertOk()
            ->assertJsonPath('data.footer_message', 'Synthetic footer from the saved API setting')
            ->assertJsonPath('data.footer_url', 'https://footer.example.test/old');

        $this->get(route('nfse.settings.edit'))
            ->assertOk()
            ->assertSee('nfse-email-footer-form')
            ->assertDontSee('Synthetic footer from the saved API setting');

        $this->patchJson(route('nfse.settings.email-footer'), [
            'footer_message' => 'Another synthetic footer',
            'footer_url' => 'https://footer.example.test/new',
        ])->assertOk()
            ->assertJsonPath('data.footer_message', 'Another synthetic footer')
            ->assertJsonPath('data.footer_url', 'https://footer.example.test/new');

        self::assertSame('Another synthetic footer', setting('nfse.email_footer_message'));
    }

    public function testEmailFooterRejectsInsecureUrlsWithoutChangingSavedContent(): void
    {
        $this->withExceptionHandling()->loginAs();
        setting([
            'nfse.email_footer_message' => 'Original synthetic footer',
            'nfse.email_footer_url' => 'https://footer.example.test/safe',
        ]);
        setting()->save();

        $this->patchJson(route('nfse.settings.email-footer'), [
            'footer_message' => 'Unapproved footer',
            'footer_url' => 'https://user:password@footer.example.test/private',
        ])->assertUnprocessable()
            ->assertDontSee('password@footer');

        self::assertSame('Original synthetic footer', setting('nfse.email_footer_message'));
        self::assertSame('https://footer.example.test/safe', setting('nfse.email_footer_url'));
    }
}
