<?php

declare(strict_types=1);

$layouts = json_decode((string) file_get_contents(__DIR__ . '/page-builder-layouts.json'), true, 512, JSON_THROW_ON_ERROR);

$definitions = array_replace([
    'hero' => [
        'label' => 'Hero', 'description' => 'A high-impact page opening with an image or video.', 'icon' => 'monitor-play', 'group' => 'SenseCMS essentials', 'singleton' => true,
        'fields' => [
            ['key'=>'media_type','type'=>'select','label'=>'Media type','options'=>['image'=>'Image','video'=>'Video'],'wide'=>true],
            ['key'=>'image','type'=>'image','label'=>'Hero image','wide'=>true,'show_when'=>['media_type'=>'image']], ['key'=>'mobile_image','type'=>'image','label'=>'Mobile image override','wide'=>true,'show_when'=>['media_type'=>'image']],
            ['key'=>'video','type'=>'video','label'=>'Hero video','wide'=>true,'show_when'=>['media_type'=>'video']], ['key'=>'poster','type'=>'image','label'=>'Video poster / fallback','wide'=>true,'show_when'=>['media_type'=>'video']],
            ['key'=>'focus_x','type'=>'select','label'=>'Horizontal focal point','options'=>['center'=>'Center','left'=>'Left','right'=>'Right']], ['key'=>'focus_y','type'=>'select','label'=>'Vertical focal point','options'=>['center'=>'Center','top'=>'Top','bottom'=>'Bottom']],
            ['key'=>'image_alt','type'=>'text','label'=>'Alternative text','max'=>240,'wide'=>true,'show_when'=>['media_type'=>'image']], ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow','max'=>180],
            ['key'=>'title','type'=>'text','label'=>'Headline','required'=>true,'max'=>240,'wide'=>true], ['key'=>'text','type'=>'textarea','label'=>'Introduction','max'=>900,'wide'=>true],
            ['key'=>'primary_label','type'=>'text','label'=>'Primary action label','max'=>100], ['key'=>'primary_url','type'=>'url','label'=>'Primary action URL'],
            ['key'=>'secondary_label','type'=>'text','label'=>'Secondary action label','max'=>100], ['key'=>'secondary_url','type'=>'url','label'=>'Secondary action URL'],
        ],
        'defaults' => ['media_type'=>'image','image'=>'/theme-assets/sensecms/images/sensecms-hero.webp','mobile_image'=>'','video'=>'','poster'=>'/theme-assets/sensecms/images/sensecms-hero.webp','focus_x'=>'center','focus_y'=>'center','image_alt'=>'Our school community','eyebrow'=>'Our learning community','title'=>'A confident start for every learner.','text'=>'A welcoming trilingual school community where children grow with curiosity, character and purpose.','primary_label'=>'Discover our school','primary_url'=>'#programs','secondary_label'=>'Book a visit','secondary_url'=>'#contact'],
    ],
    'hero-slider' => [
        'label' => 'Hero Slider', 'description' => 'A multilingual image and video sequence with accessible controls.', 'icon' => 'gallery-horizontal-end', 'group' => 'SenseCMS essentials', 'singleton' => true,
        'shared_fields' => [
            ['key'=>'autoplay','type'=>'checkbox','label'=>'Autoplay'], ['key'=>'interval','type'=>'number','label'=>'Slide duration (seconds)','min'=>3,'max'=>15],
            ['key'=>'transition','type'=>'select','label'=>'Transition','options'=>['fade'=>'Fade','slide'=>'Slide']], ['key'=>'height','type'=>'select','label'=>'Height','options'=>['full'=>'Full viewport','large'=>'Large','medium'=>'Medium']],
            ['key'=>'arrows','type'=>'checkbox','label'=>'Show arrows'], ['key'=>'dots','type'=>'checkbox','label'=>'Show pagination'], ['key'=>'loop','type'=>'checkbox','label'=>'Loop slides'], ['key'=>'pause_hover','type'=>'checkbox','label'=>'Pause on hover'],
        ],
        'shared_defaults' => ['autoplay'=>true,'interval'=>6,'transition'=>'fade','height'=>'full','arrows'=>true,'dots'=>true,'loop'=>true,'pause_hover'=>true],
        'fields' => [[
            'key'=>'slides','type'=>'repeater','label'=>'Slides','item_label'=>'Slide','min'=>1,'max'=>10,'fields'=>[
                ['key'=>'media_type','type'=>'select','label'=>'Media type','options'=>['image'=>'Image','video'=>'Video'],'wide'=>true], ['key'=>'image','type'=>'image','label'=>'Image','wide'=>true,'show_when'=>['media_type'=>'image']],
                ['key'=>'mobile_image','type'=>'image','label'=>'Mobile image override','wide'=>true,'show_when'=>['media_type'=>'image']], ['key'=>'video','type'=>'video','label'=>'Video','wide'=>true,'show_when'=>['media_type'=>'video']], ['key'=>'poster','type'=>'image','label'=>'Video poster','wide'=>true,'show_when'=>['media_type'=>'video']],
                ['key'=>'focus_x','type'=>'select','label'=>'Horizontal focal point','options'=>['center'=>'Center','left'=>'Left','right'=>'Right']], ['key'=>'focus_y','type'=>'select','label'=>'Vertical focal point','options'=>['center'=>'Center','top'=>'Top','bottom'=>'Bottom']],
                ['key'=>'alt','type'=>'text','label'=>'Alternative text','max'=>240,'wide'=>true,'show_when'=>['media_type'=>'image']], ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow','max'=>180], ['key'=>'title','type'=>'text','label'=>'Headline','required'=>true,'max'=>240,'wide'=>true],
                ['key'=>'text','type'=>'textarea','label'=>'Introduction','max'=>900,'wide'=>true], ['key'=>'primary_label','type'=>'text','label'=>'Primary action label','max'=>100], ['key'=>'primary_url','type'=>'url','label'=>'Primary action URL'],
                ['key'=>'secondary_label','type'=>'text','label'=>'Secondary action label','max'=>100], ['key'=>'secondary_url','type'=>'url','label'=>'Secondary action URL'],
            ],
        ]],
        'defaults' => ['slides'=>[['media_type'=>'image','image'=>'/theme-assets/sensecms/images/sensecms-hero.webp','mobile_image'=>'','video'=>'','poster'=>'','focus_x'=>'center','focus_y'=>'center','alt'=>'Our school community','eyebrow'=>'Our learning community','title'=>'A confident start for every learner.','text'=>'A welcoming trilingual school community where children grow with curiosity, character and purpose.','primary_label'=>'Discover our school','primary_url'=>'#programs','secondary_label'=>'Book a visit','secondary_url'=>'#contact']]],
    ],
    'gallery' => [
        'label'=>'Gallery','description'=>'A responsive image gallery for stories, facilities and school life.','icon'=>'images','group'=>'SenseCMS essentials',
        'fields'=>[
            ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow','max'=>180], ['key'=>'title','type'=>'text','label'=>'Headline','required'=>true,'max'=>240,'wide'=>true], ['key'=>'text','type'=>'textarea','label'=>'Introduction','max'=>900,'wide'=>true],
            ['key'=>'items','type'=>'repeater','label'=>'Gallery images','item_label'=>'Image','min'=>1,'max'=>10,'fields'=>[['key'=>'image','type'=>'image','label'=>'Image','required'=>true,'wide'=>true],['key'=>'focus_x','type'=>'select','label'=>'Horizontal focal point','options'=>['center'=>'Center','left'=>'Left','right'=>'Right']],['key'=>'focus_y','type'=>'select','label'=>'Vertical focal point','options'=>['center'=>'Center','top'=>'Top','bottom'=>'Bottom']],['key'=>'alt','type'=>'text','label'=>'Alternative text','max'=>240,'wide'=>true],['key'=>'eyebrow','type'=>'text','label'=>'Caption eyebrow','max'=>100],['key'=>'title','type'=>'text','label'=>'Caption title','max'=>160]]],
        ],
        'defaults'=>['eyebrow'=>'Life at our school','title'=>'Learning is active, shared and full of possibility.','text'=>'From discovery to teamwork, every day invites children to move, make friends and find confidence.','items'=>[
            ['image'=>'/theme-assets/sensecms/images/sensecms-studio.webp','focus_x'=>'center','focus_y'=>'center','alt'=>'Students learning through play','eyebrow'=>'School life','title'=>'Joy in every day'],
            ['image'=>'/theme-assets/sensecms/images/sensecms-facility.webp','focus_x'=>'center','focus_y'=>'center','alt'=>'Students taking part in an outdoor activity','eyebrow'=>'Discovery','title'=>'Time to explore'],
            ['image'=>'/theme-assets/sensecms/images/sensecms-hero.webp','focus_x'=>'center','focus_y'=>'center','alt'=>'Our school learning community','eyebrow'=>'Community','title'=>'Better together'],
            ['image'=>'/theme-assets/sensecms/images/sensecms-movement.webp','focus_x'=>'center','focus_y'=>'center','alt'=>'Students practising balance and movement outdoors','eyebrow'=>'Movement','title'=>'Energy and balance'],
            ['image'=>'/theme-assets/sensecms/images/sensecms-creative-learning.webp','focus_x'=>'center','focus_y'=>'center','alt'=>'Students and a teacher creating a project together','eyebrow'=>'Creativity','title'=>'Ideas come to life'],
        ]],
    ],
    'admissions' => [
        'label'=>'Admissions','description'=>'A step-by-step admissions journey with a primary action.','icon'=>'route','group'=>'SenseCMS essentials','singleton'=>true,
        'fields'=>[
            ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow','max'=>180], ['key'=>'title','type'=>'text','label'=>'Headline','required'=>true,'max'=>240,'wide'=>true], ['key'=>'text','type'=>'textarea','label'=>'Introduction','max'=>1000,'wide'=>true],
            ['key'=>'steps','type'=>'repeater','label'=>'Admissions steps','item_label'=>'Step','min'=>1,'max'=>6,'fields'=>[['key'=>'title','type'=>'text','label'=>'Title','required'=>true,'max'=>120],['key'=>'text','type'=>'textarea','label'=>'Description','max'=>700,'wide'=>true],['key'=>'label','type'=>'text','label'=>'Action label','max'=>80],['key'=>'url','type'=>'url','label'=>'Action URL']]],
            ['key'=>'cta_label','type'=>'text','label'=>'Primary action label','max'=>100], ['key'=>'cta_url','type'=>'url','label'=>'Primary action URL'],
        ],
        'defaults'=>['eyebrow'=>'Admissions at our school','title'=>'A clear path to your child’s next chapter.','text'=>'Start with a conversation, experience the facility, then join a community where every learner is known.','steps'=>[['title'=>'Discover','text'=>'Explore our learning approach and the life of our school community.','label'=>'Take this step','url'=>'#programs'],['title'=>'Visit','text'=>'Meet our team, see the facility and ask the questions that matter to your family.','label'=>'Take this step','url'=>'#contact'],['title'=>'Join','text'=>'Take your next step with personalised guidance from our admissions team.','label'=>'Take this step','url'=>'#contact']],'cta_label'=>'Talk to admissions','cta_url'=>'#contact'],
    ],
    'contact-form' => [
        'label'=>'Contact Form','description'=>'A secure AJAX form with inbox storage, email notification and CAPTCHA.','icon'=>'mail-check','group'=>'Forms','singleton'=>false,
        'shared_fields'=>[
            ['key'=>'recipient_email','type'=>'email','label'=>'Notification recipient','max'=>190,'wide'=>true], ['key'=>'store_submissions','type'=>'checkbox','label'=>'Store submissions in SenseCMS'],
            ['key'=>'email_notifications','type'=>'checkbox','label'=>'Send email notifications'], ['key'=>'sender_copy','type'=>'checkbox','label'=>'Send a branded copy to the sender (email field; stored submissions only)'], ['key'=>'captcha','type'=>'checkbox','label'=>'Require CAPTCHA'], ['key'=>'rate_limit','type'=>'number','label'=>'Submissions per 15 minutes','min'=>1,'max'=>20],
        ],
        'shared_defaults'=>['recipient_email'=>'','store_submissions'=>true,'email_notifications'=>true,'captcha'=>true,'rate_limit'=>5],
        'fields'=>[
            ['key'=>'eyebrow','type'=>'text','label'=>'Eyebrow','max'=>180], ['key'=>'title','type'=>'text','label'=>'Headline','required'=>true,'max'=>240,'wide'=>true], ['key'=>'text','type'=>'textarea','label'=>'Introduction','max'=>900,'wide'=>true],
            ['key'=>'fields','type'=>'repeater','label'=>'Form fields','item_label'=>'Field','min'=>1,'max'=>12,'fields'=>[
                ['key'=>'key','type'=>'text','label'=>'Field key','required'=>true,'max'=>40], ['key'=>'type','type'=>'select','label'=>'Field type','options'=>['text'=>'Text','email'=>'Email','tel'=>'Phone','textarea'=>'Long text','select'=>'Select','checkbox'=>'Checkbox / consent']],
                ['key'=>'label','type'=>'text','label'=>'Label','required'=>true,'max'=>140], ['key'=>'placeholder','type'=>'text','label'=>'Placeholder / consent text','max'=>180], ['key'=>'options','type'=>'textarea','label'=>'Options, one per line','max'=>1000,'wide'=>true,'show_when'=>['type'=>'select']], ['key'=>'required','type'=>'checkbox','label'=>'Required'],
            ]],
            ['key'=>'submit_label','type'=>'text','label'=>'Submit button','required'=>true,'max'=>80], ['key'=>'success_message','type'=>'text','label'=>'Success message','required'=>true,'max'=>240,'wide'=>true], ['key'=>'error_message','type'=>'text','label'=>'Error message','required'=>true,'max'=>240,'wide'=>true],
            ['key'=>'privacy_text','type'=>'textarea','label'=>'Privacy note','max'=>500,'wide'=>true],
        ],
        'defaults'=>['eyebrow'=>'Contact us','title'=>'How can we help?','text'=>'Send us a message and our team will respond as soon as possible.','fields'=>[['key'=>'name','type'=>'text','label'=>'Your name','placeholder'=>'Enter your name','options'=>'','required'=>true],['key'=>'email','type'=>'email','label'=>'Email address','placeholder'=>'name@example.com','options'=>'','required'=>true],['key'=>'phone','type'=>'tel','label'=>'Phone number','placeholder'=>'Optional','options'=>'','required'=>false],['key'=>'message','type'=>'textarea','label'=>'Your message','placeholder'=>'How can we help?','options'=>'','required'=>true]],'submit_label'=>'Send message','success_message'=>'Thank you. Your message has been sent.','error_message'=>'Your message could not be sent. Please check the form and try again.','privacy_text'=>'Your information is handled securely and used only to respond to your enquiry.'],
    ],
    'custom-html' => [
        'label'=>'Custom HTML','description'=>'Sanitized HTML for trusted editorial layouts without executable scripts.','icon'=>'code-xml','group'=>'Advanced content',
        'fields'=>[['key'=>'admin_label','type'=>'text','label'=>'Administrative label','max'=>120],['key'=>'html','type'=>'code','label'=>'HTML','required'=>true,'max'=>20000,'wide'=>true]],
        'defaults'=>['admin_label'=>'Custom content','html'=>'<section><h2>Add your content</h2><p>Use safe semantic HTML.</p></section>'],
    ],
], $layouts);

$localizedDefaults = require __DIR__ . '/page-builder-localized-defaults.php';
foreach ($localizedDefaults as $locale => $components) {
    foreach ($components as $type => $values) {
        if (isset($definitions[$type]) && is_array($values)) {
            $definitions[$type]['localized_defaults'][$locale] = $values;
        }
    }
}

return $definitions;
