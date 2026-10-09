import sys
import re

with open('assets/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace("credentials: 'same-origin'", "credentials: 'include'")

with open('assets/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)
