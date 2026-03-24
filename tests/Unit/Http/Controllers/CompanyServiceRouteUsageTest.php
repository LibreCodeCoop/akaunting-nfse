<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Http\Controllers;

use Modules\Nfse\Tests\TestCase;

final class CompanyServiceRouteUsageTest extends TestCase
{
    public function testCompanyServiceControllerRedirectsToDefinedSettingsEditRoute(): void
    {
        $content = file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/CompanyServiceController.php');

        self::assertNotFalse($content);
        self::assertStringContainsString("route('nfse.settings.edit'", $content);
        self::assertStringNotContainsString("route('nfse.settings'", $content);
    }

    public function testCompanyServiceControllerStoreDoesNotUseUndefinedUserCompanyMethod(): void
    {
        $content = file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/CompanyServiceController.php');

        self::assertNotFalse($content);
        self::assertStringNotContainsString('auth()->user()->company()', $content);
        self::assertStringContainsString('resolveCompanyId', $content);
        self::assertStringContainsString("\$request->route('company_id')", $content);
        self::assertStringContainsString('company_id()', $content);
    }

    public function testCompanyServiceControllerSupportsToggleActiveEndpoint(): void
    {
        $content = file_get_contents(dirname(__DIR__, 4) . '/Http/Controllers/CompanyServiceController.php');

        self::assertNotFalse($content);
        self::assertStringContainsString('function toggleActive(Request $request, CompanyService $service)', $content);
        self::assertStringContainsString('authorizeServiceOwnership($request, $service)', $content);
        self::assertStringContainsString("route('nfse.settings.edit', ['tab' => 'services'])", $content);
    }

    public function testServiceViewsUseDefinedSettingsEditRouteForBackAndCancel(): void
    {
        $createView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/create.blade.php');
        $editView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/edit.blade.php');
        $servicesPartial = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/settings/partials/services.blade.php');

        self::assertNotFalse($createView);
        self::assertNotFalse($editView);
        self::assertNotFalse($servicesPartial);

        self::assertStringContainsString("nfse.settings.edit", $createView);
        self::assertStringContainsString("nfse.settings.edit", $editView);
        self::assertStringContainsString("route('nfse.settings.edit', ['tab' => 'services'])", $servicesPartial);
        self::assertStringNotContainsString("route('nfse.settings'", $createView);
        self::assertStringNotContainsString("route('nfse.settings'", $editView);
    }

    public function testEditViewIncludesMakeDefaultAndToggleActiveQuickActions(): void
    {
        $editView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/edit.blade.php');

        self::assertNotFalse($editView);
        self::assertStringContainsString("route('nfse.settings.services.make-default', \$service->id)", $editView);
        self::assertStringContainsString("route('nfse.settings.services.toggle-active', \$service->id)", $editView);
        self::assertStringContainsString('star_border', $editView);
        self::assertStringContainsString('toggle_on', $editView);
        self::assertStringContainsString('toggle_off', $editView);
    }

    public function testCreateViewUsesNoAkauntingFormGroupComponents(): void
    {
        $createView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/create.blade.php');

        self::assertNotFalse($createView);
        self::assertStringNotContainsString('<x-form.group.', $createView);
        self::assertStringNotContainsString('<x-form.input.', $createView);
        self::assertStringContainsString('<input', $createView);
        self::assertStringContainsString('<textarea', $createView);
        self::assertStringContainsString('name="item_lista_servico_display"', $createView);
        self::assertStringContainsString('name="item_lista_servico"', $createView);
        self::assertStringContainsString('name="aliquota"', $createView);
        self::assertStringContainsString('name="is_active"', $createView);
        self::assertStringContainsString('name="description"', $createView);
    }

