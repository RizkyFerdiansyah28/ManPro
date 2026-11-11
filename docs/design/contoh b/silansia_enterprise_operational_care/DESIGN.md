---
name: Silansia Enterprise Operational Care
colors:
  surface: '#f8f9ff'
  surface-dim: '#cbdbf5'
  surface-bright: '#f8f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#eff4ff'
  surface-container: '#e5eeff'
  surface-container-high: '#dce9ff'
  surface-container-highest: '#d3e4fe'
  on-surface: '#0b1c30'
  on-surface-variant: '#3e4943'
  inverse-surface: '#213145'
  inverse-on-surface: '#eaf1ff'
  outline: '#6e7a73'
  outline-variant: '#bdc9c1'
  surface-tint: '#006c4e'
  primary: '#005d42'
  on-primary: '#ffffff'
  primary-container: '#047857'
  on-primary-container: '#9ffdd3'
  inverse-primary: '#7bd8b1'
  secondary: '#0058be'
  on-secondary: '#ffffff'
  secondary-container: '#2170e4'
  on-secondary-container: '#fefcff'
  tertiary: '#7d4200'
  on-tertiary: '#ffffff'
  tertiary-container: '#a05600'
  on-tertiary-container: '#ffe5d3'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#97f5cc'
  primary-fixed-dim: '#7bd8b1'
  on-primary-fixed: '#002115'
  on-primary-fixed-variant: '#00513a'
  secondary-fixed: '#d8e2ff'
  secondary-fixed-dim: '#adc6ff'
  on-secondary-fixed: '#001a42'
  on-secondary-fixed-variant: '#004395'
  tertiary-fixed: '#ffdcc3'
  tertiary-fixed-dim: '#ffb77d'
  on-tertiary-fixed: '#2f1500'
  on-tertiary-fixed-variant: '#6e3900'
  background: '#f8f9ff'
  on-background: '#0b1c30'
  surface-variant: '#d3e4fe'
typography:
  headline-xl:
    fontFamily: Inter
    fontSize: 36px
    fontWeight: '700'
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-xl-mobile:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '700'
    lineHeight: 36px
    letterSpacing: -0.01em
  headline-lg:
    fontFamily: Inter
    fontSize: 30px
    fontWeight: '600'
    lineHeight: 38px
    letterSpacing: -0.02em
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
    letterSpacing: -0.01em
  title-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
    letterSpacing: 0em
  title-sm:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0em
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
    letterSpacing: 0em
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
    letterSpacing: 0em
  body-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 16px
    letterSpacing: 0em
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.04em
  label-sm:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 14px
    letterSpacing: 0.05em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1.5rem
  gutter-sm: 1rem
  margin: 2rem
  margin-sm: 1rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2rem
---

## Brand & Style

This design system establishes a high-trust, utilitarian operational management platform tailored for enterprise elder care facilities, administrative coordinators, and care-floor supervisors. The tone is clear, authoritative, and disciplined—prioritizing immediate administrative legibility and situational clarity over clinical or decorative clutter.

The aesthetic follows an Enterprise Corporate Modern archetype:
- **Clean Structure:** 8px baseline rhythm, systematic alignments, high data-density without visual friction.
- **Controlled Warmth & Credibility:** Deep emerald accents convey safety, care continuity, and institutional stability against neutral slate backdrops.
- **Workflow-Driven Clarity:** Visual weight directly mirrors operational priorities—incident escalation (fall management), room occupancy, caregiver shifts, and optical monitoring windows.
- **Zero Distraction:** Strictly excludes consumer health gamification, faux medical telemetry graphs, or unnecessary regulatory iconography in favor of pragmatic facility operations.

## Colors

The palette is engineered around high legibility, accessible contrast ratios (WCAG AA/AAA compliant), and functional purpose.

