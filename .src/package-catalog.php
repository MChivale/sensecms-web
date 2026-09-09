<?php
declare(strict_types=1);

// Official-site inventory, not part of portable Core or a customer theme.
// Free/paid tiers and USD/year amounts checked against live eduvixo.com/marketplace/.
return [
    ['type'=>'system','slug'=>'sensecms','name'=>'Sense CMS Core','usd_year'=>360,'status'=>'development','description'=>'The general-purpose content management system: facilities, publishing, permissions and a license-first web installer.'],
    ['type'=>'theme','slug'=>'sensecms','name'=>'Sense CMS Theme','usd_year'=>0,'status'=>'packaged','version'=>'0.3.8','description'=>'The official product theme with a consistent navy hero, responsive layouts and CMS-managed pages.'],
    ['type'=>'theme','slug'=>'shoudu','name'=>'Shoudu Custom Theme','usd_year'=>0,'status'=>'adaptation','description'=>'A custom public presentation theme. A separate Sense CMS-compatible package still requires adaptation and verification.'],
    ['type'=>'plugin','slug'=>'ifirewall','name'=>'iFirewall','usd_year'=>120,'status'=>'adaptation','description'=>'IP firewall and security monitoring for PHP applications, including IPv4, IPv6 and CIDR rules.'],
    ['type'=>'addon','slug'=>'calendar','name'=>'Sense CMS Calendar','usd_year'=>120,'status'=>'packaged','version'=>'0.1.0','description'=>'Facility scheduling, custom event categories, recurring events, resources and conflict prevention.'],
    ['type'=>'plugin','slug'=>'google-calendar','name'=>'Google Calendar','usd_year'=>12,'status'=>'packaged','version'=>'0.1.0','requires'=>'Sense CMS Calendar','description'=>'Outbound event delivery from Sense CMS Calendar to a writable Google calendar.'],
    ['type'=>'plugin','slug'=>'apple-calendar','name'=>'Apple Calendar','usd_year'=>12,'status'=>'packaged','version'=>'0.1.0','requires'=>'Sense CMS Calendar','description'=>'Outbound CalDAV event delivery from Sense CMS Calendar to a private writable Apple iCloud calendar.'],
    ['type'=>'plugin','slug'=>'microsoft-365-calendar','name'=>'Microsoft 365 Calendar','usd_year'=>12,'status'=>'packaged','version'=>'0.1.0','requires'=>'Sense CMS Calendar','description'=>'Outbound event delivery from Sense CMS Calendar through Microsoft Graph.'],
    ['type'=>'plugin','slug'=>'telegram-notifications','name'=>'Telegram Notifications','usd_year'=>48,'status'=>'packaged','version'=>'0.1.1','description'=>'Deliver authorised system notifications to Telegram. The calendar addon is optional.'],
    ['type'=>'plugin','slug'=>'whatsapp-notifications','name'=>'WhatsApp Notifications','usd_year'=>48,'status'=>'adaptation','description'=>'Deliver system notifications to WhatsApp, including forms, workflow and optional calendar alerts.'],
    ['type'=>'plugin','slug'=>'google-analytics','name'=>'Google Analytics','usd_year'=>0,'status'=>'packaged','version'=>'0.1.1','description'=>'Consent-based analytics for published CMS pages with Measurement ID configuration in the Sense CMS panel.'],
    ['type'=>'plugin','slug'=>'ai-translation-assistant','name'=>'AI Translation Assistant','usd_year'=>0,'status'=>'adaptation','description'=>'Assist multilingual content editing while preserving HTML and placeholders. Human review remains required before publication.'],
    ['type'=>'application','slug'=>'windows','name'=>'Desktop Client for Windows','usd_year'=>0,'status'=>'adaptation','description'=>'Desktop access to an online CMS workspace. A Sense CMS-specific Windows release is not available yet.'],
];
