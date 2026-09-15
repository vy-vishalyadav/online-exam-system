---
name: exam-integrity-audit
description: >-
  Audits, tests, and validates online examination proctoring and anti-cheat behaviors
  in student/exam.php. Use this skill when investigating cheating vectors,
  modifying exam security listeners, or verifying violation logging.
---

# Exam Integrity & Anti-Cheat Audit Skill

Use this runbook to maintain, verify, and extend the browser-based proctoring mechanisms in `student/exam.php`.

## 1. Proctoring Rules & Trigger Matrix

| Event / Vector | Trigger Mechanism | Expected Action | Violation Type | Integrity Warning Incremented? |
| :--- | :--- | :--- | :--- | :--- |
| **Fullscreen Exit** | `fullscreenchange` / `webkitfullscreenchange` | Lock UI, display re-entry modal | `fullscreen_exit` | Yes |
| **Tab / App Switching** | `visibilitychange` (`hidden`) & `blur` | Log strike, show warning count | `tab_switch` / `window_blur` | Yes |
| **Clipboard / Paste** | `beforeinput` (`insertFromPaste`, `insertFromDrop`) | Block input, show red toast | `blocked_key` | No (blocked real-time) |
| **Voluntary Exit** | Exit button / Navbar link modal confirm | Clean exit without double strike | `exit_exam` | Yes (voluntary recorded) |
| **Inspection / Shortcuts** | `keydown` (F12, Ctrl+U, Ctrl+C, Ctrl+V) | Block default key event, show toast | `blocked_key` | No (preventive) |
| **Right-Click Menu** | `contextmenu` | Suppress context menu, show toast | `blocked_key` | No (preventive) |

## 2. Integrity Guards Checklist

When editing `student/exam.php`, ensure these safeguards are preserved:

1. **Top-Level Toast Visibility**:
   - The toast alert element must be appended to `document.fullscreenElement || document.body` with `z-index: 2147483647`.
   - Never append it exclusively to `document.body`, or it will be hidden underneath the fullscreen element.

2. **False-Positive Blur Suppression**:
   - When the user clicks the exit confirmation modal or the re-enter fullscreen modal, `window.blur` fires naturally as browser focus shifts.
   - The flag `isInExamFullscreen` and modal focus guards prevent false positive strikes during these legitimate transitions.

3. **No Retake Time Exploits**:
   - The timer must always calculate remaining seconds against the exam's scheduled end:
     `remainingSeconds = scheduled_end_timestamp - current_timestamp`.
   - Never initialize timers from full exam duration on resume.

4. **Usability Preservation**:
   - Textareas must keep `user-select: text;` to allow students to edit their typed responses.
   - Normal typing (`insertText`), delete (`deleteContentBackward`), and enter (`insertLineBreak`) must never be blocked.

## 3. Database Audit Queries

To audit violations logged for an exam attempt:
```sql
SELECT v.id, v.attempt_id, v.violation_type, v.details, v.created_at, s.name, s.student_id 
FROM exam_violations v
JOIN exam_attempts a ON v.attempt_id = a.id
JOIN students s ON a.student_id = s.id
ORDER BY v.created_at DESC LIMIT 50;
```