- **Primary (`#047857` - Emerald 700):** Represents core administrative actions, positive operational states, primary navigation markers, and verified system events.
- **Primary Hover (`#065F46` - Emerald 800):** Interactive hover states for primary buttons and high-priority controls.
- **Light Accent / Active Surface (`#ECFDF5` - Emerald 50):** Background tint for active side navigation nodes, selected table rows, active monitoring status tags, and success badges.
- **Background (`#F8FAFC` - Slate 50):** System foundation canvas providing subtle separation from white surface containers.
- **Surface (`#FFFFFF`):** High-clarity cards, modular panels, tables, dialogs, and popovers.
- **Text Primary (`#0F172A` - Slate 900):** Maximum-contrast copy for titles, numerical indices, critical metrics, and table contents.
- **Text Secondary (`#64748B` - Slate 500):** Secondary metadata, timestamps, column headers, and helper text.
- **Border (`#E2E8F0` - Slate 200):** Thin, crisp structural dividers and component perimeters.
- **Status Amber (`#D97706` - Amber 600):** Room inspection alerts, pending shift approvals, off-schedule camera check-ins.
- **Status Danger (`#DC2626` - Red 600):** Fall incident alarms, critical perimeter breaches, unassigned emergency notifications.
- **Status Info (`#3B82F6` - Blue 600):** Facility notices, scheduled maintenance, resident stay transfers.

## Typography

The typography uses Inter across all application tiers to enforce structural harmony and legibility under dense administrative conditions.

- **Numerics & Monospace Characteristics:** Use tabular figures (`font-variant-numeric: tabular-nums`) across all analytical tables, timestamp registries, and incident ID counts to maintain horizontal alignment.
- **Headings (`headline-*`):** Tight letter tracking (`-0.01em` to `-0.02em`) gives section anchors a compact, professional appearance.
- **Data Table Cells & Operational Logs:** Rely strictly on `body-md` (regular text) paired with `title-sm` (row entity keys).
- **Status Badges & Table Column Headers:** Rely on `label-sm` or `label-md` with uppercase transformation and explicit tracking (`0.04em` to `0.05em`) to distinguish metadata from content values.

## Layout & Spacing

The system runs on an 8px base rhythm (`0.5rem` steps), with `0.25rem` (`4px`) reserved for micro-alignments like icon-label pairings and badge padding.

### Grid & Viewport Layout
- **Desktop (1280px+):** Fixed collateral navigation sidebar (260px width), fluid 12-column work area, 24px (`1.5rem`) gutters, and 32px (`2rem`) content canvas padding.
- **Tablet (768px - 1279px):** Collapsed sidebar rail (64px width), 8-column layout, 16px (`1rem`) gutters, and 24px (`1.5rem`) outer margin.
- **Mobile (<768px):** Single-column stacked layout, off-canvas navigation drawer, 16px (`1rem`) horizontal canvas margins. Data tables convert into segmented list cards.

### Spacing Tokens
- `space-xs` (4px): Inner badge padding, button icon gaps, table row separators.
- `space-sm` (8px): Input field internal vertical padding, inline button pairings, metric label gaps.
- `space-md` (16px): Card internal content padding, form field vertical stacks, toolbar gaps.
- `space-lg` (24px): Inter-card margins, modal section dividers, primary summary card spacing.
- `space-xl` (32px): Major page header separation, administrative section blocks.

## Elevation & Depth

Visual hierarchy prioritizes surface stability and crisp contrast over exaggerated skeuomorphic drop shadows. Depth is communicated via clean borders and low-opacity ambient shadows:

- **Level 0 (Flat / Canvas):** Applied to the main page canvas (`#F8FAFC`). No shadow.
- **Level 1 (Card & Module Default):** Surface cards, standard table frames, and monitoring camera grid tiles use `#FFFFFF` background, a 1px solid border (`#E2E8F0`), and an ambient shadow: `0 1px 3px 0 rgba(15, 23, 42, 0.05), 0 1px 2px -1px rgba(15, 23, 42, 0.03)`.
- **Level 2 (Hover & Active Contexts):** Draggable facility cards, active room assignment panels, and table row hover states use: `0 4px 6px -1px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.05)`.
- **Level 3 (Overlays & Dropdowns):** Operational filter menus, multi-select action menus, and time-window select dialogs use: `0 10px 15px -3px rgba(15, 23, 42, 0.08), 0 4px 6px -4px rgba(15, 23, 42, 0.04)` combined with `#E2E8F0` border.
- **Level 4 (Critical Modal / Emergency Incident Dispatch):** Fall incident confirmation overlays and high-priority room re-allocation prompts use: `0 20px 25px -5px rgba(15, 23, 42, 0.1), 0 8px 10px -6px rgba(15, 23, 42, 0.05)` with a backdrop filter overlay of `rgba(15, 23, 42, 0.4)`.

