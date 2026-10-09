import sys

with open('assets/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace("const opts = { method, headers: { 'Accept': 'application/json' } };", "const opts = { method, headers: { 'Accept': 'application/json' }, credentials: 'include' };")

with open('assets/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)
