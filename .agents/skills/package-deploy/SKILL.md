---
name: package-deploy
description: >-
  Prepares, validates, and builds production deployment archives (site.zip) for
  hosting on InfinityFree, cPanel, or external servers. Use this skill when
  the user asks to build the project, create a zip, prepare for deployment, or release changes.
---

# Package & Deploy Skill

This skill automates the pre-flight validation and generation of production deployment packages for the **Online Exam System**.

## Workflow Steps

### Step 1: Pre-Flight Linting & Packaging
Execute the packaging automation script from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File ".agents/skills/package-deploy/scripts/package.ps1"
```

This script:
1. Validates all `.php` files across the codebase with `php -l`. If any file fails syntax check, the build immediately halts.
2. Excludes development and version control directories (`.git`, `.agents`, `.gemini`, `.vscode`, temp logs).
3. Compresses the clean codebase into `site.zip` in the project root.

### Step 2: Verification Checklist
Verify the following before distributing the package:
- [ ] `site.zip` is generated and roughly 150–200 KB.
- [ ] Database credentials in `config/db.php` match the target hosting environment (e.g. `sqlXXX.infinityfree.com` or `localhost`).
- [ ] Ensure `.htaccess` or server URL rewrites match host configurations.

### Step 3: Deployment Instructions for User
When presenting the deployment package to the user, include these upload instructions:
1. Log in to your hosting Control Panel (cPanel / InfinityFree).
2. Open **File Manager** and navigate into `htdocs/` (or `public_html/`).
3. Upload `site.zip` and extract its contents directly into `htdocs/`.
4. Import `database.sql` into phpMyAdmin on your hosting server if not already created.
5. Update `config/db.php` with your remote DB name, username, and password.
