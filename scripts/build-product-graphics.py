"""Build the official theme's small explanatory diagrams and social cards.

Operator tool: Python + Pillow; pass a local TrueType font, never fetch assets.
Usage: python scripts/build-product-graphics.py --font C:/Windows/Fonts/arial.ttf
"""
import argparse
import html
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--font', required=True, type=Path)
args = parser.parse_args()
assets = Path(__file__).resolve().parents[1] / '.themes/sensecms/assets'
navy, blue, pale, muted = '#0b254b', '#1760dc', '#eef5ff', '#506686'
diagrams = {
    'architecture': {
        'en': ['ONE FOUNDATION', 'Sense CMS Core', 'Content · roles · public addresses', 'Themes', 'Presentation', 'Extensions', 'Tools & connections', 'Your public website'],
        'pl': ['WSPÓLNA PODSTAWA', 'Sense CMS Core', 'Treści · role · publiczne adresy', 'Motywy', 'Wygląd witryny', 'Rozszerzenia', 'Narzędzia i integracje', 'Twoja publiczna witryna'],
    },
    'knowledge': {
        'en': ['FROM SOURCE TO ANSWER', 'Approved sources', 'Published pages · reviewed knowledge', 'Local index', 'Find useful context', 'AI answer', 'Check source links', 'Missing context? Human support'],
        'pl': ['OD ŹRÓDŁA DO ODPOWIEDZI', 'Zatwierdzone źródła', 'Publiczne strony · sprawdzona wiedza', 'Lokalny indeks', 'Wyszukanie kontekstu', 'Odpowiedź AI', 'Sprawdzenie źródeł', 'Brak kontekstu? Pomoc człowieka'],
    },
    'locales': {
        'en': ['PLACE AND LANGUAGE', 'Shared workspace', 'Organisation-wide content & permissions', 'Facility A', 'English / Polski', 'Facility B', 'English / Polski', 'Review each location and language'],
        'pl': ['MIEJSCE I JĘZYK', 'Wspólny panel', 'Treści organizacji i uprawnienia', 'Placówka A', 'Polski / English', 'Placówka B', 'Polski / English', 'Sprawdź każdą placówkę i język'],
    },
}
for name, locales in diagrams.items():
    for locale, labels in locales.items():
        eyebrow, title, subtitle, left, left_sub, right, right_sub, footer = map(html.escape, labels)
        markup = f'''<svg xmlns="http://www.w3.org/2000/svg" width="480" height="440" viewBox="0 0 480 440" role="img" aria-labelledby="title desc" lang="{locale}">
<title id="title">{title}</title><desc id="desc">{subtitle}. {left}: {left_sub}. {right}: {right_sub}. {footer}.</desc>
<rect width="480" height="440" rx="24" fill="{pale}"/>
<g font-family="Arial, sans-serif" text-anchor="middle">
<text x="240" y="43" font-size="12" letter-spacing="1.5" fill="{blue}">{eyebrow}</text>
<rect x="32" y="68" width="416" height="108" rx="18" fill="{navy}"/>
<text x="240" y="112" font-size="25" font-weight="700" fill="white">{title}</text>
<text x="240" y="143" font-size="15" fill="#c4d9fa">{subtitle}</text>
<path d="M240 176V206M240 206H120V237M240 206H360V237" fill="none" stroke="#9abbe9" stroke-width="2"/>
<rect x="24" y="237" width="208" height="98" rx="15" fill="white" stroke="#cbdcf4"/>
<rect x="248" y="237" width="208" height="98" rx="15" fill="white" stroke="#cbdcf4"/>
<text x="128" y="278" font-size="21" font-weight="700" fill="{navy}">{left}</text>
<text x="128" y="307" font-size="14" fill="{muted}">{left_sub}</text>
<text x="352" y="278" font-size="21" font-weight="700" fill="{navy}">{right}</text>
<text x="352" y="307" font-size="14" fill="{muted}">{right_sub}</text>
<path d="M128 335V361H352V335M240 361V378" fill="none" stroke="#9abbe9" stroke-width="2"/>
<text x="240" y="405" font-size="16" fill="{blue}">{footer}</text>
</g></svg>'''
        if name == 'knowledge':
            # Make the pipeline sequential: sources -> index -> answer -> support.
            markup = markup.replace('M240 176V206M240 206H120V237M240 206H360V237', 'M240 176V206H128V237')
            markup = markup.replace('M128 335V361H352V335M240 361V378', 'M232 286H248M352 335V361H240V378')
        (assets / f'{name}-{locale}.svg').write_text(markup, encoding='utf-8')

cards = {
    'platform': {'en': ['Your website.', 'One workspace.', 'Content / Teams / Languages'], 'pl': ['Twoja strona.', 'Jeden panel.', 'Treści / Zespoły / Języki']},
    'ai': {'en': ['Useful knowledge.', 'Reviewed answers.', 'Sources / Context / People'], 'pl': ['Przydatna wiedza.', 'Sprawdzane odpowiedzi.', 'Źródła / Kontekst / Ludzie']},
    'extensions': {'en': ['A focused Core.', 'Room to extend.', 'Themes / Plugins / Addons / Modules'], 'pl': ['Solidny rdzeń.', 'Miejsce na rozwój.', 'Motywy / Wtyczki / Dodatki / Moduły']},
    'docs': {'en': ['Start with clarity.', 'Build with confidence.', 'Installation / Licensing / Development'], 'pl': ['Zacznij świadomie.', 'Twórz z pewnością.', 'Instalacja / Licencje / Rozwój']},
    'releases': {'en': ['Know your version.', 'Plan your next step.', 'Release status / Preparation / Recovery'], 'pl': ['Poznaj swoją wersję.', 'Zaplanuj kolejny krok.', 'Status wydań / Przygotowanie / Odzyskiwanie']},
}
for name, locales in cards.items():
    for locale, (first, second, caption) in locales.items():
        image = Image.new('RGB', (1200, 630), navy)
        draw = ImageDraw.Draw(image)
        font = lambda size: ImageFont.truetype(str(args.font), size)
        draw.rounded_rectangle((830, -120, 1310, 780), radius=150, fill='#123766')
        draw.text((70, 58), 'Sense CMS', font=font(32), fill='white')
        draw.line((70, 122, 1130, 122), fill='#315279', width=1)
        for y, text, color in [(215, first, 'white'), (300, second, '#a9ceff')]:
            size = 58
            while draw.textlength(text, font=font(size)) > 980:
                size -= 1
            draw.text((70, y), text, font=font(size), fill=color)
        draw.text((73, 430), caption, font=font(25), fill='#c4d9fa')
        draw.text((73, 550), 'sensecms.com', font=font(22), fill='white')
        draw.text((1080, 550), locale.upper(), font=font(22), fill='#a9ceff')
        image.save(assets / f'share-{name}-{locale}.png', optimize=True)
print('Built six localized SVG diagrams and ten 1200 × 630 social cards.')
