---
name: SILANSIA Healthcare Management
colors:
  surface: '#faf8ff'
  surface-dim: '#d2d9f4'
  surface-bright: '#faf8ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f2f3ff'
  surface-container: '#eaedff'
  surface-container-high: '#e2e7ff'
  surface-container-highest: '#dae2fd'
  on-surface: '#131b2e'
  on-surface-variant: '#3d4a42'
  inverse-surface: '#283044'
  inverse-on-surface: '#eef0ff'
  outline: '#6d7a72'
  outline-variant: '#bccac0'
  surface-tint: '#006c4a'
  primary: '#006948'
  on-primary: '#ffffff'
  primary-container: '#00855d'
  on-primary-container: '#f5fff7'
  inverse-primary: '#68dba9'
  secondary: '#006c49'
  on-secondary: '#ffffff'
  secondary-container: '#6cf8bb'
  on-secondary-container: '#00714d'
  tertiary: '#0051d5'
  on-tertiary: '#ffffff'
  tertiary-container: '#316bf3'
  on-tertiary-container: '#fefcff'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#85f8c4'
  primary-fixed-dim: '#68dba9'
  on-primary-fixed: '#002114'
  on-primary-fixed-variant: '#005137'
  secondary-fixed: '#6ffbbe'
  secondary-fixed-dim: '#4edea3'
  on-secondary-fixed: '#002113'
  on-secondary-fixed-variant: '#005236'
  tertiary-fixed: '#dbe1ff'
  tertiary-fixed-dim: '#b4c5ff'
  on-tertiary-fixed: '#00174b'
  on-tertiary-fixed-variant: '#003ea8'
  background: '#faf8ff'
  on-background: '#131b2e'
  surface-variant: '#dae2fd'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 40px
    fontWeight: '700'
    lineHeight: 48px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.015em
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 26px
    fontWeight: '700'
    lineHeight: 34px
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
  title-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  label-sm:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '600'
    lineHeight: 14px
    letterSpacing: 0.03em
  metric-num:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '700'
    lineHeight: 32px
    letterSpacing: -0.02em
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

This design system delivers an enterprise-grade, serene, and authoritative experience tailored for elderly care management and geriatric clinical facilities across Indonesia. 

### Brand Personality & Demographics
- **Tone & Mood:** Compassionate, clinical yet warm, profoundly reliable, organized, and dignified. It eliminates clinical coldness while avoiding childish tropes, recognizing the sensitivity and gravity of elderly care.
- **Target Audience:** Medical staff, care facility administrators, gerontologists, social workers, and family caregivers in Indonesia.
- **Visual Identity Direction:** Modern Clinical Minimalism blended with Warm Enterprise SaaS. The interface prioritizes legible data tables, patient vital indicators, medication registries, and real-time status boards with generous whitespace and calm contrast.
- **Localization:** Designed primarily in natural, clear, and respectful administrative Bahasa Indonesia (*e.g., Rekam Medis, Rencana Perawatan, Tanda Vital, Insiden Kritis, Daftar Jaga*).

## Colors

The palette revolves around therapeutic emerald tones, balanced with clinical slates to foster trust, reduce cognitive strain during long shifts, and ensure clear status legibility.

### Palette Implementation
- **Primary (`#059669` / Deep Emerald):** Serves as the primary brand touchpoint, used for primary actions, navigation active states, high-priority confirmative buttons, and core branding elements.
- **Secondary (`#10B981` / Emerald Mint):** Accent color used for positive status indicators, health trend graphs, active care trackers, and subtle hover states. Tinted surfaces rely heavily on `#ECFDF5` for highlighted cards, callout alerts, and active table rows.
- **Tertiary (`#2563EB` / Clinical Blue):** Dedicated to informative messaging, patient medical record links, doctor notes, and secondary telemetry data.
- **Neutral Palette:**
  - Base Background: `#F8FAFC` (Slate 50) and `#F1F5F9` (Slate 100) for structural depth.
  - Surface Containers: Pure `#FFFFFF` cards and modals.
  - Borders: Crisp `#E2E8F0` (Slate 200).
  - Typography: `#0F172A` (Headlines, primary metrics), `#334155` (Body text, forms), and `#64748B` (Muted labels, metadata).
- **Semantic Accents:**
  - **Kritis / Bahaya (Critical):** `#DC2626` paired with `#FEF2F2` background.
  - **Peringatan / Tinjauan (Warning / Pending):** `#D97706` paired with `#FFFBEB` background.
  - **Stabil / Terverifikasi (Normal / Verified):** `#059669` paired with `#ECFDF5` background.
  - **Netral / Arsip (Neutral):** `#64748B` paired with `#F1F5F9` background.

## Typography

Inter serves as the single font family across the entire system. Its tall x-height, clear aperture, and distinction between glyphs ensure legibility for staff checking medication charts, vital signs, or patient identifiers across both workstation monitors and handheld tablets.

### Hierarchy & Usage
- **Headings (`display-lg`, `headline-lg`, `headline-md`):** Reserved for high-level facility overviews, patient name headers, and dashboard summaries. Tight tracking (`-0.015em` to `-0.02em`) gives a crisp, modern editorial finish.
- **Body (`body-lg`, `body-md`, `body-sm`):** Set at relaxed line heights (1.45 to 1.5) in `#334155` for clinical notes, dietary instructions, and medication schedules.
- **Labels & Badges (`label-md`, `label-sm`):** Rendered in semibold with slight positive letter spacing, used for status indicators, table header columns (all caps where appropriate), and metadata tags.
- **Tabular Figures:** Always apply `font-feature-settings: 'tnum' on, 'cv05' on` in clinical data tables, heart-rate monitors, blood pressure readings, and timestamps to prevent layout shifting.

