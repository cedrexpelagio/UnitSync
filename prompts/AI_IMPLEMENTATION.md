#What UnitSync is

UnitSync is a ROTC attendance system built in plain PHP 8, PDO/MySQL, and vanilla JS with no framework or CDN. It is about 14.8k lines of PHP and 7k lines of JS, and it has five roles:

Role	Scope
Admin	Accounts, terms, sessions, audit log
Platoon Leader	Own platoon: cadets and attendance
Class President	Read-only view of one program
Battalion S1 (×2)	All cadets; approve attendance
Brigade S1 (×1)	All cadets; required approver

The core is an approval workflow: Draft → Submitted → approved by Battalion S1 and Brigade S1 → Approved and locked, with a Return path and approvals cleared on edit. Roles, scopes, and states are enforced server-side with require_role(), CSRF tokens, and prepared statements, so the foundation is solid.

Why it suits a guide chatbot: the workflow has many rules a new user has to learn, such as “all cadets must be marked before submit”, “only S1 can move a cadet”, and “editing resets approvals”. Today there is no in-app help at all.

Things I noticed along the way:

The archive includes config/config.local.php, which is git-ignored but holds DB and admin credentials. I read only its key names and never printed the values. Rotate those credentials and strip the file before sharing the project again.
display_errors is on, and BASE_URL has a hardcoded /Backend_ni_Ced/UnitSync branch that RULES.md forbids.
FEATURE.md is behind the code. The S1 cadet and officer imports and the officer roster aren’t in it, so the chatbot’s knowledge base should be written from the actual pages, not just that spec.
Recommended design: a role-aware guide bot

A floating “Help” widget on every page, answering “how do I…?” and “why can’t I…?” for the logged-in user’s role. It fits your existing conventions.

[chatbot.js widget] → api/chat.php → Claude API
                         │
         role + current page + role-filtered knowledge

1. Knowledge base: no vector DB needed. The rules are small, around 15–20 KB of text. Write one markdown file per role in knowledge/ (platoon_leader.md, s1.md, admin.md, class_president.md, common.md), each covering page-by-page steps, statuses, error causes, and the attendance % formula. The server puts only the caller’s role file plus common.md into the system prompt, so a Platoon Leader’s bot never knows S1-only procedures. That follows your “scope is checked on the server” rule. Prompt caching keeps the repeated prefix cheap.

2. Context from the page. chatbot.js sends the current script name (for example attendance.php), so “how do I submit this?” is answered for the page the user is on. The server decides the role from the session, never from the client.

3. api/chat.php, following your JSON convention {ok, message, data}. In order, it does these:

require_login(), CSRF check, and a per-user rate limit and daily cap.
session_write_close() before the API call. PHP session locking would otherwise freeze the user’s other tabs for the several seconds the model takes.
Last 6–8 messages of history, trimmed server-side.
cURL to the Claude API, with the key in config.local.php and never in JS.
Returns the answer plus optional deep links built with BASE_URL.

4. Model choice. Haiku 5.5 (claude-haiku-5-5) is enough for FAQ-style guidance and is fast and cheap. Sonnet 5.5 (claude-sonnet-5-5) is the upgrade if answers need more reasoning.

5. Guardrails.

The prompt says: answer only from the knowledge base, say “I’m not sure, ask your S1/Admin” otherwise, and never invent features.
The bot is read-only and takes no actions.
Don’t send cadet names, birthdays, emails, or contact numbers to the API. This matters under RA 10173, which your docs already cite. Send only role, page, and assignment label.
Add a chat_logs table as migration 009, with 👍/👎 feedback and a flag for unanswered questions. That log tells you which help topics to write next.

6. UI. The widget goes in includes/footer.php, with CSS using your existing tokens (so dark mode works) and inline SVG icons. It uses showToast() for errors and no alert(), and it needs a mobile layout since leaders use phones.

What it answers, by role
Role	Example questions
Platoon Leader	“Why is Submit disabled?” (unmarked cadets). “I edited after submitting, what happened?” (approvals cleared). “How do I remove a cadet?”
S1	“Why can’t I approve this?” (already approved at your level). “How do I import cadets by CSV?” “How do I reassign an unassigned cadet?”
Class President	“What does At Risk mean?” “How is the attendance % computed?”
Admin	“Why can’t I approve this registration?” (S1 slot limit). “How do I switch the active term?”
Phasing
v1: guide bot. Widget, api/chat.php, knowledge files, logging. About 1–2 days of work, with the knowledge writing being the real effort.
v2: live status. Add a few read-only tools scoped by the server, such as “what’s the status of my last submission?” or “which session is blocking me?”. The bot then explains from real data, still without sending PII.
Later, if you want: an explainer for CSV import errors, a plain-language at-risk summary for S1, and a bot that answers from the audit log.