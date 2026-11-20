# UI palette

The interface uses one six-colour palette. Every surface, chart and status
badge is derived from these values, so a photo, a PDF and the screen all look
like the same product.

| Token | Hex | Role |
| --- | --- | --- |
| `--blue-700` | `#0249E1` | Primary. Navigation, links, primary buttons, chart series 1. |
| `--blue-400` | `#80B0EC` | Soft blue. Secondary series, "due" states, tints and tracks. |
| `--card` | `#FFFFFF` | Surfaces: cards, tables, forms, printed pages. |
| `--ink` | `#111111` | Text, the sidebar, ID-card headers and the login backdrop. |
| `--lime-500` | `#DAFB71` | Positive accent: paid, confirmed, active, the one primary CTA on the login screen. |
| `--coral-500` | `#EE3D5A` | Danger: overdue, missed, reversals. |

## Shade scale

Full scales live at the top of `assets/css/style.css`:

- `--blue-50 … --blue-900` — tints through deep navy.
- `--lime-100 … --lime-700` — `lime-700` is the readable text tone.
- `--coral-50 … --coral-700` — `coral-700` is the readable text tone.

Legacy names (`--g*`, `--gold-*`, `--red-*`) still resolve to the palette, so
older views keep working without edits.

## Usage rules

1. Lime and soft blue are **backgrounds**. On white, lime text is unreadable,
   so text on lime is always ink (`#111111`).
2. Coral text on white uses `--coral-700` (`#B32440`); `--coral-500` is for
   fills, borders, icons and chart strokes.
3. Charts follow meaning, not decoration: primary blue = collected / active,
   soft blue = outstanding / due, coral = overdue, lime = paid.
4. The sidebar and login screen are the only ink-heavy surfaces. Everything
   else stays white on a paper tint of `#F5F7FB`.
5. Dates remain `DD/MM/YYYY` and all assets stay local — no web fonts, no
   external images.

## Files that carry the theme

- `assets/css/style.css` — tokens, shell, sidebar, buttons, tables, forms.
- `assets/css/lms.css` — dashboard, charts, import flow, pills, motion.
- `assets/css/login-glass.css` — sign-in screen.
- `includes/charts.php` — SVG chart palette and axis colours.
- `includes/pdf_header.php` — printed statements and dossiers.
- `assets/loan-manager-icon.svg`, `manifest.json`, `includes/header.php` —
  app icon and browser theme colour.
- `capture.php` — the phone camera page has its own inline styles and mirrors
  the same tokens.
