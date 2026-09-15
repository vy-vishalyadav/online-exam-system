# Online Exam System — Workspace AI Guidelines & Rules

Welcome! This repository is a full-featured, secure **Online Examination System** built with **PHP 8 & MySQL (XAMPP)**, **Bootstrap 5**, and custom browser-based proctoring/anti-cheat mechanisms.

Any AI assistant or developer working on this codebase must strictly adhere to the architecture, security standards, and frontend contracts outlined below.

---

## 1. Core Architecture & Tech Stack

- **Backend**: Native PHP 8.x (procedural + prepared statements via `mysqli`).
- **Database**: MySQL / MariaDB via `config/db.php`.
- **Frontend**: Bootstrap 5.3.2, Bootstrap Icons 1.11.1, KaTeX 0.16.10 (math formulas), custom CSS in `css/style.css`.
- **Primary Roles**:
  - `student/`: Student exam portal, live exam room (`exam.php`), countdown timers, question cards, results.
  - `admin/`: Exam management, class management, question banks, student rosters, violation audit trail (`violations.php`).

---

## 2. Mandatory Security & Database Guidelines

### A. SQL Injection Prevention
- **Always** use prepared statements (`mysqli_prepare`, `mysqli_stmt_bind_param`, `mysqli_stmt_execute`, `mysqli_stmt_get_result`) for any query involving user input or session data.
- **Never** concatenate dynamic variables into SQL query strings.

### B. Cross-Site Scripting (XSS)
- Sanitize all database output rendered in HTML using `htmlspecialchars($data, ENT_QUOTES, 'UTF-8')`.

### C. Authentication & Session Access Control
- Every admin page must begin with:
  ```php
  session_start();
  if (!isset($_SESSION['admin_id'])) {
      header("Location: ../index.php");
      exit;
  }
  ```
- Every student page must begin with:
  ```php
  session_start();
  if (!isset($_SESSION['student_id'])) {
      header("Location: ../index.php");
      exit;
  }
  ```
- Ensure sensitive authenticated pages send anti-cache headers (`Cache-Control: no-store, no-cache, must-revalidate`).

---

## 3. Frontend & Bootstrap Rules (DO NOT BREAK)

### A. Single Bootstrap Bundle Loading
- `bootstrap.bundle.min.js` is loaded **strictly once** in `<head>` inside `includes/header.php`.
- **NEVER** include `bootstrap.bundle.min.js` in `includes/footer.php` or within individual page templates.
- *Reason*: Loading Bootstrap twice registers duplicate click listeners on `document`, causing dropdown buttons (e.g. Student Profile) and collapse menus to toggle open and immediately close in the same click event.

### B. Dropdown Carets & Icons
- When creating dropdown buttons that already feature an icon (e.g., `<i class="bi bi-chevron-down"></i>`), add the classes `dropdown-toggle no-caret`.
- The rule `.dropdown-toggle.no-caret::after { display: none !important; }` in `css/style.css` suppresses Bootstrap's default triangle to prevent duplicate arrows.

---

## 4. Online Exam Integrity & Anti-Cheat Invariants

The exam room (`student/exam.php`) enforces browser-level proctoring. Preserve the following invariants:

1. **Strict Schedule-Based Timers**:
   - Exams follow a global schedule (`scheduled_end_timestamp`).
   - If a student resumes an exam mid-way, their timer displays the exact time remaining until the scheduled end. Retakes must not grant fresh elapsed time.

2. **Clipboard & OS-Level Paste Blocking**:
   - Do NOT rely solely on the `paste` event.
   - We capture the W3C Level 2 `beforeinput` event (`inputType === 'insertFromPaste'` or `insertFromDrop`). This reliably blocks `Ctrl+V`, `Win+V` (Windows Clipboard History), and mobile virtual keyboards (Gboard/Samsung pinned clips) before text enters any textarea.
   - Do NOT use blunt character-rate or burst heuristics that punish legitimate fast typists, voice input, or mobile autocorrect.

3. **Fullscreen & Violation Modal Protection**:
   - The toast warning container must be attached to `document.fullscreenElement || document.body` with `z-index: 2147483647` so it is always visible on top in HTML5 fullscreen.
   - When a student clicks voluntary exit or re-enters fullscreen via modal, temporary suppressors (`isInExamFullscreen`, `isSubmittingExam`) must prevent false positive blur or double-strike exit violations.

4. **Prompt Copy Protection**:
   - `.question-card` and `.options-container` must retain `user-select: none;` to prevent copying questions into external AI tools.
   - Textareas must retain `user-select: text;` so students can edit their own typed answers.

---

## 5. Verification & Code Quality Standards

Before finishing any task or reporting completion:
1. **PHP Syntax Validation**: Always run syntax check on every modified PHP file:
   ```powershell
   C:\xampp\php\php.exe -l <relative_path>
   ```
2. **Package Archive (`zip.zip`)**: Always update `zip.zip` (and `site.zip`) using the packaging skill script. The archive must contain strictly the essential runtime files required for the website to run (`admin/`, `student/`, `includes/`, `config/`, `css/`, `sql/`, `index.php`, `logout.php`, `version.txt`) and exclude development/agent artifacts:
   ```powershell
   powershell -ExecutionPolicy Bypass -File ".agents/skills/package-deploy/scripts/package.ps1"
   ```
3. **Git Commits**: Use descriptive conventional commit messages (e.g., `fix(navbar): ...`, `feat(exam): ...`).

