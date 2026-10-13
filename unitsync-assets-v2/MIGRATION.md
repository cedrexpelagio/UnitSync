# UnitSync assets v2 - migration guide

## Quick start: no template edits needed

Copy the whole `assets/` folder over your old one and run `python3 build.py` once (or just use the files as shipped).
The ORIGINAL file names (`css/style.css`, `css/auth.css`, `js/ui.js`, `js/auth.js`, ...) are included and work exactly
as before, so your PHP keeps loading the same paths. They are generated copies of the new grouped sources.
Switch to the new paths in section 1 whenever you like, then delete the old-name files.

Every existing class name still works. Moving to the new paths is optional.

## 1. Template changes

| Replace this | With this |
|---|---|
| `assets/css/style.css` **and** `assets/css/app-shell.css` | `assets/css/core.css` |
| `assets/js/ui.js` **and** `assets/js/app-shell.js` | `assets/js/core.js` |
| Auth pages: `auth.js` loaded alone | `core.js` **then** `pages/auth/auth.js` (auth.js now shares the core helpers) |

Page files move as follows (load them after `core.css` / `core.js`, exactly where you loaded them before):

| Old | New |
|---|---|
| css/auth.css | css/pages/auth/auth.css |
| css/register.css | css/pages/auth/register.css |
| css/dashboard.css | css/pages/s1/dashboard.css |
| css/roster.css | css/pages/s1/roster.css |
| css/s1_manage.css | css/pages/s1/manage.css |
| css/leader.css | css/pages/leader/leader.css |
| css/add_cadet.css | css/pages/leader/add-cadet.css |
| css/admin.css | css/pages/admin/admin.css |
| *(was inside style.css, lines 923-1202)* | css/pages/leader/attendance-sheet.css - **add this link on the leader take-attendance page and any page using `.att-*` classes** |
| *(was inside style.css, S1 matrix block)* | css/pages/s1/attendance-matrix.css - add on the S1 matrix page if it uses those classes |
| js/auth.js | js/pages/auth/auth.js |
| js/register.js | js/pages/auth/register.js |
| js/dashboard.js | js/pages/s1/dashboard.js |
| js/roster.js | js/pages/s1/roster.js |
| js/s1_manage.js | js/pages/s1/manage.js |
| js/view_attendance.js | js/pages/s1/view-attendance.js |
| js/reassign_officer.js | js/pages/s1/reassign-officer.js |
| js/leader.js | js/pages/leader/leader.js |
| js/admin.js | js/pages/admin/admin.js |

Page scripts that use the shared helpers (admin, auth, leader, dashboard, s1/manage) exit quietly if `core.js` is missing, so a wrong load order degrades to plain server-rendered pages rather than breaking.

## 2. Source layout and build

```
css/base/        tokens · reset · motion
css/layout/      app-shell · auth-card · page-header
css/components/  button · form · banner · card · table · tabs · badge · modal · toast
css/pages/       auth · s1 · leader · admin
js/core/         dom.js        (UnitSync.$, $$, esc, reducedMotion)
js/components/   ui.js · app-shell.js
js/pages/        auth · s1 · leader · admin
```

`python3 build.py` regenerates `css/core.css` and `js/core.js` (never edit those two by hand). `css/main.css` is an `@import` entry for development only.

## 3. Rules going forward

- No hex colours, radii, shadows, durations or easings in component or page files. Use tokens from `css/base/tokens.css`.
- Three elevations: flat (default), `--shadow-md` (menus, popovers), `--shadow-lg` (modals).
- Three durations (`--dur-1/2/3` = 120/180/280ms), two easings. Motion confirms a state change; it never entertains.
- One accent (olive). Status colours are semantic: Present = ok, Late = warn, Absent = err, Excused = info.
- New pages open with `.page-header` (title, one-line description, primary action).
- Reduced motion is handled once, in `base/motion.css`.

## 4. Intentionally removed

CSS classes (decorative, no function): `ua-orb`, `ua-confetti`, `ua-char`, `db-kpi-pulse`, and the `is-leaving-left/right` slide-out states. Existing markup that still carries them renders unaffected (they are hidden or inert).
JS: register-success confetti and letter-by-letter username reveal; slide-out page transition on login/register links (links now navigate immediately). Count-up numbers shortened to 400ms; reveal stagger shortened to 30ms steps.

## 4b. Visual changes worth knowing

- Header is light (was dark green gradient with dot grid); sidebar is a tinted, flat list with no icon boxes.
- Login/register: single centred card; the dark hero side panel is hidden (its copy remains in the markup).
- Gold is retired from the interface; highlights use the olive accent tint, warnings use amber.
- Cards, KPIs and tables are flat (hairline border, no resting shadow, no hover lift).

## 5. Not verified

The PHP templates were not in the archive, so pages were reviewed via `styleguide/` (components, app shell, login) rather than the live app. Please click through: register stepper, take-attendance sheet (autosave + P/L/A/E), S1 dashboard charts, roster filters, S1 attendance matrix, and admin pages.
