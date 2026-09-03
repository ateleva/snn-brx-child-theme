# WKF Deploy & Design Tokens

This file lives inside the child-theme git repo (`eleva/docs/`) specifically so it travels with the code. `docs/` at the project root is **not** version-controlled (see project `CLAUDE.md`), so anything written there is invisible to git and won't exist on any other checkout.

It answers one question: *what moves by git, what moves by WP Migrate, and what can never move automatically?*

**The one-line mental model:** code is git, everything a Bricks template or a design token lives in is the database, and content is authored wherever it was authored.

This doc replaces the old `docs/update-child-theme.md` → "Deploying to SiteGround" section for the *deploy* half of that file. That file's git-fork-and-upstream-merge workflow (fetch upstream → merge → push to `origin`) is unrelated and still current — nothing here changes it.

---

## Part 1 — Where each kind of work actually lives

| Layer | Contents | Travels how |
|---|---|---|
| **Git — child theme** | `eleva/` only: `hooks.php`, `assets/css/wkf-components.css`, `wkf-megamenu.css`, `assets/js/wkf-megamenu-a11y.js`, templates, this doc | `git push origin main` → `git pull` on staging |
| **Git — plugin** | all of `wkf-woocommerce-utilities`: PHP modules, `assets/css/wkf-calculator.css`, JS, `templates/calculator.php`, `languages/` | same |
| **Database — Bricks design system** | options `bricks_global_settings`, `bricks_global_variables`, `bricks_color_palette`, `bricks_theme_styles`, `bricks_global_classes`, `bricks_components`, `bricks_breakpoints` | WP Migrate, or per-option WP-CLI transfer (Part 3) |
| **Database — Bricks templates** | posts **7, 65, 434, 439, 592, 1019, 1084, 1153, 1201, 1277, 1290, 1362, 1412, 1476, 1478** + pages **2** (home) and **1468** (guide index), with their serialized `_bricks_page_content_*` / `_bricks_page_header_*` / `_bricks_page_footer_*` meta and `_bricks_template_settings` conditions | WP Migrate, or Bricks' own template export/import JSON |
| **Database — WP config** | `page_for_posts` (1468), `category_base` (**`argomenti`**), `permalink_structure`, `rewrite_rules`, Rank Math settings | WP Migrate; then a hard permalink flush (Part 4) |
| **Database — ACF** | field groups as `acf-field-group` posts (created in the UI per project rule, so DB-only — including `group_6a987fb28c488` / `wkf_guide_products`) | WP Migrate |
| **Database — content** | products, variations, posts, categories, terms, attachment rows, orders | **first migration only** — after that, authored on the target |
| **Filesystem — uploads** | media files, Bricks custom-font files, `uploads/bricks/css/*.min.css` (compiled, regenerable) | WP Migrate media add-on, or rsync/zip |

Never hand-edit a serialized `_bricks_page_*` value directly in a SQL client or export file — every Bricks element tree is a PHP-serialized array, and a naive string edit or find/replace corrupts it. WP Migrate is serialization-aware and handles this correctly; that's the whole reason to use it instead of a raw SQL dump for anything containing Bricks content.

---

## Part 2 — First migration (staging is empty or disposable)

**Prerequisite — already satisfied.** WP Migrate DB Pro **2.7.10** is installed locally (`app/public/wp-content/plugins/wp-migrate-db-pro`), and Pro is also installed on staging at **`https://workingfiniture.aldeialab.it/`**. This is not an open gap — nothing to install before running the steps below.

**One-time setup: pair the two sites.** In wp-admin on either site, go to **Migrate DB Pro → Connect**. Each side generates a connection key/URL from its own **Migrate DB Pro → Settings** screen; paste local's into staging's "Connect to a remote site" field (or vice versa) once. After pairing, both directions (push and pull) are available from either site's Migrate screen without re-entering credentials. Do this once, before the first real migration.

### Steps

