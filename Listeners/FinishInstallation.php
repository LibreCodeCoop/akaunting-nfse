<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Events\Module\Installed as Event;
use App\Jobs\Setting\CreateEmailTemplate;
use App\Traits\Jobs;
use App\Traits\Permissions;

class FinishInstallation
{
    use Jobs;
    use Permissions;

    public string $alias = 'nfse';

    public function handle(Event $event): void
    {
        if ($event->alias !== $this->alias) {
            return;
        }

        $this->updatePermissions();
        $this->createNfseEmailTemplates($event);
    }

    protected function updatePermissions(): void
    {
        // c=create, r=read, u=update, d=delete
        $this->attachPermissionsToAdminRoles([
            $this->alias . '-settings' => 'r,u,d',
        ]);
    }

    protected function createNfseEmailTemplates(Event $event): void
    {
        $subject = trans('email_templates.nfse_issued_customer.subject');
        $body = trans('email_templates.nfse_issued_customer.body');

        $this->dispatch(new CreateEmailTemplate([
            'company_id' => $event->company_id,
            'alias' => 'nfse_issued_customer',
            'class' => \Modules\Nfse\Notifications\NfseIssued::class,
            'name' => 'settings.email.templates.nfse_issued_customer',
            'subject' => is_string($subject) ? $subject : 'NFS-e {nfse_number} emitida',
            'body' => is_string($body) ? $body : 'Prezado(a) {customer_name},',
            'created_from' => 'nfse::seed',
        ]));
    }
}
