# UnitSync: UI Design

Screen layouts and interface rules for UnitSync. Features, roles, and workflows are in `FEATURE.md`. Technical rules are in `RULES.md`.

---

## 1. Pages Before Sign In

These pages have no top bar or left navigation.

```
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│                    [Logo] UnitSync                               │
│                                                                  │
│               ┌──────────────────────────────┐                   │
│               │ [Status banner, if any]      │                   │
│               │ Username                     │                   │
│               │ Password                     │                   │
│               │ [Log In]                     │                   │
│               │ Forgot password?             │                   │
│               │ Don't have an account?       │                   │
│               │ Register                     │                   │
│               └──────────────────────────────┘                   │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

- The log-in card is centered, with the logo above it.
- Account status messages (pending, rejected, deactivated) appear as a **banner** at the top of the card (see section 4).
- The registration page uses the same centered card. The Company and Platoon fields (Platoon Leader) or Program field (Class President) appear after the role is selected.
- After registering, a confirmation screen shows the generated username.
- The **Forgot password** page uses the same card: an email field and a Send button, then a confirmation message.
- The **Change password** page (forced at first login or after an Admin reset) uses the same card, with the current password, new password, and confirm fields. The user cannot open any other page until it is done.

---

## 2. Dashboard Layout (all roles)

Every role uses the same shell. Only the navigation items and page content change.

```
┌──────────────────────────────────────────────────────────────────┐
│ [Logo] UnitSync                  [Bell]  Name (Role)  [Sign out] │
├───────────────┬──────────────────────────────────────────────────┤
│ Navigation    │                                                  │
│ (left side)   │   Dashboard / page content                       │
│               │                                                  │
│ • Item 1      │   (remaining space)                              │
│ • Item 2      │                                                  │
│ • Item 3      │                                                  │
└───────────────┴──────────────────────────────────────────────────┘
```

- **Top bar:** The logo and system name are on the left. On the right are the notification bell, the user's name and role, and the **Sign out** button.
- **Notification bell:** Shows a count of unread notifications. Clicking it opens a dropdown with the latest notifications, newest first, and a "Mark all as read" action.
- **Left navigation:** A vertical menu. The active page is highlighted.
- **Content area:** Takes the remaining space and scrolls on its own, while the top bar and navigation stay fixed.
- **Mobile:** The navigation collapses into a menu button in the top bar and opens as a slide-in drawer. Tables scroll horizontally inside the content area.
- **Sign out:** Opens a styled confirmation dialog (see section 4), then returns the user to the log-in page.

---

## 3. Navigation Items per Role

| Role | Left navigation |
|---|---|
| Admin | Dashboard, Registration Requests, Users, Training Sessions, Structure Setup (programs, companies, platoons, settings), Attendance, Audit Log |
| Class President | Dashboard (cadet attendance table for their program) |
| Platoon Leader | Dashboard, Add Cadet, View Cadets, Take Attendance |
| Battalion S1 | Dashboard, Cadet Roster, Review Attendance, View Attendance |
| Brigade S1 | Dashboard, Cadet Roster, Review Attendance, View Attendance |

The dashboard home page shows a short summary for each role:

| Role | Dashboard home shows |
|---|---|
| Admin | Number of pending registration requests, active users, and the next training session |
| Class President | Program attendance table and each cadet's attendance percentage |
| Platoon Leader | Platoon cadet count, sessions waiting to be submitted, and sessions returned by S1 |
| Battalion S1 and Brigade S1 | Number of sessions waiting for approval, and recently approved sessions |

---

## 4. No Browser Alerts

The system never uses the browser's `alert()`, `confirm()`, or `prompt()` pop-ups. All feedback is built with HTML and styled with CSS so it matches the design of the system:

| Situation | Styled replacement |
|---|---|
| Success or error after an action (saved, submitted, approved) | **Toast** message in a corner of the screen that disappears after a few seconds |
| Confirmation (sign out, submit attendance, reject account, remove cadet from platoon, return attendance) | **Modal dialog** with a title, short message, and Confirm and Cancel buttons |
| Form errors (missing field, duplicate student number, weak password) | **Inline message** under the field, with the field border highlighted |
| Account status on log in (pending, rejected, deactivated) | **Banner** above the log-in form |
| Reasons and remarks (rejection reason, return remarks, excuse reason) | Text area inside a modal dialog, not a prompt box |
| Empty lists and loading | Styled empty-state message and loading indicator inside the content area |

Style rules:
- Use one consistent color for each message type: success, error, warning, and info.
- Do not rely on color alone. Each message also has an icon or label for accessibility.
- Modals trap keyboard focus, close with the Esc key, and block the page behind them with a dimmed overlay.
- Attendance buttons use a distinct color for each status (P, A, L, E) plus the letter itself.

---

## 5. Color and Style

Vibe: minimal and professional. Lots of white space, few borders, one accent at a time.

### Color tokens (define as CSS variables in `:root`)

| Token | Value | Use |
|---|---|---|
| `--green-900` | `#243321` | Top bar, headings |
| `--green-700` | `#3B5232` | Primary: buttons, active nav item, links |
| `--green-100` | `#E8EFE4` | Light tint: hover rows, selected nav background |
| `--gold-500` | `#C9A227` | Secondary: accents, badges, focus rings, highlights |
| `--gold-100` | `#FBF3D6` | Light gold background for notices |
| `--white` | `#FFFFFF` | Page background and cards |
| `--gray-50` | `#F7F8F6` | Areas behind cards, table stripes |
| `--gray-300` | `#D5D9D2` | Borders |
| `--gray-700` | `#4A4F47` | Body text |
| `--success` | `#2E7D32` | Success messages, Present |
| `--error` | `#B3261E` | Error messages, Absent |
| `--warning` | `#B26A00` | Warnings, Late |
| `--info` | `#1F5F8B` | Information, Excused |

Rules:
- The page background is white. Gold is for accents and highlights, never for small text on white (low contrast). Use dark text on gold backgrounds.
- Text on green is white. Body text is gray-700 on white.
- Attendance statuses: P is success green, A is error red, L is warning amber, E is info blue. The letter is always shown.

### Spacing and shape
- Spacing scale: 4, 8, 12, 16, 24, 32, 48 px. Use only these values. Space is part of the design: generous padding in cards (24px) and between sections (32px).
- Border radius: 8px for cards and inputs, 999px for badges.
- Soft shadow on cards only: `0 1px 3px rgba(0,0,0,0.08)`.

### Typography
- One font family: the system UI stack (Segoe UI, Roboto, Arial, sans-serif). No external fonts.
- Sizes: 14px body, 12px small, 18px section titles, 24px page titles.

### Icons
- Inline SVG, one consistent line style, 20px.
- Every icon has a purpose: information, status, or an action. No decorative icons.
- A small "i" info icon sits next to rules or explanations the user may need, such as the password policy or the attendance percentage formula.

### Hover and animation
- Transitions of 150 to 200ms (ease-out) on hover, focus, and pressed states.
- Buttons darken slightly on hover. Table rows get the green-100 background on hover.
- Modals fade and slide in. Toasts slide in from a corner. The mobile drawer slides in from the left.
- Respect `prefers-reduced-motion` by turning animations off.
- Every interactive element has a visible focus ring (gold-500).