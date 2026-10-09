<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Listeners;

use App\Models\Common\Contact;
use Modules\Nfse\Support\ContactFiscalProfileInput;
use Modules\Nfse\Support\ContactFiscalProfileStore;

/**
 * Bridges native ContactCreating/Updating into the in-transaction Eloquent
 * Contact::saving/saved hooks, as done for item fiscal fields.
 */
final class PersistContactFiscalProfile
{
    /** @var array{municipal_registration:string,legal_name:string}|null */
    private ?array $values = null;
    private ?string $operation = null;
    private int $companyId = 0;
    private int $contactId = 0;

    public function creating(object $event): void
    {
        $this->capture($event->request ?? null, 'create');
    }

    public function updating(object $event): void
    {
        $this->capture($event->request ?? null, 'update', $event->contact ?? null);
    }

    public function beforeSave(Contact $contact): void
    {
        if ($this->matches($contact, false)) {
            $this->assertCompanyAndTransaction($contact);
        }
    }

    public function afterSave(Contact $contact): void
    {
        if (!$this->matches($contact, true)) {
            return;
        }
        $this->assertCompanyAndTransaction($contact);
        $values = $this->values;
        $this->clear();
        if ($values !== null) {
            (new ContactFiscalProfileStore())->persist(
                (int) $contact->company_id,
                (int) $contact->id,
                $values,
            );
        }
    }

    private function capture(mixed $request, string $operation, mixed $contact = null): void
    {
        $this->clear();
        $values = ContactFiscalProfileInput::fromRequest($request);
        if ($values === null || !function_exists('company_id')) {
            return;
        }
        $companyId = (int) company_id();
        $contactId = $operation === 'update' && is_object($contact) && is_numeric($contact->id ?? null)
            ? (int) $contact->id : 0;
        if ($companyId <= 0 || ($operation === 'update' && $contactId <= 0)) {
            return;
        }

        $this->values = $values;
        $this->operation = $operation;
        $this->companyId = $companyId;
        $this->contactId = $contactId;
    }

    private function matches(Contact $contact, bool $saved): bool
    {
        if ($this->values === null || ($contact->type ?? '') !== Contact::CUSTOMER_TYPE) {
            return false;
        }
        if ($this->operation === 'create') {
            return $saved ? $contact->wasRecentlyCreated : !$contact->exists;
        }
        return $this->operation === 'update'
            && $contact->exists && (int) $contact->id === $this->contactId;
    }

    private function assertCompanyAndTransaction(Contact $contact): void
    {
        $connection = $contact->getConnection();
        if ((int) $contact->company_id !== $this->companyId
            || $connection->transactionLevel() < 1) {
            $this->clear();
            throw new \RuntimeException('NFS-e customer profile requires native company transaction.');
        }
    }

    private function clear(): void
    {
        $this->values = null;
        $this->operation = null;
        $this->companyId = 0;
        $this->contactId = 0;
    }
}
