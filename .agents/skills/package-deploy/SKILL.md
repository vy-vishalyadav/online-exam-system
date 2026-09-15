---
name: package-deploy
description: >-
  Prepares, validates, and builds production deployment archives (zip.zip / site.zip) for
  hosting on InfinityFree, cPanel, or external servers. Use this skill when
  the user asks to build the project, create a zip, prepare for deployment, or release changes.
---

# Package & Deploy Skill

This skill automates the pre-flight validation and generation of production deployment packages (`zip.zip`) for the **Online Exam System**.

## Workflow Steps

### Step 1: Pre-Flight Linting & Packaging
Execute the packaging automation script from the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File ".agents/skills/package-deploy/scripts/package.ps1"
```

This script:
1. Validates all 22 `.php` files across the codebase with `php -l`. If any file fails syntax check, the build immediately halts.
2. Collects **strictly essential runtime files** required for the website to run:
   - `admin/` (10 files)
   - `student/` (7 files)
   - `includes/` (2 files)
   - `config/` (1 file)
   - `css/` (1 file)
   - `sql/` (1 file)
   - `index.php`, `logout.php`, `version.txt` (3 files)
3. Excludes all development artifacts (`.git/`, `.agents/`, `AGENTS.md`, scratch scripts, markdown notes).
4. Generates a clean `zip.zip` (and copies to `site.zip`) with standard forward slashes for full Linux/cPanel compatibility.

### Step 2: Verification Checklist
Verify the following before distributing the package:
- [ ] `zip.zip` is generated and contains exactly 25 essential files (~86 KB).
- [ ] Database credentials in `config/db.php` match the target hosting environment (e.g. `sqlXXX.infinityfree.com` or `localhost`).
- [ ] Ensure `.htaccess` or server URL rewrites match host configurations.

### Step 3: Deployment Instructions for User
When presenting the deployment package to the user, include these upload instructions:
1. Log in to your hosting Control Panel (cPanel / InfinityFree).
2. Open **File Manager** and navigate into `htdocs/` (or `public_html/`).
3. Upload `zip.zip` and extract its contents directly into `htdocs/`.
4. Import `sql/setup.sql` into phpMyAdmin on your hosting server if not already created.
5. Update `config/db.php` with your remote DB name, username, and password.

