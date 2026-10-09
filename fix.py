import sys

with open('assets/js/app.js', 'r', encoding='utf-8') as f:
    content = f.read()

# Replace local base assignments that prepend API calls (except inside appUrl/apiUrl)
content = content.replace("const base = (window.APP_URL || '/').replace(/\\/+$/, '');", "")

# Replace fetch(base + '/api/...) with fetch(apiUrl('api/...))
content = content.replace("base + '/api/", "apiUrl('api/")

# Replace appUrl('api/...) with apiUrl('api/...)
content = content.replace("appUrl('api/", "apiUrl('api/")
content = content.replace('appUrl("api/', 'apiUrl("api/')

# Replace the specific coach fallback URL
content = content.replace("const phpUrl = (window.APP_URL || '/').replace(/\\/+$/, '') + '/api/", "const phpUrl = apiUrl('api/")

# Fix apiFetch
api_fetch_old = """    const apiFetch = async (endpoint, method = 'GET', body = null) => {
      const url = base + '/' + endpoint.replace(/^\\/+/, '');"""
api_fetch_new = """    const apiFetch = async (endpoint, method = 'GET', body = null) => {
      const url = apiUrl(endpoint);"""
content = content.replace(api_fetch_old, api_fetch_new)

with open('assets/js/app.js', 'w', encoding='utf-8') as f:
    f.write(content)