1. **Code first.** Push both repos from local:
   ```bash
   cd "/Users/alessandro/Local Sites/working-finiture/app/public/wp-content/themes/snn-brx-child-theme"
   git push origin main

   cd "/Users/alessandro/Local Sites/working-finiture/app/public/wp-content/plugins/wkf-woocommerce-utilities"
   git push origin main
   ```
   Then, on staging (shell access to `workingfiniture.aldeialab.it` — **placeholder**: this session doesn't have SSH/terminal details for that host; confirm login method before running):
   ```bash
   # first time only
   cd wp-content/themes/ && git clone https://github.com/ateleva/snn-brx-child-theme.git
   cd ../plugins/  && git clone https://github.com/ateleva/wkf-woocommerce-utilities.git

   # every time after
   cd wp-content/themes/snn-brx-child-theme && git pull origin main
   cd wp-content/plugins/wkf-woocommerce-utilities && git pull origin main
   ```

2. **Full DB push, local → staging.** From local's **Migrate DB Pro** panel, choose the paired staging connection and run a **Push**. Once two sites are paired, WP Migrate auto-detects both the site-URL pair (`workingfiniture.local` ↔ `workingfiniture.aldeialab.it`) and the filesystem-path pair from the connection profile and pre-fills them as find-and-replace rules — verify they're correct in the review screen before running, you shouldn't need to type them by hand. This matters because every Bricks element tree is a serialized PHP array: a naive SQL find-and-replace on the URL or path would corrupt it, and WP Migrate handles serialized data correctly.

3. **Then uploads.** Push media via WP Migrate's Media Files add-on if licensed, otherwise rsync or zip/unzip `wp-content/uploads/` across. This must include Bricks' self-hosted custom-font files and any `uploads/bricks/css/*.min.css` (the latter gets regenerated anyway — Part 4).

4. **Then run Part 4's post-migration checklist in full.** A first migration hits every item on that list, not just the permalink flush.

---

## Part 3 — Ongoing loop (staging has real content you must not overwrite)

This is the state the site reaches almost immediately after the first migration, and it's the day-to-day workflow.

- **Code changes** (a CSS fix, a new hook, a plugin module): **git only.** Commit locally → push → pull on staging. Nothing else needed, no DB involvement, no risk to staging's own content.

- **Design-system changes** (a token value, a global class, a theme-style default): these live in a handful of single `wp_options` rows. Transfer them surgically — never push the whole `wp_options` table, which also carries the site URL, the active-plugin list, and WooCommerce/RFQ settings you do **not** want to clobber on staging. Via WP-CLI, per option:
  ```bash
  # on local
  wp option get bricks_global_variables --format=json > /tmp/bgv.json
  # on staging
  wp option update bricks_global_variables "$(cat /tmp/bgv.json)" --format=json
  ```
  Repeat the same shape for each of: `bricks_global_variables`, `bricks_color_palette`, `bricks_theme_styles`, `bricks_global_classes`, `bricks_components`, `bricks_global_settings`, `bricks_breakpoints`. After any of these land on staging, **regenerate `style-manager.min.css`** (Part 4) — the compiled CSS file is not a source of truth and won't reflect the new option values on its own.

- **Template changes** (a Bricks template or page layout): Bricks ships its own **export → import as JSON** for a template, and that export carries the global classes the template references — this is the reviewable, git-diffable-adjacent path once staging has content you can't blow away. The alternative — a WP Migrate selective-table push limited to `wp_posts` + `wp_postmeta` — carries a trap: those two tables hold *every* post, so it moves all content along with the one template you meant to update. Prefer the Bricks JSON route once staging has real content.

- **Content** (products, guide posts, categories, media): authored directly on the target. Never migrated upward from local once staging is live. If bulk product data needs to move, that's the WP All Import path already documented in `docs/wp-all-import-product-guide.md` (project-root `docs/`, not this repo) — not a database migration.

- **ACF field groups**: created in the UI on each environment separately (project rule forbids registering them in code — see `acf-fields-via-ui-only` in memory), so either recreate the group on staging by hand, or move it with a selective push of just its `acf-field-group` post(s). Either way, **the field name must match exactly** — e.g. `wkf_guide_products` — because Bricks template dynamic-data tags and PHP gates (`get_field('wkf_guide_products', ...)`) hardcode that name; a differently-named field on staging silently produces empty/absent content, not an error.

---

## Part 4 — Post-migration checklist

Run this after **every** DB move in either direction, not just the first one. Each item here is a failure this project actually hit during development — not a generic precaution.

1. **Hard permalink flush.**
   ```php
   delete_option('rewrite_rules');
   global $wp_rewrite;
   $wp_rewrite->flush_rules(true);
   ```
   The soft `flush_rewrite_rules()` does **not** reliably rebuild category rewrite rules when called from a REST/CLI-style execution context — proved directly during Phase G.0, where `category_base` was updated, a soft flush ran, and `get_option('rewrite_rules')` still showed zero category rules afterward. Force the hard rebuild instead. Then verify `/argomenti/posa-e-installazione/` returns HTTP 200, not 404.

2. **Bricks filter-index rebuild.**
   ```php
   \Bricks\Query_Filters::get_instance()->fix_filter_element_db();
   \Bricks\Query_Filters::get_instance()->reindex();
   // the above only *clears* the index and defers rebuilding to WP-Cron, which may not run promptly (or at all, on some local envs)
   while ( ! empty( \Bricks\Query_Filters_Indexer::get_instance()->get_jobs() ) ) {
       \Bricks\Query_Filters_Indexer::get_instance()->continue_index_jobs();
   }
   ```
   Drive it synchronously with the loop above rather than trusting cron. Verify the brand/document filters on templates 1189/1185/1182 still return results afterward.

3. **Regenerate the compiled style file.**
   ```php
   \Bricks\Ajax::generate_style_manager_css_file();
   ```
   `uploads/bricks/css/style-manager.min.css` is a compiled artifact, not a source of truth — it will not reflect any option values pushed by Part 3 until this runs.

4. **Bricks custom fonts.** The font *files* live in `uploads/` and the font *definitions* live in the `bricks_fonts` option/posts — both must be present on the target. A DB-only move without the matching uploads leaves the generated CSS pointing at font files that don't exist, and text silently falls back to the browser default.

5. **Verify WP config landed correctly.** `page_for_posts` must equal the Guide Tecniche page id (**1468**) and `category_base` must equal **`argomenti`** (not the originally-planned `guide-tecniche/argomento` — that shape 404'd during development, see Part 5's history note) on the target.

6. **Rebuild the Relevanssi index** after any content import or push, and confirm `_wkf_search_blob` postmeta (the plugin's own denormalized search field) is populated on the moved products — the plugin writes it on save, so a bulk import or DB push that doesn't trigger a save hook may need a re-save pass (e.g. `wp post list --post_type=product --format=ids | xargs -n1 wp post update --post_status=publish` as a no-op resave, or the plugin's own re-index routine if one exists).

7. **Smoke test** before calling the migration done: homepage, one product per brand (ITW/Akifix/Baufloor), an L2 category, `/guide-tecniche/`, one `/argomenti/<slug>/` archive, one single guide post, and the quote-request modal — each should return HTTP 200 with zero browser console errors.

---

## Part 5 — Design-system reference

The final, shipped state of the WKF design system as landed in Bricks. Source: `wkf-design-system-plan.md` (memory) and the master implementation plan `ok-i-already-download-lucky-gadget.md`.

### Colour tokens

Bricks Color Palette **"Eleva Colors x WKF"** (id `sdvjpn`):

| Token | Value |
|---|---|
| `--wkf-primary` | `#044A87` |
| `--wkf-primary-d-1` | `#033663` |
| `--wkf-black` | `#1D2126` |
| `--wkf-grey-l-2` (steel) | `#DCE0E5` |
| `--wkf-grey-d-1` | `#5C646E` |
| `--wkf-surface` | `#F3F5F7` |
| `--wkf-ok` | `#1E7A46` |
| `--wkf-placeholder` | `#EDEFF2` |
| `--akifix-primary` | `#FFD500` |
| `--itw-primary` | `#FF4D00` |

Baufloor reuses the Akifix yellow (`#FFD500`) as its accent — there is no separate `--baufloor-primary`. `--wkf-secondary` (a gold ramp) exists in the palette but is unused — the design has no secondary colour. Primary/ink ramps (light/dark steps) were regenerated off these base values, not hand-picked.

**Retired palette — must not appear anywhere in computed styles:** `#184080`, `#14356b`, `#0073aa`, `#F8ED03`, `#EE4E03`, `#858585`, `#E5E5E5`. A verification sweep found zero hits as of the 2026-09-03 audit; a new hit is a regression.

### Non-colour tokens → Bricks Global Variables

Category **WKF Tokens** (`f17c78`):
```
--wkf-radius       : 2px
--wkf-shadow       : 0 1px 2px rgba(23,27,33,.06), 0 8px 24px rgba(23,27,33,.07)
--wkf-container    : 95%
--wkf-font-display : 'Archivo', sans-serif
--wkf-font-body    : 'Hanken Grotesk', sans-serif
--wkf-font-mono    : 'JetBrains Mono', monospace
```

Category **WKF Tracking** (`0f9e28`):
```
--wkf-tracking-tight : -0.015em   (H1 / display headings)
--wkf-tracking-snug  : -0.01em    (section headings h2/h3)
--wkf-tracking-wide  : 0.08em     (uppercase eyebrows)
```

Category **WKF Weights** (`0bc6e8`):
```
--fw-medium    : 500
--fw-semibold  : 600
--fw-bold      : 700
--fw-extrabold : 800
```

Line-heights (WKF Line Heights category): `--lh-snug: 1.3` (headings), `--lh-relaxed: 1.6` (body).

`--wkf-header-h` (**64px**) is a plain CSS custom property defined in `eleva/assets/css/wkf-megamenu.css`, **not** a Bricks Global Variable — used for sticky-rail top offsets (`calc(var(--wkf-header-h) + var(--space-m))`). `--wkf-hpsc-tint` and `--wkf-mm-chevron` are similarly locally defined inside the child-theme CSS (homepage "Settori" gradient tint, mega-menu chevron glyph) — both intentional, don't flag them as undefined during an audit.

### Fluid type & spacing scale

Root is **16px** (`html{font-size:100%}`, overriding Bricks' default 10px root — set in `wkf-components.css`, applies in the builder canvas too). Every entry below is `clamp(min, calc(slope · (100vw − 20rem) + min), max)`, min at 320px viewport, max at 1200px:

| Scale | min→max px, in order |
|---|---|
| `--text-2xs` … `--text-4xl` | 10→11 · 11→12 · 12→13 · **15→16 (`--text-m`, body)** · 16→18 · 18→22 · 22→28 · 26→36 · 34→54 |
| `--h1` … `--h6` | 32→40 · 26→32 · 21→24 · 18→20 · 16→17 · 14→15 |
| `--space-2xs` … `--space-4xl` | 4→6 · 6→8 · 8→10 · 10→12 · 12→16 · 16→24 · 20→32 · 28→48 · 40→72 |

`--radius-xs/-m/-l/-xl` also exist as Bricks variables (rem-based, unrelated to `--wkf-radius`, which is the flat 2px design token most components actually use).

### Typography

| Role | Family | Applied to |
|---|---|---|
| Display | **Archivo** | H1–H3, mega-menu column titles, brand names, card titles — any `.brxe-heading` |
| Body | **Hanken Grotesk** | everything else |
| Mono | **JetBrains Mono** | SKUs, quantities, counts, phone/email/VAT, spec values, small badges, eyebrows |

Weights used: 500/600/700/800, 600 dominant. `text-transform:uppercase` only on eyebrows and small labels. Target rendered sizes: body 15–16px, H1 40px (32px at ≤480px), H2 32px, H3 24px, eyebrow 12px, caption 12px. H1 `line-height: 1.08`.

Theme style **`eleva_css_x_wkf`** sets this site-wide: body → Hanken Grotesk / `var(--text-m)` / `var(--lh-relaxed)`; h1–h6 → Archivo 700 / `var(--h*)` / `var(--lh-snug)` / `-0.015em`. That theme-style rule does **not** reach `.brxe-heading` elements set to a non-h1–h6 tag (e.g. a `span` styled as a heading), so a separate rule in `wkf-components.css` covers those: `.brxe-heading:not(.wkf-eyebrow){font-family:var(--wkf-font-display);letter-spacing:var(--wkf-tracking-tight)}`.

### Shape

`border-radius` is `var(--wkf-radius)` (2px) everywhere except two intentional `50%` circles. Rounded-pill filter buttons are a known "fix to 2px" item from early recon — confirmed fixed in Phase 7.

### Signature detail — "filetto righello" (ruler-rule divider)

Global class **`qxelsw`** = `wkf-righello`. Current shipped state (confirmed by the Phase 8.1 audit, which found and corrected a stale note from Phase 2.5c): the class itself carries the full styling — typed `_height` (8px), typed `_border` (1px top, `var(--wkf-grey-l-2)`), typed `_margin` (**32px** top — `2rem` at the 16px root; this is the user's explicit call, not the original design artboard's 56px, see "Resolved" history below), **and its own `_cssCustom` gradient**. The file rule in `wkf-components.css` (`.wkf-righello{width:100%}`) is deliberately trimmed to just that one property, because it's the one thing the global class doesn't cover — a Bricks `.brxe-div` inside a flex parent collapses to 0 width without an explicit `width`.

The gradient CSS itself (either in the class's `_cssCustom` or the file, functionally identical):
```css
.wkf-righello {
  border-top: 1px solid var(--wkf-grey-l-2);
  height: 8px;
  background-image:
    repeating-linear-gradient(90deg, var(--wkf-grey-l-2) 0 1px, transparent 1px 40px),
    repeating-linear-gradient(90deg, var(--wkf-grey-l-2) 0 1px, transparent 1px 8px);
  background-size: 100% 8px, 100% 4px;
  background-repeat: no-repeat;
}
```

**History note, so old commit messages/plan text don't mislead a future reader:** the design artboards specify `margin-top: 56px`; the user explicitly chose to keep `2rem` (32px at the restored 16px root) instead — recorded as a resolved decision, not a bug. A separate, unrelated note from Phase 2.5c claiming "the gradient lives only in `wkf-components.css`, not the global class" was itself found stale during the Phase 8.1 audit — the class was rebuilt again after that note was written and does carry its own gradient. This doc reflects the current, verified-live state.

### Global classes map

| Class name | Global class id | Purpose |
|---|---|---|
| `wkf-eyebrow` | `aclrwr` | Pre-title / eyebrow label: mono font, uppercase, `var(--wkf-tracking-wide)` (0.08em), `var(--wkf-grey-d-1)`. Compiles to the **compound selector** `.wkf-eyebrow.brxe-heading` — beats a plain single-class per-instance override regardless of source order (see gotcha #2 below). |
| `wkf-righello` | `qxelsw` | Ruler-rule divider, see above. |
| `wkf-container` | `yzmogb` | Max-width content wrapper (`var(--wkf-container)`, 95%) — a plain layout wrapper, no other styling. |
| `list_square` | `ndhjpq` | Blue square bullet marker treatment. Two DOM shapes handled: (A) a raw `<ul><li>` — keeps the native `::marker`; (B) a Bricks-authored `<ul>` where each `<li>` is a flex block wrapping a `display:list-item` `<span>` (e.g. ACF-loop "Applicazioni" lists) — native marker suppressed, `<li>` forced `flex-direction:row`, and a drawn `::before` square substitutes. Selector is structural (`ul.list_square.brxe-block > li.brxe-block`), so every brand template inherits it automatically. |
| `product_sku`, `product_brand`, `product_promise` | *(ids not captured in the source plan docs)* | User-authored wrapper classes for product-meta rows; typography lives on the class, the actual value styling (`.product_sku span.text`, etc.) lives in `wkf-components.css`. |

### Component map

Three reusable Bricks Components, all in the "Loop Items" category, all built with **zero `properties`** (every field is dynamic data, so any instance works unconfigured):

| Component | Id | Used on | Notes |
|---|---|---|---|
| Scheda Prodotto (product card) | `2b807c` | Category archives (1277/1290/1201), brand pages, ITW "Prodotti correlati", homepage-adjacent | Framed image, brand eyebrow, product name, mono SKU, price/"Su richiesta", "Vedi scheda →". Hover: typed `_border:hover` + `_transform:hover` (`translateY(-2px)`), plus one hardcoded-class `_cssCustom` line for the shadow: `.brxe-2b807c:hover{box-shadow:var(--wkf-shadow)}` — a component instance never renders an `id` attribute in the DOM (only the `brxe-2b807c` class), so an `#brxe-` or `%root%` selector silently matches nothing; the class must be hardcoded. |
| Scheda Categoria (category tile) | `dc9cc7` | Categoria L1 (1290), Catalogo (1201), all 3 brand pages, Homepage | Restyled once, in place, from an original blue-poster skin to the current **stacked white card** (framed 16/10 image, term name, plain mono post-count, "Esplora →") — because it's a single component, that restyle applied to every instance simultaneously. The blue-poster description in older plan text is superseded; the white card is what's live everywhere today. |
| Scheda Guida (blog/guide card) | `ffffd3` | Guide Tecniche index (1468), category archive (1476), single guide "Altre guide" (1478) | Full-bleed image (no padded frame — an interim padded-frame version was tried and reverted per user feedback), category eyebrow, `h3` title, word-limited excerpt (`{post_excerpt:22}`, no CSS line-clamp), mono date + "Leggi la guida →". All internal element ids are ≤6 characters (`ggimg`, `ggcont`, `ggeyeb`, `ggtitl`, `ggexce`, `ggfoot`, `ggdate`, `gglink`) — see gotcha #1. |

### Template & page map

| Id | Title | Bricks type | Notes |
|---|---|---|---|
| 7 | Main Header | `header` | «Preventivo» RFQ button, mega menu, drawer breakpoint 1279 |
| 65 | Single Product — ITW | `wc_product` | First-built product template; source of the reusable patterns |
| 434 | Single Product — Akifix | `wc_product` | 2-col layout, no technical aside |
| 439 | Single Product — Baufloor | `wc_product` | 3-col layout, calculator-driven info column |
| 592 | Popup – Legenda Simboli | `popup` | Badge/symbol legend |
| 1019 | Quick View — Akifix | `popup` | |
| 1084 | Quick View — Baufloor | `popup` | brand-35 only |
| 1153 | Area Download | `archive` (scoped `wkf_document`) | Explicitly deferred — unstyled |
| 1201 | Catalogo | `wc_archive` | Tree root, no breadcrumb |
| 1277 | Categorie L2/L3 | `wc_archive` | |
| 1290 | Categoria L1 | `wc_archive` | |
| 1182 / 1185 / 1189 | Brand pages — Baufloor / Akifix / ITW | `wc_archive` | |
| 1362 | Footer | `footer` | |
| 1412 | Popup – Lista Preventivo | `popup` (`[{main:"any"}]`) | Quote-cart modal |
| 1476 | Guide – Argomento | `archive` (`archiveType:term`, `archiveTerms:["category::all"]`) | Taxonomy-scoped to native `category`, never touches `product_cat`/`product_brand` |
| 1478 | Guida – Articolo singolo | `content` (`postType:["post"]`) | Bricks has no dedicated `"single"` type — `content` is the correct native type for single-post templates |
| Page 2 | Homepage | — (real `page`, not a `bricks_template`) | Rendered via Bricks' `is_home()` merge |
| Page 1468 | Guide Tecniche | — (real `page`, `page_for_posts`) | Same `is_home()` mechanism as the homepage |

Full element-id vocabularies for cloning or auditing a specific template live in memory, not duplicated here: `bricks-template-65-itw.md`, `bricks-product-card-component.md`, `bricks-category-tile-component.md`, `bricks-guide-blog-templates.md`, `bricks-native-controls-notes.md`.

### Breakpoints

Header/nav drawer breakpoint is a **custom 1279px** (`#wkfnav`: `mobileMenu:"custom"`, `mobileMenuCustomBreakpoint:"1279"`). This value has a history — it was 1150, then briefly tried at 1200 and 1320 (1320 broke the logic, since Bricks inverts the min/max comparison above the theme's `desktop` base width, which is 1279 on this site) — 1279 is final. Everything else uses Bricks defaults: `tablet_portrait` 991 / `mobile_landscape` 767 / `mobile_portrait` 478.

### Two standing Bricks gotchas (Phase G, apply to any future component/template work)

1. **Component-internal element ids must be ≤6 characters.** Bricks truncates ids to 6 characters in the rendered HTML `class` attribute for elements *inside a Component*, but compiles the CSS selector from the full, untruncated id — so any component-internal element with an id longer than 6 characters silently receives **zero** of its typed-control styling (no error, no warning). This is Component-instance-specific: page-level and template-level element ids (outside any component) are not affected and can be any length. `2b807c` and `dc9cc7` never hit this by coincidence (their ids all happened to be exactly 6 characters already); `ffffd3` did hit it and was fixed by renaming every internal id to ≤6 characters.

2. **A global class's compound selector can silently beat a per-instance override**, even when the per-instance rule is correctly stored and compiled. `wkf-eyebrow` (`aclrwr`) compiles to `.wkf-eyebrow.brxe-heading` (specificity 0,2,0) — this beats a plain per-instance selector like `.brxe-ggeyeb` (0,1,0) regardless of source order, so a typed `_typography.font-size` override on an eyebrow-classed element is silently dead weight. Diagnosis: if a typed override "isn't working" despite verifying it's correctly stored and present in the compiled CSS, check what other classes — especially global classes — are also on the element and compare selector specificity, not just presence/absence of the rule. Fix pattern used: drop the losing typed property, add a narrowly-scoped `_cssCustom` with `!important` targeting the element's own class (e.g. `.brxe-ggeyeb{font-size:var(--text-2xs) !important;}`) — a deliberate, well-precedented exception to the "no unnecessary custom CSS" rule, not something to reach for casually.