## Layout & Spacing

The layout is grounded on an 8px vertical baseline grid with a 12-column responsive fluid container.

### Layout Mechanics
- **Grid Architecture:** 12-column layout on desktop (`>1024px`) with 24px (`1.5rem`) gutters and 32px (`2rem`) outer margin. Tablet devices (`768px - 1023px`) scale to 8 columns with 16px (`1rem`) gutters. Mobile displays (`<768px`) adapt to a 4-column single-stack arrangement with 16px margins.
- **Structural Shelves:** A persistent 260px wide left navigation rail houses the primary module links (*Dashboard, Daftar Penghuni, Jadwal Obat, Pemeriksaan Medis, Insiden & Laporan, Pengaturan Fasilitas*), collapsing into an icon-rail or slide-over drawer on smaller viewports.
- **Internal Density:**
  - Card internal padding defaults to `space-lg` (24px) for prominent dashboards, reducing to `space-md` (16px) for high-density clinical logs.
  - Element groupings (such as icon + label or label + input) enforce strict pairings of `space-xs` (4px) and `space-sm` (8px).

## Elevation & Depth

Visual hierarchy uses crisp, architectural surface layering paired with ultra-diffused, ambient micro-shadows rather than heavy drop shadows.

### Elevation Hierarchy
- **Level 0 (Canvas Base):** `#F8FAFC`. Unclickable, background infrastructure.
- **Level 1 (Card & Module Layer):** Pure `#FFFFFF` with a 1px continuous outline in `#E2E8F0` and an ambient shadow: `0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.02)`.
- **Level 2 (Hovered Cards & Interactive Rows):** Outlined with `#CBD5E1`, elevated via `0 4px 6px -1px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(15, 23, 42, 0.04)`.
- **Level 3 (Dropdowns, Popovers, & Context Menus):** `#FFFFFF` surface, border `#E2E8F0`, shadow `0 10px 15px -3px rgba(15, 23, 42, 0.08), 0 4px 6px -4px rgba(15, 23, 42, 0.03)`.
- **Level 4 (Modals & Emergency Drawers):** Backdrop shaded with `rgba(15, 23, 42, 0.45)` with 4px backdrop blur (`backdrop-blur-sm`). Container elevated via `0 20px 25px -5px rgba(15, 23, 42, 0.1), 0 8px 10px -6px rgba(15, 23, 42, 0.04)`.

## Shapes

The design system employs a refined, rounded geometric profile (`roundedness: 2`), projecting modern technical clarity while softening clinical rigidity.

### Radius Assignments
- **Inputs, Buttons, Chips, & Badges:** `0.5rem` (8px). Consistent across all interactive form controls.
- **Cards, Panels, & Tables:** `0.75rem` (12px) to `1rem` (16px) for standard grouping containers.
- **Dialogs & Slide-out Drawers:** `1rem` (16px) on exposed corners.
- **Status Pills & Avatars:** Fully rounded (`9999px`) to emphasize personal identity or atomic status.

## Components

### Tombol (Buttons)
- **Utama (Primary):** Solid `#059669` background with white text, 8px radius, height of 40px (or 48px for emergency actions), font `body-md` semibold. Hover state darkens to `#047857`. Focus ring: 2px solid `#10B981` with 2px offset.
- **Sekunder (Secondary):** `#FFFFFF` background with 1px border `#E2E8F0`, text in `#334155`. Hover shifts to `#F8FAFC` background with border `#CBD5E1`.
- **Destruktif / Darurat (Critical Action):** Background `#DC2626`, white text. Used for triggering alerts (*Lapor Insiden Kritis*).

### Lencana Status (Status Badges / Chips)
- Built with 9999px pill radius, height 24px, padding 0 10px, typography `label-sm`.
- **Terverifikasi / Sehat (Verified / Stable):** Background `#ECFDF5`, text `#065F46`, paired with an emerald leading dot.
- **Perlu Tinjauan (Pending Review):** Background `#FFFBEB`, text `#92400E`, with an amber leading dot.
- **Darurat / Kritis (Emergency / High Risk):** Background `#FEF2F2`, text `#991B1B`, with a pulse indicator.
- **Netral / Terjadwal (Scheduled / Neutral):** Background `#F1F5F9`, text `#475569`.

### Kolom Input & Formulir (Input Fields)
- Clean 1px `#E2E8F0` border on `#FFFFFF` surface, height 42px, padding horizontal 12px, font `body-md`.
- Placeholder color: `#94A3B8`.
- Focus state: Border turns to `#059669` with a subtle emerald glow (`box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15)`).
- Form labels: Displayed directly above input in `body-sm` semibold `#334155`.

### Tabel Rekam Medis (Clinical Data Tables)
- Row striping is discarded in favor of `#FFFFFF` rows with 1px `#F1F5F9` dividers.
- Table headers: `#F8FAFC` background, typography `label-md` uppercase in `#64748B`, with height of 44px.
- Hovered row: Subtle `#F8FAFC` tint with pointer affordance.
- Monospaced digits (`tabular-nums`) for blood pressure, SpO2, heart rate, and dates.

### Kartu Lansia / Profil (Patient Cards)
- Border 1px `#E2E8F0`, `#FFFFFF` surface, padding 20px.
- Header holds patient avatar (48px circle), full name, age, room/bed assignment (*e.g., Kamar 204 - Teratai*), and vital summary chip.
- Bottom actions provide direct access to *Catatan Medis*, *Beri Obat*, and *Kontak Wali*.

### Kotak Centang & Radio (Checkboxes & Radio Buttons)
- 18px box size with 4px radius (checkbox) or circular (radio).
- Border 1.5px `#CBD5E1`. When selected, fills with `#059669` displaying a crisp white SVG checkmark.