## Shapes

The design system standardizes on a structured 12px card border radius (`rounded-lg`) to balance structural rigor with modern ergonomic enterprise styling.

- **Cards & Data Panels (`rounded-lg` / 12px / 0.75rem):** Standardized containers across all dashboard widgets, camera overview cells, and incident detail sheets.
- **Interactive Controls (`rounded` / 8px / 0.5rem):** Form inputs, standard buttons, select dropdowns, and date-range toggles use an 8px radius for clear clickable affordance.
- **Micro-Indicators & Badges (`rounded-sm` / 4px / 0.25rem or full pill):** Incident priority tags, resident stay status flags, and camera status chips use `4px` corner radiuses for crisp tabular legibility.
- **Focus Rings:** Non-offsetting 2px outline using `#047857` with 2px offset in `#FFFFFF` on keyboard focus.

## Components

### 1. Buttons
- **Primary:** Background `#047857`, text `#FFFFFF`, border none, 8px border radius, 36px height (desktop `body-md` bold). Hover `#065F46`. Focus ring 2px emerald.
- **Secondary / Outline:** Background `#FFFFFF`, text `#0F172A`, border 1px solid `#E2E8F0`. Hover `#F8FAFC`.
- **Destructive:** Background `#DC2626`, text `#FFFFFF`. Hover `#B91C1C`. Used exclusively for critical escalations and stay terminations.
- **Subtle / Ghost:** Background transparent, text `#64748B`. Hover `#F1F5F9`, text `#0F172A`.

### 2. Status Chips & Badges
- **Active / Operational:** Background `#ECFDF5`, text `#047857`, border 1px solid `#A7F3D0`.
- **Attention / Pending Verification:** Background `#FFFBEB`, text `#D97706`, border 1px solid `#FDE68A`.
- **Urgent / Fall Incident:** Background `#FEF2F2`, text `#DC2626`, border 1px solid `#FECACA`.
- **Scheduled / Info:** Background `#EFF6FF`, text `#2563EB`, border 1px solid `#BFDBFE`.

### 3. Cards & Panels
- **Container Structure:** Background `#FFFFFF`, 12px border radius, 1px border `#E2E8F0`, subtle shadow.
- **Header:** Divided by a 1px border `#E2E8F0`, featuring `title-md` title and action slot on the right.
- **Internal Padding:** `space-md` (16px) or `space-lg` (24px) depending on density setting.

### 4. Form Inputs & Select Controls
- **Standard Input:** Height 38px, background `#FFFFFF`, border 1px solid `#E2E8F0`, 8px radius, typography `body-md`.
- **Active / Focused:** Border `#047857`, box-shadow `0 0 0 1px #047857`.
- **Error State:** Border `#DC2626`, error label `body-sm` `#DC2626`.

### 5. Checkboxes & Radio Controls
- **Size:** 16px × 16px with 4px border radius for checkboxes; circular for radio.
- **Checked:** Background `#047857`, border `#047857`, check mark `#FFFFFF`.

### 6. Lists & Data Tables
- **Header Row:** Background `#F8FAFC`, text `#64748B`, typography `label-sm`, height 40px, bottom border 1px solid `#E2E8F0`.
- **Data Row:** Background `#FFFFFF`, text `#0F172A`, height 48px, bottom border 1px solid `#F1F5F9`. Hover background `#F8FAFC`.
- **Selected Row:** Background `#ECFDF5`, border-left 3px solid `#047857`.

### 7. Domain-Specific Components
- **Camera Operating Period Card:** Clean grid tile showing room identifier (e.g., "Wing B - Rm 204"), active monitoring window time badge ("08:00 - 20:00"), feed health indicator (green/amber dot), and privacy shutter toggle.
- **Incident Escalation Feed:** Chronological stack showing timestamp (`body-sm` tabular), severity dot (`#DC2626` for confirmed fall, `#D97706` for abnormal stay duration), room reference, assigned responder avatar/tag, and one-click "Acknowledge" button.
- **Stay Management Timeline:** Horizontal milestone bar tracking resident facility tenure: Admission Date, Room Transfer, Active Operating Status, and Discharge Schedule without medical diagnostics.