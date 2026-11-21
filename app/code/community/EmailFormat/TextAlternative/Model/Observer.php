<?php

/**
 * SPDX-FileCopyrightText: 2026 Email Text Alternative contributors
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Maho\Event\Observer;
use Symfony\Component\Mime\Email;

final class EmailFormat_TextAlternative_Model_Observer
{
    private const CONFIG_PATH = 'system/smtp/text_alternative';
    private const HEADER = 'X-Email-Text-Alternative';

    #[Maho\Config\Observer('email_template_dispatch_before')]
    public function recordStoreSetting(Observer $observer): void
    {
        $event = $observer->getEvent();
        $template = $event->getTemplate();
        if (!$template instanceof Mage_Core_Model_Email_Template || $template->isPlain()) {
            return;
        }

        $store = $event->getVariables()['store'] ?? null;
        $storeId = $store instanceof Mage_Core_Model_Store ? $store->getId() : $template->getTemplateFilter()->getStoreId();
        if (Mage::getStoreConfigFlag(self::CONFIG_PATH, $storeId)) {
            $event->getHeaders()->setData(self::HEADER, '1');
        }
    }

    #[Maho\Config\Observer('email_queue_send_before')]
    public function addTextAlternative(Observer $observer): void
    {
        $email = $observer->getEvent()->getMail();
        if (!$email instanceof Email) {
            return;
        }

        $headers = $email->getHeaders();
        $enabled = $headers->get(self::HEADER)?->getBodyAsString() === '1';
        $headers->remove(self::HEADER);
        $html = $email->getHtmlBody();
        if (!$enabled || $email->getTextBody() !== null || !is_string($html) || !mb_check_encoding($html, 'UTF-8')) {
            return;
        }

        $text = $this->convertHtml($html);
        if ($text !== '') {
            $email->text($text);
        }
    }

    private function convertHtml(#[\SensitiveParameter] string $html): string
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'strip_placeholder_links' => true,
            'remove_nodes' => 'head script style iframe noscript form button svg img',
            'hard_break' => true,
            'bold_style' => '',
            'italic_style' => '',
        ]);
        $converter->getEnvironment()->addConverter(new class implements ConverterInterface {
            #[\Override]
            public function convert(ElementInterface $element): string
            {
                $text = $element->getValue();
                return match ($element->getTagName()) {
                    'table' => "\n\n" . trim($text) . "\n\n",
                    'tr' => rtrim($text) . "\n",
                    default => $text . ' ',
                };
            }

            /** @return list<string> */
            #[\Override]
            public function getSupportedTags(): array
            {
                return ['table', 'tr', 'th', 'td'];
            }
        });

        try {
            return trim(html_entity_decode($converter->convert($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } catch (InvalidArgumentException|RuntimeException) {
            return '';
        }
    }
}