    public function testEditViewUsesNoAkauntingFormGroupComponents(): void
    {
        $editView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/edit.blade.php');

        self::assertNotFalse($editView);
        self::assertStringNotContainsString('<x-form.group.', $editView);
        self::assertStringNotContainsString('<x-form.input.', $editView);
        self::assertStringContainsString('<input', $editView);
        self::assertStringContainsString('<textarea', $editView);
        self::assertStringContainsString('name="aliquota"', $editView);
        self::assertStringNotContainsString('name="is_active"', $editView);
        self::assertStringContainsString('name="description"', $editView);
    }

    public function testServicesListingViewIncludesFilterAndToggleActiveControls(): void
    {
        $servicesPartial = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/settings/partials/services.blade.php');

        self::assertNotFalse($servicesPartial);
        self::assertStringContainsString('id="services-filter-form"', $servicesPartial);
        self::assertStringContainsString('name="services_search"', $servicesPartial);
        self::assertStringContainsString('name="services_status"', $servicesPartial);
        self::assertStringContainsString("route('nfse.settings.services.toggle-active'", $servicesPartial);
        self::assertStringContainsString("route('nfse.settings.services.make-default'", $servicesPartial);
        self::assertStringContainsString("trans('general.enabled')", $servicesPartial);
        self::assertStringContainsString("trans('general.disabled')", $servicesPartial);
        self::assertStringContainsString('material-icons-outlined', $servicesPartial);
        self::assertStringContainsString('>edit<', $servicesPartial);
        self::assertStringContainsString('>delete<', $servicesPartial);
        self::assertStringContainsString('>star_border<', $servicesPartial);
        self::assertStringContainsString("'toggle_on'", $servicesPartial);
        self::assertStringContainsString("'toggle_off'", $servicesPartial);
    }

    public function testServiceViewsDoNotDuplicateAliquotaPercentageSuffixInLabel(): void
    {
        $createView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/create.blade.php');
        $editView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/edit.blade.php');

        self::assertNotFalse($createView);
        self::assertNotFalse($editView);

        self::assertStringContainsString("trans('nfse::general.settings.services.aliquota')", $createView);
        self::assertStringContainsString("trans('nfse::general.settings.services.aliquota')", $editView);
        self::assertStringNotContainsString("trans('nfse::general.settings.services.aliquota') . ' (%)'", $createView);
        self::assertStringNotContainsString("trans('nfse::general.settings.services.aliquota') . ' (%)'", $editView);
    }

    public function testServiceViewsUseExplicitSubmitButtonsInsteadOfVueLoadingButtonsComponent(): void
    {
        $createView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/create.blade.php');
        $editView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/edit.blade.php');

        self::assertNotFalse($createView);
        self::assertNotFalse($editView);

        self::assertStringNotContainsString('<x-form.buttons', $createView);
        self::assertStringNotContainsString('<x-form.buttons', $editView);
        self::assertStringContainsString('type="submit"', $createView);
        self::assertStringContainsString('type="submit"', $editView);
        self::assertStringContainsString("route('nfse.settings.edit', ['tab' => 'services'])", $createView);
        self::assertStringContainsString("route('nfse.settings.edit', ['tab' => 'services'])", $editView);
    }

    public function testServiceViewsUseNativeHtmlFormSubmissionInsteadOfXFormComponent(): void
    {
        $createView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/create.blade.php');
        $editView = file_get_contents(dirname(__DIR__, 4) . '/Resources/views/services/edit.blade.php');

        self::assertNotFalse($createView);
        self::assertNotFalse($editView);

        self::assertStringNotContainsString('<x-form id="nfse-company-service"', $createView);
        self::assertStringNotContainsString('<x-form id="nfse-company-service"', $editView);
        self::assertStringContainsString('<form method="POST" action="{{ route(\'nfse.settings.services.store\') }}">', $createView);
        self::assertStringContainsString('<form method="POST" action="{{ route(\'nfse.settings.services.update\', $service->id) }}">', $editView);
        self::assertStringContainsString('@method(\'PATCH\')', $editView);
    }
}
