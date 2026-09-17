"""Draw the site's share card with local geometry and fonts; no external images."""
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[1]
image = Image.new('RGB', (1200, 630), '#f3f4fc')
draw = ImageDraw.Draw(image)
font_path = Path('C:/Windows/Fonts/msyh.ttc')
if not font_path.exists():
    font_path = Path('/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc')
title = ImageFont.truetype(str(font_path), 74)
body = ImageFont.truetype(str(font_path), 32)
small = ImageFont.truetype(str(font_path), 24)
draw.rounded_rectangle((72, 80, 202, 210), radius=34, fill='#6357ec')
for y, length in [(118, 58), (145, 58), (172, 38)]:
    draw.line((105, y, 105 + length, y), fill='white', width=8)
draw.ellipse((156, 166, 168, 178), fill='#93e9f3')
draw.text((238, 97), '数享社区', font=title, fill='#20243b')
draw.text((78, 279), '发现好软件，分享真实使用经验', font=body, fill='#3a4260')
draw.text((78, 341), '核对来源 · 查看版本 · 交流反馈', font=body, fill='#65708a')
draw.line((78, 456, 1120, 456), fill='#d3d7e8', width=2)
draw.text((78, 503), 'forum.example.com', font=body, fill='#6357ec')
draw.text((759, 510), '软件资源与使用交流', font=small, fill='#65708a')
image.save(root / 'app/public/assets/share.png', optimize=True)
print('Share card generated: 1200 x 630')
