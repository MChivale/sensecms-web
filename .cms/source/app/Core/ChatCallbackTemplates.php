<?php
declare(strict_types=1);

namespace App\Core;

final class ChatCallbackTemplates
{
    public const SETTING='email_system_templates';
    public const LIMITS=['subject'=>180,'heading'=>180,'body'=>5000,'button_label'=>80,'footer'=>500,'chat'=>1000,'team_subject'=>180,'team_body'=>5000];

    public static function defaults(): array
    {
        $copy=[
            'pl'=>['Dziękujemy za rozmowę',"Dzień dobry,\n\nDziękujemy za kontakt z zespołem {{site_name}}. Otrzymaliśmy Twoją prośbę. Osoba z naszego zespołu zapozna się z Twoją sprawą i odpowie na ten adres e-mail.\n\nPozdrawiamy,\nZespół {{site_name}}",'Odwiedź naszą stronę','Potwierdzenie prośby o kontakt pozostawionej na czacie.', 'Dziękujemy za rozmowę. Zapisaliśmy Twój adres e-mail. Zespół {{site_name}} skontaktuje się z Tobą po zapoznaniu się ze sprawą.'],
            'zh'=>['感谢您的咨询',"您好！\n\n感谢您联系 {{site_name}}。我们已收到您的请求。团队成员会了解您的情况，并通过此电子邮件地址与您联系。\n\n{{site_name}} 团队",'访问我们的网站','这封邮件确认我们已收到您在聊天中留下的联系请求。', '感谢您的咨询。我们已收到您的电子邮箱。{{site_name}} 团队会在了解您的情况后与您联系。'],
            'km'=>['អរគុណសម្រាប់ការសន្ទនា',"សួស្តី!\n\nអរគុណដែលបានទាក់ទងក្រុមការងារ {{site_name}}។ យើងបានទទួលសំណើរបស់អ្នកហើយ។ សមាជិកក្រុមការងារនឹងពិនិត្យសំណើរបស់អ្នក ហើយទាក់ទងអ្នកតាមអាសយដ្ឋានអ៊ីមែលនេះ។\n\nក្រុមការងារ {{site_name}}",'ចូលមើលគេហទំព័ររបស់យើង','អ៊ីមែលនេះបញ្ជាក់ថាយើងបានទទួលសំណើសុំទំនាក់ទំនងតាមការជជែក។', 'អរគុណសម្រាប់ការសន្ទនា។ យើងបានទទួលអាសយដ្ឋានអ៊ីមែលរបស់អ្នក។ ក្រុមការងារ {{site_name}} នឹងទាក់ទងអ្នកបន្ទាប់ពីពិនិត្យសំណើរបស់អ្នក។'],
            'en'=>['Thank you for chatting with us',"Hello,\n\nThank you for getting in touch with {{site_name}}. We received your request. Someone on our team will review it and get back to you at this email address.\n\nKind regards,\nThe {{site_name}} team",'Visit our website','Confirmation of the contact request you made in our chat.', 'Thank you for chatting with us. We received your email address. The team at {{site_name}} will review your request and get back to you.'],
        ];
        $templates=[];
        foreach ($copy as $locale=>[$heading,$body,$button,$footer,$chat]) {
            [$teamSubject,$teamBody]=match ($locale) {
                'pl'=>['Prośba o kontakt z czatu — {{site_name}}','Klient zostawił adres e-mail w rozmowie z chatbotem. Otwórz rozmowę w panelu, aby odczytać zgłoszenie i odpowiedzieć klientowi.'],
                'zh'=>['聊天回电请求 — {{site_name}}','访客在聊天中留下了电子邮箱。请在工作区打开对话，查看请求并与访客联系。'],
                'km'=>['សំណើសុំទំនាក់ទំនងពីការជជែក — {{site_name}}','ភ្ញៀវបានទុកអាសយដ្ឋានអ៊ីមែលក្នុងការជជែក។ សូមបើកការសន្ទនាក្នុងផ្ទាំងគ្រប់គ្រង ដើម្បីពិនិត្យសំណើ និងទាក់ទងភ្ញៀវ។'],
                default=>['Chat callback request — {{site_name}}','A visitor left an email address in the chatbot. Open the conversation in your workspace to review the request and contact them.'],
            };
            $templates['chat_callback_'.$locale]=['subject'=>$heading.' — {{site_name}}','heading'=>$heading,'body'=>$body,'button_label'=>$button,'footer'=>$footer,'chat'=>$chat,'team_subject'=>$teamSubject,'team_body'=>$teamBody];
        }
        return $templates;
    }

    public static function render(?CmsRepository $cms,string $locale): array
    {
        $defaults=self::defaults();
        $key='chat_callback_'.strtolower(explode('-',$locale)[0]);
        if (!isset($defaults[$key])) $key='chat_callback_en';
        $stored=(array)$cms?->setting(self::SETTING,[]);
        $fields=array_replace($defaults[$key],array_intersect_key((array)($stored[$key]??[]),$defaults[$key]));
        $brand=trim((string)$cms?->setting('site_name','')) ?: (trim((string)(((array)$cms?->setting('seo_global',[]))['site_name']??'')) ?: 'Sense CMS');
        return array_map(static fn(string $text):string=>strtr($text,['{{site_name}}'=>$brand]),$fields)+['brand'=>$brand];
    }
}
