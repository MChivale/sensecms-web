ALTER TABLE languages ADD COLUMN IF NOT EXISTS flag VARCHAR(255) NULL AFTER native_name;
UPDATE languages SET flag = '/sensecms/images/flags/gb.svg' WHERE locale = 'en' AND (flag IS NULL OR flag = '');
UPDATE languages SET flag = '/sensecms/images/flags/kh.svg' WHERE locale = 'km' AND (flag IS NULL OR flag = '');
UPDATE languages SET flag = '/sensecms/images/flags/cn.svg' WHERE locale = 'zh' AND (flag IS NULL OR flag = '');
