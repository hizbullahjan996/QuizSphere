import sys

with open('assets/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace("const opts = { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd };", "const opts = { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd, credentials: 'include' };")

with open('assets/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)
