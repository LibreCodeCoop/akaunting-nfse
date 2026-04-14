<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Abstracts {
    if (!class_exists(\App\Abstracts\Notification::class, false)) {
        abstract class Notification
        {
            public array $custom_mail = [];

            /** @var object|null */
            public $template = null;

            public function __construct()
            {
            }

            public function initMailMessage(): \Illuminate\Notifications\Messages\MailMessage
            {
                return new \Illuminate\Notifications\Messages\MailMessage();
            }
        }
    }
}

namespace App\Models\Setting {
    if (!class_exists(\App\Models\Setting\EmailTemplate::class, false)) {
        class EmailTemplate
        {
            public string $alias = '';

            public string $subject = '';

            public string $body = '';

            public static ?self $stubInstance = null;

            public static function alias(string $alias): object
            {
                return new class (self::$stubInstance) {
                    public function __construct(private ?\App\Models\Setting\EmailTemplate $t)
                    {
                    }

                    public function first(): ?\App\Models\Setting\EmailTemplate
                    {
                        return $this->t;
                    }
                };
            }
        }
    }
}

namespace Illuminate\Notifications\Messages {
    if (!class_exists(\Illuminate\Notifications\Messages\MailMessage::class, false)) {
        class MailMessage
        {
            /** @var list<object> */
            public array $attachments = [];

            public function attach(object $attachment): self
            {
                $this->attachments[] = $attachment;

                return $this;
            }

            public function from(mixed ...$args): self
            {
                return $this;
            }

            public function subject(mixed ...$args): self
            {
                return $this;
            }

            public function view(mixed ...$args): self
            {
                return $this;
            }

            public function cc(mixed ...$args): self
            {
                return $this;
            }

            public function bcc(mixed ...$args): self
            {
                return $this;
            }
        }
    }
}

namespace Illuminate\Mail {
    if (!class_exists(\Illuminate\Mail\Attachment::class, false)) {
        class Attachment
        {
            /** @var callable */
            public $dataFactory;

            public string $name = '';

            public string $mime = '';

            public static function fromData(callable $dataFactory, string $name = ''): self
            {
                $instance = new self();
                $instance->dataFactory = $dataFactory;
                $instance->name = $name;

                return $instance;
            }

            public function withMime(string $mime): self
            {
                $this->mime = $mime;

                return $this;
            }
        }
    }
}

namespace Modules\Nfse\Tests\Unit\Notifications {
    use App\Models\Document\Document as Invoice;
    use App\Models\Setting\EmailTemplate;
    use Modules\Nfse\Models\NfseReceipt;
    use Modules\Nfse\Notifications\NfseIssued;
    use Modules\Nfse\Support\WebDavClient;
    use Modules\Nfse\Tests\TestCase;

    final class NfseIssuedTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            if (!class_exists(\App\Models\Document\Document::class, false)) {
                eval('namespace App\\Models\\Document; class Document { public string $document_number = ""; public string $contact_name = ""; public float $amount = 0.0; public ?object $company = null; }');
            }

