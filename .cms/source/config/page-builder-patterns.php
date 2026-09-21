<?php

declare(strict_types=1);

return [
    'landing-page' => [
        'label' => 'Landing page', 'description' => 'A complete conversion-focused page with introduction, benefits, services, proof and a final action.', 'icon' => 'rocket', 'group' => 'Marketing',
        'sections' => ['hero', 'values', 'programs', 'statistics', 'news', 'cta'],
    ],
    'campaign' => [
        'label' => 'Campaign', 'description' => 'A visual campaign flow for a focused message, supporting story, proof and response.', 'icon' => 'megaphone', 'group' => 'Marketing',
        'sections' => ['hero-slider', 'story', 'statistics', 'cta'],
    ],
    'newsletter-landing' => [
        'label' => 'Newsletter landing page', 'description' => 'A concise subscription page with an introduction, supporting copy and a configurable form.', 'icon' => 'mail-plus', 'group' => 'Marketing',
        'sections' => ['hero', 'text', 'contact-form'],
    ],
    'about-us' => [
        'label' => 'About us', 'description' => 'Present the organisation, its values, highlights, people and a clear next step.', 'icon' => 'users-round', 'group' => 'Company',
        'sections' => ['story', 'values', 'statistics', 'gallery', 'cta'],
    ],
    'contact' => [
        'label' => 'Contact', 'description' => 'A practical contact page with context, contact details, an enquiry form and next steps.', 'icon' => 'messages-square', 'group' => 'Company',
        'sections' => ['image-text', 'contact-form', 'cta'],
    ],
    'product-presentation' => [
        'label' => 'Product presentation', 'description' => 'Introduce a product, explain its main benefits, add proof and lead visitors to action.', 'icon' => 'package-open', 'group' => 'Offers',
        'sections' => ['hero', 'image-text', 'image-text', 'statistics', 'cta'],
    ],
    'service-page' => [
        'label' => 'Service page', 'description' => 'Present a service, its options and delivery process before a final enquiry action.', 'icon' => 'briefcase-business', 'group' => 'Offers',
        'sections' => ['hero', 'programs', 'admissions', 'cta'],
    ],
    'pricing' => [
        'label' => 'Pricing', 'description' => 'A structured offer page for packages, comparison points, supporting facts and conversion.', 'icon' => 'badge-dollar-sign', 'group' => 'Offers',
        'sections' => ['hero', 'programs', 'statistics', 'cta'],
    ],
    'documentation' => [
        'label' => 'Documentation', 'description' => 'A readable long-form structure for an overview, instructions, examples and further help.', 'icon' => 'book-open-check', 'group' => 'Information',
        'sections' => ['text', 'image-text', 'text', 'cta'],
    ],
    'event' => [
        'label' => 'Event', 'description' => 'Introduce an event, publish key details, add visual context and collect registrations.', 'icon' => 'calendar-heart', 'group' => 'Information',
        'sections' => ['hero', 'image-text', 'gallery', 'contact-form', 'cta'],
    ],
    'faq' => [
        'label' => 'FAQ', 'description' => 'A flexible question-and-answer page with room for grouped guidance and further contact.', 'icon' => 'circle-help', 'group' => 'Information',
        'sections' => ['text', 'text', 'text', 'contact-form', 'cta'],
    ],
];
