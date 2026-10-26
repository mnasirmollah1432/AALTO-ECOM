<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Contact;

final class PageController
{
    /** Temporary page for sections that are built in later phases. */
    public function comingSoon(): string
    {
        return view('pages/coming-soon', [
            'meta' => ['title' => 'Coming soon', 'robots' => 'noindex, follow'],
        ], 'main');
    }

    /** GET /contact - contact channels and social links from Admin -> Contact & social */
    public function contact(): string
    {
        $site = (string) config('app.name');
        $values = Contact::values();

        // Organization contact details for search engines
        $sameAs = array_values(array_filter([$values['contact_facebook'], $values['contact_instagram'], $values['contact_youtube'], $values['contact_tiktok']]));
        $jsonLd = [
            array_filter([
                '@context'     => 'https://schema.org',
                '@type'        => 'Organization',
                'name'         => $site,
                'url'          => absolute_url(url('')),
                'logo'         => absolute_url(asset('images/logo-512.png')),
                'email'        => $values['contact_email'] ?: null,
                'telephone'    => $values['contact_phone'] ?: null,
                'sameAs'       => $sameAs ?: null,
                'contactPoint' => $values['contact_phone'] !== '' ? [
                    '@type'             => 'ContactPoint',
                    'telephone'         => $values['contact_phone'],
                    'contactType'       => 'customer service',
                    'areaServed'        => (string) config('app.store.country', 'BD'),
                    'availableLanguage' => ['en', 'bn'],
                ] : null,
            ], static fn ($v) => $v !== null),
            breadcrumb_json_ld([['Home', url('')], ['Contact us', url('contact')]]),
        ];

        return view('pages/contact', [
            'values'   => $values,
            'channels' => Contact::channels(),
            'meta'     => [
                'title'       => 'Contact us',
                'description' => str_limit('Contact ' . $site . ' by WhatsApp, Messenger, imo, phone or email. We reply quickly and confirm every order by phone.', 160),
                'canonical'   => url('contact'),
                'json_ld'     => $jsonLd,
            ],
        ], 'main');
    }
}
