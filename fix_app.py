import sys

with open('assets/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace("apiUrl('api/session_info.php'", "appUrl('api/session_info.php'")
content = content.replace("apiUrl('api/session_bridge.php'", "appUrl('api/session_bridge.php'")
content = content.replace("apiUrl('api/session_clear.php'", "appUrl('api/session_clear.php'")
content = content.replace("apiUrl('api/coach.php'", "appUrl('api/coach.php'")
content = content.replace("apiUrl('api/pdf_quiz.php'", "appUrl('api/pdf_quiz.php'")

with open('assets/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)
