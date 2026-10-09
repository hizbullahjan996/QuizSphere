import sys
import re

with open('assets/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

content = re.sub(r"fetch\(apiUrl\('api/([^']+)'\,", r"fetch(apiUrl('api/\1'),", content)

with open('assets/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)
