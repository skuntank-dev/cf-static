# Cloudflare Access-Friendly WordPress Static Page Generator (cf-static)

A WordPress plugin that generates a fully static version of your site — even when protected by **Cloudflare Access** — and optionally deploys it directly to **Cloudflare Pages** using Wrangler.

Built for developers who want:
- Static exports of Access-protected WordPress sites
- Easy deployment to Cloudflare Pages

Perfect for private staging WordPress websites that are locked behind Cloudflare Access!

---

## Features

- 🔐 **Cloudflare Access support**  
  Authenticates using CF Access service tokens (Client ID + Secret) to bypass Access during static generation.

- 🧱 **Static site generation**  
  Crawls your WordPress frontend and exports clean HTML files.

- 📦 **Asset crawling**
  - Downloads theme, plugin, and upload assets
  - Handles `src`, `href`, and `srcset`
  - Copies required WordPress core JS (jQuery, etc.)

- 🧩 **Plugin JavaScript selection**
  - Choose which active plugins’ JS should be included
  - Automatically excludes admin-only JS

- 🚫 **Admin JS sanitization**
  - Removes admin-specific JavaScript from the final static build

- ❌ **Optional 404.html generation**
  - Generates a static `404.html` using your theme’s 404 template

- ☁️ **Cloudflare Pages deployment**
  - Deploy directly via Wrangler CLI
  - Supports production and preview branches

- 📦 **Downloadable ZIP**
  - Automatically generates a ZIP archive of the static site

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- cURL enabled
- ZipArchive enabled
- **Wrangler CLI** (required only for Cloudflare Pages deployment)

Wrangler installation guide:  
https://developers.cloudflare.com/workers/cli-wrangler/install-update/

---

## Installation

1. [Download the latest release's ZIP file](https://github.com/skuntank-dev/cf-static/releases/latest)
2. Upload it via **WordPress Admin → Plugins → Add Plugin → Upload Plugin**
3. Activate the plugin

---

## Cloudflare Access Setup (Optional)

If your site is protected by **Cloudflare Access**:

1. Create a **Service Token** in Cloudflare Zero Trust
2. Copy the:
- Client ID
- Client Secret
3. Paste both values into the plugin settings

⚠️ **Important:**  
Static generation will fail if **only one** of the two fields is filled.  
Either provide **both**, or leave **both empty**.

---

## Usage

### Generate Static Site

1. Open **CF Static Generator** in WordPress admin
2. (Optional) Enter CF Access credentials
3. Select plugins whose JS should be included
4. (Optional) Enable `Generate 404.html`
5. Click **Generate Static Site**

After generation:
- A ZIP file will be created
- A download button will appear
- The static site will be stored internally in `/static`

---

### Deploy to Cloudflare Pages

> Requires Wrangler CLI installed and accessible on the server

1. Fill in:
- Project Name
- Branch (`main` for production)
- Cloudflare Account ID
- API Token (Pages Edit permission required)
2. Click **Deploy to Cloudflare Pages**

Credentials can be optionally remembered (not recommended on shared servers).

---

## Security Notes

- CF Access tokens and API tokens are stored **only if explicitly allowed**
- Tokens are never embedded into the static output
- Admin JavaScript files are removed from the build
- Deployment uses environment variables for Wrangler

---

## Author

Developed by **skuntank.dev**  

🌐 https://skuntank.dev

📧 skuntank@skuntank.dev

---

## License

GPL-2.0 license