            if (!class_exists(\Modules\Nfse\Models\NfseReceipt::class, false)) {
                eval('namespace Modules\\Nfse\\Models; class NfseReceipt { public string $nfse_number = ""; public ?object $data_emissao = null; public ?string $danfse_webdav_path = null; public ?string $xml_webdav_path = null; }');
            }
        }

        protected function tearDown(): void
        {
            parent::tearDown();
            EmailTemplate::$stubInstance = null;
        }

        private function makeTemplate(string $subject = 'NFS-e {nfse_number} emitida', string $body = 'Prezado {customer_name}'): EmailTemplate
        {
            $template = new EmailTemplate();
            $template->alias = 'nfse_issued_customer';
            $template->subject = $subject;
            $template->body = $body;
            EmailTemplate::$stubInstance = $template;

            return $template;
        }

        private function makeInvoice(string $docNumber = 'INV-001', string $contactName = 'Cliente Teste'): Invoice
        {
            $invoice = new Invoice();
            $invoice->document_number = $docNumber;
            $invoice->contact_name = $contactName;

            return $invoice;
        }

        private function makeReceipt(string $nfseNumber = '12345', ?string $danfsePath = null, ?string $xmlPath = null): NfseReceipt
        {
            $receipt = new NfseReceipt();
            $receipt->nfse_number = $nfseNumber;
            $receipt->danfse_webdav_path = $danfsePath;
            $receipt->xml_webdav_path = $xmlPath;

            return $receipt;
        }

        public function testGetTagsReturnsAllExpectedTags(): void
        {
            $this->makeTemplate();
            $notification = new NfseIssued($this->makeInvoice(), $this->makeReceipt());
            $tags = $notification->getTags();

            self::assertContains('{invoice_number}', $tags);
            self::assertContains('{nfse_number}', $tags);
            self::assertContains('{customer_name}', $tags);
            self::assertContains('{company_name}', $tags);
            self::assertContains('{nfse_issue_date}', $tags);
        }

        public function testGetTagsReplacementMapsFieldsFromInvoiceAndReceipt(): void
        {
            $this->makeTemplate();
            $invoice = $this->makeInvoice('INV-123', 'Empresa ABC');
            $receipt = $this->makeReceipt('9988');
            $notification = new NfseIssued($invoice, $receipt);
            $replacements = $notification->getTagsReplacement();

            self::assertContains('INV-123', $replacements);
            self::assertContains('9988', $replacements);
            self::assertContains('Empresa ABC', $replacements);
        }

        public function testToMailOverridesNotifiableEmailWhenCustomMailToProvided(): void
        {
            $this->makeTemplate();

            $notification = new class ($this->makeInvoice(), $this->makeReceipt(), true, true, ['to' => 'custom@example.com']) extends NfseIssued {
                protected function makeWebDavClient(): WebDavClient
                {
                    return new WebDavClient('http://nowhere', request: static fn (): array => [200, '']);
                }

                public function initMailMessage(): \Illuminate\Notifications\Messages\MailMessage
                {
                    return new \Illuminate\Notifications\Messages\MailMessage();
                }
            };

            $notifiable = new class () {
                public string $email = '';
            };
            $notification->toMail($notifiable);

            self::assertSame('custom@example.com', $notifiable->email);
        }

        public function testToMailAttachesDanfseWhenAttachDanfseTrueAndPathSet(): void
        {
            $this->makeTemplate();
            $receipt = $this->makeReceipt('12345', '/nfse/file.pdf');

            $notification = new class ($this->makeInvoice(), $receipt, true, false, []) extends NfseIssued {
                protected function makeWebDavClient(): WebDavClient
                {
                    return new WebDavClient('http://nowhere', request: static fn (): array => [200, 'PDF_DATA']);
                }

                public function initMailMessage(): \Illuminate\Notifications\Messages\MailMessage
                {
                    return new \Illuminate\Notifications\Messages\MailMessage();
                }
            };

            $notifiable = new class () {
                public string $email = 'a@b.com';
            };
            $message = $notification->toMail($notifiable);

            self::assertCount(1, $message->attachments);
            self::assertSame('application/pdf', $message->attachments[0]->mime ?? null);
        }

        public function testToMailDoesNotAttachWhenAttachDanfseFalse(): void
        {
            $this->makeTemplate();
            $receipt = $this->makeReceipt('12345', '/nfse/file.pdf');

            $notification = new class ($this->makeInvoice(), $receipt, false, false, []) extends NfseIssued {
                protected function makeWebDavClient(): WebDavClient
                {
                    return new WebDavClient('http://nowhere', request: static fn (): array => [200, 'PDF_DATA']);
                }

                public function initMailMessage(): \Illuminate\Notifications\Messages\MailMessage
                {
                    return new \Illuminate\Notifications\Messages\MailMessage();
                }
            };

            $notifiable = new class () {
                public string $email = 'a@b.com';
            };
            $message = $notification->toMail($notifiable);

            self::assertCount(0, $message->attachments);
        }

        public function testToMailAttachesBothDanfseAndXmlWhenBothEnabled(): void
        {
            $this->makeTemplate();
            $receipt = $this->makeReceipt('12345', '/nfse/file.pdf', '/nfse/file.xml');

            $notification = new class ($this->makeInvoice(), $receipt, true, true, []) extends NfseIssued {
                protected function makeWebDavClient(): WebDavClient
                {
                    return new WebDavClient('http://nowhere', request: static fn (): array => [200, 'CONTENT']);
                }

                public function initMailMessage(): \Illuminate\Notifications\Messages\MailMessage
                {
                    return new \Illuminate\Notifications\Messages\MailMessage();
                }
            };

            $notifiable = new class () {
                public string $email = 'a@b.com';
            };
            $message = $notification->toMail($notifiable);

            self::assertCount(2, $message->attachments);
        }

        public function testClassExtendsAkauntingNotification(): void
        {
            $source = file_get_contents(__DIR__ . '/../../../Notifications/NfseIssued.php');
            self::assertStringContainsString('use App\\Abstracts\\Notification', $source);
            self::assertStringContainsString('extends Notification', $source);
        }

        public function testTemplateAliasIsCorrect(): void
        {
            $source = file_get_contents(__DIR__ . '/../../../Notifications/NfseIssued.php');
            self::assertStringContainsString("'nfse_issued_customer'", $source);
        }
    }
}
