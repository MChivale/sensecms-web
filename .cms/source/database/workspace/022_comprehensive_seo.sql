INSERT INTO settings (`key`,value,updated_at) VALUES (
    'seo_global',
    JSON_OBJECT(
        'site_name','Sense CMS','alternate_name','','site_url','','organization_type','Organization',
        'organization_description','','organization_logo','','default_social_image','',
        'default_social_image_alt','','default_image_width',1200,'default_image_height',630,'default_image_type','image/webp',
        'author','','publisher','','twitter_site','','twitter_creator','','facebook_app_id','',
        'facebook_url','','instagram_url','','linkedin_url','','youtube_url','','country_code','',
        'google_site_verification','','bing_site_verification','','yandex_verification','','pinterest_domain_verify','',
        'robots','index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1',
        'localized',JSON_OBJECT()
    ),
    NOW()
)
ON DUPLICATE KEY UPDATE value=JSON_MERGE_PATCH(VALUES(value),value),updated_at=NOW();
