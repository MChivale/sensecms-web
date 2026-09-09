UPDATE content_blocks
SET source_theme='core'
WHERE type IN ('hero','hero-slider','story','values','programs','statistics','gallery','motion','admissions','news','cta','text','image-text','contact-form','custom-html')
  AND (source_theme IS NULL OR source_theme<>'core');
