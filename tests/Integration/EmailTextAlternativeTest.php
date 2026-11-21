<?php

/**
 * SPDX-FileCopyrightText: 2026 Email Text Alternative contributors
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Maho\Event\Observer;
use Maho\Queue\QueueManager;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\RawMessage;

uses(Tests\MahoBackendTestCase::class);

const TEXT_ALTERNATIVE_CONFIG = 'system/smtp/text_alternative';
const TEXT_ALTERNATIVE_HEADER = 'X-Email-Text-Alternative';

final class TextAlternativeFixtureAttachment
{
    public function getPdf(array $documents): string
    {
        return 'Order attachment';
    }
}

final class TextAlternativeFixtureObserver
{
    public function addText(Observer $observer): void
    {
        $observer->getEvent()->getMail()->text('Text supplied by another observer.');
    }
}

function textAlternativeTemplate(int $storeId, string $body, bool $plain = false): Mage_Core_Model_Email_Template
{
    $template = Mage::getModel('core/email_template');
    $template->setSenderName('Example store')->setSenderEmail('store@example.invalid')->setTemplateSubject('Order summary');
    $template->setDesignConfig(['area' => 'frontend', 'store' => $storeId]);
    $template->setTemplateType($plain ? Mage_Core_Model_Email_Template::TYPE_TEXT : Mage_Core_Model_Email_Template::TYPE_HTML);
    return $template->setTemplateText($body);
}

beforeEach(function (): void {
    QueueManager::reset();
    $this->connection = Mage::getSingleton('core/resource')->getConnection('core_write');
    $this->connection->beginTransaction();
    $this->developerMode = Mage::getIsDeveloperMode();
    Mage::setIsDeveloperMode(true);

    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->once())->method('send')->willReturnCallback(function (RawMessage $email): ?SentMessage {
        expect($email)->toBeInstanceOf(Email::class);
        $this->email = $email;
        return null;
    });
    $helper = $this->createPartialMock(Mage_Core_Helper_Data::class, ['getMailTransport']);
    $helper->method('getMailTransport')->willReturn($transport);
    Mage::unregister('_helper/core');
    Mage::register('_helper/core', $helper);
});

afterEach(function (): void {
    Mage::setIsDeveloperMode($this->developerMode);
    $this->connection->rollBack();
    QueueManager::reset();
});

it('sends a multipart alternative and preserves the rendered HTML and attachment', function (): void {
    $store = Mage::app()->getDefaultStoreView();
    $store->setConfig(TEXT_ALTERNATIVE_CONFIG, '1');
    $html = '<table><tr><td><p>Résumé &amp; details</p><p>Books</p></td><td>3</td></tr></table>'
        . '<p><a href="https://example.invalid/orders">Order status</a></p>';
    $template = textAlternativeTemplate((int) $store->getId(), $html);
    $template->setAttachments([
        new Mage_Core_Model_Email_Attachment(TextAlternativeFixtureAttachment::class, 'sales/order', 0, 'order.txt', 'text/plain'),
    ]);

    expect($template->send('customer@example.invalid'))->toBeTrue();

    $body = $this->email->getBody();
    expect($this->email->getHtmlBody())->toBe($html)
        ->and($this->email->getTextBody())->toBeString()->toContain('Résumé & details', 'Books', 'https://example.invalid/orders')
        ->and($this->email->getTextBody())->not->toContain('Books3')
        ->and($this->email->getHeaders()->has(TEXT_ALTERNATIVE_HEADER))->toBeFalse()
        ->and($body)->toBeInstanceOf(MixedPart::class)
        ->and($body->getParts()[0])->toBeInstanceOf(AlternativePart::class)
        ->and(array_map(fn($part) => $part->getMediaSubtype(), $body->getParts()[0]->getParts()))->toBe(['plain', 'html'])
        ->and($this->email->getAttachments()[0]->getFilename())->toBe('order.txt')
        ->and($this->email->getAttachments()[0]->getBody())->toBe('Order attachment');
});

it('honors the dispatch store view when the worker uses a different view', function (bool $enabled): void {
    $primary = Mage::app()->getDefaultStoreView();
    $other = Mage::getModel('core/store');
    $other->setWebsiteId($primary->getWebsiteId())->setGroupId($primary->getGroupId())
        ->setCode('text_fixture')->setName('Example second view')->setIsActive(true)->save();
    Mage::app()->reinitStores();
    $primary = Mage::app()->getStore($primary->getId());
    $other = Mage::app()->getStore($other->getId());
    $primary->setConfig(TEXT_ALTERNATIVE_CONFIG, $enabled ? '1' : '0');
    $other->setConfig(TEXT_ALTERNATIVE_CONFIG, $enabled ? '0' : '1');
    Mage::app()->setCurrentStore($other);
    Mage::setIsDeveloperMode(false);

    $template = textAlternativeTemplate((int) $primary->getId(), '<p>Example order summary</p>');
    expect($template->send('customer@example.invalid'))->toBeTrue();

    $transport = QueueManager::dbTransport();
    $envelopes = iterator_to_array($transport->get());
    expect($envelopes)->toHaveCount(1);
    $message = $envelopes[0]->getMessage();
    expect($message)->toBeInstanceOf(Mage_Core_Model_Email_SendMessage::class)
        ->and(array_key_exists(TEXT_ALTERNATIVE_HEADER, $message->headers))->toBe($enabled);
    new Mage_Core_Model_Email_SendMessageHandler()($message);
    $transport->ack($envelopes[0]);

    expect($this->email->getTextBody())->toBe($enabled ? 'Example order summary' : null)
        ->and($this->email->getHtmlBody())->toBe('<p>Example order summary</p>')
        ->and($this->email->getHeaders()->has(TEXT_ALTERNATIVE_HEADER))->toBeFalse();
})->with([true, false]);

it('keeps text supplied by an observer', function (): void {
    $store = Mage::app()->getDefaultStoreView();
    $store->setConfig(TEXT_ALTERNATIVE_CONFIG, '1');
    Mage::getConfig()->setNode('global/events/email_queue_send_before/observers/fixture_text/class', TextAlternativeFixtureObserver::class);
    Mage::getConfig()->setNode('global/events/email_queue_send_before/observers/fixture_text/method', 'addText');

    expect(textAlternativeTemplate((int) $store->getId(), '<p>Original HTML</p>')->send('customer@example.invalid'))->toBeTrue()
        ->and($this->email->getHtmlBody())->toBe('<p>Original HTML</p>')
        ->and($this->email->getTextBody())->toBe('Text supplied by another observer.')
        ->and($this->email->getHeaders()->has(TEXT_ALTERNATIVE_HEADER))->toBeFalse();
});

it('preserves a single body when disabled, already plain text, or empty after conversion', function (bool $enabled, bool $plain, string $body): void {
    $store = Mage::app()->getDefaultStoreView();
    if ($enabled) {
        $store->setConfig(TEXT_ALTERNATIVE_CONFIG, '1');
    }

    expect(textAlternativeTemplate((int) $store->getId(), $body, $plain)->send('customer@example.invalid'))->toBeTrue()
        ->and($this->email->getTextBody())->toBe($plain ? $body : null)
        ->and($this->email->getHtmlBody())->toBe($plain ? null : $body)
        ->and($this->email->getBody()->getMediaSubtype())->toBe($plain ? 'plain' : 'html')
        ->and($this->email->getHeaders()->has(TEXT_ALTERNATIVE_HEADER))->toBeFalse();
})->with([
    'disabled by default' => [false, false, '<p>HTML only</p>'],
    'plain template' => [true, true, 'Résumé &amp; details'],
    'no convertible body' => [true, false, '<img src="cid:logo" alt="Logo">'],
]);
