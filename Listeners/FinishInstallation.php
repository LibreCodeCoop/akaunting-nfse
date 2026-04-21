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
        $defaults = self::defaultEmailTemplateContent();

        $this->dispatch(new CreateEmailTemplate([
            'company_id' => $event->company_id,
            'alias' => 'invoice_nfse_issued_customer',
            'class' => \Modules\Nfse\Notifications\NfseIssued::class,
            'name' => 'settings.email.templates.invoice_nfse_issued_customer',
            'subject' => $defaults['subject'],
            'body' => $defaults['body'],
            'created_from' => 'nfse::seed',
        ]));
    }

    public static function defaultEmailTemplateContent(): array
    {
        $fallbackSubject = 'NFS-e {nfse_number} emitida';
        $fallbackBody    = 'Prezado(a) {customer_name},<br><br>Segue a NFS-e nº {nfse_number} referente à fatura {invoice_number}.<br><br>Atenciosamente,<br>{company_name}';
        $subjectKey      = 'email_templates.invoice_nfse_issued_customer.subject';
        $bodyKey         = 'email_templates.invoice_nfse_issued_customer.body';

        try {
            $subject = trans($subjectKey);
            $body    = trans($bodyKey);
        } catch (\Throwable) {
            return ['subject' => $fallbackSubject, 'body' => $fallbackBody];
        }

        $subjectValue = is_string($subject) && $subject !== '' && $subject !== $subjectKey ? $subject : $fallbackSubject;
        $bodyValue    = is_string($body) && $body !== '' && $body !== $bodyKey ? $body : $fallbackBody;

        return [
            'subject' => $subjectValue,
            'body'    => $bodyValue,
        ];
    }
}
